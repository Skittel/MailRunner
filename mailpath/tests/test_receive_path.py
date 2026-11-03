"""Tests for the receive mail path. Run: python3 -I -m unittest discover -s mailpath/tests"""
import base64
import importlib.machinery
import importlib.util
import json
import os
import sys
import tempfile
import unittest
from unittest import mock

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, "..", ".."))
sys.path.insert(0, os.path.join(ROOT, "mailpath", "receive"))

from mailrunner_rx import crypto, message, secrets, store  # noqa: E402
from mailrunner_rx.routes import Routes  # noqa: E402

SAMPLE = (b"Received: from mx.example.net (mx.example.net [192.0.2.10])\r\n\tby node (Postfix) with ESMTPS id 4AbCdEfGhJ\r\n"
          b"From: =?utf-8?q?J=C3=B6rg?= <joerg@example.net>\r\nTo: test0001@test.example.org\r\nSubject: Hallo\r\n"
          b"Date: Sun, 01 Nov 2026 16:00:00 +0000\r\nMIME-Version: 1.0\r\n"
          b"Content-Type: multipart/mixed; boundary=\"b1\"\r\n\r\n"
          b"--b1\r\nContent-Type: text/plain\r\n\r\nText\r\n"
          b"--b1\r\nContent-Type: application/pdf\r\nContent-Disposition: attachment; filename=\"rechnung.pdf\"\r\n"
          b"Content-Transfer-Encoding: base64\r\n\r\nJVBERi0xLjQK\r\n--b1--\r\n")


def split_like_postfix(raw):
    """Headers as (name, value-with-leading-space) and body, as Postfix hands them to a milter with HDR_LEADSPC."""
    head, body = raw.split(b"\r\n\r\n", 1)
    headers = []
    for line in head.split(b"\r\n"):
        if line[:1] in (b" ", b"\t"):
            name, value = headers[-1]
            headers[-1] = (name, value + b"\n" + line)
        else:
            name, value = line.split(b":", 1)
            headers.append((name.decode(), value))
    return headers, body


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
        blob = crypto.encrypt(key, b"secret", "store:a:eml")
        with self.assertRaises(crypto.CryptoError):
            crypto.decrypt(key, blob, "store:b:eml")
        with self.assertRaises(crypto.CryptoError):
            crypto.decrypt(os.urandom(32), blob, "store:a:eml")


class RoutesTest(unittest.TestCase):
    def test_exact_address_wins_over_domain(self):
        r = Routes({"routes": {"example.org": {"project": "a/b"}, "info@example.org": {"project": "c/d"}}})
        self.assertEqual("c/d", r.lookup("Info@Example.org")["project"])
        self.assertEqual("a/b", r.lookup("x@example.org")["project"])
        self.assertIsNone(r.lookup("x@sub.example.org"))


class MessageTest(unittest.TestCase):
    def test_rebuild_is_byte_exact(self):
        headers, body = split_like_postfix(SAMPLE)
        self.assertEqual(SAMPLE, message.build_raw(headers, [body[:10], body[10:]]))

    def test_summary_and_attachments(self):
        s = message.summary(SAMPLE)
        self.assertEqual("Jörg <joerg@example.net>", s["header_from"])
        self.assertEqual("Hallo", s["subject"])
        self.assertEqual([{"name": "rechnung.pdf", "size": 9, "content_type": "application/pdf", "disposition": "attachment"}],
                         message.attachments(SAMPLE))


class StoreTest(unittest.TestCase):
    def test_save_writes_encrypted_files_and_handoff(self):
        key = os.urandom(32)
        with tempfile.TemporaryDirectory() as tmp:
            store_dir, spool_dir = os.path.join(tmp, "store"), os.path.join(tmp, "spool")
            os.makedirs(os.path.join(spool_dir, "analyzed"))
            mid = store.new_id()
            meta = store.save(store_dir, spool_dir, key, mid, {"debug": {"x": 1}}, {"spool_id": mid}, raw=b"RAW")
            self.assertTrue(meta["analysis_path"].endswith(f"{mid}.analysis.json.enc"))
            with open(os.path.join(store_dir, meta["raw_path"]), "rb") as fh:
                self.assertEqual(b"RAW", crypto.decrypt(key, fh.read(), f"store:{mid}:eml"))
            with open(os.path.join(spool_dir, "analyzed", f"{mid}.json"), "rb") as fh:
                handoff = json.loads(crypto.decrypt(key, fh.read(), f"spool:{mid}:analyzed"))
            self.assertEqual(meta, handoff)
            meta2 = store.save(store_dir, spool_dir, key, store.new_id(), {}, {}, raw=None)
            self.assertIsNone(meta2["raw_path"])


class SecretsTest(unittest.TestCase):
    def test_reads_env_file_ignores_comments_and_empty_values(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = os.path.join(tmp, "secrets.env")
            with open(path, "w") as fh:
                fh.write("# comment\nSPAMHAUS_DQS_KEY=abc123\nBLM_USER = 'user'\nBLM_PASS=\n")
            self.assertEqual({"SPAMHAUS_DQS_KEY": "abc123", "BLM_USER": "user"}, secrets.load(path))
            self.assertEqual({}, secrets.load(os.path.join(tmp, "missing")))

    def test_common_secrets_from_admin_local_values_win(self):
        from unittest import mock
        with tempfile.TemporaryDirectory() as tmp:
            common, local = os.path.join(tmp, "common.env"), os.path.join(tmp, "secrets.env")
            with open(common, "w") as fh:
                fh.write("# revision=abcd-1234\nSPAMHAUS_DQS_KEY=central\nBLM_USER=central-user\n")
            with open(local, "w") as fh:
                fh.write("SPAMHAUS_DQS_KEY=local\nBLM_USER=\n")  # empty local value: the central one stays
            with mock.patch.object(secrets.config, "SECRETS_FILE", local):
                self.assertEqual({"SPAMHAUS_DQS_KEY": "local", "BLM_USER": "central-user"}, secrets.load(common=common))

    def test_blacklistmaster_skips_selftests_and_non_public_addresses(self):
        from mailrunner_rx import blacklistmaster
        self.assertEqual({"status": "skipped", "reason": "selftest"}, blacklistmaster.check("192.0.2.1", "selftest-abc", "u", "p"))
        self.assertEqual("skipped", blacklistmaster.check("127.0.0.1", "abcdefgh", "u", "p")["status"])
        self.assertEqual("skipped", blacklistmaster.check("10.1.2.3", "abcdefgh", "u", "p")["status"])

    def test_analysis_reads_queue_id_and_tls_from_our_received_header(self):
        from mailrunner_rx import analyzer  # noqa: F401  (puts the vendored library on sys.path)
        import re
        hdr = message.received_header("mx.example.net", "2001:db8::1", "mx.example.net", "node.example", "4AbCdEfGhJ",
                                      "x@example.org", "TLSv1.3", "TLS_AES_256_GCM_SHA384", "256").decode()
        # the same regular expressions as smartmailflow.analysis.analyze()
        self.assertEqual("4AbCdEfGhJ", re.search(r"\bid\s+([A-Za-z0-9]{10,16})\b", hdr).group(1))
        t = re.search(r"\bTLSv\s*([0-9.]+).*?\bcipher\s+([A-Za-z0-9_\-]+)", hdr, re.I | re.S)
        self.assertEqual(("1.3", "TLS_AES_256_GCM_SHA384"), t.groups())
        self.assertIn("[IPv6:2001:db8::1]", hdr)


class CacheTest(unittest.TestCase):
    def test_ttl_cache_expires_and_limits_size(self):
        from mailrunner_rx.cache import TTLCache
        c = TTLCache(3600, max_entries=2)
        c.put("a", 1)
        c.put("b", 2)
        c.put("c", 3)
        self.assertEqual(2, len(c))
        self.assertIsNone(c.get("a"))
        self.assertEqual(3, c.get("c")[0])
        with mock.patch("time.time", return_value=c.get("c")[1] + 3600):
            self.assertIsNone(c.get("c"))

    def test_spamhaus_answers_cached_but_not_errors_and_other_names_untouched(self):
        from mailrunner_rx import analyzer
        analyzer.SPAMHAUS_CACHE = analyzer.TTLCache(3600)
        calls = []
        answers = {"2.0.0.127.key.zen.dq.spamhaus.net": (["127.0.0.2"], None),
                   "1.2.0.192.key.zen.dq.spamhaus.net": ([], "timeout"),
                   "example.org": (["192.0.2.1"], None)}

        def fake(self, name, rtype):
            calls.append(name)
            return answers[name]
        with mock.patch.object(analyzer.smf_dnsclient.DNSClient, "_lookup", fake):
            for _ in range(2):
                c = analyzer.CachingDNSClient()
                for name in answers:
                    c._lookup(name, "A")
        self.assertEqual(1, calls.count("2.0.0.127.key.zen.dq.spamhaus.net"), "listed answer cached")
        self.assertEqual(2, calls.count("1.2.0.192.key.zen.dq.spamhaus.net"), "timeout not cached")
        self.assertEqual(2, calls.count("example.org"), "other names only via unbound (TTL)")
        self.assertIn("2.0.0.127.key.zen.dq.spamhaus.net", c.cache_hits)
        debug = {"dnsbl": {"lists": [{"zone": "zen.dq.spamhaus.net", "name": "Spamhaus ZEN (DQS)"}, {"zone": "bl.spamcop.net", "name": "SpamCop"}]}}
        analyzer.mark_cached_dnsbl(debug, c)
        self.assertTrue(debug["dnsbl"]["lists"][0]["cached"])
        self.assertNotIn("cached", debug["dnsbl"]["lists"][1])

    def test_blacklistmaster_cached_per_ip_errors_not(self):
        from mailrunner_rx import analyzer
        analyzer.BLM_CACHE = analyzer.TTLCache(3600)
        results = iter([{"status": "Not blacklisted"}, "http_500", "http_500"])
        with mock.patch.object(analyzer.blacklistmaster, "check", side_effect=lambda *a: next(results)) as check:
            first = analyzer.blacklistmaster_cached("198.51.100.7", "abcdefgh", "u", "p")
            second = analyzer.blacklistmaster_cached("198.51.100.7", "abcdefgh", "u", "p")
            analyzer.blacklistmaster_cached("198.51.100.8", "abcdefgh", "u", "p")
            analyzer.blacklistmaster_cached("198.51.100.8", "abcdefgh", "u", "p")
        self.assertEqual({"status": "Not blacklisted"}, first)
        self.assertTrue(second["cached"])
        self.assertIn("cached_at", second)
        self.assertEqual(3, check.call_count, "first IP once, error for second IP twice")


class IpregistryTest(unittest.TestCase):
    def test_parse_picks_wanted_fields(self):
        from mailrunner_rx import ipregistry
        data = {"ip": "2a01:111:f403:c20c::1", "type": "IPv6",
                "connection": {"asn": 8075, "organization": "Microsoft Corporation", "domain": "microsoft.com", "route": "2a01:111::/32", "type": "business"},
                "location": {"country": {"code": "DE", "name": "Germany"}, "city": "Frankfurt am Main", "continent": {"code": "EU", "name": "Europe"}},
                "carrier": {"name": None}, "security": {"is_threat": False}}
        r = ipregistry.parse(data)
        self.assertEqual((8075, "Microsoft Corporation", "DE", "Frankfurt am Main", "EU", None, False),
                         (r["asn"], r["as_organization"], r["country_code"], r["city"], r["continent_code"], r["carrier_name"], r["is_threat"]))

    def test_private_skipped_and_errors_never_contain_key(self):
        from mailrunner_rx import ipregistry
        self.assertEqual("skipped", ipregistry.lookup("192.168.1.1", "SECRETKEY")["status"])
        with mock.patch("requests.get", side_effect=RuntimeError("https://api.ipregistry.co/1.1.1.1?key=SECRETKEY")):
            r = ipregistry.lookup("1.1.1.1", "SECRETKEY")
        self.assertEqual({"status": "error", "error": "RuntimeError"}, r)

    def test_ipregistry_cached_only_ok(self):
        from mailrunner_rx import analyzer
        analyzer.IPREGISTRY_CACHE = analyzer.TTLCache(3600)
        answers = iter([{"status": "ok", "asn": 1}, {"status": "error", "error": "TOO_MANY_REQUESTS"}, {"status": "ok", "asn": 2}])
        with mock.patch.object(analyzer.ipregistry, "lookup", side_effect=lambda *a: next(answers)) as lookup:
            self.assertEqual(1, analyzer.ipregistry_cached("1.1.1.1", "k")["asn"])
            self.assertTrue(analyzer.ipregistry_cached("1.1.1.1", "k")["cached"])
            self.assertEqual("error", analyzer.ipregistry_cached("8.8.8.8", "k")["status"])
            self.assertEqual(2, analyzer.ipregistry_cached("8.8.8.8", "k")["asn"])
        self.assertEqual(3, lookup.call_count)


def load_milter_module():
    path = os.path.join(ROOT, "mailpath", "receive", "bin", "mr-milter")
    loader = importlib.machinery.SourceFileLoader("mr_milter", path)
    spec = importlib.util.spec_from_loader("mr_milter", loader)
    module = importlib.util.module_from_spec(spec)
    loader.exec_module(module)
    return module


class MilterTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.mm = load_milter_module()
        cfg = self.mm.config
        self.key = os.urandom(32)
        keyfile = os.path.join(self.tmp.name, "node.key")
        with open(keyfile, "w") as fh:
            fh.write(base64.b64encode(self.key).decode())
        routes = os.path.join(self.tmp.name, "routes.json")
        with open(routes, "w") as fh:
            json.dump({"routes": {"test.example.org": {"project_id": 1, "project": "smf/test", "profile": "analysis"},
                                  "in.example.org": {"project_id": 2, "project": "acme/in", "profile": "checked"}},
                       "role_localparts": ["postmaster", "abuse"]}, fh)
        self.store_dir, self.spool_dir = os.path.join(self.tmp.name, "store"), os.path.join(self.tmp.name, "spool")
        os.makedirs(os.path.join(self.spool_dir, "analyzed"))
        self.patches = [mock.patch.object(cfg, "NODE_KEY_FILE", keyfile), mock.patch.object(cfg, "ROUTES_FILE", routes),
                        mock.patch.object(cfg, "STORE_DIR", self.store_dir), mock.patch.object(cfg, "SPOOL_DIR", self.spool_dir),
                        mock.patch.object(cfg, "SECRETS_FILE", os.path.join(self.tmp.name, "none.env")),
                        mock.patch.object(self.mm, "log", lambda *a: None)]
        for p in self.patches:
            p.start()
        self.mm.ROUTES = self.mm.RouteCache()

    def tearDown(self):
        for p in self.patches:
            p.stop()
        self.tmp.cleanup()

    def session(self, rcpts, analyze):
        m = self.mm.ReceiveMilter()
        macros = {"i": "4AbCdEfGhJ", "j": "node.example", "{tls_version}": "TLSv1.3", "{cipher}": "TLS_AES_256_GCM_SHA384", "{cipher_bits}": "256"}
        m.getsymval = macros.get
        m.replies = []
        m.setreply = lambda *args: m.replies.append(args)
        m.connect("mx.example.net", self.mm.socket.AF_INET, ("192.0.2.10", 25))
        m.hello("mx.example.net")
        m.envfrom("<joerg@example.net>")
        results = [m.envrcpt(f"<{r}>") for r in rcpts]
        headers, body = split_like_postfix(SAMPLE)
        for name, value in headers:
            m.header(name, value)
        m.body(body)
        with mock.patch.object(self.mm.analyzer, "analyze", analyze):
            return m, results, m.eom()

    @staticmethod
    def fake_analysis(raw, envelope, creds, mail_id, timeout):
        return {"queue_id": "4AbCdEfGhJ", "smtp_mail_from": envelope["mail_from"], "smtp_rcpt_to": envelope["rcpt"],
                "header_from": "x", "header_to": "y", "subject": "Hallo", "date_hdr": "d",
                "spf_status": "pass", "dkim_status": "none", "debug": {"spf": {"result": "pass"}}}

    def handoffs(self):
        out = []
        for f in sorted(os.listdir(os.path.join(self.spool_dir, "analyzed"))):
            mid = f[:-5]
            with open(os.path.join(self.spool_dir, "analyzed", f), "rb") as fh:
                out.append(json.loads(crypto.decrypt(self.key, fh.read(), f"spool:{mid}:analyzed")))
        return out

    def test_profile_analysis_keeps_only_analysis_and_answers_250(self):
        m, rc, result = self.session(["test0001@test.example.org"], self.fake_analysis)
        self.assertEqual(self.mm.Milter.DISCARD, result)  # DISCARD = 250 to the client
        [meta] = self.handoffs()
        self.assertIsNone(meta["raw_path"])
        self.assertEqual("joerg@example.net", meta["mail_from"])
        self.assertEqual("192.0.2.10", meta["client_ip"])
        self.assertEqual("rechnung.pdf", meta["attachments"][0]["name"])
        with open(os.path.join(self.store_dir, meta["analysis_path"]), "rb") as fh:
            doc = json.loads(crypto.decrypt(self.key, fh.read(), f"store:{meta['spool_id']}:analysis"))
        self.assertEqual("test0001", doc["record"]["mail_id"])
        self.assertEqual({"spf": {"result": "pass"}}, doc["debug"])

    def test_abuse_mail_keeps_raw_even_with_profile_analysis(self):
        m, rc, result = self.session(["abuse@test.example.org"], self.fake_analysis)
        self.assertEqual(self.mm.Milter.DISCARD, result)
        [meta] = self.handoffs()
        self.assertEqual("abuse", meta["role"])
        self.assertIsNotNone(meta["raw_path"])
        self.assertTrue(os.path.exists(os.path.join(self.store_dir, meta["raw_path"])))

    def test_profile_checked_keeps_raw_mail_with_own_received_header(self):
        seen = {}

        def capture(raw, envelope, creds, mail_id, timeout):
            seen["raw"] = raw
            return self.fake_analysis(raw, envelope, creds, mail_id, timeout)
        _, _, result = self.session(["a@in.example.org"], capture)
        self.assertEqual(self.mm.Milter.DISCARD, result)
        [meta] = self.handoffs()
        with open(os.path.join(self.store_dir, meta["raw_path"]), "rb") as fh:
            stored = crypto.decrypt(self.key, fh.read(), f"store:{meta['spool_id']}:eml")
        self.assertEqual(seen["raw"], stored)
        self.assertTrue(stored.endswith(SAMPLE), "original message unchanged after our Received header")
        top = stored[:stored.index(SAMPLE)].decode()
        self.assertIn("(using TLSv1.3 with cipher TLS_AES_256_GCM_SHA384 (256/256 bits))", top)
        self.assertIn("by node.example (Postfix) with ESMTPS id 4AbCdEfGhJ", top)
        self.assertIn("[192.0.2.10]", top)

    def test_second_project_in_one_transaction_is_deferred(self):
        _, rc, _ = self.session(["test0001@test.example.org", "a@in.example.org"], self.fake_analysis)
        self.assertEqual([self.mm.Milter.CONTINUE, self.mm.Milter.TEMPFAIL], rc)

    def test_timeout_analysis_profile_accepts_checked_profile_tempfails(self):
        def slow(*a):
            raise self.mm.analyzer.AnalysisTimeout("x")
        _, _, result = self.session(["test0001@test.example.org"], slow)
        self.assertEqual(self.mm.Milter.DISCARD, result)
        self.assertEqual("timeout", self.handoffs()[0]["analysis_status"])
        m, _, result = self.session(["a@in.example.org"], slow)
        self.assertEqual(self.mm.Milter.TEMPFAIL, result)
        self.assertEqual(("451", "4.7.0", "Message check not finished, please try again later"), m.replies[-1])

    def test_internal_error_is_tempfail_never_250(self):
        def broken(*a):
            raise RuntimeError("boom")
        m, _, result = self.session(["test0001@test.example.org"], broken)
        self.assertEqual(self.mm.Milter.TEMPFAIL, result)
        self.assertEqual([], self.handoffs())


if __name__ == "__main__":
    unittest.main()
