"""Self test of the send role (E-27): configuration, FCrDNS, local addresses, outgoing port 25; no addresses in details."""
import os
import smtplib
import sys
import unittest
from unittest import mock

sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", "receive"))
from mailrunner_rx import selftest  # noqa: E402

HELO = "out.example.net"
V4, V6 = "192.0.2.25", "2001:db8::25"


class SendChecksTest(unittest.TestCase):
    def run_checks(self, helo=HELO, ips=(V4, V6), forward=None, ptrs=None, own=(V4, V6), probe=None):
        forward = {(HELO, "A"): [V4], (HELO, "AAAA"): [V6]} if forward is None else forward
        ptrs = {V4: [HELO + "."], V6: [HELO]} if ptrs is None else ptrs
        run = selftest.Run("node.example.net")
        with mock.patch.object(selftest, "redact", lambda text, values=None: text):
            selftest.send_checks(run, helo, list(ips), resolve=lambda n, t: forward.get((n, t), []), ptr=lambda ip: ptrs.get(ip, []),
                                 probe=probe or (lambda ip, fam, h: ("ok", "port 25 open, EHLO accepted")), own=set(own))
        return {c["name"]: c for c in run.checks}

    def test_all_ok(self):
        checks = self.run_checks()
        self.assertEqual(["send.config", "send.helo_dns", "send.v4.1.ptr", "send.v4.1.port25", "send.v6.1.ptr", "send.v6.1.port25"], list(checks))
        self.assertTrue(all(c["status"] == "ok" for c in checks.values()), checks)
        for c in checks.values():
            self.assertNotIn(V4, c["detail"])
            self.assertNotIn("2001:db8", c["detail"])

    def test_missing_configuration(self):
        checks = self.run_checks(helo="", ips=())
        self.assertEqual("fail", checks["send.config"]["status"])
        self.assertEqual(1, len(checks))

    def test_dns_ptr_local_and_port25_failures(self):
        def blocked(ip, fam, helo):
            raise TimeoutError("timed out")
        checks = self.run_checks(forward={(HELO, "A"): [V4]}, ptrs={V4: ["other.example.net."]}, own=(V4,), probe=blocked)
        self.assertEqual("fail", checks["send.helo_dns"]["status"])
        self.assertIn("v6.1", checks["send.helo_dns"]["detail"])
        self.assertEqual("fail", checks["send.v4.1.ptr"]["status"])
        self.assertEqual("fail", checks["send.v4.1.port25"]["status"])
        self.assertIn("blocked by the provider", checks["send.v4.1.port25"]["detail"])
        self.assertEqual("fail", checks["send.v6.1.local"]["status"])
        self.assertNotIn("send.v6.1.ptr", checks)

    def test_labels_normalise_addresses(self):
        self.assertEqual([("2001:db8::25", 6, "v6.1"), ("192.0.2.25", 4, "v4.1"), ("192.0.2.26", 4, "v4.2")],
                         selftest.send_labels(["2001:0db8:0000::0025", V4, "192.0.2.26"]))

    def test_probe_errors_are_smtp_exceptions(self):
        checks = self.run_checks(probe=lambda ip, fam, h: (_ for _ in ()).throw(smtplib.SMTPServerDisconnected("closed")))
        self.assertEqual("fail", checks["send.v4.1.port25"]["status"])


if __name__ == "__main__":
    unittest.main()
