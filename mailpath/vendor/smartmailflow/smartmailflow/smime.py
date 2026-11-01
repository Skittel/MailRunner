"""S/MIME-Signatur (RFC 8551): Integrität, Vertrauenskette, Zertifikat des Unterzeichners.

Geprüft wird mit `openssl cms -verify` in drei Schritten:
  0. -noverify -nosigs → Struktur lesbar? Liefert das Signer-Zertifikat (auch wenn die Signatur ungültig ist).
  1. -noverify         → ist die Signatur über den Inhalt gültig (unverändert)?
  2. -CAfile           → führt das Zertifikat zu einer vertrauenswürdigen Wurzel (Zweck smimesign)?
Vertrauensbasis ist der System-Zertifikatsspeicher (Mozilla-Wurzeln, gepflegt für TLS). Einzelne reine
S/MIME-CAs können darin fehlen → dann "chain_trusted": false trotz gültiger Signatur.

Verschlüsselte Mails (enveloped-data) lassen sich ohne Empfängerschlüssel nicht prüfen → status "encrypted".
"""
import datetime
import os
import subprocess
import tempfile

from cryptography import x509
from cryptography.hazmat.primitives.asymmetric import ec, ed25519, rsa
from cryptography.x509.oid import ExtendedKeyUsageOID, NameOID

SYSTEM_CAFILE = "/etc/ssl/certs/ca-certificates.crt"
SIG_TYPES = ("application/pkcs7-signature", "application/x-pkcs7-signature")
MIME_TYPES = ("application/pkcs7-mime", "application/x-pkcs7-mime")
NAMES = {NameOID.EMAIL_ADDRESS: "E"}       # lesbare Namen statt OID in subject/issuer


def find_smime(msg):
    """(art, teil, verschachtelt): art = detached | opaque | encrypted | None."""
    for depth, part in enumerate(msg.walk()):
        ctype = part.get_content_type()
        if ctype == "multipart/signed" and (part.get_param("protocol") or "").lower() in SIG_TYPES:
            return "detached", part, depth > 0
        if ctype in MIME_TYPES:
            st = (part.get_param("smime-type") or "").lower()
            if st in ("enveloped-data", "authenveloped-data"):
                return "encrypted", part, depth > 0
            if st in ("signed-data", ""):
                # ohne smime-type: nach Dateiname raten (smime.p7m ist meist signed-data oder verschlüsselt)
                return ("opaque" if st else "opaque_or_encrypted"), part, depth > 0
    return None, None, False


def _openssl(args, data, timeout=10):
    with tempfile.TemporaryDirectory() as tmp:
        src = os.path.join(tmp, "mail.eml")
        signer = os.path.join(tmp, "signer.pem")
        with open(src, "wb") as fh:
            fh.write(data)
        cmd = ["openssl", "cms", "-verify", "-in", src, "-out", os.devnull, "-signer", signer] + args
        try:
            p = subprocess.run(cmd, capture_output=True, timeout=timeout)
        except subprocess.TimeoutExpired:
            return False, "timeout", None
        pem = None
        if os.path.exists(signer):
            with open(signer, "rb") as fh:
                pem = fh.read() or None
        err = (p.stderr.decode(errors="replace").strip().splitlines() or [""])
        # letzte aussagekräftige Zeile (OpenSSL schreibt "...:Verify error: <grund>")
        reason = next((l.split("Verify error:")[-1].strip() for l in err if "Verify error:" in l), None) \
            or (err[0].split(":")[-1].strip() if p.returncode else "")
        return p.returncode == 0, reason, pem


def _name(n, oid):
    v = n.get_attributes_for_oid(oid)
    return v[0].value if v else None


def cert_info(pem, from_address):
    certs = x509.load_pem_x509_certificates(pem)
    c = certs[0]
    try:
        emails = c.extensions.get_extension_for_class(x509.SubjectAlternativeName).value.get_values_for_type(
            x509.RFC822Name)
    except x509.ExtensionNotFound:
        emails = []
    subj_email = _name(c.subject, NameOID.EMAIL_ADDRESS)
    if subj_email and subj_email not in emails:
        emails.append(subj_email)
    try:
        eku = c.extensions.get_extension_for_class(x509.ExtendedKeyUsage).value
        email_protection = ExtendedKeyUsageOID.EMAIL_PROTECTION in eku
    except x509.ExtensionNotFound:
        email_protection = None          # ohne EKU ist der Zweck nicht eingeschränkt
    key = c.public_key()
    if isinstance(key, rsa.RSAPublicKey):
        key_algo, key_bits = "rsa", key.key_size
    elif isinstance(key, ec.EllipticCurvePublicKey):
        key_algo, key_bits = f"ec-{key.curve.name}", key.key_size
    elif isinstance(key, ed25519.Ed25519PublicKey):
        key_algo, key_bits = "ed25519", 256
    else:
        key_algo, key_bits = type(key).__name__, None
    now = datetime.datetime.now(datetime.timezone.utc)
    utc = datetime.timezone.utc
    # cryptography < 42 (Ubuntu 24.04: 41.x) kennt nur die naiven UTC-Werte not_valid_before/after
    nb = getattr(c, "not_valid_before_utc", None) or c.not_valid_before.replace(tzinfo=utc)
    na = getattr(c, "not_valid_after_utc", None) or c.not_valid_after.replace(tzinfo=utc)
    return {
        "subject": c.subject.rfc4514_string(NAMES), "common_name": _name(c.subject, NameOID.COMMON_NAME),
        "emails": emails, "issuer": c.issuer.rfc4514_string(NAMES),
        "issuer_organization": _name(c.issuer, NameOID.ORGANIZATION_NAME) or _name(c.issuer, NameOID.COMMON_NAME),
        "serial": format(c.serial_number, "x"), "not_before": nb.strftime("%Y-%m-%dT%H:%M:%SZ"),
        "not_after": na.strftime("%Y-%m-%dT%H:%M:%SZ"), "expired": now > na, "not_yet_valid": now < nb,
        "days_left": (na - now).days, "self_signed": c.issuer == c.subject,
        "key_algorithm": key_algo, "key_bits": key_bits,
        "signature_hash": c.signature_hash_algorithm.name if c.signature_hash_algorithm else None,
        "email_protection": email_protection,
        "matches_from": bool(from_address) and from_address.lower() in [e.lower() for e in emails],
    }


def check_smime(raw_bytes, msg, from_address, cafile=SYSTEM_CAFILE):
    res = {"status": "not_present", "type": None, "nested": False, "micalg": None, "signature_valid": None,
           "chain_trusted": None, "trust_store": "system", "signer": None, "reasons": [], "error": None}
    kind, part, nested = find_smime(msg)
    if not kind:
        return res
    res.update(type=kind, nested=nested)
    if kind == "encrypted":
        res.update(status="encrypted", reasons=["verschlüsselt – ohne Empfängerschlüssel nicht prüfbar"])
        return res
    if kind == "detached":
        res["micalg"] = part.get_param("micalg")
    # Oberste Ebene: Rohmail unverändert prüfen. Verschachtelt (z. B. Gateway hat einen Hinweis angehängt):
    # Teil neu serialisieren – kann die Signatur verfälschen, deshalb als "nested" markiert.
    data = raw_bytes if not nested else part.as_bytes()

    _, reason, pem = _openssl(["-noverify", "-nosigs"], data)
    if not pem:
        res.update(status="permerror", error=reason or "keine Signatur/kein Zertifikat lesbar")
        if kind == "opaque_or_encrypted":
            res.update(status="encrypted", type="encrypted", error=None,
                       reasons=["verschlüsselt oder unlesbar – ohne Empfängerschlüssel nicht prüfbar"])
        return res
    res["type"] = "opaque" if kind == "opaque_or_encrypted" else kind
    try:
        res["signer"] = cert_info(pem, from_address)
    except Exception as e:
        res["error"] = f"Zertifikat nicht lesbar: {e.__class__.__name__}"
    ok, reason, _ = _openssl(["-noverify"], data)
    res["signature_valid"] = ok
    if not ok:
        res.update(status="fail", error=reason, reasons=["Inhalt nach dem Signieren verändert oder Signatur ungültig"])
        return res

    trusted, treason, _ = _openssl(["-CAfile", cafile, "-purpose", "smimesign"], data)
    res["chain_trusted"] = trusted
    s = res["signer"] or {}
    if not trusted:
        res["reasons"].append(f"Zertifikatskette nicht vertrauenswürdig ({treason})" if treason
                              else "Zertifikatskette nicht vertrauenswürdig")
    if s.get("self_signed"):
        res["reasons"].append("selbstsigniertes Zertifikat")
    if s.get("expired"):
        res["reasons"].append("Zertifikat abgelaufen")
    if s.get("not_yet_valid"):
        res["reasons"].append("Zertifikat noch nicht gültig")
    if s and not s.get("matches_from"):
        res["reasons"].append("Zertifikat passt nicht zur From-Adresse")
    if s.get("email_protection") is False:
        res["reasons"].append("Zertifikat nicht für E-Mail-Schutz ausgestellt")
    if nested:
        res["reasons"].append("Signatur steckt in einem Untereintrag (z. B. Gateway-Hinweis angehängt)")
    if s and not s.get("expired") and 0 <= s.get("days_left", 999) < 30:
        res["reasons"].append(f"Zertifikat läuft in {s['days_left']} Tagen ab")
    blocking = [r for r in res["reasons"] if not r.startswith("Zertifikat läuft in")]
    res["status"] = "pass" if trusted and not blocking else "warn"
    return res
