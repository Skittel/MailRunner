"""SPF (RFC 7208), DKIM (RFC 6376/8301/8463), DMARC (RFC 7489) und ARC (RFC 8617) – eigene Prüfung über DNSClient."""
import base64
import re

import dkim
import dns.name
import spf
from cryptography.hazmat.primitives.asymmetric import ed25519, rsa
from cryptography.hazmat.primitives.serialization import load_der_public_key

PSL_FILE = "/usr/share/publicsuffix/public_suffix_list.dat"


def domain_of(addr):
    return addr.rsplit("@", 1)[1].lower().rstrip(".") if "@" in addr else addr.lower().rstrip(".")


# --------------------------------------------------------------------------- SPF
def check_spf(dnsc, client_ip, mail_from, helo):
    """Eigene SPF-Prüfung mit pyspf; DNS läuft über dnsc. Bei Null-Sender wird die HELO-Identität geprüft."""
    def lookup(name, qtype, tcpfallback=True, timeout=30):
        answers, err = dnsc.query(name, qtype)
        if err in ("timeout", "servfail") or (err or "").startswith("error:"):
            raise spf.TempError(f"DNS {err} {name}/{qtype}")
        out = []
        for a in answers:
            if qtype == "MX":
                pref, host = a.split(None, 1)
                out.append(((name, qtype), (int(pref), dns.name.from_text(host))))
            elif qtype in ("TXT", "SPF"):
                out.append(((name, qtype), [a.encode()]))
            elif qtype == "PTR":
                out.append(((name, qtype), a.rstrip(".")))
            else:
                out.append(((name, qtype), a))
        return out

    saved = spf.DNSLookup
    spf.DNSLookup = lookup
    q = None
    try:
        q = spf.query(i=client_ip, s=mail_from or "", h=helo or "unknown", querytime=20)
        result, _code, expl = q.check()
    except Exception as e:
        result, expl = "temperror", str(e)
    finally:
        spf.DNSLookup = saved
    identity = "mailfrom" if mail_from else "helo"
    domain = domain_of(mail_from) if mail_from else (helo or "").lower()
    txt, _ = dnsc.query(domain, "TXT") if domain else ([], None)
    records = [t for t in txt if t.lower().startswith("v=spf1")]
    # Entscheidender Mechanismus: bei none/permerror/temperror gibt es keinen
    mechanism = getattr(q, "mechanism", None) if result in ("pass", "fail", "softfail", "neutral") else None
    return {"result": result, "identity": identity, "domain": domain, "explanation": expl,
            "record": records[0] if len(records) == 1 else (records or None),
            "mechanism": mechanism or (("default" if result == "neutral" else None) if records else None),
            "dns_lookups": getattr(q, "lookups", None), "dns_lookup_limit": 10}


# --------------------------------------------------------------------------- DKIM
def dkim_tags(txt):
    tags = {}
    for part in txt.split(";"):
        if "=" in part:
            k, v = part.split("=", 1)
            tags[k.strip().lower()] = re.sub(r"\s+", "", v)
    return tags


def dkim_key_info(txt):
    """Algorithmus und echte Schlüssellänge aus einem DKIM-DNS-Record."""
    tags = dkim_tags(txt)
    algo = tags.get("k", "rsa").lower()
    # Bewertung (E-015): RSA ab 1024 Bit gilt als ok – viele Anbieter (z. B. busymouse24, Postal) stellen nur
    # 1024 Bit aus. Unter 2048 Bit gibt es eine Empfehlung, aber keine Warnung.
    info = {"algorithm": algo, "key_bits": None, "key_status": "ok", "key_strength": "n/a",
            "recommended_bits": 2048 if algo == "rsa" else None, "recommendation": None}
    p = tags.get("p")
    if p is None:
        info["key_status"] = "invalid"
        return info
    if p == "":
        info["key_status"] = "revoked"
        return info
    try:
        der = base64.b64decode(p, validate=True)
        if algo == "ed25519":
            ed25519.Ed25519PublicKey.from_public_bytes(der)
            info.update(key_bits=256, key_strength="ok")
        else:
            key = load_der_public_key(der)
            if not isinstance(key, rsa.RSAPublicKey):
                info["key_status"] = "unsupported"
                return info
            bits = key.key_size
            info.update(key_bits=bits, key_strength="ok" if bits >= 1024 else "weak",
                        recommendation=None if bits >= 2048 else "Ein Schlüssel mit 2048 Bit ist empfohlen, sofern der Anbieter das unterstützt.")
    except Exception:
        info["key_status"] = "invalid"
    return info


def check_dkim(dnsc, raw_bytes, msg, from_domain=None, adkim="r"):
    """Jede DKIM-Signatur einzeln. Gesamtstatus: pass > fail > temperror > permerror; none ohne Signatur.
    aligned = Signaturdomain passt (relaxed) zur From-Domain; DMARC wertet mit dem echten adkim nach."""
    def dnsfunc(name, timeout=5):
        n = name.decode() if isinstance(name, bytes) else name
        answers, _ = dnsc.query(n, "TXT")
        return answers[0].encode() if answers else b""

    sigs = []
    for idx, hdr in enumerate(msg.get_all("DKIM-Signature", [])):
        compact = str(hdr).replace("\r", "").replace("\n", "").strip()
        tags = dkim_tags(compact)
        sel, dom = tags.get("s", ""), tags.get("d", "").lower()
        sig = {"domain": dom, "selector": sel, "signing_algorithm": tags.get("a", ""),
               "canonicalization": tags.get("c", "simple/simple"),
               "signed_headers": [h.strip().lower() for h in tags.get("h", "").split(":") if h.strip()],
               "body_length_limit": int(tags["l"]) if tags.get("l", "").isdigit() else None,
               "dns_name": f"{sel}._domainkey.{dom}" if sel and dom else None, "dns_txt": None,
               "key_algorithm": None, "key_bits": None, "key_status": None, "key_strength": None,
               "recommended_bits": None, "recommendation": None, "testing": False, "body_hash_ok": None,
               "aligned_with_from": bool(from_domain and dom and _aligned(dom, from_domain, adkim)),
               "result": "permerror", "reason": "", "header": compact}
        if not (sel and dom):
            sig["reason"] = "selector/domain missing"
        else:
            answers, err = dnsc.query(f"{sel}._domainkey.{dom}".lower().strip("."), "TXT")
            if err:
                sig["result"] = "temperror" if err in ("timeout", "servfail") else "permerror"
                sig["reason"] = f"key {err}"
            else:
                sig["dns_txt"] = answers[0]
                ki = dkim_key_info(answers[0])
                sig.update(key_algorithm=ki["algorithm"], key_bits=ki["key_bits"], key_status=ki["key_status"],
                           key_strength=ki["key_strength"], recommended_bits=ki["recommended_bits"],
                           recommendation=ki["recommendation"],
                           testing="y" in dkim_tags(answers[0]).get("t", "").split(":"))
                if ki["key_status"] == "revoked":
                    sig.update(result="fail", reason="key revoked (p= empty)")
                elif ki["key_status"] != "ok":
                    sig.update(result="permerror", reason=f"key {ki['key_status']}")
                else:
                    try:
                        ok = dkim.DKIM(raw_bytes).verify(idx=idx, dnsfunc=dnsfunc)
                        sig.update(result="pass" if ok else "fail", body_hash_ok=True,
                                   reason="signature verified" if ok else "header signature invalid")
                    except dkim.DKIMException as e:
                        # dkimpy meldet einen abweichenden Body-Hash als Exception – fachlich "fail"
                        bh = "body hash mismatch" in str(e)
                        sig.update(result="fail" if bh else "permerror", reason=str(e),
                                   body_hash_ok=False if bh else None)
                    except Exception as e:
                        sig.update(result="temperror", reason=str(e))
        sigs.append(sig)

    status, detail = "none", "no signature"
    if sigs:
        results = [s["result"] for s in sigs]
        status = next(c for c in ("pass", "fail", "temperror", "permerror") if c in results)
        detail = next(s["reason"] for s in sigs if s["result"] == status)
    return status, detail, sigs


# --------------------------------------------------------------------------- DMARC
_psl = None


def _load_psl():
    global _psl
    if _psl is None:
        rules, exc, wild = set(), set(), set()
        try:
            with open(PSL_FILE, encoding="utf-8") as fh:
                for line in fh:
                    line = line.strip()
                    if not line or line.startswith("//"):
                        continue
                    rule = line.split()[0].lower()
                    if rule.startswith("!"):
                        exc.add(rule[1:])
                    elif rule.startswith("*."):
                        wild.add(rule[2:])
                    else:
                        rules.add(rule)
        except OSError:
            pass
        _psl = (rules, exc, wild)
    return _psl


def org_domain(dom):
    """Organisationsdomain nach Public-Suffix-List (Default-Regel "*": Suffix = TLD)."""
    rules, exc, wild = _load_psl()
    labels = dom.lower().rstrip(".").split(".")
    suffix_len = 1
    for i in range(len(labels)):
        cand = ".".join(labels[i:])
        if cand in exc:
            suffix_len = len(labels) - i - 1
            break
        if cand in rules:
            suffix_len = len(labels) - i
            break
        if i + 1 < len(labels) and ".".join(labels[i + 1:]) in wild:
            suffix_len = len(labels) - i
            break
    if len(labels) <= suffix_len:
        return dom.lower().rstrip(".")
    return ".".join(labels[-(suffix_len + 1):])


def _dmarc_record(dnsc, name):
    answers, err = dnsc.query(f"_dmarc.{name}", "TXT")
    if err in ("timeout", "servfail") or (err or "").startswith("error:"):
        return None, err
    recs = [a for a in answers if a.strip().lower().startswith("v=dmarc1")]
    return (recs[0] if len(recs) == 1 else None), None


def rate_alignment(spf_res, dkim_status, dkim_sigs, from_domain, aspf="r", adkim="r"):
    """Gesamtbewertung je Verfahren (ab 2.4.0): aligned = Domain passt zur From-Domain (unabhängig vom Ergebnis),
    rating = ok (bestanden + ausgerichtet) | warning (bestanden, aber nicht ausgerichtet; softfail/neutral;
    vorübergehender DNS-Fehler; DKIM-Schlüssel unter 1024 Bit) | error (fail/permerror/none).
    Ohne eindeutige From-Domain ist aligned None und zählt nicht gegen das Ergebnis."""
    aspf, adkim = aspf or "r", adkim or "r"
    spf_aligned = (_aligned(spf_res["domain"], from_domain, aspf)
                   if from_domain and spf_res.get("domain") else None)
    r = spf_res["result"]
    if r == "pass":
        spf_rating = "warning" if spf_aligned is False else "ok"
    elif r in ("softfail", "neutral", "temperror"):
        spf_rating = "warning"
    else:
        spf_rating = "error"

    passing = [s for s in dkim_sigs if s.get("result") == "pass"]
    dkim_aligned = (any(s.get("aligned_with_from") for s in (passing or dkim_sigs)) if from_domain and dkim_sigs
                    else None)
    if dkim_status == "pass":
        ok_sigs = [s for s in passing if s.get("aligned_with_from") or not from_domain]
        if not ok_sigs:
            dkim_rating = "warning"
        elif all(s.get("key_strength") == "weak" for s in ok_sigs):
            dkim_rating = "warning"
        else:
            dkim_rating = "ok"
    elif dkim_status == "temperror":
        dkim_rating = "warning"
    else:
        dkim_rating = "error"
    return ({"aligned": spf_aligned, "alignment_mode": aspf, "rating": spf_rating},
            {"aligned": dkim_aligned, "alignment_mode": adkim, "rating": dkim_rating})


def _aligned(a, b, mode):
    a, b = a.lower().rstrip("."), b.lower().rstrip(".")
    return a == b if mode == "s" else org_domain(a) == org_domain(b)


def dmarc_tags(dnsc, from_domain):
    """DMARC-Record der From-Domain bzw. Organisationsdomain: (tags, record, quelle, fehler)."""
    rec, err = _dmarc_record(dnsc, from_domain)
    source = from_domain
    org = org_domain(from_domain)
    if not rec and not err and org != from_domain:
        rec, err = _dmarc_record(dnsc, org)
        source = org
    return (dkim_tags(rec) if rec else {}), rec, source, err


def check_dmarc(dnsc, header_from_domains, spf_res, dkim_sigs):
    """result = DMARC-Ergebnis. published_policy/subdomain_policy = veröffentlichte Werte (p/sp),
    effective_policy = für diese From-Domain geltende Policy, disposition = was ein Empfänger nach DMARC
    mit dieser Mail tun soll (none bei pass). Ein Empfänger kann aus anderen Gründen anders entscheiden."""
    res = {"result": "none", "from_domain": None, "org_domain": None, "record": None, "record_source": None,
           "published_policy": None, "subdomain_policy": None, "effective_policy": None, "pct": None,
           "adkim": None, "aspf": None, "disposition": "none", "spf_aligned": False, "dkim_aligned": False,
           "reason": ""}
    if len(set(header_from_domains)) != 1 or not header_from_domains[0]:
        res.update(result="permerror", reason="From-Header fehlt oder mehrdeutig")
        return res
    frm = header_from_domains[0]
    org = org_domain(frm)
    res.update(from_domain=frm, org_domain=org)

    tags, rec, source, err = dmarc_tags(dnsc, frm)
    if err:
        res.update(result="temperror", reason=f"DNS {err}")
        return res
    if not rec:
        res["reason"] = "kein DMARC-Record"
        return res
    adkim, aspf = tags.get("adkim", "r"), tags.get("aspf", "r")
    pct = int(tags["pct"]) if tags.get("pct", "").isdigit() else 100
    effective = tags.get("sp", tags.get("p")) if source != frm else tags.get("p")
    res.update(record=rec, record_source=source, published_policy=tags.get("p"), subdomain_policy=tags.get("sp"),
               effective_policy=effective, pct=pct, adkim=adkim, aspf=aspf)

    res["spf_aligned"] = spf_res["result"] == "pass" and _aligned(spf_res["domain"], frm, aspf)
    res["dkim_aligned"] = any(s["result"] == "pass" and s["domain"] and _aligned(s["domain"], frm, adkim)
                              for s in dkim_sigs)
    res["result"] = "pass" if res["spf_aligned"] or res["dkim_aligned"] else "fail"
    if res["result"] == "fail" and effective in ("quarantine", "reject"):
        # pct < 100: Policy gilt nur für diesen Anteil, der Rest wird eine Stufe milder behandelt
        res["disposition"] = effective if pct >= 100 else f"{effective} ({pct}%)"
    return res


# --------------------------------------------------------------------------- ARC
def _arc_aar(msg, instance):
    """Ergebnisse aus ARC-Authentication-Results i=<instance> (was der Weiterleiter gesehen hat)."""
    for hdr in msg.get_all("ARC-Authentication-Results", []):
        line = re.sub(r"\s+", " ", str(hdr))
        if re.match(rf"\s*i\s*=\s*{instance}\s*;", line):
            out = {"authserv_id": (line.split(";")[1].strip().split() or [None])[0]}
            for method in ("spf", "dkim", "dmarc"):
                m = re.search(rf"\b{method}=(\w+)", line)
                out[method] = m.group(1).lower() if m else None
            return out
    return None


def check_arc(dnsc, raw_bytes, msg):
    """ARC-Kette prüfen (RFC 8617): Vollständigkeit, Kryptografie von AMS/AS je Instanz, cv-Werte.
    Ob ein Sealer vertrauenswürdig ist, kann ein Testdienst nicht wissen → sealer_trust = "unknown".
    Das ARC-Ergebnis ändert nie das eigene DMARC-Ergebnis."""
    res = {"status": "not_present", "chain_validation": "none", "sealer_trust": "unknown", "instances": [],
           "reason": ""}
    if not any(k.lower() in ("arc-seal", "arc-message-signature", "arc-authentication-results")
               for k in msg.keys()):
        return res

    temp = []

    def dnsfunc(name, timeout=5):
        n = name.decode() if isinstance(name, bytes) else name
        answers, err = dnsc.query(n, "TXT")
        if err in ("timeout", "servfail"):
            temp.append(n)
        return answers[0].encode() if answers else b""

    try:
        cv, results, comment = dkim.arc_verify(raw_bytes, dnsfunc=dnsfunc)
    except Exception as e:
        res.update(status="permerror", chain_validation="fail", reason=str(e))
        return res
    dec = lambda v: v.decode() if isinstance(v, bytes) else v
    for r in sorted(results, key=lambda x: x.get("instance", 0)):
        i = r.get("instance")
        res["instances"].append({
            "i": i, "seal_domain": dec(r.get("as-domain")), "seal_selector": dec(r.get("as-selector")),
            "ams_domain": dec(r.get("ams-domain")), "ams_selector": dec(r.get("ams-selector")),
            "ams_verified": r.get("ams-valid"), "as_verified": r.get("as-valid"),
            "cv": dec(r.get("cv")), "aar": _arc_aar(msg, i)})
    cv = dec(cv)
    res["chain_validation"] = cv or "fail"
    res["reason"] = comment if cv != "pass" else ""
    if cv == "pass":
        res["status"] = "pass"
    elif temp:
        res["status"] = "temperror"
    else:
        res["status"] = "fail"
    return res
