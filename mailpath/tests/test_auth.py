"""mr-auth: login rules, rate limit and the Dovecot auth protocol as Postfix speaks it."""
import asyncio
import base64
import importlib.machinery
import importlib.util
import json
import os
import sys
import tempfile
import time
import unittest

import nacl.pwhash

sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", "receive"))
from mailrunner_rx import auth  # noqa: E402

FAST = dict(opslimit=nacl.pwhash.argon2id.OPSLIMIT_MIN, memlimit=nacl.pwhash.argon2id.MEMLIMIT_MIN)


def hashed(pw):
    return nacl.pwhash.argon2id.str(pw.encode(), **FAST).decode()


def entry(**kw):
    e = {"credential_id": "mrc_test", "project_id": 7, "project": "acme/app", "profile": "checked_auth",
         "hash": hashed("current-password-1234"), "previous_hash": None, "previous_valid_until": None,
         "tls_required": True, "allowed_networks": None}
    e.update(kw)
    return e


class LoginRules(unittest.TestCase):
    def test_password_tls_networks_and_grace(self):
        e = entry()
        self.assertIsNone(auth.check_login(e, "current-password-1234", True, "198.51.100.7"))
        self.assertEqual("wrong password", auth.check_login(e, "nope", True, "198.51.100.7"))
        self.assertEqual("TLS required", auth.check_login(e, "current-password-1234", False, "198.51.100.7"))
        self.assertIsNone(auth.check_login(entry(tls_required=False), "current-password-1234", False, "198.51.100.7"))
        self.assertEqual("unknown user", auth.check_login(None, "x", True, "198.51.100.7"))
        n = entry(allowed_networks=["198.51.100.0/24", "2001:db8::/32"])
        self.assertIsNone(auth.check_login(n, "current-password-1234", True, "2001:db8::5"))
        self.assertEqual("network not allowed", auth.check_login(n, "current-password-1234", True, "203.0.113.9"))
        g = entry(previous_hash=hashed("old-password-123456"), previous_valid_until=time.time() + 60)
        self.assertIsNone(auth.check_login(g, "old-password-123456", True, "198.51.100.7"))
        g["previous_valid_until"] = time.time() - 1
        self.assertEqual("wrong password", auth.check_login(g, "old-password-123456", True, "198.51.100.7"))

    def test_php_hash_format(self):
        # PHP password_hash(PASSWORD_ARGON2ID) default parameters
        php = "$argon2id$v=19$m=65536,t=4,p=1$" + "c2FsdHNhbHRzYWx0c2FsdA$" + "x" * 43
        self.assertFalse(auth.verify_hash(php, "anything"))   # malformed digest: False, no exception

    def test_plain_decoding(self):
        ok = base64.b64encode(b"\0user@example.org\0secret").decode()
        self.assertEqual(("user@example.org", "secret"), auth.decode_plain(ok))
        same = base64.b64encode(b"u\0u\0p").decode()
        self.assertEqual(("u", "p"), auth.decode_plain(same))
        other = base64.b64encode(b"admin\0u\0p").decode()
        self.assertIsNone(auth.decode_plain(other))
        self.assertIsNone(auth.decode_plain("not base64!"))

    def test_limiter_blocks_after_repeated_failures(self):
        now = [1000.0]
        lim = auth.Limiter(now=lambda: now[0])
        for _ in range(auth.FAIL_LIMIT - 1):
            lim.failed("198.51.100.7")
        self.assertFalse(lim.is_blocked("198.51.100.7"))
        lim.failed("198.51.100.7")
        self.assertTrue(lim.is_blocked("198.51.100.7"))
        self.assertFalse(lim.is_blocked("198.51.100.8"))
        now[0] += auth.BLOCK_SECONDS + 1
        self.assertFalse(lim.is_blocked("198.51.100.7"))


class Protocol(unittest.TestCase):
    """Runs mr-auth's connection handler against a fake Postfix client."""

    def setUp(self):
        self.dir = tempfile.mkdtemp()
        path = os.path.join(self.dir, "credentials.json")
        with open(path, "w") as fh:
            json.dump({"credentials": {"app@example.org": entry(), "old@example.org": entry(tls_required=False)}}, fh)
        loader = importlib.machinery.SourceFileLoader("mr_auth", os.path.join(os.path.dirname(__file__), "..", "receive", "bin", "mr-auth"))
        spec = importlib.util.spec_from_loader("mr_auth", loader)
        self.m = importlib.util.module_from_spec(spec)
        loader.exec_module(self.m)
        self.m.CREDENTIALS = auth.Credentials(path)
        self.m.LIMITER = auth.Limiter()
        self.m.FAIL_DELAY = 0
        self.m.log = lambda level, text: None

    async def session(self, lines):
        server = await asyncio.start_server(self.m.handle, "127.0.0.1", 0)
        port = server.sockets[0].getsockname()[1]
        r, w = await asyncio.open_connection("127.0.0.1", port)
        hello = []
        while (line := (await r.readline()).decode()) != "DONE\n":
            hello.append(line.split("\t")[0])
        w.write(b"VERSION\t1\t2\nCPID\t42\n")
        out = []
        for line in lines:
            w.write((line + "\n").encode())
            await w.drain()
            out.append((await r.readline()).decode().rstrip("\n"))
        w.close()
        server.close()
        return hello, out

    def run_session(self, lines):
        return asyncio.run(self.session(lines))

    def test_plain_login_ok_and_tls_rule(self):
        resp = base64.b64encode(b"\0app@example.org\0current-password-1234").decode()
        hello, out = self.run_session([
            f"AUTH\t1\tPLAIN\tservice=submission\tnologin\tlip=192.0.2.1\trip=198.51.100.7\tsecured\tresp={resp}",
            f"AUTH\t2\tPLAIN\tservice=smtp\tnologin\tlip=192.0.2.1\trip=198.51.100.7\tresp={resp}",
        ])
        self.assertEqual(["VERSION", "MECH", "MECH", "SPID", "CUID", "COOKIE"], hello)
        self.assertEqual("OK\t1\tuser=app@example.org", out[0])
        self.assertEqual("FAIL\t2\tuser=app@example.org", out[1])   # no TLS, credential needs TLS

    def test_login_mechanism_and_plaintext_credential(self):
        b = lambda s: base64.b64encode(s.encode()).decode()
        hello, out = self.run_session([
            "AUTH\t5\tLOGIN\tservice=smtp\tnologin\trip=198.51.100.7",
            f"CONT\t5\t{b('old@example.org')}",
            f"CONT\t5\t{b('current-password-1234')}",
        ])
        self.assertEqual(f"CONT\t5\t{b('Username:')}", out[0])
        self.assertEqual(f"CONT\t5\t{b('Password:')}", out[1])
        self.assertEqual("OK\t5\tuser=old@example.org", out[2])   # allowed without TLS


if __name__ == "__main__":
    unittest.main()
