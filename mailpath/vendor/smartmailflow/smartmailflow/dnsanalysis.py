"""DNS-Analyse der Absenderdomain (From-Header), wie ein DNS-Prüfdienst sie zeigt:

  mx     – MX-Server mit STARTTLS-Prüfung (Protokoll, Zertifikat: Aussteller, Laufzeit, Art, Hostname-Treffer)
  spf    – vollständiger SPF-Eintrag, jeder Bestandteil erklärt, Include-/Redirect-Baum mit aufgelösten IPs,
           DNS-Abfragen über den ganzen Eintrag (Grenze 10, RFC 7208 4.6.4) – unabhängig von der Absender-IP
  dkim   – verwendete Selektoren mit erklärten Schlüssel-Tags und Signaturdaten (Zeitstempel, Ablauf)
  dmarc  – DMARC-Eintrag, jedes Tag erklärt, Hinweise

Alles hier ist Information über die Domain-Konfiguration, kein Ergebnis der Authentifizierung dieser Mail.
"""
import ipaddress
import re
from concurrent.futures import ThreadPoolExecutor

from . import auth

MAX_MX = 5
SPF_LOOKUP_LIMIT = 10
SPF_VOID_LIMIT = 2
SPF_MAX_NODES = 60

# --------------------------------------------------------------------------- MX + TLS

CERT_POLICY = {"2.23.140.1.2.1": "DV (Domain validiert)", "2.23.140.1.2.2": "OV (Organisation validiert)",
               "2.23.140.1.2.3": "IV (Person validiert)", "2.23.140.1.1": "EV (erweitert validiert)"}


def cert_summary(der, host):
    """Server-Zertifikat (DER) lesbar: Aussteller, Laufzeit, Art (DV/OV/EV), Schlüssel, Hostname-Treffer."""
    import datetime
    from cryptography import x509
    from cryptography.hazmat.primitives.asymmetric import ec, rsa
    from cryptography.x509.oid import NameOID
    c = x509.load_der_x509_certificate(der)
    utc = datetime.timezone.utc
    nb = getattr(c, "not_valid_before_utc", None) or c.not_valid_before.replace(tzinfo=utc)
    na = getattr(c, "not_valid_after_utc", None) or c.not_valid_after.replace(tzinfo=utc)
    now = datetime.datetime.now(utc)
    try:
        sans = c.extensions.get_extension_for_class(x509.SubjectAlternativeName).value.get_values_for_type(x509.DNSName)
    except x509.ExtensionNotFound:
        sans = []
    try:
        pols = [p.policy_identifier.dotted_string for p in
                c.extensions.get_extension_for_class(x509.CertificatePolicies).value]
    except x509.ExtensionNotFound:
        pols = []
    kind = next((CERT_POLICY[p] for p in pols if p in CERT_POLICY), None)
    key = c.public_key()
    key_algo = "rsa" if isinstance(key, rsa.RSAPublicKey) else (
        f"ec-{key.curve.name}" if isinstance(key, ec.EllipticCurvePublicKey) else type(key).__name__)
    get = lambda n, oid: (n.get_attributes_for_oid(oid) or [None])[0]
    cn = get(c.subject, NameOID.COMMON_NAME)
    names = [n.lower() for n in sans] or ([cn.value.lower()] if cn else [])
    h = host.lower().rstrip(".")
    match = any(n == h or (n.startswith("*.") and h.endswith(n[1:]) and h.count(".") == n.count("."))
                for n in names)
    issuer_o = get(c.issuer, NameOID.ORGANIZATION_NAME) or get(c.issuer, NameOID.COMMON_NAME)
    return {"subject_cn": cn.value if cn else None, "san": sans[:20], "issuer": c.issuer.rfc4514_string(),
            "issuer_organization": issuer_o.value if issuer_o else None,
            "not_before": nb.strftime("%Y-%m-%dT%H:%M:%SZ"), "not_after": na.strftime("%Y-%m-%dT%H:%M:%SZ"),
            "days_left": (na - now).days, "expired": now > na, "self_signed": c.issuer == c.subject,
            "type": kind or ("selbstsigniert" if c.issuer == c.subject else "unbekannt"),
            "key_algorithm": key_algo, "key_bits": getattr(key, "key_size", None),
            "signature_hash": c.signature_hash_algorithm.name if c.signature_hash_algorithm else None,
            "hostname_match": match}



def analyze_mx(dnsc, domain):
    answers, err = dnsc.query(domain, "MX")
    out = {"status": "pass", "records": [], "error": err if err not in (None, "nodata") else None, "reason": ""}
    hosts = []
    for a in answers:
        if " " in a:
            pref, host = a.split(None, 1)
            hosts.append((int(pref), host.rstrip(".").lower()))
    hosts.sort()
    if not hosts:
        out.update(status="none", reason="kein MX-Eintrag – Mails an die Domain gehen an die A-Adresse oder gar nicht")
        return out
    if len(hosts) == 1 and hosts[0][1] in ("", "."):
        out.update(status="null_mx", reason="Null-MX: Domain empfängt ausdrücklich keine Mails",
                   records=[{"pref": hosts[0][0], "host": "."}])
        return out
    probe = hosts[:MAX_MX]
    with ThreadPoolExecutor(max_workers=len(probe)) as pool:
        tls = list(pool.map(lambda h: dnsc.smtp_tls(h[1]), probe))
    for (pref, host), t in zip(probe, tls):
        a, _ = dnsc.query(host, "A")
        aaaa, _ = dnsc.query(host, "AAAA")
        out["records"].append({"pref": pref, "host": host, "a": a, "aaaa": aaaa, "tls": t})
    for pref, host in hosts[MAX_MX:]:
        out["records"].append({"pref": pref, "host": host, "a": [], "aaaa": [], "tls": None,
                               "note": f"nicht geprüft (nur die ersten {MAX_MX} MX)"})
    states = [r["tls"]["status"] for r in out["records"] if r.get("tls")]
    if any(s == "fail" for s in states):
        out.update(status="fail", reason="mindestens ein MX ohne STARTTLS – Mails an die Domain gehen unverschlüsselt")
    elif any(s == "warn" for s in states):
        out.update(status="warn", reason="mindestens ein MX mit ungültigem Zertifikat (Absender mit MTA-STS/DANE scheitern)")
    elif any(s == "error" for s in states):
        out.update(status="warn", reason="mindestens ein MX war nicht erreichbar")
    return out


# --------------------------------------------------------------------------- SPF

MECH_TEXT = {
    "all": "alle übrigen Absender",
    "ip4": "IPv4-Adresse/-Netz",
    "ip6": "IPv6-Adresse/-Netz",
    "a": "IP-Adressen (A/AAAA) des Hosts",
    "mx": "IP-Adressen der MX-Server der Domain",
    "include": "SPF-Eintrag einer anderen Domain einbinden (z. B. Mail-Dienstleister)",
    "exists": "erlaubt, wenn der (Makro-)Name existiert",
    "ptr": "Rückwärtsauflösung (veraltet, RFC 7208 rät ab)",
    "redirect": "gesamten SPF-Eintrag einer anderen Domain verwenden",
    "exp": "Domain mit Erklärungstext bei fail",
}
QUAL_TEXT = {"+": "erlauben (pass)", "-": "ablehnen (fail)", "~": "verdächtig (softfail)", "?": "neutral"}


def _parse_term(term):
    if "=" in term and ":" not in term.split("=", 1)[0]:
        name, value = term.split("=", 1)
        return {"term": term, "type": "modifier", "mechanism": name.lower(), "qualifier": None, "value": value}
    q = term[0] if term[0] in "+-~?" else "+"
    body = term[1:] if term[0] in "+-~?" else term
    m = re.match(r"([A-Za-z0-9]+)(?::([^/]*))?(/\d+)?(//\d+)?$", body)
    if not m:
        return {"term": term, "type": "invalid", "mechanism": body, "qualifier": q, "value": None}
    return {"term": term, "type": "mechanism", "mechanism": m.group(1).lower(), "qualifier": q,
            "value": m.group(2), "cidr": (m.group(3) or "") + (m.group(4) or "") or None}


def _spf_record(dnsc, domain):
    txt, err = dnsc.query(domain, "TXT")
    recs = [t for t in txt if t.lower().startswith("v=spf1 ") or t.lower() == "v=spf1"]
    return recs, err


def _resolve_ips(dnsc, name):
    a, ea = dnsc.query(name, "A")
    aaaa, e6 = dnsc.query(name, "AAAA")
    return a + aaaa, (not a and not aaaa and ea in ("nxdomain", "nodata") and e6 in ("nxdomain", "nodata"))


def spf_tree(dnsc, domain, state=None, depth=0):
    """Baum eines SPF-Eintrags. state zählt DNS-Abfragen über alle Ebenen."""
    state = state if state is not None else {"lookups": 0, "void": 0, "nodes": 0, "seen": set(), "problems": []}
    state["nodes"] += 1
    node = {"domain": domain, "record": None, "terms": [], "error": None}
    if depth > 10 or state["nodes"] > SPF_MAX_NODES:
        node["error"] = "zu tief verschachtelt (abgebrochen)"
        state["problems"].append(f"{domain}: Verschachtelung zu tief")
        return node
    if domain in state["seen"]:
        node["error"] = "Schleife (Domain schon eingebunden)"
        state["problems"].append(f"{domain}: Include-Schleife")
        return node
    state["seen"].add(domain)
    recs, err = _spf_record(dnsc, domain)
    if err in ("timeout", "servfail"):
        node["error"] = f"DNS {err}"
        state["problems"].append(f"{domain}: DNS {err}")
        return node
    if not recs:
        node["error"] = "kein SPF-Eintrag"
        if depth:
            state["void"] += 1
            state["problems"].append(f"{domain}: eingebunden, hat aber keinen SPF-Eintrag (permerror)")
        return node
    if len(recs) > 1:
        node["error"] = "mehrere SPF-Einträge (permerror)"
        state["problems"].append(f"{domain}: mehrere SPF-Einträge")
    node["record"] = recs[0]
    for raw in recs[0].split()[1:]:
        t = _parse_term(raw)
        mech = t["mechanism"]
        t["meaning"] = MECH_TEXT.get(mech, "unbekannter Bestandteil")
        if t["qualifier"]:
            t["action"] = QUAL_TEXT[t["qualifier"]]
        if t["type"] == "invalid" or (t["type"] == "mechanism" and mech not in MECH_TEXT):
            state["problems"].append(f"{domain}: ungültiger Bestandteil {raw}")
        target = t.get("value") or domain
        if mech in ("include", "redirect"):
            state["lookups"] += 1
            t["child"] = spf_tree(dnsc, target.lower().rstrip("."), state, depth + 1)
        elif mech == "a":
            state["lookups"] += 1
            ips, void = _resolve_ips(dnsc, target)
            t["ips"] = ips
            state["void"] += void
        elif mech == "mx":
            state["lookups"] += 1
            mx, err_mx = dnsc.query(target, "MX")
            state["void"] += err_mx in ("nxdomain", "nodata")
            t["hosts"] = []
            for x in mx[:10]:
                host = x.split(None, 1)[1].rstrip(".") if " " in x else x
                ips, _ = _resolve_ips(dnsc, host)
                t["hosts"].append({"host": host, "ips": ips})
        elif mech in ("ptr", "exists"):
            state["lookups"] += 1
        elif mech in ("ip4", "ip6"):
            try:
                ipaddress.ip_network(t["value"] + (t.get("cidr") or ""), strict=False)
            except Exception:
                state["problems"].append(f"{domain}: ungültige Adresse {raw}")
        node["terms"].append(t)
    return node


def _flatten(node, ips, includes):
    for t in node.get("terms", []):
        if t["mechanism"] in ("ip4", "ip6") and t.get("value"):
            ips.add(t["value"] + (t.get("cidr") or ""))
        for ip in t.get("ips", []):
            ips.add(ip)
        for h in t.get("hosts", []):
            ips.update(h["ips"])
        if t.get("child"):
            includes.append(t["child"]["domain"])
            _flatten(t["child"], ips, includes)


def analyze_spf(dnsc, domain):
    state = {"lookups": 0, "void": 0, "nodes": 0, "seen": set(), "problems": []}
    tree = spf_tree(dnsc, domain, state)
    ips, includes = set(), []
    _flatten(tree, ips, includes)
    out = {"domain": domain, "record": tree["record"], "tree": tree,
           "dns_lookups_total": state["lookups"], "dns_lookup_limit": SPF_LOOKUP_LIMIT,
           "void_lookups": state["void"], "void_lookup_limit": SPF_VOID_LIMIT,
           "includes": includes, "authorized_networks": sorted(ips), "problems": state["problems"], "status": "pass"}
    if not tree["record"]:
        out["status"] = "none" if not tree["error"] or "kein" in tree["error"] else "fail"
    elif state["lookups"] > SPF_LOOKUP_LIMIT or state["void"] > SPF_VOID_LIMIT or state["problems"]:
        out["status"] = "fail"
        if state["lookups"] > SPF_LOOKUP_LIMIT:
            out["problems"].append(f"{state['lookups']} DNS-Abfragen – über der Grenze von 10 (permerror möglich)")
    elif state["lookups"] >= 8:
        out["status"] = "warn"
        out["problems"].append(f"{state['lookups']} von 10 DNS-Abfragen – wenig Reserve")
    last = tree["terms"][-1]["term"].lower() if tree.get("terms") else ""
    if tree["record"] and last in ("+all", "all"):
        out["status"] = "fail"
        out["problems"].append("„+all“ erlaubt jedem Server den Versand")
    elif tree["record"] and not any(t["mechanism"] in ("all", "redirect") for t in tree["terms"]):
        out["problems"].append("kein abschließendes „all“ – Standard ist neutral")
    return out


# --------------------------------------------------------------------------- DKIM

DKIM_KEY_TAGS = {
    "v": "Version (DKIM1)",
    "k": "Schlüsseltyp",
    "p": "öffentlicher Schlüssel (leer = zurückgezogen)",
    "t": "Schalter: y = Testmodus, s = nur exakt diese Domain (keine Subdomains)",
    "h": "erlaubte Hash-Verfahren",
    "s": "Dienstart (* oder email)",
    "n": "Notiz",
}


def analyze_dkim(signatures):
    """Je verwendetem Selektor: erklärte Tags des Schlüssels und Daten aus der Signatur.
    DKIM-Schlüssel sind keine Zertifikate – es gibt keinen Aussteller und kein Ablaufdatum."""
    import datetime
    out = []
    for s in signatures:
        tags = auth.dkim_tags(s.get("dns_txt") or "") if s.get("dns_txt") else {}
        sig = auth.dkim_tags(s.get("header") or "")
        ts = lambda v: (datetime.datetime.fromtimestamp(int(v), datetime.timezone.utc)
                        .strftime("%Y-%m-%dT%H:%M:%SZ") if v and v.isdigit() else None)
        out.append({
            "selector": s.get("selector"), "domain": s.get("domain"), "dns_name": s.get("dns_name"),
            "key_algorithm": s.get("key_algorithm"), "key_bits": s.get("key_bits"),
            "key_strength": s.get("key_strength"), "key_status": s.get("key_status"),
            "recommendation": s.get("recommendation"),
            "key_tags": [{"tag": k, "value": (v if k != "p" else f"{len(v)} Zeichen"),
                          "meaning": DKIM_KEY_TAGS.get(k, "unbekannt")} for k, v in tags.items()],
            "signed_at": ts(sig.get("t")), "expires_at": ts(sig.get("x")),
            "signing_algorithm": s.get("signing_algorithm"), "canonicalization": s.get("canonicalization"),
            "result": s.get("result"),
            "note": "DKIM-Schlüssel sind kein Zertifikat: kein Aussteller, kein Ablaufdatum. "
                    "Empfohlen: RSA 2048 Bit oder Ed25519, regelmäßiger Schlüsselwechsel über neue Selektoren.",
        })
    return out


# --------------------------------------------------------------------------- DMARC

DMARC_TAGS = {
    "v": "Version",
    "p": "Richtlinie für die Domain: none = nur beobachten, quarantine = Spam-Ordner, reject = abweisen",
    "sp": "Richtlinie für Subdomains (ohne sp gilt p)",
    "np": "Richtlinie für nicht existierende Subdomains",
    "pct": "Anteil der Mails in Prozent, auf den die Richtlinie angewendet wird",
    "rua": "Empfänger der täglichen Sammelberichte (Aggregate Reports)",
    "ruf": "Empfänger der Einzelberichte bei Fehlschlag (Forensic Reports)",
    "adkim": "Ausrichtung DKIM: r = relaxed (Subdomains zählen), s = strict (exakt gleiche Domain)",
    "aspf": "Ausrichtung SPF: r = relaxed, s = strict",
    "fo": "wann Einzelberichte erstellt werden: 0 = alles schlägt fehl, 1 = eins schlägt fehl, d = DKIM, s = SPF",
    "rf": "Format der Einzelberichte",
    "ri": "Berichtsintervall in Sekunden",
}


def analyze_dmarc(dnsc, from_domain):
    tags, rec, source, err = auth.dmarc_tags(dnsc, from_domain)
    out = {"domain": from_domain, "record": rec, "record_source": source if rec else None, "tags": [],
           "notes": [], "status": "pass", "error": err}
    if err:
        out["status"] = "temperror"
        return out
    if not rec:
        out["status"] = "none"
        out["notes"].append("kein DMARC-Eintrag – große Anbieter (Google, Microsoft, Yahoo) verlangen DMARC")
        return out
    if source != from_domain:
        out["notes"].append(f"Eintrag stammt von der Organisationsdomain {source}")
    for k, v in tags.items():
        out["tags"].append({"tag": k, "value": v, "meaning": DMARC_TAGS.get(k, "unbekanntes Tag")})
    p = tags.get("p")
    if p not in ("none", "quarantine", "reject"):
        out["status"] = "fail"
        out["notes"].append("p= fehlt oder ist ungültig")
    elif p == "none":
        out["status"] = "warn"
        out["notes"].append("p=none: Fälschungen werden nicht abgewiesen, nur berichtet")
    if tags.get("pct", "100").isdigit() and int(tags.get("pct", "100")) < 100:
        out["status"] = "warn" if out["status"] == "pass" else out["status"]
        out["notes"].append(f"pct={tags['pct']}: Richtlinie gilt nur für einen Teil der Mails")
    if not tags.get("rua"):
        out["notes"].append("kein rua: keine Sammelberichte, Fehlkonfigurationen bleiben unbemerkt")
    if tags.get("sp") == "none" and p in ("quarantine", "reject"):
        out["notes"].append("sp=none: Subdomains sind nicht geschützt")
    return out


def analyze_domain(dnsc, from_domain, dkim_signatures):
    if not from_domain:
        return {"domain": None, "status": "not_tested", "reason": "keine eindeutige From-Domain"}
    return {"domain": from_domain, "mx": analyze_mx(dnsc, from_domain), "spf": analyze_spf(dnsc, from_domain),
            "dkim": analyze_dkim(dkim_signatures), "dmarc": analyze_dmarc(dnsc, from_domain)}
