"""Bounces of the send role (E-27 step 6). Run: python3 -I -m unittest discover -s mailpath/tests"""
import os
import sys
import unittest

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, "..", ".."))
sys.path.insert(0, os.path.join(ROOT, "mailpath", "send"))
sys.path.insert(0, os.path.join(ROOT, "mailpath", "receive"))

from mailrunner_tx import bounce, settings, worker  # noqa: E402

KEY = bounce.bounce_key(b"k" * 32)
MAIL_ID = "mrm_k3f9x2m7q1w8e4r6"


def dsn(action="failed", status="5.1.1", diag="smtp; 550 5.1.1 <a@example.net>: Recipient address rejected: User unknown"):
    return ("From: MAILER-DAEMON@mx.example.net\r\nTo: x@bounce.example\r\nSubject: Undelivered Mail Returned to Sender\r\n"
            "MIME-Version: 1.0\r\nContent-Type: multipart/report; report-type=delivery-status; boundary=\"B\"\r\n\r\n"
            "--B\r\nContent-Type: text/plain\r\n\r\nThis is the mail system.\r\n"
            "--B\r\nContent-Type: message/delivery-status\r\n\r\n"
            "Reporting-MTA: dns; mx.example.net\r\nArrival-Date: Sun, 8 Nov 2026 18:00:00 +0000\r\n\r\n"
            f"Final-Recipient: rfc822; a@example.net\r\nOriginal-Recipient: rfc822;a@example.net\r\nAction: {action}\r\nStatus: {status}\r\n"
            f"Diagnostic-Code: {diag}\r\n\r\n"
            "--B\r\nContent-Type: text/rfc822-headers\r\n\r\nFrom: info@shop.example\r\nSubject: Bestellung\r\n\r\n--B--\r\n").encode()


class AddressTest(unittest.TestCase):
    def test_roundtrip_and_checks(self):
        a = bounce.address(KEY, MAIL_ID, "node1.eu", "Bounce.Example")
        local = a.split("@")[0]
        self.assertRegex(a, r"^mrm_k3f9x2m7q1w8e4r6-node1-eu-[a-z2-7]{10}@bounce\.example$")
        self.assertLessEqual(len(local), 64)
        self.assertEqual(MAIL_ID, bounce.verify(KEY, a.upper(), "node1.eu"), "case-insensitive")
        self.assertEqual(MAIL_ID, bounce.verify(KEY, f"<{a}>", "node1.eu"))
        self.assertEqual("other", bounce.verify(KEY, bounce.address(KEY, MAIL_ID, "node2.eu", "bounce.example"), "node1.eu"))
        forged = local[:-1] + ("a" if local[-1] != "a" else "b") + "@bounce.example"
        self.assertIsNone(bounce.verify(KEY, forged, "node1.eu"), "wrong check value")
        self.assertIsNone(bounce.verify(bounce.bounce_key(b"x" * 32), a, "node1.eu"), "another node key")
        for junk in ("postmaster@bounce.example", "mrm_short-node1-eu-aaaaaaaaaa@x", "", "mrm_k3f9x2m7q1w8e4r6--aaaaaaaaaa@x"):
            self.assertIsNone(bounce.verify(KEY, junk, "node1.eu"), junk)
        self.assertEqual(("mrm_k3f9x2m7q1w8e4r6", "node-with-hyphens-1", "abcdefghij"),
                         bounce.parse("mrm_k3f9x2m7q1w8e4r6-node-with-hyphens-1-abcdefghij@b.example"))


class DsnTest(unittest.TestCase):
    def test_permanent_failure(self):
        f = bounce.failure(bounce.read_dsn(dsn()))
        self.assertEqual(("5.1.1", 550), (f["status"], f["code"]))
        self.assertIn("User unknown", f["diagnostic"])

    def test_not_a_failure(self):
        self.assertIsNone(bounce.failure(bounce.read_dsn(dsn(action="delayed", status="4.4.1", diag="smtp; 451 try later"))))
        self.assertIsNone(bounce.failure(bounce.read_dsn(b"From: a@example.net\r\nSubject: Out of office\r\n\r\nIch bin im Urlaub.\r\n")))
        self.assertEqual([], bounce.read_dsn(b"\xff\xfe garbage"))


class FakeSource:
    def __init__(self, items, raws):
        self.items, self.raws, self.acked = items, raws, []

    def messages(self, after_id, limit=100):
        out = [i for i in self.items if i["cursor"] > after_id]
        return [dict(i) for i in out], (out[-1]["cursor"] if out else after_id), False

    def raw(self, message_id):
        return self.raws[message_id]

    def ack(self, ids):
        self.acked += list(ids)


class CollectTest(unittest.TestCase):
    def test_only_own_valid_failures_are_applied_and_acknowledged(self):
        own = bounce.address(KEY, MAIL_ID, "node1.eu", "bounce.example")
        other = bounce.address(KEY, "mrm_aaaaaaaaaaaaaaaa", "node2.eu", "bounce.example")
        items = [{"cursor": 11, "message_id": "r1", "envelope": {"rcpt": own}},
                 {"cursor": 12, "message_id": "r2", "envelope": {"rcpt": other}},
                 {"cursor": 13, "message_id": "r3", "envelope": {"rcpt": "postmaster@bounce.example"}},
                 {"cursor": 14, "message_id": "r4", "envelope": {"rcpt": own}}]
        src = FakeSource(items, {"r1": dsn(), "r4": b"Subject: Auto-reply\r\n\r\nx\r\n"})
        applied = []
        cursor, stats = bounce.collect(src, KEY, "node1.eu", 10, lambda mid, f: applied.append((mid, f["status"])) or True, lambda *a: None)
        self.assertEqual([(MAIL_ID, "5.1.1")], applied)
        self.assertEqual(["r1", "r3", "r4"], src.acked, "never acknowledge another node's bounces")
        self.assertEqual((14, 1, 1, 2), (cursor, stats["bounced"], stats["other_node"], stats["ignored"]))


class WorkerReturnPathTest(unittest.TestCase):
    def test_return_path_and_grouping(self):
        s = settings.Settings({"MAILRUNNER_NODE_ID": "node1.eu", "MAILRUNNER_SEND_HELO": "n.example", "MAILRUNNER_SEND_IPV4": "192.0.2.1"})
        w = worker.Worker(s, None, b"k" * 32, None, None, lambda *a: None)
        plain = {"submission_id": "mrs_1", "recipient_domain": "example.net", "envelope_from": "info@shop.example", "return_path_domain": None}
        self.assertEqual("info@shop.example", w.envelope_from(dict(plain, message_id=MAIL_ID)))
        rp = dict(plain, return_path_domain="bounce.example")
        addr = w.envelope_from(dict(rp, message_id=MAIL_ID))
        self.assertEqual(MAIL_ID, bounce.verify(KEY, addr, "node1.eu"))
        rows = [dict(plain, message_id="mrm_a"), dict(plain, message_id="mrm_b"), dict(rp, message_id="mrm_c"), dict(rp, message_id="mrm_d")]
        self.assertEqual([2, 1, 1], [len(g) for g in w.groups(rows)], "own Return-Path: one transaction per message")


if __name__ == "__main__":
    unittest.main()
