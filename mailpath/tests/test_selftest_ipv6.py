import os
import sys
import unittest

sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", "receive"))
from mailrunner_rx import selftest  # noqa: E402


class Ipv6RouteTest(unittest.TestCase):
    def test_no_aaaa_is_skipped(self):
        self.assertEqual("skip", selftest.ipv6_route_status([])[0])

    def test_answer_from_a_root_server_is_ok(self):
        status, detail = selftest.ipv6_route_status(["mx.example"], roots=["::1"], query=lambda ip: None)
        self.assertEqual("ok", status)
        self.assertIn("mx.example", detail)

    def test_no_answer_fails(self):
        def query(ip):
            raise TimeoutError()
        status, detail = selftest.ipv6_route_status(["mx.example"], roots=["::1", "::1"], query=query)
        self.assertEqual("fail", status)
        self.assertIn("TimeoutError", detail)
        self.assertNotIn("::1", detail)


if __name__ == "__main__":
    unittest.main()
