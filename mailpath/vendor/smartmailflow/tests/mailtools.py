"""Gemeinsame Hilfen für Tests: Testschlüssel laden, Mails bauen und DKIM-signieren."""
import base64
import email.utils
import os
from email.message import EmailMessage

import dkim
from cryptography.hazmat.primitives import serialization

KEYS = os.path.join(os.path.dirname(os.path.abspath(__file__)), "keys")


def _load(keyfile):
    with open(os.path.join(KEYS, keyfile), "rb") as fh:
        return serialization.load_pem_private_key(fh.read(), password=None)


def private_key(keyfile):
    """(Schlüssel im dkimpy-Format, Signaturalgorithmus)."""
    key = _load(keyfile)
    if keyfile.startswith("ed25519"):   # dkimpy erwartet Base64 des 32-Byte-Seeds
        raw = key.private_bytes(serialization.Encoding.Raw, serialization.PrivateFormat.Raw,
                                serialization.NoEncryption())
        return base64.b64encode(raw), b"ed25519-sha256"
    pem = key.private_bytes(serialization.Encoding.PEM, serialization.PrivateFormat.TraditionalOpenSSL,
                            serialization.NoEncryption())
    return pem, b"rsa-sha256"


def dkim_record(keyfile, extra=""):
    """DKIM-TXT-Record ("v=DKIM1; k=...; p=...") zum Testschlüssel."""
    pub = _load(keyfile).public_key()
    if keyfile.startswith("ed25519"):
        raw = pub.public_bytes(serialization.Encoding.Raw, serialization.PublicFormat.Raw)
        return f"v=DKIM1; k=ed25519;{extra} p={base64.b64encode(raw).decode()}"
    der = pub.public_bytes(serialization.Encoding.DER, serialization.PublicFormat.SubjectPublicKeyInfo)
    return f"v=DKIM1; k=rsa;{extra} p={base64.b64encode(der).decode()}"


def build(hdr_from, to, subject="Test", body="Diese Mail wurde von einem Test erzeugt.\n",
          extra_hdrs=(), domain="example.test"):
    m = EmailMessage()
    m["From"] = hdr_from
    m["To"] = to
    m["Subject"] = subject
    m["Date"] = email.utils.formatdate()
    m["Message-ID"] = email.utils.make_msgid(domain=domain)
    for k, v in extra_hdrs:
        m[k] = v
    m.set_content(body)
    return m.as_bytes().replace(b"\r\n", b"\n").replace(b"\n", b"\r\n")


def sign(raw, selector, keyfile, domain):
    key, algo = private_key(keyfile)
    sig = dkim.sign(raw, selector.encode(), domain.encode(), key, signature_algorithm=algo,
                    include_headers=[b"from", b"to", b"subject", b"date", b"message-id"])
    return sig + raw


def arc_seal(raw, selector, keyfile, domain, srv_id, results="spf=pass smtp.mailfrom=example.test; dkim=pass"):
    """Wie ein Weiterleiter: Authentication-Results voranstellen und ein ARC-Set (AAR/AMS/AS) anbringen."""
    raw = f"Authentication-Results: {srv_id}; {results}\r\n".encode() + raw
    key, algo = private_key(keyfile)
    hdrs = dkim.arc_sign(raw, selector.encode(), domain.encode(), key, srv_id.encode(), signature_algorithm=algo,
                         include_headers=[b"from", b"to", b"subject", b"date", b"message-id"])
    return b"".join(hdrs) + raw


def smime_sign(raw, signer="smime-signer", detached=True, extra_certs="smime-ca.pem"):
    """Mail per openssl cms S/MIME-signieren (wie ein Gateway). raw = komplette Mail (Header + Body)."""
    import subprocess
    import tempfile
    head, _, body = raw.partition(b"\r\n\r\n")
    hdrs = [l for l in head.split(b"\r\n") if not l.lower().startswith((b"content-type", b"content-transfer",
                                                                          b"mime-version"))]
    ctype = [l for l in head.split(b"\r\n") if l.lower().startswith(b"content-type")] or [b"Content-Type: text/plain"]
    with tempfile.NamedTemporaryFile() as fh:
        fh.write(ctype[0] + b"\r\n\r\n" + body)
        fh.flush()
        cmd = ["openssl", "cms", "-sign", "-in", fh.name, "-signer", os.path.join(KEYS, f"{signer}.pem"),
               "-inkey", os.path.join(KEYS, f"{signer}.key"), "-crlfeol"]
        if extra_certs:
            cmd += ["-certfile", os.path.join(KEYS, extra_certs)]
        if not detached:
            cmd.append("-nodetach")
        out = subprocess.run(cmd, capture_output=True, check=True).stdout
    return b"\r\n".join(hdrs) + b"\r\n" + out.replace(b"\r\n", b"\n").replace(b"\n", b"\r\n")
