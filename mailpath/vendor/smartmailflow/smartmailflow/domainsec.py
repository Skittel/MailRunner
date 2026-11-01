"""Domain-Konfiguration der Absenderdomain (From-Header): DNSSEC, MTA-STS (RFC 8461), TLS-RPT (RFC 8460),
DANE für die MX (RFC 7672) und BIMI.

Das sind Konfigurationsprüfungen der Domain, kein Nachweis über diese Mail: MTA-STS und DANE schützen
Mails, die AN diese Domain gehen. Sie fließen nicht in SPF/DKIM/DMARC ein.
"""
import re

MAX_MX = 5


def _txt(dnsc, name, prefix):
    answers, err = dnsc.query(name, "TXT")
    recs = [a for a in answers if a.strip().lower().startswith(prefix.lower())]
    return recs, err


def check_dnssec(dnsc, domain):
    answers, err, ad = dnsc.query_dnssec(domain, "MX")
    res = {"status": "unsigned", "validated": ad, "reason": ""}
    if err == "servfail":
        res.update(status="bogus", reason="validierender Resolver meldet SERVFAIL (DNSSEC-Fehler oder DNS-Ausfall)")
    elif err == "timeout":
        res.update(status="temperror", reason="keine Antwort")
    elif ad:
        res["status"] = "signed"
    else:
        res["reason"] = "Zone nicht signiert"
    return res, [a.split(None, 1)[1].rstrip(".").lower() for a in answers if " " in a]


def mx_matches(mx, pattern):
    mx, pattern = mx.lower().rstrip("."), pattern.lower().rstrip(".")
    if pattern.startswith("*."):
        return mx.endswith(pattern[1:]) and mx.count(".") == pattern.count(".")
    return mx == pattern


def check_mta_sts(dnsc, domain, mx_hosts):
    res = {"status": "not_present", "txt": None, "policy_url": None, "mode": None, "max_age": None,
           "mx_patterns": [], "mx_not_covered": [], "reason": ""}
    recs, err = _txt(dnsc, f"_mta-sts.{domain}", "v=STSv1")
    if err in ("timeout", "servfail"):
        res.update(status="temperror", reason=f"DNS {err}")
        return res
    if not recs:
        return res
    res["txt"] = recs[0]
    if len(recs) > 1 or not re.search(r"\bid=[A-Za-z0-9]{1,32}\b", recs[0]):
        res.update(status="fail", reason="TXT-Record ungültig (mehrere Records oder id fehlt)")
        return res
    url = f"https://mta-sts.{domain}/.well-known/mta-sts.txt"
    res["policy_url"] = url
    code, body, ferr = dnsc.fetch(url)
    if ferr or code != 200:
        res.update(status="fail", reason=f"Policy nicht abrufbar ({ferr or f'HTTP {code}'})")
        return res
    fields = {}
    for line in body.splitlines():
        if ":" in line:
            k, v = line.split(":", 1)
            k, v = k.strip().lower(), v.strip()
            if k == "mx":
                res["mx_patterns"].append(v)
            else:
                fields[k] = v
    res["mode"] = fields.get("mode")
    res["max_age"] = int(fields["max_age"]) if fields.get("max_age", "").isdigit() else None
    if fields.get("version") != "STSv1" or res["mode"] not in ("enforce", "testing", "none") \
            or res["max_age"] is None or (res["mode"] != "none" and not res["mx_patterns"]):
        res.update(status="fail", reason="Policy ungültig (version/mode/max_age/mx)")
        return res
    res["mx_not_covered"] = [m for m in mx_hosts if not any(mx_matches(m, p) for p in res["mx_patterns"])]
    if res["mode"] != "none" and res["mx_not_covered"]:
        res.update(status="fail", reason="MX nicht von der Policy abgedeckt")
    elif res["mode"] == "enforce":
        res["status"] = "pass"
    else:
        res.update(status="warn", reason=f"mode={res['mode']} – Policy wird nicht erzwungen")
    return res


def check_tls_rpt(dnsc, domain):
    recs, err = _txt(dnsc, f"_smtp._tls.{domain}", "v=TLSRPTv1")
    if err in ("timeout", "servfail"):
        return {"status": "temperror", "record": None, "reason": f"DNS {err}"}
    if not recs:
        return {"status": "not_present", "record": None, "reason": ""}
    ok = len(recs) == 1 and re.search(r"\brua=(mailto:|https:)", recs[0], re.I)
    m = re.search(r"\brua=([^;]+)", recs[0], re.I)
    return {"status": "pass" if ok else "fail", "record": recs[0],
            "rua": [x.strip() for x in m.group(1).split(",")] if m else [],
            "reason": "" if ok else "Record ungültig (mehrere Records oder rua fehlt)"}


def check_dane(dnsc, mx_hosts):
    """TLSA-Records für _25._tcp.<mx>. Wirksam nur, wenn die Antwort DNSSEC-validiert ist."""
    res = {"status": "not_present", "mx": [], "reason": ""}
    for mx in mx_hosts[:MAX_MX]:
        answers, err, ad = dnsc.query_dnssec(f"_25._tcp.{mx}", "TLSA")
        res["mx"].append({"host": mx, "tlsa": answers, "validated": ad,
                          "error": err if err not in (None, "nxdomain", "nodata") else None})
    with_tlsa = [m for m in res["mx"] if m["tlsa"]]
    if not res["mx"] or not with_tlsa:
        res["reason"] = "keine MX" if not res["mx"] else ""
        return res
    if any(not m["validated"] for m in with_tlsa):
        res.update(status="fail", reason="TLSA ohne gültige DNSSEC-Signatur – unwirksam")
    elif len(with_tlsa) < len(res["mx"]):
        res.update(status="warn", reason="nicht alle MX haben TLSA-Records")
    else:
        res["status"] = "pass"
    return res


def check_bimi(dnsc, domain):
    recs, err = _txt(dnsc, f"default._bimi.{domain}", "v=BIMI1")
    if err in ("timeout", "servfail"):
        return {"status": "temperror", "record": None, "logo_url": None, "vmc_url": None, "reason": f"DNS {err}"}
    if not recs:
        return {"status": "not_present", "record": None, "logo_url": None, "vmc_url": None, "reason": ""}
    l = re.search(r"\bl=([^;\s]*)", recs[0])
    a = re.search(r"\ba=([^;\s]*)", recs[0])
    logo = l.group(1) if l else ""
    ok = len(recs) == 1 and logo.lower().startswith("https://")
    return {"status": "pass" if ok else "fail", "record": recs[0], "logo_url": logo or None,
            "vmc_url": (a.group(1) or None) if a else None,
            "reason": "" if ok else "Record ungültig (mehrere Records oder Logo-URL fehlt/kein https)"}


def check_domain_security(dnsc, domain):
    if not domain:
        return {"domain": None, "status": "not_tested", "reason": "keine From-Domain"}
    dnssec, mx_hosts = check_dnssec(dnsc, domain)
    mx_hosts = [m for m in mx_hosts if m and m != "."]
    res = {"domain": domain, "mx": mx_hosts, "dnssec": dnssec,
           "mta_sts": check_mta_sts(dnsc, domain, mx_hosts), "tls_rpt": check_tls_rpt(dnsc, domain),
           "dane": check_dane(dnsc, mx_hosts), "bimi": check_bimi(dnsc, domain)}
    # Bewertet werden DNSSEC, MTA-STS, TLS-RPT. DANE und BIMI sind reine Information (bei kleinen Firmen
    # selten vorhanden) und fließen nie in Warnung/Fehler ein.
    core = {"dnssec": dnssec["status"] == "signed", "mta_sts": res["mta_sts"]["status"] == "pass",
            "tls_rpt": res["tls_rpt"]["status"] == "pass"}
    broken = dnssec["status"] == "bogus" or any(res[k]["status"] == "fail" for k in ("mta_sts", "tls_rpt"))
    res["summary"] = {"rated": core, "rated_ok": sum(core.values()), "rated_total": len(core),
                      "status": "fail" if broken else ("pass" if all(core.values()) else "warn"),
                      "informational": {"dane": res["dane"]["status"], "bimi": res["bimi"]["status"]}}
    return res
