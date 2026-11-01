"""Netz-/DNS-Prüfungen: Return-Path (RFC 5321/7505), FCrDNS, HELO, DNSBL, IPv4/IPv6, TLS."""
import ipaddress
import re
import time

TEMP_ERRORS = ("timeout", "servfail")


def _is_temp(err):
    return err in TEMP_ERRORS or (err or "").startswith("error:")


# --------------------------------------------------------------------------- Return-Path
def check_envelope_dns(dnsc, dom):
    """Ist die Return-Path-Domain rückantwortfähig? CNAME-Kette, MX, impliziter MX, Null-MX."""
    res = {"domain": dom, "status": "pass", "reasons": [], "cname_chain": [], "final_name": dom,
           "mx": [], "implicit_mx": []}
    if not dom:
        res.update(status="not_tested", reasons=["null_sender"])
        return res
    name, seen = dom.rstrip(".").lower(), set()
    for _ in range(8):
        if name in seen:
            res.update(status="fail", reasons=["cname_loop"])
            return res
        seen.add(name)
        target, err = dnsc.query(name, "CNAME")
        if _is_temp(err):
            res.update(status="temperror", reasons=[f"cname_{err}"])
            return res
        if not target:
            break
        name = target[0].rstrip(".").lower()
        res["cname_chain"].append(name)
    else:
        res.update(status="fail", reasons=["cname_chain_too_long"])
        return res
    res["final_name"] = name

    mx, err = dnsc.query(name, "MX")
    if _is_temp(err):
        res.update(status="temperror", reasons=[f"mx_{err}"])
        return res
    if err == "nxdomain":
        res.update(status="fail", reasons=["nxdomain"])
        return res
    if mx:
        hosts = sorted((int(p), h.rstrip(".").lower()) for p, h in (x.split(None, 1) for x in mx))
        if len(hosts) == 1 and hosts[0][1] in ("", "."):
            res.update(status="fail", reasons=["null_mx"], mx=[{"pref": hosts[0][0], "host": "."}])
            return res
        resolvable, temp = 0, False
        for pref, host in hosts:
            entry = {"pref": pref, "host": host, "is_cname": bool(dnsc.query(host, "CNAME")[0])}
            entry["a"], ea = dnsc.query(host, "A")
            entry["aaaa"], e6 = dnsc.query(host, "AAAA")
            if entry["a"] or entry["aaaa"]:
                resolvable += 1
            else:
                entry["error"] = ea if ea == e6 else f"{ea}/{e6}"
                temp = temp or _is_temp(ea) or _is_temp(e6)
            if entry["is_cname"]:
                res["reasons"].append(f"mx_is_cname:{host}")
            res["mx"].append(entry)
        if resolvable == 0:
            res["status"] = "temperror" if temp else "fail"
            res["reasons"].append("mx_targets_unresolvable")
        elif resolvable < len(hosts) or any(e["is_cname"] for e in res["mx"]):
            res["status"] = "warn"
            if resolvable < len(hosts):
                res["reasons"].append("some_mx_targets_unresolvable")
        return res
    a, ea = dnsc.query(name, "A")
    aaaa, e6 = dnsc.query(name, "AAAA")
    res["implicit_mx"] = a + aaaa
    if a or aaaa:
        res.update(status="warn", reasons=["no_mx_implicit_a"])
    elif _is_temp(ea) or _is_temp(e6):
        res.update(status="temperror", reasons=["implicit_mx_dns_error"])
    else:
        res.update(status="fail", reasons=["no_mx_no_a"])
    return res


# --------------------------------------------------------------------------- IP / FCrDNS / HELO
def ip_version(ip):
    try:
        return ipaddress.ip_address(ip).version
    except ValueError:
        return None


def reverse_name(ip):
    return ipaddress.ip_address(ip).reverse_pointer


def check_fcrdns(dnsc, ip):
    """PTR der Client-IP und Rückauflösung des PTR-Namens auf dieselbe IP (Forward-Confirmed rDNS)."""
    res = {"ip": ip, "status": "fail", "ptr": [], "confirmed": None, "reason": ""}
    try:
        addr = ipaddress.ip_address(ip)
    except ValueError:
        res.update(status="not_tested", reason="keine gültige IP")
        return res
    ptrs, err = dnsc.query(reverse_name(ip), "PTR")
    res["ptr"] = [p.rstrip(".").lower() for p in ptrs]
    if _is_temp(err):
        res.update(status="temperror", reason=f"PTR {err}")
        return res
    if not ptrs:
        res["reason"] = "kein PTR"
        return res
    rtype = "A" if addr.version == 4 else "AAAA"
    temp = False
    for name in res["ptr"]:
        addrs, e = dnsc.query(name, rtype)
        temp = temp or _is_temp(e)
        if any(ipaddress.ip_address(a) == addr for a in addrs):
            res.update(status="pass", confirmed=name, reason="")
            return res
    res.update(status="temperror" if temp else "fail",
               reason="PTR-Name löst nicht auf die IP auf" if not temp else "DNS-Fehler bei Rückauflösung")
    return res


def check_helo(dnsc, helo, ip):
    """HELO/EHLO-Name in Teilprüfungen: Syntax (FQDN/Adressliteral), Auflösbarkeit, Treffer auf die Client-IP.
    Ein fehlender IP-Treffer ist nur eine Beobachtung (warn) – große Anbieter nutzen oft gemeinsame HELO-Namen."""
    res = {"name": helo, "status": "fail", "syntax": None, "resolves": None, "matches_client_ip": None,
           "addresses": [], "reason": ""}
    h = (helo or "").strip().lower().rstrip(".")
    if not h or h == "unknown":
        res.update(syntax="missing", reason="kein HELO")
        return res
    if h.startswith("[") and h.endswith("]"):
        lit = h[1:-1].removeprefix("ipv6:")
        same = ip_version(lit) is not None and ipaddress.ip_address(lit) == ipaddress.ip_address(ip)
        res.update(syntax="address_literal", matches_client_ip=same, status="warn" if same else "fail",
                   reason="Adressliteral statt Hostname" + ("" if same else ", ≠ Client-IP"))
        return res
    if "." not in h or not re.fullmatch(r"[a-z0-9_]([a-z0-9_-]*[a-z0-9])?(\.[a-z0-9_]([a-z0-9_-]*[a-z0-9])?)+", h):
        res.update(syntax="invalid", reason="kein gültiger FQDN")
        return res
    res["syntax"] = "fqdn"
    a, ea = dnsc.query(h, "A")
    aaaa, e6 = dnsc.query(h, "AAAA")
    res["addresses"] = a + aaaa
    if not res["addresses"]:
        temp = _is_temp(ea) or _is_temp(e6)
        res.update(status="temperror" if temp else "fail", resolves=None if temp else False,
                   reason="DNS-Fehler beim HELO-Namen" if temp else "HELO-Name nicht auflösbar")
        return res
    res["resolves"] = True
    try:
        match = any(ipaddress.ip_address(x) == ipaddress.ip_address(ip) for x in res["addresses"])
    except ValueError:
        match = False
    res.update(matches_client_ip=match, status="pass" if match else "warn",
               reason="" if match else "HELO-Name zeigt auf andere IP (bei großen Anbietern üblich, kein Fehler)")
    return res


def check_protocol(ip, rcpt_domain):
    """IPv4/IPv6 als eigene Testdimension: angefordert (ip4./ip6.-Adresse) vs. tatsächlich genutzt."""
    used = ip_version(ip)
    d = (rcpt_domain or "").lower()
    requested = 4 if d.startswith("ip4.") else 6 if d.startswith("ip6.") else None
    if requested is None:
        status, reason = "pass", "auto – getestet wurde nur IPv%s" % used
    elif requested == used:
        status, reason = "pass", ""
    else:
        status, reason = "not_tested", f"IPv{requested} angefordert, Zustellung kam per IPv{used}"
    return {"requested": f"ipv{requested}" if requested else "auto", "used": f"ipv{used}" if used else None,
            "status": status, "reason": reason}


# --------------------------------------------------------------------------- DNSBL
# Rückgabecodes laut Betreiber-Dokumentation. 127.255.255.x = Abfragefehler, KEIN Listing.
DNSBLS = [
    {"zone": "zen.spamhaus.org", "name": "Spamhaus ZEN", "ipv6": True, "codes": {
        "127.0.0.2": "SBL – Spamquelle", "127.0.0.3": "SBL CSS – Snowshoe/Spam",
        "127.0.0.4": "XBL – kompromittiert/Exploit", "127.0.0.5": "XBL", "127.0.0.6": "XBL", "127.0.0.7": "XBL",
        "127.0.0.9": "SBL DROP", "127.0.0.10": "PBL – dynamische IP (Provider)",
        "127.0.0.11": "PBL – dynamische IP (Spamhaus)"},
     "errors": {"127.255.255.252": "Tippfehler im Zonennamen", "127.255.255.254":
                "Abfrage über öffentlichen/offenen Resolver – von Spamhaus blockiert",
                "127.255.255.255": "Abfragelimit überschritten"}},
    {"zone": "bl.spamcop.net", "name": "SpamCop", "ipv6": False, "codes": {"127.0.0.2": "gelistet"}, "errors": {}},
    # Mailspike: 127.0.0.2 = Listing, 127.0.0.10–14 = negative Reputationsstufen (L5 schlechteste … L1)
    {"zone": "bl.mailspike.net", "name": "Mailspike", "ipv6": False, "codes": {
        "127.0.0.2": "gelistet – Spamquelle", "127.0.0.10": "Reputation L5 – schlechteste",
        "127.0.0.11": "Reputation L4 – sehr schlecht", "127.0.0.12": "Reputation L3 – schlecht",
        "127.0.0.13": "Reputation L2 – auffällig", "127.0.0.14": "Reputation L1 – leicht negativ"}, "errors": {}},
    # SpamRATS (u. a. von der Telekom genutzt) beantwortet Anfragen großer Resolver (Hetzner, Google,
    # Cloudflare) nicht → direkt bei den Listen-Servern fragen; rbldns1 war am 2026-10-15 nicht erreichbar.
    {"zone": "all.spamrats.com", "name": "SpamRATS", "ipv6": False,
     "direct_ns": [f"rbldns{i}.spamrats.com" for i in (2, 3, 4, 5, 6, 1)], "codes": {
        "127.0.0.36": "RATS-Dyna – dynamische IP (kein Spamnachweis)",
        "127.0.0.37": "RATS-NoPtr – kein PTR (kein Spamnachweis)",
        "127.0.0.38": "RATS-Spam – Spam beobachtet", "127.0.0.43": "RATS-Auth – Auth-Angriffe",
        "127.0.0.2": "Testeintrag (nur 127.0.0.2)"}, "errors": {}},
    {"zone": "psbl.surriel.com", "name": "PSBL", "ipv6": False, "codes": {"127.0.0.2": "gelistet"}, "errors": {}},
    {"zone": "dnsbl-1.uceprotect.net", "name": "UCEPROTECT L1", "ipv6": False,
     "codes": {"127.0.0.2": "gelistet"}, "errors": {}},
]


def dnsbl_lists(spamhaus_dqs_key=None):
    """Listen-Konfiguration; mit DQS-Key wird Spamhaus über zen.dq.spamhaus.net (Data Query Service) abgefragt.
    Der Key steht nur im Abfragenamen und wird in allen gespeicherten Feldern maskiert."""
    lists = [dict(bl) for bl in DNSBLS]
    if spamhaus_dqs_key:
        for bl in lists:
            if bl["zone"] == "zen.spamhaus.org":
                bl.update(zone=f"{spamhaus_dqs_key}.zen.dq.spamhaus.net", display_zone="zen.dq.spamhaus.net",
                          name="Spamhaus ZEN (DQS)", secret=spamhaus_dqs_key)
    return lists


def dnsbl_query_name(ip, zone):
    addr = ipaddress.ip_address(ip)
    if addr.version == 4:
        return ".".join(reversed(ip.split("."))) + "." + zone
    return ".".join(reversed(addr.exploded.replace(":", ""))) + "." + zone


KEY_PLACEHOLDER = "[key]"   # ersetzt Zugangsschlüssel in gespeicherten Abfragenamen


def check_dnsbl(dnsc, ip, lists=None):
    """Jede Liste einzeln: listed / not_listed / no_response (Timeout) / error / not_supported,
    mit Code, Bedeutung, Zeitpunkt, DNS-Antwortcode, Resolver und Dauer.
    not_listed nur bei ausdrücklicher Negativantwort (NXDOMAIN/NODATA) der Liste."""
    out = {"ip": ip, "checked_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
           "listed_count": 0, "error_count": 0, "no_response_count": 0, "lists": []}
    ver = ip_version(ip)
    for bl in lists if lists is not None else DNSBLS:
        e = {"zone": bl.get("display_zone", bl["zone"]), "name": bl["name"], "status": "not_listed",
             "codes": [], "meaning": [], "query": None}
        if ver is None or (ver == 6 and not bl["ipv6"]):
            e["status"] = "not_supported" if ver == 6 else "not_tested"
            out["lists"].append(e)
            continue
        qname = dnsbl_query_name(ip, bl["zone"])
        t = time.monotonic()
        if bl.get("direct_ns"):
            answers, err = dnsc.query_direct(qname, "A", bl["direct_ns"])
        else:
            answers, err = dnsc.query(qname, "A")
        e["query"] = qname.replace(bl["secret"], KEY_PLACEHOLDER) if bl.get("secret") else qname
        e["resolver"] = "list_nameservers" if bl.get("direct_ns") else "system"
        e["rcode"] = {None: "NOERROR", "nxdomain": "NXDOMAIN", "nodata": "NOERROR", "servfail": "SERVFAIL",
                      "timeout": None}.get(err, "ERROR")
        e["duration_ms"] = round((time.monotonic() - t) * 1000)
        if err in ("nxdomain", "nodata"):
            pass
        elif err == "timeout":
            e.update(status="no_response", error="keine Antwort der Liste")
        elif err:
            e.update(status="error", error=err)
        else:
            e["codes"] = answers
            errs = [bl["errors"].get(a) or "Fehlercode" for a in answers if a.startswith("127.255.255.")]
            if errs:
                e.update(status="error", error="; ".join(errs))
            else:
                txt, _ = (dnsc.query_direct(qname, "TXT", bl["direct_ns"]) if bl.get("direct_ns")
                          else dnsc.query(qname, "TXT"))
                e.update(status="listed", meaning=[bl["codes"].get(a, "unbekannter Code") for a in answers],
                         evidence=txt[:3])
        if e["status"] == "listed":
            out["listed_count"] += 1
        elif e["status"] == "error":
            out["error_count"] += 1
        elif e["status"] == "no_response":
            out["no_response_count"] += 1
        out["lists"].append(e)
    return out


# --------------------------------------------------------------------------- TLS
def check_tls(protocol, cipher):
    """Verschlüsselung der eingehenden SMTP-Verbindung (nur dieser Hop, laut eigenem Received-Header).
    Aussagen über Zertifikate oder andere Hops sind damit nicht möglich."""
    res = {"protocol": protocol or None, "cipher": cipher or None, "status": "pass", "reason": ""}
    if not protocol:
        res.update(status="fail", reason="unverschlüsselt übertragen")
    elif protocol in ("TLSv1.3", "TLSv1.2"):
        pass
    else:
        res.update(status="warn", reason=f"veraltetes Protokoll {protocol}")
    return res
