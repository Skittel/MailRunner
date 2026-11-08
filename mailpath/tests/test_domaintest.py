"""Domain test of the send role (E-27 step 5). Run: python3 -I -m unittest discover -s mailpath/tests"""
import asyncio
import base64
import os
import sys
import unittest

import nacl.bindings

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, "..", ".."))
sys.path.insert(0, os.path.join(ROOT, "mailpath", "send"))
sys.path.insert(0, os.path.join(ROOT, "mailpath", "receive"))

from mailrunner_tx import domaintest, settings, smtp  # noqa: E402


def smf_result(family=4, spf="pass", dkim="pass", dmarc="pass", policy="reject", listed=0, blm="Not blacklisted"):
    """Shape of a SmartMailFlow API answer ("data" part), reduced to what the test reads."""
    return {"debug": {
        "ip_protocol": {"requested": f"ipv{family}", "used": f"ipv{family}", "status": "pass"},
        "spf": {"result": spf}, "dkim": {"result": dkim}, "dmarc": {"result": dmarc, "effective_policy": policy},
        "dnsbl": {"listed_count": str(listed)}, "blacklistmaster": {"status": blm, "blacklist_cnt": "1" if blm == "Blacklisted" else "0",
                                                                     "blacklists": [{"blacklist": "bl.example.org"}] if blm == "Blacklisted" else []},
        "smtp": {"tls": {"status": "pass", "protocol": "TLSv1.3"}},
    }}


class FakeDns:
    def __init__(self, records):
        self.records = records

    def txt(self, name):
        return self.records.get(name, [])


class JudgeTest(unittest.TestCase):
    def test_criteria_and_judgement(self):
        crit = domaintest.criteria_of('{"dmarc": false, "blacklist": true, "unknown": true}')
        self.assertEqual({"spf": True, "dkim": True, "dmarc": False, "dmarc_policy": False, "blacklist": True}, crit)
        checks = domaintest.judge(smf_result(dmarc="fail", blm="Blacklisted"), 4, crit)
        self.assertFalse(checks["dmarc"]["ok"])
        self.assertFalse(checks["dmarc"]["required"])
        self.assertEqual((False, True, ["bl.example.org"]), (checks["blacklist"]["ok"], checks["blacklist"]["required"], checks["blacklist"]["lists"]))
        self.assertEqual(["blacklist.v4"], domaintest.failed_checks({4: checks}))
        wrong = smf_result()
        wrong["debug"]["ip_protocol"]["used"] = "ipv4"
        self.assertEqual(["family.v6"], domaintest.failed_checks({6: domaintest.judge(wrong, 6, crit)}), "the mail must arrive over the tested family")

    def test_precheck(self):
        dns = FakeDns({"s1._domainkey.shop.example": ["v=DKIM1; k=ed25519; p=AAAA BBBB"]})
        pre = domaintest.precheck("shop.example", "s1", "AAAABBBB", [(4, "192.0.2.1"), (6, "2001:db8::1")], "node.example", dns,
                                  spf_check=lambda ip: "pass" if "." in ip else "softfail")
        self.assertEqual({"spf": {"v4": "pass", "v6": "softfail"}, "dkim": "pass"}, pre)
        self.assertEqual("mismatch", domaintest.precheck("shop.example", "s1", "OTHER", [], "h", dns, spf_check=lambda ip: "pass")["dkim"])
        self.assertEqual("missing", domaintest.precheck("shop.example", "s2", "AAAABBBB", [], "h", dns, spf_check=lambda ip: "pass")["dkim"])


class RunTestTest(unittest.TestCase):
    def setUp(self):
        self.seed = os.urandom(32)
        pub, _ = nacl.bindings.crypto_sign_seed_keypair(self.seed)
        self.pub = base64.b64encode(pub).decode()
        self.row = {"domain": "shop.example", "dkim_selector": "s1", "dkim_public": self.pub, "dkim_algorithm": "ed25519", "send_test_criteria": None}
        self.s = settings.Settings({"MAILRUNNER_SEND_HELO": "node.example", "MAILRUNNER_SEND_IPV4": "192.0.2.1", "MAILRUNNER_SEND_IPV6": "2001:db8::1"})
        self.dns = FakeDns({"s1._domainkey.shop.example": [f"v=DKIM1; k=ed25519; p={self.pub}"]})
        self.sent = []

    async def no_sleep(self, s):
        return None

    def deliver(self, result="sent"):
        async def fake(domain, rcpts, envelope_from, data, helo, sources, resolver):
            self.sent.append((domain, rcpts[0], sorted(sources), data))
            return [smtp.Try("data", result, rcpts, 250 if result == "sent" else 451, None, "ok")]
        return fake

    def run_test(self, fetch, deliver=None, spf="pass", dns=None):
        return asyncio.run(domaintest.run_test(self.row, self.s, base64.b64encode(self.seed), dns or self.dns, deliver=deliver or self.deliver(),
                                               fetch=fetch, sleep=self.no_sleep, spf_check=lambda ip: spf))

    def test_passed_over_both_families(self):
        tokens = []

        def fetch(token):
            tokens.append(token)
            return {"error": "", "data": smf_result(int(token[-1]))}
        outcome, details = self.run_test(fetch)
        self.assertEqual("passed", outcome, details)
        self.assertEqual([("ip6.smartmailflow.email", 6), ("ip4.smartmailflow.email", 4)], [(d, src[0]) for d, _, src, _ in self.sent])
        self.assertTrue(all(data.startswith(b"DKIM-Signature: v=1; a=ed25519-sha256") for *_, data in self.sent))
        self.assertTrue(all(rcpt.startswith("mrdt") for _, rcpt, _, _ in self.sent))
        self.assertEqual(self.sent[0][1].split("@")[0][:-1], self.sent[1][1].split("@")[0][:-1], "one random id per run, family as suffix")
        self.assertEqual({"v4", "v6"}, set(details["checks"]))
        self.assertEqual([], details["failed"])

    def test_failed_by_smartmailflow_result(self):
        outcome, details = self.run_test(lambda t: {"data": smf_result(int(t[-1]), dkim="fail")})
        self.assertEqual(("failed", ["dkim.v4", "dkim.v6"]), (outcome, details["failed"]))

    def test_precheck_failure_sends_nothing(self):
        outcome, details = self.run_test(lambda t: self.fail("no API call"), spf="softfail")
        self.assertEqual(("failed", "precheck", ["spf.v6", "spf.v4"]), (outcome, details["stage"], details["failed"]))
        self.assertEqual([], self.sent)
        outcome, details = self.run_test(lambda t: self.fail("no API call"), dns=FakeDns({}))
        self.assertEqual(["dkim.record_missing"], details["failed"])

    def test_smartmailflow_trouble_is_not_a_failure(self):
        outcome, details = self.run_test(lambda t: {"error": "not found", "data": None})
        self.assertEqual("unavailable", outcome)
        self.assertIn("no SmartMailFlow result", details["error"])
        outcome, details = self.run_test(lambda t: self.fail("no API call"), deliver=self.deliver("deferred"))
        self.assertEqual("unavailable", outcome)
        self.assertIn("not delivered", details["error"])

        def broken(token):
            raise OSError("connection refused")
        self.assertEqual("unavailable", self.run_test(broken)[0])


if __name__ == "__main__":
    unittest.main()
