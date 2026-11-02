import os
import sys
import unittest

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(HERE, "..", "receive"))

from mailrunner_rx import selftest  # noqa: E402


class RedactTest(unittest.TestCase):
    def test_secret_inside_dns_error_is_removed(self):
        key = "abcdefghijklmnopqrstuvwxyz"
        detail = f"NoNameservers: query 2.0.0.127.{key}.zen.dq.spamhaus.net. IN A: SERVFAIL"
        out = selftest.redact(detail, [key, "short"])
        self.assertNotIn(key, out)
        self.assertIn("2.0.0.127.<redacted>.zen.dq.spamhaus.net", out)

    def test_short_values_are_not_replaced(self):
        self.assertEqual(selftest.redact("ok 1", ["1"]), "ok 1")


if __name__ == "__main__":
    unittest.main()
