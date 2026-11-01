#!/usr/bin/env python3
"""Analysetests ohne SMTP: Rohmail + Verbindungsdaten + feste DNS-Antworten (FakeDNS).

  python3 -m unittest tests/test_analysis.py -v      (aus dem Repo-Verzeichnis)

Deckt auch Fälle ab, die per SMTP nicht erzeugbar sind (DNS-Timeouts/SERVFAIL, DNSBL-Treffer,
gesperrter Resolver, IPv6-Absender ohne PTR, …).
"""
import json
import os
import sys
import unittest

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(HERE, "..", "app"))
sys.path.insert(0, HERE)

from mailtools import arc_seal, build, dkim_record, sign, smime_sign                        # noqa: E402
from smartmailflow import analysis, auth, dnsanalysis, net                         # noqa: E402
from smartmailflow.dnsclient import FakeDNS                           # noqa: E402

IP4, IP6 = "192.0.2.10", "2001:db8::10"
D = "example.test"


def zone(**over):
    """Gesunde Basis-Zone; einzelne Einträge per Keyword überschreiben/entfernen (Wert None)."""
    z = {
        (D, "TXT"): [f"v=spf1 ip4:{IP4} ip6:{IP6} -all"],
        (D, "MX"): [f"10 mx.{D}."],
        (f"mx.{D}", "A"): ["192.0.2.25"],
        (f"_dmarc.{D}", "TXT"): ["v=DMARC1; p=reject"],
        (f"s2048._domainkey.{D}", "TXT"): [dkim_record("s2048.key")],
        (f"s1024._domainkey.{D}", "TXT"): [dkim_record("s1024.key")],
        (f"ed25519._domainkey.{D}", "TXT"): [dkim_record("ed25519.key")],
        (f"revoked._domainkey.{D}", "TXT"): ["v=DKIM1; k=rsa; p="],
        ("10.2.0.192.in-addr.arpa", "PTR"): [f"mail.{D}."],
        (f"mail.{D}", "A"): [IP4],
        (f"mail.{D}", "AAAA"): [IP6],
    }
    for k, v in over.items():
        name, rtype = k.rsplit("__", 1)
        key = (name.replace("_DOT_", ".").replace("_US_", "_"), rtype)
        if v is None:
            z.pop(key, None)
        else:
            z[key] = v
    return z


def run(raw, zone_dict=None, ip=IP4, helo=f"mail.{D}", mail_from=f"bounce@{D}", rcpt="t1@smartmailflow.email",
        config=None):
    dnsc = FakeDNS(zone_dict if zone_dict is not None else zone())
    res = analysis.analyze(raw, {"client_ip": ip, "helo": helo, "mail_from": mail_from, "rcpt": rcpt}, dnsc, config)
    json.dumps(res["debug"])          # muss serialisierbar sein (DB)
    return res, res["debug"]


def signed(selector="s2048", keyfile="s2048.key", frm=f"ok@{D}", **kw):
    return sign(build(frm, "t1@smartmailflow.email", **kw), selector, keyfile, D)


class SPF(unittest.TestCase):
    def spf(self, record, ip=IP4, mail_from=f"x@{D}", helo=f"mail.{D}", z=None):
        z = z or zone()
        if record is None:
            z.pop((D, "TXT"), None)
        elif record != "keep":
            z[(D, "TXT")] = record
        dnsc = FakeDNS(z)
        return auth.check_spf(dnsc, ip, mail_from, helo)["result"]

    def test_pass(self):            self.assertEqual(self.spf("keep"), "pass")
    def test_pass_ipv6(self):       self.assertEqual(self.spf("keep", ip=IP6), "pass")
    def test_fail(self):            self.assertEqual(self.spf(["v=spf1 -all"]), "fail")
    def test_softfail(self):        self.assertEqual(self.spf(["v=spf1 ~all"]), "softfail")
    def test_neutral(self):         self.assertEqual(self.spf(["v=spf1 ?all"]), "neutral")
    def test_none(self):            self.assertEqual(self.spf(None), "none")
    def test_permerror_syntax(self): self.assertEqual(self.spf(["v=spf1 ip4:999.1.1.1 -all"]), "permerror")
    def test_permerror_double(self): self.assertEqual(self.spf(["v=spf1 -all", "v=spf1 ~all"]), "permerror")
    def test_temperror_timeout(self): self.assertEqual(self.spf("timeout"), "temperror")
    def test_temperror_servfail(self): self.assertEqual(self.spf("servfail"), "temperror")

    def test_null_sender_uses_helo(self):
        z = zone()
        z[(f"mail.{D}", "TXT")] = ["v=spf1 -all"]
        r = auth.check_spf(FakeDNS(z), IP4, "", f"mail.{D}")
        self.assertEqual((r["result"], r["identity"], r["domain"]), ("fail", "helo", f"mail.{D}"))


class DKIM(unittest.TestCase):
    def status(self, raw, z=None):
        _, dbg = run(raw, z)
        return dbg["dkim"]["result"], dbg["dkim"]["signatures"]

    def test_pass_2048(self):
        st, sigs = self.status(signed())
        self.assertEqual((st, sigs[0]["key_bits"], sigs[0]["key_strength"]), ("pass", 2048, "ok"))

    def test_pass_1024_warning(self):
        st, sigs = self.status(signed("s1024", "s1024.key"))
        self.assertEqual((st, sigs[0]["key_bits"], sigs[0]["key_strength"]), ("pass", 1024, "ok"))
        self.assertIn("2048 Bit ist empfohlen", sigs[0]["recommendation"])
        self.assertIsNone(self.status(signed())[1][0]["recommendation"])

    def test_ed25519(self):
        st, sigs = self.status(signed("ed25519", "ed25519.key"))
        self.assertEqual((st, sigs[0]["key_algorithm"], sigs[0]["key_bits"]), ("pass", "ed25519", 256))

    def test_body_modified(self):
        raw = signed().replace(b"Diese Mail wurde", b"Diese Mail WURDE")
        self.assertEqual(self.status(raw)[0], "fail")

    def test_wrong_key(self):
        self.assertEqual(self.status(signed("s2048", "other.key"))[0], "fail")

    def test_missing_key(self):
        self.assertEqual(self.status(signed("missing", "s2048.key"))[0], "permerror")

    def test_key_timeout(self):
        z = zone()
        z[(f"s2048._domainkey.{D}", "TXT")] = "timeout"
        self.assertEqual(self.status(signed(), z)[0], "temperror")

    def test_revoked(self):
        self.assertEqual(self.status(signed("revoked", "s2048.key"))[0], "fail")

    def test_unsigned(self):
        self.assertEqual(self.status(build(f"ok@{D}", "t1@smartmailflow.email"))[0], "none")

    def test_two_signatures_one_bad(self):
        raw = sign(signed("s2048", "other.key"), "s1024", "s1024.key", D)
        st, sigs = self.status(raw)
        self.assertEqual((st, sorted(s["result"] for s in sigs)), ("pass", ["fail", "pass"]))


class DMARC(unittest.TestCase):
    def dmarc(self, raw, z=None, mail_from=f"bounce@{D}", ip=IP4):
        _, dbg = run(raw, z, mail_from=mail_from, ip=ip)
        return dbg["dmarc"]

    def test_pass_via_dkim_and_spf(self):
        d = self.dmarc(signed())
        self.assertEqual((d["result"], d["spf_aligned"], d["dkim_aligned"]), ("pass", True, True))

    def test_pass_via_dkim_only(self):
        d = self.dmarc(signed(), ip="198.51.100.1")         # SPF fail
        self.assertEqual((d["result"], d["spf_aligned"], d["dkim_aligned"]), ("pass", False, True))

    def test_fail(self):
        d = self.dmarc(build(f"ok@{D}", "t"), ip="198.51.100.1")
        self.assertEqual((d["result"], d["published_policy"], d["disposition"]), ("fail", "reject", "reject"))

    def test_none_without_record(self):
        z = zone()
        del z[(f"_dmarc.{D}", "TXT")]
        self.assertEqual(self.dmarc(signed(), z)["result"], "none")

    def test_temperror(self):
        z = zone()
        z[(f"_dmarc.{D}", "TXT")] = "timeout"
        self.assertEqual(self.dmarc(signed(), z)["result"], "temperror")

    def test_org_domain_fallback_sp(self):
        z = zone()
        z[(f"_dmarc.{D}", "TXT")] = ["v=DMARC1; p=reject; sp=quarantine"]
        d = self.dmarc(signed(frm=f"x@sub.{D}"), z)
        self.assertEqual((d["result"], d["record_source"], d["effective_policy"], d["disposition"]),
                         ("pass", D, "quarantine", "none"))

    def test_strict_unaligned(self):
        z = zone()
        z[(f"_dmarc.sub.{D}", "TXT")] = ["v=DMARC1; p=reject; adkim=s; aspf=s"]
        self.assertEqual(self.dmarc(signed(frm=f"x@sub.{D}"), z)["result"], "fail")

    def test_multiple_from_permerror(self):
        raw = build(f"a@{D}, b@other.test", "t")
        self.assertEqual(self.dmarc(raw)["result"], "permerror")


class Envelope(unittest.TestCase):
    def env(self, z, dom=D):
        return net.check_envelope_dns(FakeDNS(z), dom)

    def test_pass(self):
        self.assertEqual(self.env(zone())["status"], "pass")

    def test_cname_dead_target(self):          # Regressionsfall sushi-for-friends
        z = {("psrp.shop.test", "CNAME"): ["rp.mail01.test."], ("rp.mail01.test", "TXT"): ["v=spf1 -all"]}
        r = self.env(z, "psrp.shop.test")
        self.assertEqual((r["status"], r["reasons"], r["cname_chain"]), ("fail", ["no_mx_no_a"], ["rp.mail01.test"]))

    def test_null_mx(self):
        self.assertEqual(self.env(zone(**{f"{D}__MX": ["0 ."]}))["reasons"], ["null_mx"])

    def test_implicit_a(self):
        z = zone(**{f"{D}__MX": None})
        z[(D, "A")] = ["192.0.2.1"]
        self.assertEqual(self.env(z)["status"], "warn")

    def test_mx_timeout(self):
        self.assertEqual(self.env(zone(**{f"{D}__MX": "timeout"}))["status"], "temperror")

    def test_nxdomain(self):
        self.assertEqual(self.env({}, "gibtsnicht.test")["reasons"], ["nxdomain"])

    def test_null_sender(self):
        self.assertEqual(self.env({}, "")["status"], "not_tested")

    def test_cname_loop(self):
        z = {("a.test", "CNAME"): ["b.test."], ("b.test", "CNAME"): ["a.test."]}
        self.assertEqual(self.env(z, "a.test")["reasons"], ["cname_loop"])


class FCrDNS_HELO_Protocol(unittest.TestCase):
    def test_fcrdns_pass(self):
        self.assertEqual(net.check_fcrdns(FakeDNS(zone()), IP4)["status"], "pass")

    def test_fcrdns_no_ptr(self):
        r = net.check_fcrdns(FakeDNS(zone()), "198.51.100.7")
        self.assertEqual((r["status"], r["reason"]), ("fail", "kein PTR"))

    def test_fcrdns_mismatch(self):
        z = zone()
        z[(f"mail.{D}", "A")] = ["192.0.2.99"]
        self.assertEqual(net.check_fcrdns(FakeDNS(z), IP4)["status"], "fail")

    def test_fcrdns_temperror(self):
        z = zone()
        z[("10.2.0.192.in-addr.arpa", "PTR")] = "timeout"
        self.assertEqual(net.check_fcrdns(FakeDNS(z), IP4)["status"], "temperror")

    def test_fcrdns_ipv6(self):
        z = zone()
        z[(net.reverse_name(IP6), "PTR")] = [f"mail.{D}."]
        self.assertEqual(net.check_fcrdns(FakeDNS(z), IP6)["status"], "pass")

    def test_helo(self):
        dnsc = FakeDNS(zone())
        self.assertEqual(net.check_helo(dnsc, f"mail.{D}", IP4)["status"], "pass")
        self.assertEqual(net.check_helo(dnsc, f"mail.{D}", "198.51.100.1")["status"], "warn")
        self.assertEqual(net.check_helo(dnsc, "localhost", IP4)["status"], "fail")
        self.assertEqual(net.check_helo(dnsc, f"gibtsnicht.{D}", IP4)["status"], "fail")
        self.assertEqual(net.check_helo(dnsc, f"[{IP4}]", IP4)["status"], "warn")

    def test_protocol(self):
        self.assertEqual(net.check_protocol(IP4, "ip4.smartmailflow.email")["status"], "pass")
        self.assertEqual(net.check_protocol(IP4, "ip6.smartmailflow.email")["status"], "not_tested")
        self.assertEqual(net.check_protocol(IP6, "smartmailflow.email")["requested"], "auto")


class DNSBL(unittest.TestCase):
    def lists(self, z, ip="127.0.0.2"):
        return {e["zone"]: e for e in net.check_dnsbl(FakeDNS(z), ip)["lists"]}

    def test_rfc_testpoint_listed(self):
        r = self.lists({("2.0.0.127.bl.spamcop.net", "A"): ["127.0.0.2"],
                        ("2.0.0.127.bl.spamcop.net", "TXT"): ["Blocked - see https://www.spamcop.net"]})
        self.assertEqual((r["bl.spamcop.net"]["status"], r["psbl.surriel.com"]["status"]), ("listed", "not_listed"))

    def test_spamhaus_blocked_resolver_is_error_not_listing(self):
        r = self.lists({("2.0.0.127.zen.spamhaus.org", "A"): ["127.255.255.254"]})
        self.assertEqual(r["zen.spamhaus.org"]["status"], "error")
        self.assertIn("öffentlichen", r["zen.spamhaus.org"]["error"])

    def test_spamhaus_dqs_key_masked(self):
        key = "abcdefghijklmnopqrstuvwxyz"
        dnsc = FakeDNS({(f"2.0.0.127.{key}.zen.dq.spamhaus.net", "A"): ["127.0.0.2", "127.0.0.4"]})
        out = net.check_dnsbl(dnsc, "127.0.0.2", net.dnsbl_lists(key))
        e = {x["zone"]: x for x in out["lists"]}["zen.dq.spamhaus.net"]
        self.assertEqual((e["status"], e["name"]), ("listed", "Spamhaus ZEN (DQS)"))
        self.assertNotIn(key, json.dumps(out))
        self.assertTrue(e["query"].endswith(".[key].zen.dq.spamhaus.net"))

    def test_mailspike_reputation(self):
        r = self.lists({("2.0.0.127.bl.mailspike.net", "A"): ["127.0.0.2", "127.0.0.10"]})
        self.assertEqual(r["bl.mailspike.net"]["status"], "listed")
        self.assertIn("schlechteste", r["bl.mailspike.net"]["meaning"][1])

    def test_timeout_is_no_response(self):
        r = self.lists({("2.0.0.127.psbl.surriel.com", "A"): "timeout",
                        ("2.0.0.127.all.spamrats.com", "A"): "timeout"})
        self.assertEqual((r["psbl.surriel.com"]["status"], r["all.spamrats.com"]["status"]),
                         ("no_response", "no_response"))

    def test_servfail_is_error(self):
        r = self.lists({("2.0.0.127.psbl.surriel.com", "A"): "servfail"})
        self.assertEqual(r["psbl.surriel.com"]["status"], "error")

    def test_spamrats_sublist(self):
        r = self.lists({("2.0.0.127.all.spamrats.com", "A"): ["127.0.0.36", "127.0.0.38"]})
        self.assertEqual(r["all.spamrats.com"]["status"], "listed")
        self.assertIn("Dyna", r["all.spamrats.com"]["meaning"][0])

    def test_ipv6(self):
        r = self.lists({}, ip=IP6)
        self.assertEqual(r["bl.spamcop.net"]["status"], "not_supported")
        self.assertTrue(r["zen.spamhaus.org"]["query"].startswith("0.1.0.0.0.0.0.0"))
        self.assertIsNone(r["bl.spamcop.net"]["query"])


class Spoofing(unittest.TestCase):
    def test_forged_headers_below_own_received_are_ignored(self):
        raw = (b"Received-SPF: Fail (mailfrom) identity=mailfrom; envelope-from=x@example.test\r\n"
               b"Authentication-Results: mx-de-01.smartmailflow.email; dmarc=fail header.from=example.test\r\n"
               b"Received: from x (x [192.0.2.10]) by mx-de-01.smartmailflow.email (Postfix) with ESMTPS id 1A2B3C4D5E\r\n"
               b"Received-SPF: Pass (mailfrom) identity=mailfrom\r\n"
               b"Authentication-Results: mx-de-01.smartmailflow.email; dmarc=pass\r\n"
               + build(f"ok@{D}", "t"))
        _, dbg = run(raw, ip="198.51.100.1")
        self.assertEqual((dbg["mta_checks"]["spf"], dbg["mta_checks"]["dmarc"]), ("fail", "fail"))
        self.assertEqual((dbg["spf"]["result"], dbg["dmarc"]["result"]), ("fail", "fail"))
        self.assertEqual(dbg["queue_id"], "1A2B3C4D5E")


class Details2(unittest.TestCase):
    """Neu in 2.0.0: Herleitung und Detailfelder."""

    def test_spf_mechanism_and_record(self):
        _, dbg = run(signed())
        self.assertEqual((dbg["spf"]["mechanism"], dbg["spf"]["record"]),
                         (f"ip4:{IP4}", f"v=spf1 ip4:{IP4} ip6:{IP6} -all"))
        self.assertIsInstance(dbg["spf"]["dns_lookups"], int)

    def test_spf_fail_mechanism_all(self):
        _, dbg = run(signed(), ip="198.51.100.1")
        self.assertEqual((dbg["spf"]["result"], dbg["spf"]["mechanism"]), ("fail", "-all"))

    def test_dkim_signature_details(self):
        _, dbg = run(signed())
        s = dbg["dkim"]["signatures"][0]
        self.assertEqual((s["canonicalization"], s["aligned_with_from"], s["body_hash_ok"], s["dns_name"]),
                         ("relaxed/simple", True, True, f"s2048._domainkey.{D}"))
        self.assertIn("from", s["signed_headers"])

    def test_dkim_body_hash_flag(self):
        _, dbg = run(signed().replace(b"Diese Mail wurde", b"Diese Mail WURDE"))
        self.assertFalse(dbg["dkim"]["signatures"][0]["body_hash_ok"])

    def test_dkim_foreign_domain_not_aligned(self):
        raw = sign(build(f"ok@{D}", "t"), "s2048", "s2048.key", "other.test")
        z = zone()
        z[("s2048._domainkey.other.test", "TXT")] = [dkim_record("s2048.key")]
        _, dbg = run(raw, z)
        s = dbg["dkim"]["signatures"][0]
        self.assertEqual((s["result"], s["aligned_with_from"]), ("pass", False))

    def test_dmarc_pct_disposition(self):
        z = zone()
        z[(f"_dmarc.{D}", "TXT")] = ["v=DMARC1; p=quarantine; pct=50"]
        _, dbg = run(build(f"ok@{D}", "t"), z, ip="198.51.100.1")
        self.assertEqual((dbg["dmarc"]["result"], dbg["dmarc"]["pct"], dbg["dmarc"]["disposition"]),
                         ("fail", 50, "quarantine (50%)"))

    def test_dmarc_policy_none_disposition(self):
        z = zone()
        z[(f"_dmarc.{D}", "TXT")] = ["v=DMARC1; p=none"]
        _, dbg = run(build(f"ok@{D}", "t"), z, ip="198.51.100.1")
        self.assertEqual((dbg["dmarc"]["result"], dbg["dmarc"]["disposition"]), ("fail", "none"))

    def test_header_unfolded_and_outlook_display_name(self):
        raw = build(f"Max Muster <ok@{D}>", "x", subject="=?UTF-8?Q?Gr=C3=BC=C3=9Fe?=")
        raw = raw.replace(b"To: x", b"To: \"'t1-abcdefgh@smartmailflow.email'\"\r\n\t<t1-abcdefgh@smartmailflow.email>")
        res, dbg = run(sign(raw, "s2048", "s2048.key", D))
        self.assertEqual(res["header_to"],
                         "\"'t1-abcdefgh@smartmailflow.email'\" <t1-abcdefgh@smartmailflow.email>")
        self.assertNotIn("\n", res["header_to"])
        to = dbg["header"]["to"][0]
        self.assertEqual((to["address"], to["display_name_is_address"]), ("t1-abcdefgh@smartmailflow.email", True))
        self.assertEqual((res["subject"], res["header_from"]), ("Grüße", f"Max Muster <ok@{D}>"))
        self.assertEqual(dbg["header"]["from"][0]["display_name"], "Max Muster")

    def test_tls(self):
        self.assertEqual(net.check_tls("TLSv1.3", "X")["status"], "pass")
        self.assertEqual(net.check_tls("TLSv1.2", "X")["status"], "pass")
        self.assertEqual(net.check_tls("TLSv1", "X")["status"], "warn")
        self.assertEqual(net.check_tls("", "")["status"], "fail")

    def test_helo_parts(self):
        dnsc = FakeDNS(zone())
        h = net.check_helo(dnsc, f"mail.{D}", "198.51.100.1")
        self.assertEqual((h["syntax"], h["resolves"], h["matches_client_ip"], h["status"]),
                         ("fqdn", True, False, "warn"))
        self.assertEqual(net.check_helo(dnsc, "bad_host..x", IP4)["syntax"], "invalid")

    def test_dnsbl_details(self):
        out = net.check_dnsbl(FakeDNS({}), "127.0.0.2")
        e = out["lists"][1]
        self.assertEqual((e["status"], e["rcode"], e["resolver"]), ("not_listed", "NXDOMAIN", "system"))
        self.assertIsInstance(e["duration_ms"], int)

    def test_duration_and_checked_at(self):
        _, dbg = run(signed())
        self.assertIsInstance(dbg["duration_ms"], int)
        self.assertRegex(dbg["checked_at"], r"^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$")


class ARC(unittest.TestCase):
    FWD = f"fwd.{D}"

    def test_not_present(self):
        _, dbg = run(signed())
        self.assertEqual(dbg["arc"]["status"], "not_present")

    def test_pass(self):
        _, dbg = run(arc_seal(signed(), "s2048", "s2048.key", D, self.FWD))
        a = dbg["arc"]
        self.assertEqual((a["status"], a["chain_validation"], a["sealer_trust"]), ("pass", "pass", "unknown"))
        i = a["instances"][0]
        self.assertEqual((i["i"], i["seal_domain"], i["ams_verified"], i["as_verified"], i["cv"]),
                         (1, D, True, True, "none"))
        self.assertEqual((i["aar"]["authserv_id"], i["aar"]["spf"]), (self.FWD, "pass"))

    def test_forwarded_dkim_broken_arc_pass(self):
        """Weiterleiter ändert den Betreff (DKIM bricht) und versiegelt danach – ARC pass, DMARC bleibt fail."""
        raw = signed().replace(b"Subject: Test", b"Subject: [Liste] Test")
        _, dbg = run(arc_seal(raw, "s2048", "s2048.key", D, self.FWD), ip="198.51.100.1")
        self.assertEqual((dbg["dkim"]["result"], dbg["arc"]["status"], dbg["dmarc"]["result"]),
                         ("fail", "pass", "fail"))

    def test_modified_after_seal_fails(self):
        raw = arc_seal(signed(), "s2048", "s2048.key", D, self.FWD).replace(b"Diese Mail wurde", b"Diese Mail WURDE")
        _, dbg = run(raw)
        self.assertEqual((dbg["arc"]["status"], dbg["arc"]["instances"][0]["ams_verified"]), ("fail", False))

    def test_two_instances(self):
        raw = arc_seal(signed(), "s2048", "s2048.key", D, self.FWD)
        raw = arc_seal(raw, "s1024", "s1024.key", D, f"fwd2.{D}", results="arc=pass; spf=fail")
        _, dbg = run(raw)
        self.assertEqual((dbg["arc"]["status"], [i["i"] for i in dbg["arc"]["instances"]]), ("pass", [1, 2]))
        self.assertEqual(dbg["arc"]["instances"][1]["cv"], "pass")

    def test_key_missing_fails(self):
        raw = arc_seal(signed(), "missing", "s2048.key", D, self.FWD)
        _, dbg = run(raw)
        self.assertEqual(dbg["arc"]["status"], "fail")


class DomainSecurity(unittest.TestCase):
    POLICY = "version: STSv1\nmode: enforce\nmx: mx.example.test\nmax_age: 86400\n"

    def ds(self, extra=None):
        z = zone()
        z.update(extra or {})
        _, dbg = run(signed(), z)
        return dbg["domain_security"]

    def test_nothing_configured(self):
        d = self.ds()
        self.assertEqual((d["domain"], d["dnssec"]["status"], d["mta_sts"]["status"], d["tls_rpt"]["status"],
                          d["dane"]["status"], d["bimi"]["status"]),
                         (D, "unsigned", "not_present", "not_present", "not_present", "not_present"))

    def test_full(self):
        d = self.ds({(D, "DNSSEC"): True, (f"_mta-sts.{D}", "TXT"): ["v=STSv1; id=2026101501"],
                       (f"https://mta-sts.{D}/.well-known/mta-sts.txt", "HTTP"): (200, self.POLICY),
                       (f"_smtp._tls.{D}", "TXT"): ["v=TLSRPTv1; rua=mailto:tls@example.test"],
                       (f"_25._tcp.mx.{D}", "TLSA"): ["3 1 1 ABCDEF"],
                       (f"default._bimi.{D}", "TXT"): ["v=BIMI1; l=https://example.test/logo.svg; a="]})
        self.assertEqual((d["dnssec"]["status"], d["mta_sts"]["status"], d["mta_sts"]["mode"], d["tls_rpt"]["status"],
                          d["dane"]["status"], d["bimi"]["status"]), ("signed", "pass", "enforce", "pass", "pass", "pass"))

    def test_mta_sts_problems(self):
        d = self.ds({(f"_mta-sts.{D}", "TXT"): ["v=STSv1; id=1"]})
        self.assertEqual(d["mta_sts"]["status"], "fail")            # Policy nicht abrufbar
        d = self.ds({(f"_mta-sts.{D}", "TXT"): ["v=STSv1; id=1"],
                       (f"https://mta-sts.{D}/.well-known/mta-sts.txt", "HTTP"): (200, self.POLICY.replace(
                           "mx.example.test", "other.example.test"))})
        self.assertEqual((d["mta_sts"]["status"], d["mta_sts"]["mx_not_covered"]), ("fail", [f"mx.{D}"]))
        d = self.ds({(f"_mta-sts.{D}", "TXT"): ["v=STSv1; id=1"],
                       (f"https://mta-sts.{D}/.well-known/mta-sts.txt", "HTTP"): (200, self.POLICY.replace(
                           "enforce", "testing"))})
        self.assertEqual(d["mta_sts"]["status"], "warn")

    def test_dane_without_dnssec_ineffective(self):
        d = self.ds({(f"_25._tcp.mx.{D}", "TLSA"): ["3 1 1 ABCDEF"]})
        self.assertEqual(d["dane"]["status"], "fail")

    def test_dnssec_bogus(self):
        self.assertEqual(self.ds({(D, "DNSSEC"): "bogus"})["dnssec"]["status"], "bogus")

    def test_wildcard_mx(self):
        from smartmailflow.domainsec import mx_matches
        self.assertTrue(mx_matches("a.mx.example.test", "*.mx.example.test"))
        self.assertFalse(mx_matches("a.b.mx.example.test", "*.mx.example.test"))


class SMIME(unittest.TestCase):
    TEST_CA = {"smime_cafile": os.path.join(HERE, "keys", "smime-ca.pem")}

    def sm(self, raw, config=None):
        return run(raw, config=config or self.TEST_CA)[1]["smime"]

    def mail(self, frm="ok@example.test"):
        return build(frm, "t1@smartmailflow.email")

    def test_not_present(self):
        self.assertEqual(self.sm(signed())["status"], "not_present")

    def test_valid_trusted(self):
        r = self.sm(smime_sign(self.mail()))
        self.assertEqual((r["status"], r["type"], r["signature_valid"], r["chain_trusted"], r["micalg"]),
                         ("pass", "detached", True, True, "sha-256"))
        s = r["signer"]
        self.assertEqual((s["matches_from"], s["self_signed"], s["expired"], s["key_algorithm"], s["key_bits"],
                          s["email_protection"]), (True, False, False, "rsa", 2048, True))
        self.assertIn("ok@example.test", s["emails"])

    def test_unknown_ca_is_warn(self):
        r = self.sm(smime_sign(self.mail()), {"smime_cafile": "/etc/ssl/certs/ca-certificates.crt"})
        self.assertEqual((r["status"], r["signature_valid"], r["chain_trusted"]), ("warn", True, False))

    def test_self_signed(self):
        r = self.sm(smime_sign(self.mail("ok@smartmailflow.test"), signer="smime-self", extra_certs=None))
        self.assertEqual((r["status"], r["signer"]["self_signed"]), ("warn", True))
        self.assertIn("selbstsigniertes Zertifikat", r["reasons"])

    def test_from_mismatch(self):
        r = self.sm(smime_sign(self.mail("anders@example.test")))
        self.assertEqual((r["status"], r["signer"]["matches_from"]), ("warn", False))

    def test_tampered(self):
        raw = smime_sign(self.mail()).replace(b"Diese Mail wurde", b"Diese Mail WURDE")
        r = self.sm(raw)
        self.assertEqual((r["status"], r["signature_valid"]), ("fail", False))

    def test_opaque(self):
        r = self.sm(smime_sign(self.mail(), detached=False))
        self.assertEqual((r["status"], r["type"]), ("pass", "opaque"))

    def test_encrypted(self):
        import subprocess, tempfile
        with tempfile.NamedTemporaryFile() as fh:
            fh.write(b"Content-Type: text/plain\r\n\r\ngeheim\r\n")
            fh.flush()
            enc = subprocess.run(["openssl", "cms", "-encrypt", "-in", fh.name, "-aes256",
                                  os.path.join(HERE, "keys", "smime-signer.pem")], capture_output=True, check=True).stdout
        raw = b"From: ok@example.test\r\nTo: t1@smartmailflow.email\r\nSubject: x\r\n" + enc.replace(b"\n", b"\r\n")
        r = self.sm(raw)
        self.assertEqual((r["status"], r["type"]), ("encrypted", "encrypted"))

    def test_nested_in_mixed(self):
        inner = smime_sign(self.mail())
        head, _, body = inner.partition(b"\r\n\r\n")
        ctype = [l for l in head.split(b"\r\n") if l.lower().startswith(b"content-type")][0]
        raw = (b"From: ok@example.test\r\nTo: t1@smartmailflow.email\r\nSubject: x\r\nMIME-Version: 1.0\r\n"
               b"Content-Type: multipart/mixed; boundary=\"GW\"\r\n\r\n--GW\r\n" + ctype + b"\r\n\r\n" + body +
               b"\r\n--GW\r\nContent-Type: text/plain\r\n\r\nHinweis vom Gateway\r\n--GW--\r\n")
        r = self.sm(raw)
        self.assertTrue(r["nested"])
        self.assertIn(r["status"], ("warn", "fail"))


class DNSAnalysis(unittest.TestCase):
    """Neu in 2.2.0: DNS-Analyse der From-Domain."""

    def da(self, extra=None, raw=None):
        z = zone()
        z.update(extra or {})
        return run(raw or signed(), z)[1]

    def test_spf_tree_and_total_lookups(self):
        z = {("d.test", "TXT"): ["v=spf1 a:h.d.test mx include:esp.test ip4:192.0.2.0/24 ~all"],
             ("h.d.test", "A"): ["192.0.2.5"], ("d.test", "MX"): ["10 mx.d.test."], ("mx.d.test", "A"): ["192.0.2.25"],
             ("esp.test", "TXT"): ["v=spf1 ip6:2001:db8::/32 include:sub.esp.test -all"],
             ("sub.esp.test", "TXT"): ["v=spf1 ip4:198.51.100.0/24 -all"]}
        r = dnsanalysis.analyze_spf(FakeDNS(z), "d.test")
        self.assertEqual((r["status"], r["dns_lookups_total"], r["includes"]), ("pass", 4, ["esp.test", "sub.esp.test"]))
        self.assertEqual(set(r["authorized_networks"]), {"192.0.2.5", "192.0.2.25", "192.0.2.0/24", "2001:db8::/32",
                                                         "198.51.100.0/24"})
        t = r["tree"]["terms"]
        self.assertEqual((t[0]["ips"], t[1]["hosts"][0]["host"], t[4]["action"]),
                         (["192.0.2.5"], "mx.d.test", "verdächtig (softfail)"))
        self.assertEqual(t[2]["child"]["terms"][1]["child"]["domain"], "sub.esp.test")

    def test_spf_too_many_lookups(self):
        z = {("d.test", "TXT"): ["v=spf1 " + " ".join(f"include:i{i}.test" for i in range(11)) + " -all"]}
        for i in range(11):
            z[(f"i{i}.test", "TXT")] = ["v=spf1 -all"]
        r = dnsanalysis.analyze_spf(FakeDNS(z), "d.test")
        self.assertEqual((r["status"], r["dns_lookups_total"]), ("fail", 11))

    def test_spf_loop_void_plus_all(self):
        z = {("a.test", "TXT"): ["v=spf1 include:b.test +all"], ("b.test", "TXT"): ["v=spf1 include:a.test -all"]}
        r = dnsanalysis.analyze_spf(FakeDNS(z), "a.test")
        self.assertEqual(r["status"], "fail")
        self.assertTrue(any("Schleife" in p for p in r["problems"]))
        self.assertTrue(any("+all" in p for p in r["problems"]))
        r = dnsanalysis.analyze_spf(FakeDNS({("a.test", "TXT"): ["v=spf1 include:gibtsnicht.test -all"]}), "a.test")
        self.assertEqual((r["status"], r["void_lookups"]), ("fail", 1))

    def test_dmarc_explained(self):
        d = self.da({(f"_dmarc.{D}", "TXT"): ["v=DMARC1; p=none; pct=50; adkim=s"]})["dns_analysis"]["dmarc"]
        self.assertEqual(d["status"], "warn")
        tags = {t["tag"]: t for t in d["tags"]}
        self.assertIn("strict", tags["adkim"]["meaning"])
        self.assertTrue(any("p=none" in n for n in d["notes"]))
        self.assertTrue(any("rua" in n for n in d["notes"]))

    def test_dkim_info(self):
        k = self.da()["dns_analysis"]["dkim"][0]
        self.assertEqual((k["selector"], k["key_bits"]), ("s2048", 2048))
        self.assertIn("kein Zertifikat", k["note"])
        self.assertRegex(k["signed_at"], r"^\d{4}-")

    def test_mx_tls(self):
        good = {"starttls": True, "protocol": "TLSv1.3", "cipher": "X", "verified": True, "verify_error": None,
                "certificate": {"issuer_organization": "Let's Encrypt"}, "status": "pass", "reason": ""}
        m = self.da({(f"mx.{D}", "SMTPTLS"): good})["dns_analysis"]["mx"]
        self.assertEqual((m["status"], m["records"][0]["tls"]["protocol"]), ("pass", "TLSv1.3"))
        bad = dict(good, starttls=False, status="fail")
        self.assertEqual(self.da({(f"mx.{D}", "SMTPTLS"): bad})["dns_analysis"]["mx"]["status"], "fail")
        self.assertEqual(self.da({(D, "MX"): None})["dns_analysis"]["mx"]["status"], "none")

    def test_domain_security_summary_ignores_dane_bimi(self):
        policy = "version: STSv1\nmode: enforce\nmx: mx.example.test\nmax_age: 86400\n"
        ds = self.da({(D, "DNSSEC"): True, (f"_mta-sts.{D}", "TXT"): ["v=STSv1; id=1"],
                      (f"https://mta-sts.{D}/.well-known/mta-sts.txt", "HTTP"): (200, policy),
                      (f"_smtp._tls.{D}", "TXT"): ["v=TLSRPTv1; rua=mailto:a@x.test,mailto:b@x.test"],
                      (f"_25._tcp.mx.{D}", "TLSA"): ["3 1 1 AB"]})["domain_security"]   # DANE ohne DNSSEC am MX
        self.assertEqual((ds["summary"]["status"], ds["summary"]["rated_ok"], ds["summary"]["rated_total"]),
                         ("pass", 3, 3))
        self.assertEqual(ds["tls_rpt"]["rua"], ["mailto:a@x.test", "mailto:b@x.test"])
        self.assertEqual(ds["summary"]["informational"]["bimi"], "not_present")

    def test_arc_differences_are_information(self):
        raw = arc_seal(signed(), "s2048", "s2048.key", D, f"fwd.{D}", results="spf=softfail; dmarc=fail")
        a = run(raw)[1]["arc"]
        self.assertEqual(a["status"], "pass")
        self.assertEqual({(d["check"], d["station_result"], d["own_result"]) for d in a["aar_differences"]},
                         {("spf", "softfail", "pass"), ("dmarc", "fail", "pass")})
        self.assertIn("Kein Fehler", a["aar_note"])
        self.assertIsNone(run(signed())[1]["arc"]["aar_note"])


class Alignment(unittest.TestCase):
    """Neu in 2.4.0: aligned + rating für SPF und DKIM."""

    def test_both_aligned_ok(self):
        _, dbg = run(signed())
        self.assertEqual((dbg["spf"]["aligned"], dbg["spf"]["rating"], dbg["dkim"]["aligned"], dbg["dkim"]["rating"]),
                         (True, "ok", True, "ok"))

    def test_spf_pass_foreign_envelope_is_warning(self):
        z = zone()
        z[("bounce.esp.test", "TXT")] = [f"v=spf1 ip4:{IP4} -all"]
        _, dbg = run(signed(), z, mail_from="x@bounce.esp.test")
        self.assertEqual((dbg["spf"]["result"], dbg["spf"]["aligned"], dbg["spf"]["rating"]), ("pass", False, "warning"))
        self.assertEqual(dbg["dkim"]["rating"], "ok")

    def test_strict_mode(self):
        z = zone()
        z[(f"_dmarc.{D}", "TXT")] = ["v=DMARC1; p=reject; aspf=s; adkim=s"]
        z[(f"bounce.{D}", "TXT")] = [f"v=spf1 ip4:{IP4} -all"]
        _, dbg = run(signed(), z, mail_from=f"x@bounce.{D}")
        self.assertEqual((dbg["spf"]["aligned"], dbg["spf"]["alignment_mode"], dbg["spf"]["rating"]), (False, "s", "warning"))
        self.assertEqual((dbg["dkim"]["aligned"], dbg["dkim"]["rating"]), (True, "ok"))

    def test_dkim_pass_foreign_domain_is_warning(self):
        raw = sign(build(f"ok@{D}", "t"), "s2048", "s2048.key", "other.test")
        z = zone()
        z[("s2048._domainkey.other.test", "TXT")] = [dkim_record("s2048.key")]
        _, dbg = run(raw, z)
        self.assertEqual((dbg["dkim"]["result"], dbg["dkim"]["aligned"], dbg["dkim"]["rating"]), ("pass", False, "warning"))

    def test_failures_are_error(self):
        _, dbg = run(build(f"ok@{D}", "t"), ip="198.51.100.1")
        self.assertEqual((dbg["spf"]["rating"], dbg["dkim"]["rating"]), ("error", "error"))
        self.assertIsNone(dbg["dkim"]["aligned"])            # keine Signatur
        _, dbg = run(signed(), zone(**{f"{D}__TXT": ["v=spf1 ~all"]}))
        self.assertEqual(dbg["spf"]["rating"], "warning")    # softfail

    def test_1024_counts_as_ok(self):
        _, dbg = run(signed("s1024", "s1024.key"))
        self.assertEqual(dbg["dkim"]["rating"], "ok")


class EndToEnd(unittest.TestCase):
    def test_healthy_mail(self):
        res, dbg = run(signed(), rcpt="ip4-test@ip4.smartmailflow.email")
        self.assertEqual((res["spf_status"], res["dkim_status"], dbg["dmarc"]["result"]), ("pass", "pass", "pass"))
        self.assertEqual((dbg["return_path_dns"]["status"], dbg["fcrdns"]["status"], dbg["helo"]["status"],
                          dbg["ip_protocol"]["status"], dbg["dnsbl"]["listed_count"]), ("pass", "pass", "pass", "pass", 0))
        self.assertEqual((dbg["smtp"]["mail_from"], res["smtp_mail_from"], res["smtp_rcpt_to"]),
                         (f"bounce@{D}", f"bounce@{D}", "ip4-test@ip4.smartmailflow.email"))
        self.assertEqual((dbg["arc"]["status"], dbg["smtp"]["tls"]["status"]), ("not_present", "fail"))
        for old in ("env_sender", "analysis_version", "spf_output", "dkim_status", "dmarc_result", "envelope_dns",
                    "helo_check", "protocol", "rbl", "client_ip", "tls_proto"):
            self.assertNotIn(old, dbg)

    def test_software_version(self):
        import re
        import smartmailflow
        _, dbg = run(signed())
        self.assertEqual(dbg["software_version"], smartmailflow.__version__)
        self.assertRegex(dbg["software_version"], r"^\d+\.\d+\.\d+$")
        self.assertEqual(next(iter(dbg)), "software_version")


if __name__ == "__main__":
    unittest.main()
