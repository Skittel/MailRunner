"""Delivery worker mr-sender (E-27 step 4). Run: python3 -I -m unittest discover -s mailpath/tests"""
import asyncio
import base64
import datetime as dt
import os
import sys
import unittest

import nacl.bindings

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, "..", ".."))
sys.path.insert(0, os.path.join(ROOT, "mailpath", "send"))
sys.path.insert(0, os.path.join(ROOT, "mailpath", "receive"))

import dkim  # noqa: E402

from mailrunner_tx import queue, schedule, settings, smtp, worker  # noqa: E402

MAIL = b"From: Shop <info@shop.example>\r\nTo: a@example.net\r\nSubject: Test\r\nMessage-ID: <1@shop.example>\r\n\r\nHallo\r\n"


class FakeSmtp:
    """Minimal SMTP server: answers per recipient local part (bad* 550, later* 451), DATA as configured."""

    def __init__(self, greeting="220 fake ESMTP", data_reply="250 2.0.0 Ok: queued as X1", mail_reply="250 2.1.0 Ok"):
        self.greeting, self.data_reply, self.mail_reply = greeting, data_reply, mail_reply
        self.received = []
        self.server = None

    async def start(self):
        self.server = await asyncio.start_server(self.handle, "127.0.0.1", 0)
        return self.server.sockets[0].getsockname()[1]

    async def handle(self, reader, writer):
        def send(line):
            writer.write(line.encode() + b"\r\n")
        send(self.greeting)
        await writer.drain()
        if not self.greeting.startswith("220"):
            writer.close()
            return
        rcpts = []
        while line := await reader.readline():
            cmd = line.decode().strip()
            up = cmd.upper()
            if up.startswith(("EHLO", "HELO")):
                send("250-fake.example")
                send("250-SIZE 10000000")
                send("250 8BITMIME")
            elif up.startswith("MAIL FROM"):
                send(self.mail_reply)
            elif up.startswith("RCPT TO"):
                addr = cmd.split(":", 1)[1].strip().strip("<>")
                if addr.startswith("bad"):
                    send("550 5.1.1 <%s>: Recipient address rejected: User unknown" % addr)
                elif addr.startswith("later"):
                    send("451 4.2.0 Greylisted, try again")
                else:
                    rcpts.append(addr)
                    send("250 2.1.5 Ok")
            elif up == "DATA":
                send("354 End data with <CR><LF>.<CR><LF>")
                await writer.drain()
                body = b""
                while not body.endswith(b"\r\n.\r\n"):
                    chunk = await reader.readline()
                    if not chunk:
                        break
                    body += chunk
                self.received.append((list(rcpts), body))
                send(self.data_reply)
            elif up == "QUIT":
                send("221 2.0.0 Bye")
                await writer.drain()
                break
            else:
                send("502 5.5.2 Error")
            await writer.drain()
        writer.close()

    async def stop(self):
        self.server.close()
        await self.server.wait_closed()


def run(coro):
    return asyncio.run(coro)


class ScheduleTest(unittest.TestCase):
    def test_same_plan_as_php(self):
        plan = schedule.retry_schedule(16, 28800)
        self.assertEqual((15, 60, 28800), (len(plan), plan[0], plan[-1]))
        self.assertEqual([60, 160, 325, 600], schedule.retry_schedule(5, 600))   # PHP: 1, 2.7, 5.4, 10 min
        self.assertEqual([60, 120, 180, 240], schedule.retry_schedule(5, 240))

    def test_next_attempt(self):
        c = dt.datetime(2026, 11, 8, 12, 0, 0)
        e = c + dt.timedelta(hours=8)
        self.assertEqual(c + dt.timedelta(seconds=60), schedule.next_attempt(c, e, 16, 1, c + dt.timedelta(seconds=2)))
        late = c + dt.timedelta(hours=1)   # worker was behind: not in the past, at least 30 s later
        self.assertEqual(late + dt.timedelta(seconds=30), schedule.next_attempt(c, e, 16, 2, late))
        self.assertIsNone(schedule.next_attempt(c, e, 16, 16, c + dt.timedelta(hours=7)), "no attempt left")
        self.assertIsNone(schedule.next_attempt(c, e, 16, 3, e), "expired")


class DeliverTest(unittest.TestCase):
    def deliver(self, server, rcpts, targets=None):
        async def go():
            port = await server.start()
            try:
                return await smtp.deliver("example.net", rcpts, "bounce@shop.example", MAIL, "node.example",
                                          {4: "127.0.0.1"}, port=port, targets=targets or [("mx.example.net", [(4, "127.0.0.1")])])
            finally:
                await server.stop()
        return run(go())

    def test_one_transaction_with_an_answer_per_recipient(self):
        srv = FakeSmtp()
        tries = self.deliver(srv, ["a@example.net", "bad@example.net", "later@example.net", "b@example.net"])
        self.assertEqual("sent", smtp.outcome(tries, "a@example.net").result)
        self.assertEqual("sent", smtp.outcome(tries, "b@example.net").result)
        bad = smtp.outcome(tries, "bad@example.net")
        self.assertEqual(("bounced", "rcpt", 550, "5.1.1"), (bad.result, bad.stage, bad.code, bad.enhanced))
        later = smtp.outcome(tries, "later@example.net")
        self.assertEqual(("deferred", 451, "4.2.0"), (later.result, later.code, later.enhanced))
        self.assertEqual(1, len(srv.received), "one transaction for the domain")
        self.assertEqual(["a@example.net", "b@example.net"], srv.received[0][0])
        ok = smtp.outcome(tries, "a@example.net")
        self.assertEqual(("data", 250, "2.0.0", "mx.example.net", "127.0.0.1", "127.0.0.1"), (ok.stage, ok.code, ok.enhanced, ok.mx_host, ok.mx_ip, ok.local_ip))
        self.assertIn("Ok: queued as X1", ok.response)

    def test_data_and_sender_refusals(self):
        t = smtp.outcome(self.deliver(FakeSmtp(data_reply="554 5.7.1 Message rejected as spam"), ["a@example.net"]), "a@example.net")
        self.assertEqual(("bounced", "data", 554), (t.result, t.stage, t.code))
        t = smtp.outcome(self.deliver(FakeSmtp(mail_reply="451 4.7.1 Try later"), ["a@example.net"]), "a@example.net")
        self.assertEqual(("deferred", "mail"), (t.result, t.stage))

    def test_next_mx_after_refused_greeting_or_no_connection(self):
        async def go():
            bad, good = FakeSmtp(greeting="554 5.7.1 No service"), FakeSmtp()
            p1, p2 = await bad.start(), await good.start()
            try:
                # aiosmtplib connects to a fixed port per call: use the port inside the targets via two deliveries
                t1 = await smtp.deliver("example.net", ["a@example.net"], "b@shop.example", MAIL, "node.example", {4: "127.0.0.1"},
                                        port=p1, targets=[("mx1.example.net", [(4, "127.0.0.1")])])
                t2 = await smtp.deliver("example.net", ["a@example.net"], "b@shop.example", MAIL, "node.example", {4: "127.0.0.1"},
                                        port=p2, targets=[("mx0.example.net", [(4, "127.0.0.9")]), ("mx1.example.net", [(4, "127.0.0.1")])])
                return t1, t2, good
            finally:
                await bad.stop()
                await good.stop()
        t1, t2, good = run(go())
        self.assertEqual(("greeting", "bounced", 554), (t1[-1].stage, t1[-1].result, t1[-1].code), "every server refused permanently")
        self.assertEqual("connect", t2[0].stage)
        self.assertEqual(("deferred", "mx0.example.net"), (t2[0].result, t2[0].mx_host))
        self.assertEqual(("sent", "mx1.example.net"), (t2[-1].result, t2[-1].mx_host))
        self.assertEqual(1, len(good.received))

    def test_nothing_reachable_is_deferred(self):
        tries = run(smtp.deliver("example.net", ["a@example.net"], "b@shop.example", MAIL, "node.example", {4: "127.0.0.1"},
                                 port=1, targets=[("mx.example.net", [(4, "127.0.0.1")]), ("mx2.example.net", [])]))
        self.assertEqual(["deferred"], sorted({t.result for t in tries}))
        self.assertEqual("dns", tries[-1].stage)


class SignAndDecideTest(unittest.TestCase):
    def test_ed25519_signature_verifies(self):
        seed = os.urandom(32)
        pub, _ = nacl.bindings.crypto_sign_seed_keypair(seed)
        signed = worker.sign(MAIL, "s1", "shop.example", "ed25519", base64.b64encode(seed))
        self.assertTrue(signed.startswith(b"DKIM-Signature: v=1; a=ed25519-sha256;"))
        self.assertIn(b"h=from : subject : message-id : to", signed.replace(b"\r\n", b"").replace(b"\t", b""))
        self.assertTrue(dkim.verify(signed, dnsfunc=lambda name, timeout=5: b"v=DKIM1; k=ed25519; p=" + base64.b64encode(pub)))
        with self.assertRaises(worker.LocalError):
            worker.sign(MAIL, "s1", "shop.example", "rsa", b"not a key")

    def test_decide(self):
        c = dt.datetime(2026, 11, 8, 12, 0, 0)
        m = {"created_at": c, "expires_at": c + dt.timedelta(hours=8), "retry_attempts": 16, "attempts": 0}
        T = smtp.Try
        self.assertEqual(("sent", None, None), worker.decide(m, T("data", "sent", []), c))
        self.assertEqual(("bounced", "rejected_rcpt", None), worker.decide(m, T("rcpt", "bounced", []), c))
        status, reason, nxt = worker.decide(m, T("connect", "deferred", []), c)
        self.assertEqual(("deferred", "temporary_connect"), (status, reason))
        self.assertEqual(c + dt.timedelta(seconds=60), nxt)
        self.assertEqual(("failed", "retries_exhausted", None), worker.decide(dict(m, attempts=15), T("rcpt", "deferred", []), c))
        self.assertEqual(("failed", "local_error", None), worker.decide(m, T("local", "failed", []), c))


class GateTest(unittest.TestCase):
    def msg(self, **kw):
        c = dt.datetime(2026, 11, 8, 12, 0, 0)
        m = {"raw_path": "x", "domain_row": 1, "expires_at": c + dt.timedelta(hours=8), "created_at": c, "project_enabled": 1,
             "customer_enabled": 1, "test_status": "passed", "test_key_sha256": "k", "key_sha256": "k", "attempts": 0}
        m.update(kw)
        return m, c

    def test_no_sending_without_a_passed_test_for_the_current_key(self):
        m, c = self.msg()
        self.assertEqual("sending", queue.Queue.gate(m, c)[0])
        m, c = self.msg(test_status="untested")
        self.assertEqual(("waiting_test", "domain_not_verified"), queue.Queue.gate(m, c + dt.timedelta(seconds=10))[:2])
        self.assertEqual(("failed", "dns_not_verified"), queue.Queue.gate(m, c + dt.timedelta(seconds=301))[:2], "5 minutes, then hardfail")
        m, c = self.msg(test_key_sha256="old")   # key changed after the test
        self.assertEqual("waiting_test", queue.Queue.gate(m, c)[0])
        m, c = self.msg(test_status="failed", attempts=2)   # interval test failed: stop, keep the retry window
        self.assertEqual(("deferred", "dns_not_verified"), queue.Queue.gate(m, c + dt.timedelta(hours=1))[:2])

    def test_other_gates(self):
        m, c = self.msg(raw_path=None)
        self.assertEqual(("failed", "mail_missing"), queue.Queue.gate(m, c)[:2])
        m, c = self.msg(domain_row=None)
        self.assertEqual(("failed", "dkim_missing"), queue.Queue.gate(m, c)[:2])
        m, c = self.msg()
        self.assertEqual(("failed", "expired"), queue.Queue.gate(m, c + dt.timedelta(hours=9))[:2])
        m, c = self.msg(project_enabled=0)
        self.assertEqual(("deferred", "project_disabled"), queue.Queue.gate(m, c)[:2])


class WorkerTest(unittest.TestCase):
    def test_handle_records_each_message_with_its_own_tries(self):
        recorded = []

        class FakeQueue:
            pending_pings = 0

            def dkim_key(self, pid, domain):
                return {"dkim_selector": "s1", "dkim_algorithm": "ed25519", "dkim_private_enc": b"k", "key_sha256": "x"}

            def record(self, results):
                recorded.extend(results)

        async def fake_deliver(domain, rcpts, envelope_from, data, helo, sources, resolver):
            assert data.startswith(b"DKIM-Signature:")
            return [smtp.Try("rcpt", "bounced", ["bad@example.net"], 550, "5.1.1", "unknown"),
                    smtp.Try("data", "sent", ["a@example.net"], 250, "2.0.0", "ok")]

        seed = base64.b64encode(os.urandom(32))
        decrypt = lambda key, blob, ctx: seed if ctx.startswith("dkim:") else MAIL  # noqa: E731
        s = settings.Settings({"MAILRUNNER_SEND_ENABLED": "true", "MAILRUNNER_SEND_HELO": "node.example", "MAILRUNNER_SEND_IPV4": "192.0.2.1",
                               "MAILRUNNER_DATA_DIR": HERE})
        w = worker.Worker(s, FakeQueue(), b"key", decrypt, None, lambda *a: None, deliver=fake_deliver)
        c = queue.utcnow()
        base = {"submission_id": "mrs_1", "recipient_domain": "example.net", "envelope_from": "b@shop.example", "raw_path": os.path.basename(__file__),
                "project_id": 7, "from_domain": "shop.example", "created_at": c, "expires_at": c + dt.timedelta(hours=8), "retry_attempts": 16, "attempts": 0}
        rows = [dict(base, id=1, message_id="mrm_a", recipient="a@example.net"), dict(base, id=2, message_id="mrm_b", recipient="bad@example.net")]
        s.data_dir = os.path.dirname(HERE)   # store_dir -> <..>/send/store does not exist: point prepare() at this file instead
        w.s = type("S", (), {"store_dir": HERE, "ipv4": ["192.0.2.1"], "ipv6": [], "helo": "node.example", "webhook_request": "/nonexistent/x"})()
        run(w.handle(rows))
        self.assertEqual([("sent", None), ("bounced", "rejected_rcpt")], [(r["status"], r["reason"]) for r in recorded])
        self.assertEqual([1, 1], [len(r["tries"]) for r in recorded])

    def test_settings_from_app_env(self):
        s = settings.Settings({"database.default.hostname": "localhost", "database.default.password": "x", "MAILRUNNER_SEND_ENABLED": "true",
                               "MAILRUNNER_SEND_HELO": "Node.Example", "MAILRUNNER_SEND_IPV4": "192.0.2.1 nonsense", "MAILRUNNER_SEND_IPV6": "2001:db8::1,192.0.2.2"})
        self.assertEqual((True, "node.example", ["192.0.2.1"], ["2001:db8::1"]), (s.enabled, s.helo, s.ipv4, s.ipv6))


if __name__ == "__main__":
    unittest.main()
