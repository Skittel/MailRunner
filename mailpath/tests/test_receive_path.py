"""Tests for the receive mail path (crypto, spool, routes, mr-deliver). Run: python3 -m unittest discover mailpath/tests"""
import base64
import json
import os
import subprocess
import sys
import tempfile
import unittest

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, "..", ".."))
sys.path.insert(0, os.path.join(ROOT, "mailpath", "receive"))

from mailrunner_rx import crypto, spool  # noqa: E402
from mailrunner_rx.routes import Routes  # noqa: E402


class CryptoTest(unittest.TestCase):
    def test_shared_vectors(self):
        with open(os.path.join(ROOT, "tests", "shared", "crypto_vectors.json")) as fh:
            v = json.load(fh)
        key, nonce = base64.b64decode(v["key_b64"]), base64.b64decode(v["nonce_b64"])
        for case in v["cases"]:
            pt = base64.b64decode(case["plaintext_b64"])
            self.assertEqual(base64.b64decode(case["blob_b64"]), crypto.encrypt(key, pt, case["context"], nonce))
            self.assertEqual(pt, crypto.decrypt(key, base64.b64decode(case["blob_b64"]), case["context"]))

    def test_wrong_context_or_key_fails(self):
        key = os.urandom(32)
        blob = crypto.encrypt(key, b"secret", "spool:a:eml")
        with self.assertRaises(crypto.CryptoError):
            crypto.decrypt(key, blob, "spool:b:eml")
        with self.assertRaises(crypto.CryptoError):
            crypto.decrypt(os.urandom(32), blob, "spool:a:eml")


class RoutesTest(unittest.TestCase):
    def test_exact_address_wins_over_domain(self):
        r = Routes({"routes": {"example.org": {"project": "a/b"}, "info@example.org": {"project": "c/d"}}})
        self.assertEqual("c/d", r.lookup("Info@Example.org")["project"])
        self.assertEqual("a/b", r.lookup("x@example.org")["project"])
        self.assertIsNone(r.lookup("x@sub.example.org"))
        self.assertIsNone(r.lookup(""))


class DeliverTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        cfg, data = os.path.join(self.tmp.name, "etc"), os.path.join(self.tmp.name, "var")
        os.makedirs(os.path.join(cfg, "generated", "current"))
        for stage in spool.STAGES:
            os.makedirs(os.path.join(data, "receive", "spool", stage))
        self.key = os.urandom(32)
        with open(os.path.join(cfg, "node.key"), "w") as fh:
            fh.write(base64.b64encode(self.key).decode())
        with open(os.path.join(cfg, "generated", "current", "routes.json"), "w") as fh:
            json.dump({"routes": {"test.example.org": {"project_id": 7, "project": "smf/test", "profile": "analysis"}}}, fh)
        self.env = {**os.environ, "MR_CONFIG_DIR": cfg, "MR_DATA_DIR": data, "MR_NO_SYSLOG": "1"}
        self.spool_dir = os.path.join(data, "receive", "spool")

    def tearDown(self):
        self.tmp.cleanup()

    def deliver(self, rcpt, raw=b"Subject: hi\r\n\r\nbody\r\n"):
        return subprocess.run(
            [sys.executable, "-I", os.path.join(ROOT, "mailpath", "receive", "bin", "mr-deliver"),
             "--queue-id=4Xyz12AbCd", "--client=ipv6:2001:db8::1", "--helo=mx.example.net",
             "--sender=", f"--recipient={rcpt}", f"--final-recipient={rcpt}"],
            input=raw, env=self.env, capture_output=True)

    def test_stores_encrypted_entry_with_smtp_data(self):
        raw = b"Received: from x\r\nSubject: hi\r\n\r\nbody\r\n"
        self.assertEqual(0, self.deliver("abcdefgh@test.example.org", raw).returncode)
        ids = spool.ready_ids(self.spool_dir, "new")
        self.assertEqual(1, len(ids))
        with open(os.path.join(self.spool_dir, "new", ids[0] + ".eml"), "rb") as fh:
            self.assertNotIn(b"Subject", fh.read(), "mail must be encrypted on disk")
        meta = spool.read_meta(self.spool_dir, "new", ids[0], self.key)
        self.assertEqual("2001:db8::1", meta["client_ip"])
        self.assertEqual("", meta["mail_from"])
        self.assertEqual("smf/test", meta["project"])
        self.assertEqual(raw, spool.read_raw(self.spool_dir, "new", ids[0], self.key))
        self.assertEqual([], os.listdir(os.path.join(self.spool_dir, "tmp")))

    def test_unknown_route_is_tempfail(self):
        self.assertEqual(75, self.deliver("x@other.example").returncode)
        self.assertEqual([], spool.ready_ids(self.spool_dir, "new"))



class AnalysisConfigTest(unittest.TestCase):
    def test_reads_encrypted_settings_written_by_php_format(self):
        from mailrunner_rx import analysis_config
        key = os.urandom(32)
        with tempfile.TemporaryDirectory() as tmp:
            path = os.path.join(tmp, "analysis.json.enc")
            with open(path, "wb") as fh:
                fh.write(crypto.encrypt(key, b'{"projects":{"1":{"spamhaus_dqs_key":"abc"}}}\n', "generated:analysis.json"))
            self.assertEqual({"1": {"spamhaus_dqs_key": "abc"}}, analysis_config.load(key, path))
            self.assertEqual({}, analysis_config.load(key, os.path.join(tmp, "missing")))

    def test_blacklistmaster_skips_selftests(self):
        from mailrunner_rx import blacklistmaster
        self.assertEqual({"status": "skipped", "reason": "selftest"}, blacklistmaster.check("192.0.2.1", "selftest-abc", "u", "p"))


if __name__ == "__main__":
    unittest.main()
