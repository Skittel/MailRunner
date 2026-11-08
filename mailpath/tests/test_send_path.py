"""Send role in mr-milter (E-27 step 3). Run: python3 -I -m unittest discover -s mailpath/tests"""
import base64
import json
import os
import subprocess
import sys
import tempfile
import unittest
from unittest import mock

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
sys.path.insert(0, os.path.join(os.path.abspath(os.path.join(HERE, "..", "..")), "mailpath", "receive"))

from mailrunner_rx import send  # noqa: E402
from test_receive_path import load_milter_module, split_like_postfix  # noqa: E402

SEND_JSON = {
    "accesses": {
        "mra_ip0000000000000001": {"project_id": 7, "project": "acme/mailer", "profile": "shop",
                                   "senders": ["shop.example", "info@other.example"], "recipients": None},
        "mra_smtp00000000000002": {"project_id": 7, "project": "acme/mailer", "profile": "partners",
                                   "senders": ["shop.example"], "recipients": ["partner.example"]},
    },
    "ip_accesses": [{"network": "203.0.113.8/29", "access_id": "mra_ip0000000000000001"},
                    {"network": "2001:db8:1::/64", "access_id": "mra_ip0000000000000001"}],
}
MAIL = (b"From: Shop <info@shop.example>\r\nTo: kunde@example.net\r\nSubject: Bestellung\r\n"
        b"Date: Sun, 08 Nov 2026 16:00:00 +0000\r\n\r\nDanke\r\n")


class SendRulesTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.path = os.path.join(self.tmp.name, "send.json")
        with open(self.path, "w") as fh:
            json.dump(SEND_JSON, fh)
        self.cfg = send.SendConfig(self.path)

    def tearDown(self):
        self.tmp.cleanup()

    def test_client_address_maps_to_ip_access(self):
        self.assertEqual("mra_ip0000000000000001", self.cfg.access_for_ip("203.0.113.12"))
        self.assertEqual("mra_ip0000000000000001", self.cfg.access_for_ip("::ffff:203.0.113.9"))
        self.assertEqual("mra_ip0000000000000001", self.cfg.access_for_ip("2001:db8:1::25"))
        self.assertIsNone(self.cfg.access_for_ip("203.0.113.16"))
        self.assertIsNone(self.cfg.access_for_ip("not-an-ip"))
        self.assertIsNone(send.SendConfig(os.path.join(self.tmp.name, "missing.json")).access_for_ip("203.0.113.12"))

    def test_sender_and_recipient_rules_match_the_php_rules(self):
        shop = self.cfg.access("mra_ip0000000000000001")
        self.assertIsNone(send.check_sender(shop, "Bounce@Shop.Example"))
        self.assertIsNone(send.check_sender(shop, "info@other.example"))
        self.assertEqual("550", send.check_sender(shop, "x@other.example")[0])
        self.assertEqual("550", send.check_sender(shop, "x@news.shop.example")[0], "exact domain, no subdomains")
        self.assertEqual("550", send.check_sender(shop, "")[0], "null sender cannot relay")
        partners = self.cfg.access("mra_smtp00000000000002")
        self.assertIsNone(send.check_recipient(partners, "a@partner.example", 0))
        self.assertEqual("550", send.check_recipient(partners, "a@example.net", 0)[0])
        self.assertEqual("452", send.check_recipient(shop, "a@example.net", send.MAX_RECIPIENTS)[0])

    def test_accept_parses_php_answer_and_turns_failures_into_451(self):
        seen = {}

        def run(cmd, input, capture_output, timeout):
            seen["cmd"], seen["payload"] = cmd, json.loads(input)
            return subprocess.CompletedProcess(cmd, 0, stdout=b'{"ok": true, "submission_id": "mrs_x", "messages": []}\n', stderr=b"")

        r = send.accept({"access_id": "a", "mail_from": "f@shop.example", "rcpts": ["r@x.example"], "raw": MAIL}, run=run)
        self.assertTrue(r["ok"])
        self.assertIn("--no-header", seen["cmd"])
        self.assertEqual(MAIL, base64.b64decode(seen["payload"]["raw_b64"]))
        self.assertNotIn("raw", seen["payload"])

        garbage = lambda *a, **k: subprocess.CompletedProcess(a, 1, stdout=b"PHP Fatal error", stderr=b"")  # noqa: E731
        self.assertEqual(("451", False), (send.accept({"raw": b""}, run=garbage)["code"], False))

        def slow(*a, **k):
            raise subprocess.TimeoutExpired("php", 60)
        self.assertEqual("451", send.accept({"raw": b""}, run=slow)["code"])
        self.assertEqual("Recipient ??? x", send.smtp_text("Recipient ä\r\n x"))


class SendMilterTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.mm = load_milter_module()
        path = os.path.join(self.tmp.name, "send.json")
        with open(path, "w") as fh:
            json.dump(SEND_JSON, fh)
        creds = os.path.join(self.tmp.name, "credentials.json")
        with open(creds, "w") as fh:
            json.dump({"credentials": {"shop-relay": {"scope": "send", "access_id": "mra_smtp00000000000002", "credential_id": "mrc_s",
                                                      "project_id": 7, "project": "acme/mailer", "profile": "smarthost"},
                                       "device1": {"credential_id": "mrc_r", "project_id": 2, "project": "acme/in", "profile": "checked_auth"}}}, fh)
        self.mm.SEND = send.SendConfig(path)
        self.mm.CREDENTIALS = self.mm.auth.Credentials(creds)
        self.calls = []
        self.answer = {"ok": True, "submission_id": "mrs_1", "messages": []}
        self.patches = [mock.patch.object(self.mm, "log", lambda *a: None),
                        mock.patch.object(self.mm.send, "accept", self.fake_accept),
                        mock.patch.object(self.mm.analyzer, "analyze", self.no_analysis)]
        for p in self.patches:
            p.start()

    def tearDown(self):
        for p in self.patches:
            p.stop()
        self.tmp.cleanup()

    def fake_accept(self, handoff):
        self.calls.append(handoff)
        return self.answer

    @staticmethod
    def no_analysis(*a, **k):
        raise AssertionError("a submission is not analysed or stored by the receive path")

    def session(self, client_ip, mail_from, rcpts, login=None, service="smtp"):
        m = self.mm.ReceiveMilter()
        macros = {"i": "Q1", "j": "node.example", "{daemon_name}": service, "{auth_authen}": login}
        m.getsymval = macros.get
        m.replies = []
        m.setreply = lambda *args: m.replies.append(args)
        m.connect("client.example", self.mm.socket.AF_INET6 if ":" in client_ip else self.mm.socket.AF_INET, (client_ip, 40000))
        m.hello("client.example")
        r_from = m.envfrom(f"<{mail_from}>")
        r_rcpt = [m.envrcpt(f"<{r}>") for r in rcpts] if r_from == self.mm.Milter.CONTINUE else []
        result = None
        if r_from == self.mm.Milter.CONTINUE and self.mm.Milter.CONTINUE in r_rcpt:
            headers, body = split_like_postfix(MAIL)
            for name, value in headers:
                m.header(name, value)
            m.body(body)
            result = m.eom()
        return m, r_from, r_rcpt, result

    def test_ip_access_submission_is_handed_off_and_discarded(self):
        m, r_from, r_rcpt, result = self.session("203.0.113.10", "info@shop.example", ["a@example.net", "b@example.org"])
        self.assertEqual(self.mm.Milter.DISCARD, result)
        [h] = self.calls
        self.assertEqual(("mra_ip0000000000000001", "info@shop.example", ["a@example.net", "b@example.org"], "smtp", None),
                         (h["access_id"], h["mail_from"], h["rcpts"], h["smtp_service"], h["username"]))
        self.assertIn(b"From: Shop <info@shop.example>", h["raw"])
        self.assertTrue(h["raw"].startswith(b"Received: "))

    def test_send_login_decides_profile_and_recipient_rules(self):
        m, r_from, r_rcpt, result = self.session("198.51.100.5", "info@shop.example", ["x@partner.example", "y@example.net"],
                                                 login="shop-relay", service="submission")
        self.assertEqual([self.mm.Milter.CONTINUE, self.mm.Milter.REJECT], r_rcpt)
        self.assertEqual(self.mm.Milter.DISCARD, result)
        self.assertEqual((["x@partner.example"], "shop-relay", "submission"),
                         (self.calls[0]["rcpts"], self.calls[0]["username"], self.calls[0]["smtp_service"]))

    def test_sender_not_in_profile_is_refused_at_mail_from(self):
        m, r_from, _, _ = self.session("203.0.113.10", "info@evil.example", ["a@example.net"])
        self.assertEqual(self.mm.Milter.REJECT, r_from)
        self.assertEqual("550", m.replies[0][0])
        self.assertEqual([], self.calls)

    def test_php_refusal_is_passed_on(self):
        self.answer = {"ok": False, "code": "451", "enhanced": "4.7.1", "error": "LIMIT_REACHED", "message": "Limit of profile \"shop\" reached"}
        m, _, _, result = self.session("203.0.113.10", "info@shop.example", ["a@example.net"])
        self.assertEqual(self.mm.Milter.TEMPFAIL, result)
        self.assertEqual(("451", "4.7.1"), m.replies[-1][:2])
        self.answer = {"ok": False, "code": "550", "enhanced": "5.7.1", "error": "DKIM_MISSING", "message": "no key"}
        _, _, _, result = self.session("203.0.113.10", "info@shop.example", ["a@example.net"])
        self.assertEqual(self.mm.Milter.REJECT, result)

    def test_other_clients_and_receive_logins_take_the_receive_path(self):
        m = self.mm.ReceiveMilter()
        m.getsymval = {"{auth_authen}": None}.get
        m.connect("mx.example.net", self.mm.socket.AF_INET, ("192.0.2.10", 25))
        m.envfrom("<a@example.net>")
        self.assertIsNone(m.send_access)
        m = self.mm.ReceiveMilter()
        m.getsymval = {"{auth_authen}": "device1"}.get
        m.connect("c", self.mm.socket.AF_INET, ("203.0.113.10", 25))  # inside an ip access, but logged in for receive
        m.envfrom("<a@example.net>")
        self.assertIsNone(m.send_access)
        self.assertEqual("mrc_r", m.auth["credential_id"])


if __name__ == "__main__":
    unittest.main()
