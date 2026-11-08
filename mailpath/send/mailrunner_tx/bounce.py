"""Bounces of the send role (decision E-27).

Return-Path of a mail whose profile has a return_path_domain:
    <mail-id>-<node-id>-<check>@<return_path_domain>      e.g. mrm_k3f9x2m7q1w8e4r6-node1-eu-7hq2m0x4k9@bounce.example
  mail-id   public message id (mrm_ + 16), never the database id
  node-id   MAILRUNNER_NODE_ID with dots as hyphens ("node1.eu" -> "node1-eu")
  check     10 characters of an HMAC-SHA256 over mail-id and node-id, with a key derived from the node key: only the
            node that sent the mail can check it, nothing has to be shared, forged bounces are ignored
All lower case; parsed from both ends (the mail-id has a fixed length, the check too).

The bounce domain's MX points to a receive node with a project for it. Every send node collects from that project
via the receive API (its own token), checks the address, reads the delivery status notification (RFC 3464) and
acknowledges only its own bounces. Messages for other nodes stay for them.
"""
import base64
import email
import email.policy
import hashlib
import hmac
import json
import re
import urllib.error
import urllib.request

CHECK_LEN = 10
MAIL_ID = re.compile(r"^mrm_[a-z0-9]{16}$")
ENHANCED = re.compile(r"\b([245])\.(\d{1,3})\.(\d{1,3})\b")


def node_label(node_id):
    return re.sub(r"[^a-z0-9-]", "-", (node_id or "").lower()).strip("-")


def bounce_key(node_key):
    return hmac.new(node_key, b"mailrunner bounce address v1", hashlib.sha256).digest()


def check_value(key, mail_id, node):
    digest = hmac.new(key, f"{mail_id}|{node}".encode(), hashlib.sha256).digest()
    return base64.b32encode(digest).decode().lower()[:CHECK_LEN]


def address(key, mail_id, node_id, domain):
    node = node_label(node_id)
    return f"{mail_id}-{node}-{check_value(key, mail_id, node)}@{domain.lower()}"


def parse(addr):
    """(mail_id, node, check) of a bounce address, or None if it does not have the form."""
    local = (addr or "").strip().strip("<>").lower().rsplit("@", 1)[0]
    if len(local) < 20 + 1 + 1 + 1 + CHECK_LEN or len(local) > 64:
        return None
    mail_id, rest = local[:20], local[20:]
    if not MAIL_ID.match(mail_id) or not rest.startswith("-") or rest[-CHECK_LEN - 1] != "-":
        return None
    node, check = rest[1:-CHECK_LEN - 1], rest[-CHECK_LEN:]
    if not node or not re.fullmatch(r"[a-z0-9-]+", node):
        return None
    return mail_id, node, check


def verify(key, addr, own_node_id):
    """mail_id if the address is a valid bounce address of this node; "other" if it belongs to another node; None."""
    p = parse(addr)
    if p is None:
        return None
    mail_id, node, check = p
    if node != node_label(own_node_id):
        return "other"
    return mail_id if hmac.compare_digest(check, check_value(key, mail_id, node)) else None


def read_dsn(raw):
    """Delivery status notification -> list of {action, status, diagnostic, recipient}; [] if the mail is no DSN."""
    try:
        msg = email.message_from_bytes(raw, policy=email.policy.compat32)
    except Exception:
        return []
    out = []
    for part in msg.walk():
        if part.get_content_type() != "message/delivery-status":
            continue
        blocks = part.get_payload()
        if isinstance(blocks, str):   # some MTAs: delivery-status as text
            blocks = [email.message_from_string(b.strip() + "\n") for b in re.split(r"\r?\n\r?\n", blocks) if b.strip()]
        for b in blocks[1:] if len(blocks) > 1 else blocks:   # first block = per-message fields
            action = (b.get("Action") or "").strip().lower()
            if not action:
                continue
            status = (b.get("Status") or "").strip()
            m = ENHANCED.search(status)
            out.append({"action": action, "status": m.group(0) if m else None,
                        "diagnostic": " ".join((b.get("Diagnostic-Code") or "").split())[:500] or None,
                        "recipient": ((b.get("Final-Recipient") or b.get("Original-Recipient") or "").split(";")[-1].strip().lower() or None)})
    return out


def failure(dsn):
    """The first permanent failure of a DSN ({status, diagnostic, code}) or None (delayed, delivered, relayed)."""
    for r in dsn:
        if r["action"] == "failed":
            status = r["status"] or "5.0.0"
            code = None
            m = re.match(r"^\s*smtp;\s*(\d{3})\b", r["diagnostic"] or "", re.I)
            if m:
                code = int(m.group(1))
            return {"status": status, "diagnostic": r["diagnostic"], "code": code or (550 if status.startswith("5") else 450)}
    return None


class Source:
    """Receive API of the bounce project (GET /v1/messages, /raw, POST /v1/messages/ack) with the node's token."""

    def __init__(self, base_url, token, timeout=20):
        self.base = base_url.rstrip("/")
        self.token = token
        self.timeout = timeout

    def _req(self, method, path, body=None):
        data = json.dumps(body).encode() if body is not None else None
        req = urllib.request.Request(self.base + path, data=data, method=method,
                                     headers={"Authorization": "Bearer " + self.token, "Content-Type": "application/json", "User-Agent": "MailRunner-bounces"})
        with urllib.request.urlopen(req, timeout=self.timeout) as resp:
            return resp.read()

    def messages(self, after_id, limit=100):
        d = json.loads(self._req("GET", f"/messages?acked=0&after_id={int(after_id)}&limit={int(limit)}"))
        return d.get("data") or [], int(d.get("next_after_id") or after_id), bool(d.get("has_more"))

    def raw(self, message_id):
        return self._req("GET", f"/messages/{message_id}/raw")

    def ack(self, message_ids):
        if message_ids:
            self._req("POST", "/messages/ack", {"message_ids": list(message_ids)})


def collect(source, key, node_id, after_id, apply, log, limit=500):
    """One collection run. apply(mail_id, failure_dict) records a bounce. Returns (new after_id, stats)."""
    stats = {"seen": 0, "bounced": 0, "ignored": 0, "other_node": 0, "acked": 0}
    cursor = after_id
    while stats["seen"] < limit:
        items, nxt, more = source.messages(cursor)
        mine = []
        for it in items:
            stats["seen"] += 1
            rcpt = (it.get("envelope") or {}).get("rcpt") or ""
            mail_id = verify(key, rcpt, node_id)
            if mail_id == "other":
                stats["other_node"] += 1
                continue
            mine.append(it["message_id"])
            if mail_id is None:
                stats["ignored"] += 1   # not a bounce address of ours, or a forged check value
                continue
            try:
                fail = failure(read_dsn(source.raw(it["message_id"])))
            except urllib.error.HTTPError as e:
                if e.code == 404:   # no raw mail (project profile "analysis"): cannot read the DSN
                    stats["ignored"] += 1
                    continue
                raise
            if fail is None:
                stats["ignored"] += 1   # delayed notice, auto-reply, anything that is not a permanent failure
                continue
            if apply(mail_id, fail):
                stats["bounced"] += 1
            else:
                stats["ignored"] += 1
        source.ack(mine)
        stats["acked"] += len(mine)
        cursor = nxt
        if not more:
            break
    return cursor, stats
