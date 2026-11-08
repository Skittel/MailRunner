"""DKIM signing of outgoing mail (rsa-sha256 / ed25519-sha256) with the domain key, in memory only."""
import dkim

SIGN_HEADERS = [b"from", b"sender", b"reply-to", b"subject", b"date", b"message-id", b"to", b"cc", b"mime-version",
                b"content-type", b"content-transfer-encoding", b"in-reply-to", b"references", b"list-unsubscribe",
                b"list-unsubscribe-post"]


class LocalError(Exception):
    """The mail cannot be prepared on this node (file or key missing or damaged): no SMTP attempt."""


def sign(raw, selector, domain, algorithm, private_key):
    present = {line.split(b":", 1)[0].strip().lower() for line in raw.split(b"\r\n\r\n", 1)[0].split(b"\r\n") if b":" in line and line[:1] not in (b" ", b"\t")}
    headers = [h for h in SIGN_HEADERS if h in present]
    try:
        sig = dkim.sign(raw, selector.encode(), domain.encode(), private_key, include_headers=headers,
                        signature_algorithm=b"ed25519-sha256" if algorithm == "ed25519" else b"rsa-sha256")
    except Exception as e:
        raise LocalError(f"DKIM signing failed ({e.__class__.__name__})") from e
    return sig + raw
