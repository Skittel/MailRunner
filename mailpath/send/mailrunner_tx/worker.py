"""Main loop of mr-sender: claim due messages, deliver per submission and recipient domain, record the result.

Concurrency: CONCURRENCY deliveries at once, PER_DOMAIN per recipient domain. Database work runs in one thread
(pymysql connections are not shared between threads). The mail is decrypted and DKIM-signed in memory only.
"""
import asyncio
import os
import time
from concurrent.futures import ThreadPoolExecutor

import dkim

from . import schedule, smtp
from .queue import utcnow

CONCURRENCY = 20
PER_DOMAIN = 3
POLL_SECONDS = 1.0
GROUP_TIMEOUT = 900
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


def decide(message, tried, now):
    """New status of a message after a delivery attempt: (status, reason, next_attempt_at)."""
    if tried is None:
        return "deferred", "no_attempt", schedule.next_attempt(message["created_at"], message["expires_at"], int(message["retry_attempts"]), int(message["attempts"]) + 1, now)
    if tried.result == "sent":
        return "sent", None, None
    if tried.result == "bounced":
        return "bounced", "rejected_" + tried.stage, None
    if tried.result == "failed":
        return "failed", "local_error", None
    nxt = schedule.next_attempt(message["created_at"], message["expires_at"], int(message["retry_attempts"]), int(message["attempts"]) + 1, now)
    if nxt is None:
        return "failed", "retries_exhausted", None
    return "deferred", "temporary_" + tried.stage, nxt


class Worker:
    def __init__(self, settings, queue, node_key, decrypt, resolver, log, deliver=smtp.deliver):
        self.s = settings
        self.queue = queue
        self.key = node_key
        self.decrypt = decrypt
        self.resolver = resolver
        self.log = log
        self.deliver = deliver
        self.db = ThreadPoolExecutor(max_workers=1, thread_name_prefix="mr-sender-db")
        self.domains = {}
        self.tasks = set()

    async def _db(self, fn, *args):
        return await asyncio.get_running_loop().run_in_executor(self.db, fn, *args)

    def wake_webhooks(self):
        if self.queue.pending_pings > 0:
            try:
                with open(self.s.webhook_request, "a"):
                    pass
                os.utime(self.s.webhook_request)
            except OSError as e:
                self.log("warning", f"cannot request webhook pings: {e}")

    async def run(self, stop):
        while not stop.is_set():
            free = CONCURRENCY - len(self.tasks)
            if free > 0:
                try:
                    claimed, _ = await self._db(self.queue.claim, free * 5)
                    self.wake_webhooks()
                except Exception as e:
                    self.log("error", f"claim failed: {e.__class__.__name__}: {e}")
                    claimed = []
                    await asyncio.sleep(5)
                for group in self.groups(claimed):
                    task = asyncio.create_task(self.handle(group))
                    self.tasks.add(task)
                    task.add_done_callback(self.tasks.discard)
            try:
                await asyncio.wait_for(stop.wait(), POLL_SECONDS)
            except asyncio.TimeoutError:
                pass
        if self.tasks:   # let running deliveries finish (their leases protect against a restart)
            await asyncio.wait(self.tasks, timeout=60)

    @staticmethod
    def groups(rows):
        out = {}
        for m in rows:
            out.setdefault((m["submission_id"], m["recipient_domain"]), []).append(m)
        return list(out.values())

    def prepare(self, m):
        """Decrypted and DKIM-signed mail of the submission (memory only)."""
        path = os.path.join(self.s.store_dir, m["raw_path"])
        try:
            with open(path, "rb") as fh:
                raw = self.decrypt(self.key, fh.read(), f"send:{m['submission_id']}:eml")
        except Exception as e:
            raise LocalError(f"mail file unreadable ({e.__class__.__name__})") from e
        k = self.queue.dkim_key(m["project_id"], m["from_domain"])
        if k is None:
            raise LocalError("DKIM key missing")
        try:
            private = self.decrypt(self.key, k["dkim_private_enc"], f"dkim:{m['project_id']}:{m['from_domain']}")
        except Exception as e:
            raise LocalError(f"DKIM key unreadable ({e.__class__.__name__})") from e
        return sign(raw, k["dkim_selector"], m["from_domain"], k["dkim_algorithm"], private)

    async def handle(self, rows):
        domain = rows[0]["recipient_domain"]
        sem = self.domains.setdefault(domain, asyncio.Semaphore(PER_DOMAIN))
        rcpts = [m["recipient"] for m in rows]
        async with sem:
            t0 = time.time()
            try:
                data = await self._db(self.prepare, rows[0])
                sources = {6: self.s.ipv6[0] if self.s.ipv6 else None, 4: self.s.ipv4[0] if self.s.ipv4 else None}
                tries = await asyncio.wait_for(self.deliver(domain, rcpts, rows[0]["envelope_from"], data, self.s.helo, sources, self.resolver), GROUP_TIMEOUT)
            except LocalError as e:
                tries = [smtp.Try("local", "failed", rcpts, None, None, str(e), started=t0)]
            except asyncio.TimeoutError:
                tries = [smtp.Try("local", "deferred", rcpts, None, None, f"delivery took longer than {GROUP_TIMEOUT} s", started=t0, duration_ms=int((time.time() - t0) * 1000))]
            except Exception as e:   # never leave a message in "sending" because of a bug
                self.log("error", f"delivery error {e.__class__.__name__}: {e}")
                tries = [smtp.Try("local", "deferred", rcpts, None, None, f"internal error ({e.__class__.__name__})", started=t0)]
            now = utcnow()
            results = []
            for m in rows:
                own = [t for t in tries if m["recipient"] in t.rcpts]
                status, reason, nxt = decide(m, own[-1] if own else None, now)
                results.append({"message": m, "tries": own, "status": status, "reason": reason, "next_at": nxt})
                last = own[-1] if own else None
                self.log("info", f"message={m['message_id']} submission={m['submission_id']} domain={domain} status={status}"
                                 + (f" code={last.code}" if last and last.code else "") + (f" mx={last.mx_host}" if last and last.mx_host else "")
                                 + (f" stage={last.stage}" if last else ""))
            try:
                await self._db(self.queue.record, results)
                self.wake_webhooks()
            except Exception as e:
                self.log("error", f"record failed for submission={rows[0]['submission_id']}: {e.__class__.__name__}: {e} (lease will expire)")
