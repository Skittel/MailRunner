"""SMTP session tracker: builds one record per SMTP session from the mail log (journald).

Sources (all facility mail):
  postfix/.../smtpd     connect, TLS, rejects, lost connection, disconnect (+ queue id)
  postfix/.../cleanup   milter result at end of data (discard = kept, reject = 4xx/5xx)
  mailrunner-milter     "session helo|mail|rcpt client=[ip]:port ..." and "stored id=... queue_id=..."

Postfix lines belong to a session via the smtpd process id, milter lines via the client
address and port (Postfix: smtpd_client_port_logging = yes), cleanup lines via the queue id.

A finished session (disconnect + grace period, or idle too long) is returned as a plain dict;
assigning it to projects is done by the importer (PHP), which knows the routes.
"""
import hashlib
import re

GRACE_SECONDS = 10        # wait for late cleanup/milter lines after disconnect
IDLE_SECONDS = 3600       # sessions without any line for this long are closed as "incomplete"
MAX_TEXT = 300

CLIENT = r"(?P<name>[^\s\[\]]+)\[(?P<ip>[^\]]+)\](?::(?P<port>\d+))?"
RE_CONNECT = re.compile(rf"^connect from {CLIENT}$")
RE_DISCONNECT = re.compile(rf"^disconnect from {CLIENT}(?P<stats>(?: \S+=\S+)*)\s*$")
RE_TLS = re.compile(rf"^\S+ TLS connection established from {CLIENT}: (?P<proto>\S+) with cipher (?P<cipher>\S+)")
RE_QID_CLIENT = re.compile(rf"^(?P<qid>[0-9A-Za-z]+): client={CLIENT}")
RE_REJECT = re.compile(rf"^(?P<qid>NOQUEUE|[0-9A-Za-z]+): (?P<kind>reject|milter-reject|discard|hold): "
                       rf"(?P<stage>[A-Z-]+) from {CLIENT}: (?P<rest>.*)$")
RE_LOST = re.compile(rf"^(?:[0-9A-Za-z]+: )?(?P<what>lost connection|timeout|too many errors) after (?P<cmd>\S+)"
                     rf"(?: \([^)]*\))? from {CLIENT}")
RE_WARNING = re.compile(rf"^warning: (?P<text>.*?) from {CLIENT}")
RE_CLEANUP = re.compile(rf"^(?P<qid>[0-9A-Za-z]+): milter-(?P<act>discard|reject|hold): (?P<stage>[A-Z-]+) from {CLIENT}: (?P<rest>.*)$")
RE_MILTER_SESSION = re.compile(r"^session (?P<ev>helo|mail|rcpt) client=\[(?P<ip>[^\]]*)\]:(?P<port>\d+) (?P<rest>.*)$")
RE_STORED = re.compile(r"^stored id=(?P<sid>\S+) queue_id=(?P<qid>\S+)(?:.* ms=(?P<ms>\d+))?")
RE_KV = re.compile(r"(\w+)=<([^>]*)>|(\w+)=(\S+)")


def clean(text, limit=MAX_TEXT):
    """Printable, single line, limited length (log content comes from the remote client)."""
    text = "".join(ch if ch.isprintable() else "?" for ch in (text or ""))
    return text[:limit]


def kv(text):
    out = {}
    for m in RE_KV.finditer(text or ""):
        if m.group(1):
            out[m.group(1)] = m.group(2)
        else:
            out[m.group(3)] = m.group(4)
    return out


def split_reply(rest):
    """'550 5.1.1 <a@b>: text; from=<x> to=<y> proto=ESMTP helo=<h>' -> (reply, fields)."""
    idx = len(rest)
    for marker in ("; from=<", "; to=<", "; proto=", "; helo=<"):
        pos = rest.find(marker)
        if pos != -1:
            idx = min(idx, pos)
    return clean(rest[:idx].strip()), kv(rest[idx + 1:])


def status_of(reply):
    code = (reply or "").strip()[:1]
    return {"2": "accepted", "4": "tempfail", "5": "rejected"}.get(code, "rejected")


def iso(ts):
    import datetime
    return datetime.datetime.fromtimestamp(ts, datetime.timezone.utc).strftime("%Y-%m-%dT%H:%M:%S.%fZ")


class Session:
    def __init__(self, service, pid, ts, name, ip, port, cursor_before):
        self.service = service
        self.pid = pid
        self.started = ts
        self.last = ts
        self.ended = None
        self.cursor_before = cursor_before
        self.client = {"ip": ip, "port": int(port) if port else None, "name": name}
        self.helo = None
        self.tls = None
        self.tls_at = None
        self.end = None
        self.commands = None
        self.warnings = []
        self.transactions = []
        self.session_id = "mrs_" + hashlib.sha256(f"{service}|{pid}|{ts:.6f}|{ip}|{port}".encode()).hexdigest()[:20]

    def key(self):
        return f"{self.client['ip']}:{self.client['port']}"

    def transaction(self, mail_from=None, ts=None, new=False):
        if new or not self.transactions:
            self.transactions.append({"started_at": iso(ts or self.last), "mail_from": mail_from, "queue_id": None,
                                      "recipients": [], "result": None, "spool_ids": [],
                                      "_mail_at": ts or self.last, "_reply_at": None, "_wait_ms": None})
        t = self.transactions[-1]
        if mail_from is not None and t["mail_from"] is None:
            t["mail_from"] = mail_from
        return t

    def rcpt(self, t, addr, status, reply, by):
        for r in t["recipients"]:
            if r["rcpt"] == addr and r["status"] == "accepted" and status == "accepted":
                return
        t["recipients"].append({"rcpt": addr, "status": status, "reply": reply, "by": by})

    def ms(self, ts):
        return None if ts is None else max(0, round((ts - self.started) * 1000))

    def to_dict(self):
        transactions = []
        for t in self.transactions:
            if t["result"] is None:
                accepted = any(r["status"] == "accepted" for r in t["recipients"])
                t["result"] = {"status": "aborted" if accepted else "none", "reply": None}
            reply_ms = self.ms(t["_reply_at"])
            wait_ms = t["_wait_ms"] if reply_ms is not None else None
            t = {k: v for k, v in t.items() if not k.startswith("_")} | {"timing": {
                "tls_ms": self.ms(self.tls_at), "mail_from_ms": self.ms(t["_mail_at"]),
                "data_end_ms": None if wait_ms is None else max(0, reply_ms - wait_ms),
                "reply_ms": reply_ms, "wait_ms": wait_ms}}
            transactions.append(t)
        return {
            "session_id": self.session_id, "service": self.service,
            "started_at": iso(self.started), "ended_at": iso(self.ended or self.last),
            "duration_ms": self.ms(self.ended or self.last), "tls_ms": self.ms(self.tls_at),
            "client": self.client, "helo": self.helo, "tls": self.tls,
            "end": self.end or "incomplete", "commands": self.commands,
            "warnings": self.warnings[:20], "transactions": transactions,
        }


class Tracker:
    def __init__(self):
        self.by_pid = {}      # (service, pid) -> Session
        self.by_client = {}   # "ip:port" -> Session
        self.by_qid = {}      # queue id -> Session
        self.closing = []     # sessions waiting for the grace period
        self.last_cursor = None

    # --- input ---------------------------------------------------------------
    def feed(self, ident, pid, ts, message, cursor=None):
        """One journal entry. Returns finished sessions (list of dicts)."""
        before = self.last_cursor
        self.last_cursor = cursor
        message = (message or "").strip()
        if ident == "mailrunner-milter":
            self._milter(ts, message)
        elif ident.endswith("/cleanup"):
            self._cleanup(ts, message)
        elif ident.endswith("smtpd"):
            self._smtpd(ident.split("/", 1)[1] if "/" in ident else ident, pid, ts, message, before)
        return self.due(ts)

    def _smtpd(self, service, pid, ts, msg, cursor_before):
        m = RE_CONNECT.match(msg)
        if m:
            old = self.by_pid.pop((service, pid), None)
            if old is not None:
                self._close(old, ts, "incomplete")
            s = Session(service, pid, ts, m["name"], m["ip"], m["port"], cursor_before)
            self.by_pid[(service, pid)] = s
            self.by_client[s.key()] = s
            return
        s = self.by_pid.get((service, pid))
        if s is None:
            return
        s.last = ts
        m = RE_DISCONNECT.match(msg)
        if m:
            s.commands = m["stats"].strip() or None
            self.by_pid.pop((service, pid), None)
            self._close(s, ts, s.end or "disconnect")
            return
        m = RE_TLS.match(msg)
        if m:
            s.tls = {"protocol": m["proto"], "cipher": m["cipher"]}
            s.tls_at = ts
            return
        m = RE_QID_CLIENT.match(msg)
        if m:
            t = s.transaction(ts=ts)
            t["queue_id"] = m["qid"]
            self.by_qid[m["qid"]] = s
            return
        m = RE_REJECT.match(msg)
        if m:
            reply, f = split_reply(m["rest"])
            if f.get("helo") and not s.helo:
                s.helo = clean(f["helo"], 255)
            stage = m["stage"]
            if stage == "RCPT" and "to" in f:
                t = s.transaction(clean(f.get("from"), 320) if "from" in f else None, ts)
                if m["qid"] != "NOQUEUE":
                    t["queue_id"] = t["queue_id"] or m["qid"]
                s.rcpt(t, clean(f["to"], 320), status_of(reply), reply, "milter" if m["kind"] == "milter-reject" else "postfix")
            elif stage in ("MAIL", "DATA", "BDAT", "END-OF-MESSAGE"):
                t = s.transaction(clean(f.get("from"), 320) if "from" in f else None, ts)
                if stage == "MAIL" or not t["result"]:
                    t["result"] = {"status": status_of(reply), "reply": reply, "stage": stage}
            else:
                s.warnings.append(clean(f"{stage} {reply}", 200))
            return
        m = RE_LOST.match(msg)
        if m:
            s.end = f"{m['what']} after {m['cmd']}"
            return
        m = RE_WARNING.match(msg)
        if m:
            s.warnings.append(clean(m["text"], 200))

    def _cleanup(self, ts, msg):
        m = RE_CLEANUP.match(msg)
        if not m:
            return
        s = self.by_qid.get(m["qid"])
        if s is None:
            return
        s.last = max(s.last, ts)
        t = self._tx_by_qid(s, m["qid"])
        reply, f = split_reply(m["rest"])
        # fallback if the milter session lines are missing (e.g. older milter)
        if t["mail_from"] is None and "from" in f:
            t["mail_from"] = clean(f["from"], 320)
        if s.helo is None and f.get("helo"):
            s.helo = clean(f["helo"], 255)
        if "to" in f and not any(r["status"] == "accepted" for r in t["recipients"]):
            s.rcpt(t, clean(f["to"], 320), "accepted", "250", "postfix")
        t["_reply_at"] = ts
        if m["act"] == "discard":
            t["result"] = {"status": "accepted", "reply": "250", "stage": m["stage"]}
        else:
            t["result"] = {"status": status_of(reply), "reply": reply, "stage": m["stage"]}

    def _milter(self, ts, msg):
        m = RE_STORED.match(msg)
        if m:
            s = self.by_qid.get(m["qid"])
            if s is not None:
                t = self._tx_by_qid(s, m["qid"])
                t["spool_ids"].append(m["sid"])
                if m["ms"]:   # milter time from end of data until stored (cumulative over recipients)
                    t["_wait_ms"] = max(t["_wait_ms"] or 0, int(m["ms"]))
            return
        m = RE_MILTER_SESSION.match(msg)
        if not m:
            return
        s = self.by_client.get(f"{m['ip']}:{m['port']}")
        if s is None:
            return
        s.last = max(s.last, ts)
        f = kv(m["rest"])
        if m["ev"] == "helo":
            s.helo = clean(f.get("helo"), 255)
        elif m["ev"] == "mail":
            s.transaction(clean(f.get("from"), 320), ts, new=True)
        elif m["ev"] == "rcpt":
            t = s.transaction(ts=ts)
            status = f.get("result", "accepted")
            reply = clean(m["rest"].split("reply=", 1)[1]) if "reply=" in m["rest"] else ("250" if status == "accepted" else None)
            s.rcpt(t, clean(f.get("to"), 320), status, reply, "milter")

    @staticmethod
    def _tx_by_qid(s, qid):
        for t in reversed(s.transactions):
            if t["queue_id"] == qid:
                return t
        t = s.transaction()
        t["queue_id"] = t["queue_id"] or qid
        return t

    # --- output --------------------------------------------------------------
    def _close(self, s, ts, end):
        s.ended = ts
        s.end = end
        self.closing.append(s)

    def due(self, now):
        """Sessions whose grace period is over, plus idle open sessions."""
        out = []
        keep = []
        for s in self.closing:
            (out if now - s.ended >= GRACE_SECONDS else keep).append(s)
        self.closing = keep
        for k, s in list(self.by_pid.items()):
            if now - s.last >= IDLE_SECONDS:
                del self.by_pid[k]
                s.ended = s.last
                s.end = s.end or "incomplete"
                out.append(s)
        for s in out:
            self._forget(s)
        return [s.to_dict() for s in out]

    def _forget(self, s):
        if self.by_client.get(s.key()) is s:
            del self.by_client[s.key()]
        for t in s.transactions:
            if t["queue_id"] and self.by_qid.get(t["queue_id"]) is s:
                del self.by_qid[t["queue_id"]]

    def resume_cursor(self):
        """Cursor to restart from without losing open sessions (entry before the oldest open one)."""
        open_sessions = list(self.by_pid.values()) + self.closing
        if not open_sessions:
            return self.last_cursor
        return min(open_sessions, key=lambda s: s.started).cursor_before
