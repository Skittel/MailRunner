"""Rebuilding the raw message from milter callbacks and extracting summary data.

Postfix is asked for headers with their original leading space (P_HDR_LEADSPC), so
"Name:" + value reproduces the received header line exactly (needed for DKIM/ARC).
"""
import email
import email.header
import email.policy
import re


def build_raw(headers, body_chunks):
    """headers: list of (name bytes/str, value bytes), body_chunks: list of bytes (CRLF lines)."""
    out = []
    for name, value in headers:
        name_b = name.encode("ascii", "surrogateescape") if isinstance(name, str) else name
        value_b = value if isinstance(value, bytes) else value.encode("utf-8", "surrogateescape")
        value_b = value_b.replace(b"\r\n", b"\n").replace(b"\n", b"\r\n")
        out.append(name_b + b":" + value_b + b"\r\n")
    return b"".join(out) + b"\r\n" + b"".join(body_chunks)


def header_block(raw):
    for sep in (b"\r\n\r\n", b"\n\n"):
        pos = raw.find(sep)
        if pos != -1:
            return raw[:pos].decode("utf-8", errors="replace")
    return raw.decode("utf-8", errors="replace")


def _text(value):
    if value is None:
        return None
    s = re.sub(r"\s+", " ", str(value)).strip()
    try:
        return str(email.header.make_header(email.header.decode_header(s)))
    except Exception:
        return s


def attachments(raw):
    """Attachments with name, size (decoded bytes) and content type. Never the content."""
    msg = email.message_from_bytes(raw, policy=email.policy.compat32)
    found = []
    for part in msg.walk():
        if part.is_multipart():
            continue
        filename = part.get_filename()
        disposition = (part.get("Content-Disposition") or "").split(";")[0].strip().lower()
        if not filename and disposition != "attachment":
            continue
        try:
            payload = part.get_payload(decode=True) or b""
        except Exception:
            payload = b""
        found.append({
            "name": _text(filename) if filename else None,
            "size": len(payload),
            "content_type": part.get_content_type(),
            "disposition": disposition or None,
        })
    return found


def summary(raw):
    """From, To, Subject, Date, Message-ID as text (MIME words decoded)."""
    msg = email.message_from_bytes(raw, policy=email.policy.compat32)
    return {
        "header_from": _text(", ".join(str(v) for v in msg.get_all("From", []))) or None,
        "header_to": _text(", ".join(str(v) for v in msg.get_all("To", []))) or None,
        "subject": _text(msg.get("Subject")),
        "date_hdr": _text(msg.get("Date")),
        "message_id_hdr": _text(msg.get("Message-ID")),
    }


def received_header(client_name, client_ip, helo, by_host, queue_id, rcpt, tls_version, cipher, cipher_bits, when=None):
    """Received header in Postfix format. Postfix does not pass its own Received header to milters,
    but the analysis reads queue id and TLS (protocol, cipher) from the topmost Received header."""
    import time as _time
    addr = f"IPv6:{client_ip}" if ":" in (client_ip or "") else (client_ip or "unknown")
    lines = [f"Received: from {helo or 'unknown'} ({client_name or 'unknown'} [{addr}])"]
    if tls_version:
        bits = f" ({cipher_bits}/{cipher_bits} bits)" if cipher_bits else ""
        lines.append(f"\t(using {tls_version} with cipher {cipher or 'unknown'}{bits})")
        lines.append("\t(No client certificate requested)")
    lines.append(f"\tby {by_host} (Postfix) with {'ESMTPS' if tls_version else 'ESMTP'} id {queue_id}")
    stamp = _time.strftime("%a, %d %b %Y %H:%M:%S +0000 (UTC)", _time.gmtime(when))
    lines.append(f"\tfor <{rcpt}>; {stamp}")
    return ("\r\n".join(lines) + "\r\n").encode("utf-8", "replace")
