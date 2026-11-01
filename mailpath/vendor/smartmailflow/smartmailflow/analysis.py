"""Analyse einer empfangenen Mail – unabhängig vom SMTP-Empfang.

  analyze(raw_bytes, envelope, dnsc, config) -> dict

envelope = {"client_ip", "helo", "mail_from" ("" = Null-Sender), "rcpt"}
config   = {"spamhaus_dqs_key": ...} (optional, aus /etc/smartmailflow/secrets.env)
dnsc     = DNSClient (echt) oder FakeDNS (Tests)

Alle Bewertungen (SPF, DKIM, DMARC, ARC, S/MIME, Return-Path, FCrDNS, HELO, DNSBL, Domain-Sicherheit) werden hier
selbst berechnet. Die Ergebnisse von policyd-spf/OpenDKIM/OpenDMARC stehen nur zur Gegenprobe in
"mta_checks" und werden ausschließlich aus eigenen Headern gelesen (oberhalb des eigenen Received-Headers).
Aufbau des Ergebnisses: docs/DATABASE.md (ab Version 2.0.0).
"""
import email
import email.header
import re
import time
from email.utils import getaddresses, parseaddr

from . import __version__, auth, dnsanalysis, domainsec, net, smime

OWN_AUTHSERV = "mx-de-01.smartmailflow.email"


def trusted_headers(msg):
    """Header, die dieser Server selbst vorangestellt hat: alles oberhalb des ersten Received-Headers."""
    out = []
    for name, value in msg.items():
        if name.lower() == "received":
            break
        out.append((name.lower(), re.sub(r"\s+", " ", str(value)).strip()))
    return out


def mta_checks(own_hdrs):
    """Ergebnisse der Milter/Policy (nur eigene Header) – Gegenprobe zur eigenen Prüfung."""
    res = {"spf": None, "spf_header": None, "dkim": None, "dmarc": None}
    for name, line in own_hdrs:
        if name == "received-spf" and res["spf"] is None:
            m = re.match(r"\s*(pass|fail|softfail|none|neutral|temperror|permerror)\b", line, re.I)
            res["spf"] = m.group(1).lower() if m else None
            res["spf_header"] = line
        elif name == "authentication-results" and line.lower().startswith(OWN_AUTHSERV):
            for method in ("dkim", "dmarc"):
                m = re.search(rf"\b{method}=(\w+)", line)
                if m and res[method] is None:
                    res[method] = m.group(1).lower()
    return res


def header_text(value):
    """Header-Wert zu einer Zeile zusammenfügen (RFC 5322 Unfolding) und MIME-Wörter dekodieren."""
    if value is None:
        return None
    s = re.sub(r"\s+", " ", str(value)).strip()
    try:
        return str(email.header.make_header(email.header.decode_header(s)))
    except Exception:
        return s


def addresses(values):
    """Adressliste aus From/To: Adresse + Anzeigename. Ein Anzeigename, der nur die Adresse selbst
    (ggf. in Anführungszeichen, wie Outlook es schreibt) enthält, ist kein Fehler und wird markiert."""
    out = []
    for name, addr in getaddresses([header_text(v) or "" for v in values]):
        if not addr:
            continue
        clean = name.strip().strip("'\"").strip()
        out.append({"address": addr, "display_name": name or None,
                    "display_name_is_address": bool(clean) and clean.lower() == addr.lower()})
    return out


def arc_differences(arc, own):
    """Was frühere Stationen (ARC-AAR) gesehen haben vs. eigenes Ergebnis – reine Information, nie Warnung:
    Ein Smarthost/Gateway prüft die Einlieferung vom eigenen Mailserver des Absenders (oft ohne DKIM,
    dynamische IP) und signiert danach selbst. Abweichungen sind dann normal."""
    diffs = []
    for inst in arc.get("instances") or []:
        aar = inst.get("aar") or {}
        for check in ("spf", "dkim", "dmarc"):
            seen = aar.get(check)
            if seen and seen != own.get(check):
                diffs.append({"i": inst.get("i"), "station": aar.get("authserv_id") or inst.get("seal_domain"),
                              "check": check, "station_result": seen, "own_result": own.get(check)})
    arc["aar_differences"] = diffs
    arc["aar_note"] = ("Information: Eine frühere Station hat andere Ergebnisse festgehalten. Das ist bei "
                       "Smarthosts und Mail-Gateways normal – sie prüfen die Einlieferung vom eigenen Mailserver "
                       "des Absenders und signieren die Mail erst danach. Kein Fehler.") if diffs else None


def analyze(raw_bytes, envelope, dnsc, config=None):
    t0 = time.monotonic()
    config = config or {}
    msg = email.message_from_bytes(raw_bytes)
    client_ip = envelope.get("client_ip") or "0.0.0.0"
    helo = (envelope.get("helo") or "unknown").lower()
    mail_from = envelope.get("mail_from") or ""
    rcpt = envelope.get("rcpt") or ""

    # eigener Received-Header: Queue-ID und TLS
    queue_id = tls_proto = tls_cipher = ""
    received = msg.get_all("Received") or []
    if received:
        rec = str(received[0])
        if m := re.search(r"\bid\s+([A-Za-z0-9]{10,16})\b", rec):
            queue_id = m.group(1)
        if t := re.search(r"\bTLSv\s*([0-9.]+).*?\bcipher\s+([A-Za-z0-9_\-]+)", rec, re.I | re.S):
            tls_proto, tls_cipher = "TLSv" + t.group(1), t.group(2)

    own = trusted_headers(msg)
    hdr_from = addresses(msg.get_all("From", []))
    hdr_to = addresses(msg.get_all("To", []))
    from_domains = [auth.domain_of(a["address"]) for a in hdr_from if "@" in a["address"]]
    from_domain = from_domains[0] if len(set(from_domains)) == 1 else None
    _, return_path = parseaddr(msg.get("Return-Path") or "")

    spf_res = auth.check_spf(dnsc, client_ip, mail_from, helo)
    adkim = "r"
    if from_domain:
        adkim = auth.dmarc_tags(dnsc, from_domain)[0].get("adkim", "r")
    dkim_status, dkim_detail, dkim_sigs = auth.check_dkim(dnsc, raw_bytes, msg, from_domain, adkim)
    dmarc_res = auth.check_dmarc(dnsc, from_domains, spf_res, dkim_sigs)
    spf_al, dkim_al = auth.rate_alignment(spf_res, dkim_status, dkim_sigs, from_domain,
                                          dmarc_res.get("aspf"), dmarc_res.get("adkim"))
    spf_res.update(spf_al)
    mta = mta_checks(own)
    mta["mismatch"] = [k for k, v in (("spf", spf_res["result"]), ("dkim", dkim_status),
                                      ("dmarc", dmarc_res["result"])) if mta[k] is not None and mta[k] != v]

    rcpt_domain = auth.domain_of(rcpt) if "@" in rcpt else ""
    subject = header_text(msg.get("Subject"))
    debug = {
        "software_version": __version__,
        "checked_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        "duration_ms": None,
        "queue_id": queue_id,
        "smtp": {"client_ip": client_ip, "ip_version": net.ip_version(client_ip), "helo": helo,
                 "mail_from": mail_from, "rcpt_to": rcpt, "tls": net.check_tls(tls_proto, tls_cipher)},
        "header": {"from": hdr_from, "to": hdr_to, "subject": subject, "date": header_text(msg.get("Date")),
                   "message_id": header_text(msg.get("Message-ID")), "return_path": return_path or None},
        "spf": spf_res,
        "dkim": {"result": dkim_status, "detail": dkim_detail, **dkim_al, "signatures": dkim_sigs},
        "dmarc": dmarc_res,
        "arc": auth.check_arc(dnsc, raw_bytes, msg),
        "return_path_dns": net.check_envelope_dns(dnsc, auth.domain_of(mail_from) if mail_from else ""),
        "fcrdns": net.check_fcrdns(dnsc, client_ip),
        "helo": net.check_helo(dnsc, helo, client_ip),
        "ip_protocol": net.check_protocol(client_ip, rcpt_domain),
        "dnsbl": net.check_dnsbl(dnsc, client_ip, net.dnsbl_lists(config.get("spamhaus_dqs_key"))),
        "domain_security": domainsec.check_domain_security(dnsc, from_domain),
        "dns_analysis": dnsanalysis.analyze_domain(dnsc, from_domain, dkim_sigs),
        "smime": smime.check_smime(raw_bytes, msg, hdr_from[0]["address"] if len(hdr_from) == 1 else None,
                                   config.get("smime_cafile") or smime.SYSTEM_CAFILE),
        "mta_checks": mta,
    }
    arc_differences(debug["arc"], {"spf": spf_res["result"], "dkim": dkim_status, "dmarc": dmarc_res["result"]})
    debug["duration_ms"] = round((time.monotonic() - t0) * 1000)

    return {
        "queue_id": queue_id,
        "smtp_mail_from": mail_from,
        "smtp_rcpt_to": rcpt,
        "header_from": header_text(", ".join(str(v) for v in msg.get_all("From", []))) or None,
        "header_to": header_text(", ".join(str(v) for v in msg.get_all("To", []))) or None,
        "subject": subject,
        "date_hdr": header_text(msg.get("Date")),
        "spf_status": spf_res["result"],
        "dkim_status": dkim_status,
        "debug": debug,
    }
