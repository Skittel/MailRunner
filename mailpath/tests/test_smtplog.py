"""Tests for the SMTP session tracker (journal of delivery attempts). Run: python3 -I -m unittest discover -s mailpath/tests"""
import os
import sys
import unittest

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(HERE, "..", "receive"))

from mailrunner_rx import smtplog  # noqa: E402

SMTPD = "postfix/smtpd"
CLEANUP = "postfix/cleanup"
MILTER = "mailrunner-milter"


def run(lines, end_ts=None):
    t = smtplog.Tracker()
    out = []
    ts = 1000.0
    for i, (ident, pid, msg) in enumerate(lines):
        ts += 0.1
        out += t.feed(ident, pid, ts, msg, f"c{i}")
    out += t.due((end_ts or ts) + smtplog.GRACE_SECONDS + 1)
    return out, t


ACCEPTED = [
    (SMTPD, "101", "connect from mx.example.net[192.0.2.10]:40001"),
    (SMTPD, "101", "Anonymous TLS connection established from mx.example.net[192.0.2.10]:40001: TLSv1.3 with cipher TLS_AES_256_GCM_SHA384 (256/256 bits)"),
    (MILTER, "7", "session helo client=[192.0.2.10]:40001 helo=<mx.example.net>"),
    (MILTER, "7", "session mail client=[192.0.2.10]:40001 from=<a@example.net>"),
    (SMTPD, "101", "NOQUEUE: reject: RCPT from mx.example.net[192.0.2.10]:40001: 550 5.1.1 <x@in.example.org>: Recipient address rejected: User unknown; from=<a@example.net> to=<x@in.example.org> proto=ESMTP helo=<mx.example.net>"),
    (MILTER, "7", "session rcpt client=[192.0.2.10]:40001 to=<user0001@in.example.org> result=accepted"),
    (SMTPD, "101", "ABC123: client=mx.example.net[192.0.2.10]:40001"),
    (MILTER, "7", "stored id=20261101T200000Z-00aa queue_id=ABC123 project=acme/inbound profile=analysis analysis=ok ms=10 raw_kept=False"),
    (CLEANUP, "202", "ABC123: milter-discard: END-OF-MESSAGE from mx.example.net[192.0.2.10]:40001: milter triggers DISCARD action; from=<a@example.net> to=<user0001@in.example.org> proto=ESMTP helo=<mx.example.net>"),
    (SMTPD, "101", "disconnect from mx.example.net[192.0.2.10]:40001 ehlo=2 starttls=1 mail=1 rcpt=1/2 data=1 quit=1 commands=7/8"),
]


class TrackerTest(unittest.TestCase):
    def test_accepted_attempt_with_rejected_recipient(self):
        out, t = run(ACCEPTED)
        self.assertEqual(1, len(out))
        s = out[0]
        self.assertEqual({"ip": "192.0.2.10", "port": 40001, "name": "mx.example.net"}, s["client"])
        self.assertEqual("mx.example.net", s["helo"])
        self.assertEqual("TLSv1.3", s["tls"]["protocol"])
        self.assertEqual("disconnect", s["end"])
        self.assertIn("rcpt=1/2", s["commands"])
        self.assertEqual(1, len(s["transactions"]))
        tx = s["transactions"][0]
        self.assertEqual("a@example.net", tx["mail_from"])
        self.assertEqual("ABC123", tx["queue_id"])
        self.assertEqual(["20261101T200000Z-00aa"], tx["spool_ids"])
        self.assertEqual({"status": "accepted", "reply": "250", "stage": "END-OF-MESSAGE"}, tx["result"])
        self.assertEqual([("x@in.example.org", "rejected", "postfix"), ("user0001@in.example.org", "accepted", "milter")],
                         [(r["rcpt"], r["status"], r["by"]) for r in tx["recipients"]])
        self.assertTrue(tx["recipients"][0]["reply"].startswith("550 5.1.1"))
        self.assertTrue(s["session_id"].startswith("mrs_"))
        self.assertEqual({}, t.by_client)
        self.assertEqual({}, t.by_qid)

    def test_session_is_held_for_grace_period(self):
        t = smtplog.Tracker()
        ts = 1000.0
        for i, (ident, pid, msg) in enumerate(ACCEPTED[:-1]):
            ts += 0.1
            t.feed(ident, pid, ts, msg, f"c{i}")
        self.assertEqual([], t.feed(SMTPD, "101", ts + 0.1, ACCEPTED[-1][2], "cx"))
        self.assertEqual(1, len(t.due(ts + smtplog.GRACE_SECONDS + 1)))

    def test_scanner_connection_without_mail_from(self):
        out, _ = run([
            (SMTPD, "300", "connect from unknown[198.51.100.7]:5555"),
            (SMTPD, "300", "warning: non-SMTP command from unknown[198.51.100.7]:5555: \x16\x03\x01"),
            (SMTPD, "300", "NOQUEUE: lost connection after UNKNOWN from unknown[198.51.100.7]:5555"),
            (SMTPD, "300", "disconnect from unknown[198.51.100.7]:5555 unknown=0/1 commands=0/1"),
        ])
        s = out[0]
        self.assertEqual([], s["transactions"])
        self.assertEqual("lost connection after UNKNOWN", s["end"])
        self.assertEqual(["non-SMTP command"], s["warnings"])
        self.assertIsNone(s["helo"])

    def test_tempfail_at_end_of_data_and_milter_reject_rcpt(self):
        out, _ = run([
            (SMTPD, "400", "connect from mx.example.net[192.0.2.20]:1"),
            (MILTER, "7", "session helo client=[192.0.2.20]:1 helo=<mx.example.net>"),
            (MILTER, "7", "session mail client=[192.0.2.20]:1 from=<>"),
            (MILTER, "7", "session rcpt client=[192.0.2.20]:1 to=<a@one.example> result=accepted"),
            (SMTPD, "400", "QQ1: client=mx.example.net[192.0.2.20]:1"),
            (SMTPD, "400", "QQ1: milter-reject: RCPT from mx.example.net[192.0.2.20]:1: 452 4.5.3 Too many recipients for this transaction; from=<> to=<b@two.example> proto=ESMTP helo=<mx.example.net>"),
            (CLEANUP, "500", "QQ1: milter-reject: END-OF-MESSAGE from mx.example.net[192.0.2.20]:1: 4.7.0 Message check not finished, please try again later; from=<> to=<a@one.example> proto=ESMTP helo=<mx.example.net>"),
            (SMTPD, "400", "disconnect from mx.example.net[192.0.2.20]:1 ehlo=1 mail=1 rcpt=1/2 data=0/1 quit=1 commands=4/5"),
        ])
        tx = out[0]["transactions"][0]
        self.assertEqual("", tx["mail_from"])
        self.assertEqual("tempfail", tx["result"]["status"])
        self.assertTrue(tx["result"]["reply"].startswith("4.7.0"))
        self.assertEqual(("b@two.example", "tempfail", "milter"), (tx["recipients"][1]["rcpt"], tx["recipients"][1]["status"], tx["recipients"][1]["by"]))

    def test_two_transactions_and_abort(self):
        out, _ = run([
            (SMTPD, "600", "connect from mx.example.net[192.0.2.30]:2"),
            (MILTER, "7", "session mail client=[192.0.2.30]:2 from=<a@example.net>"),
            (MILTER, "7", "session rcpt client=[192.0.2.30]:2 to=<u1@one.example> result=accepted"),
            (SMTPD, "600", "Q1: client=mx.example.net[192.0.2.30]:2"),
            (CLEANUP, "700", "Q1: milter-discard: END-OF-MESSAGE from mx.example.net[192.0.2.30]:2: milter triggers DISCARD action; from=<a@example.net> to=<u1@one.example>"),
            (MILTER, "7", "session mail client=[192.0.2.30]:2 from=<b@example.net>"),
            (MILTER, "7", "session rcpt client=[192.0.2.30]:2 to=<u2@two.example> result=accepted"),
            (SMTPD, "600", "Q2: client=mx.example.net[192.0.2.30]:2"),
            (SMTPD, "600", "lost connection after DATA (0 bytes) from mx.example.net[192.0.2.30]:2"),
            (SMTPD, "600", "disconnect from mx.example.net[192.0.2.30]:2 ehlo=1 mail=2 rcpt=2 data=1/2 commands=6/7"),
        ])
        s = out[0]
        self.assertEqual(["a@example.net", "b@example.net"], [t["mail_from"] for t in s["transactions"]])
        self.assertEqual(["accepted", "aborted"], [t["result"]["status"] for t in s["transactions"]])
        self.assertEqual("lost connection after DATA", s["end"])

    def test_idle_session_closed_incomplete_and_resume_cursor(self):
        t = smtplog.Tracker()
        t.feed(SMTPD, "1", 1000.0, "something unrelated", "c0")
        t.feed(SMTPD, "1", 1000.1, "connect from a.example[192.0.2.1]:9", "c1")
        self.assertEqual("c0", t.resume_cursor())
        out = t.due(1000.1 + smtplog.IDLE_SECONDS)
        self.assertEqual("incomplete", out[0]["end"])
        self.assertEqual("c1", t.resume_cursor())

    def test_client_text_is_sanitised(self):
        self.assertEqual("a?b", smtplog.clean("a\x00b"))
        self.assertEqual(300, len(smtplog.clean("x" * 1000)))


if __name__ == "__main__":
    unittest.main()


class SelftestHelpersTest(unittest.TestCase):
    def test_own_address_skips_quota_apis(self):
        from unittest import mock
        from mailrunner_rx import analyzer
        with mock.patch.object(analyzer, "node_addresses", return_value=frozenset({"192.0.2.10", "2001:db8::10"})):
            self.assertTrue(analyzer.is_own_address("192.0.2.10"))
            self.assertTrue(analyzer.is_own_address("2001:0db8::10"))
            self.assertFalse(analyzer.is_own_address("198.51.100.1"))
            self.assertFalse(analyzer.is_own_address("not-an-ip"))

    def test_scenario_expectations(self):
        from mailrunner_rx import selftest
        run = selftest.Run("node.example.net")
        items, subject = selftest.scenarios(run)
        self.assertIn(run.run_id, subject)
        self.assertEqual({"pass4", "pass6", "dkim_tampered", "spf_fail", "no_tls", "null_sender", "outlook_to"}, set(items))
        self.assertEqual("", items["null_sender"]["mail_from"])
        self.assertTrue(items["pass4"]["raw"].startswith(b"DKIM-Signature:"))
        good = {"spf": {"result": "pass"}, "dkim": {"result": "pass"}, "dmarc": {"result": "pass"},
                "smtp": {"tls": {"status": "pass"}, "ip_version": 4}}
        self.assertEqual([], items["pass4"]["expect"](good, {}))
        bad = dict(good, dkim={"result": "fail"})
        self.assertEqual(["debug.dkim.result='fail' expected 'pass'"], items["pass4"]["expect"](bad, {}))
        to = items["outlook_to"]["rcpt"]
        self.assertEqual([], items["outlook_to"]["expect"]({"header": {"to": [{"address": to, "display_name_is_address": True}]}, "dkim": {"result": "pass"}}, {}))
        self.assertIn(b"\r\n\t<" + to.encode() + b">", items["outlook_to"]["raw"])

    def test_result_status(self):
        from mailrunner_rx import selftest
        run = selftest.Run("node.example.net")
        run.add("base", "base.a", "ok")
        run.add("base", "base.b", "warn")
        self.assertEqual("pass", run.result()["status"])
        run.check("base", "base.c", lambda: 1 / 0)
        r = run.result()
        self.assertEqual(("fail", ["base.c"], ["base.b"]), (r["status"], r["failed"], r["warnings"]))


class MxNamesTest(unittest.TestCase):
    def test_parse(self):
        from mailrunner_rx import selftest
        self.assertEqual({"mx.example.net": {4, 6}, "mx4.example.net": {4}, "mx6.example.net": {6}},
                         selftest.parse_mx_names("mx.example.net=46 MX4.example.net.=4, mx6.example.net=6", "node.example.net"))
        self.assertEqual({"mx.example.net": {4, 6}}, selftest.parse_mx_names("mx.example.net", "node.example.net"))
        self.assertEqual({"node.example.net": None}, selftest.parse_mx_names("", "node.example.net"))
