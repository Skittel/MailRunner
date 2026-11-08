"""Domain test of the send role (decision E-27), run by mr-sender.

1. DNS pre-check without mail: SPF of the domain for every source address of the node (pyspf, like the analysis),
   the DKIM record <selector>._domainkey.<domain> carries exactly the stored public key. A clear failure ends the
   test (no mail is sent).
2. One test mail per address family of the node: to <id>@ip4.smartmailflow.email over IPv4 and
   <id>@ip6.smartmailflow.email over IPv6, signed like a customer mail. The ids are new random values per run
   (a new mail would overwrite an old result).
3. The results from https://smartmailflow.de/api?token=<id>, judged by the project's criteria (admin checkboxes):
   spf, dkim, dmarc (pass each), dmarc_policy (quarantine or reject), blacklist (no listing). The mail must have
   arrived over the intended family.
SmartMailFlow not reachable / no result in time -> "unavailable": not a failure, the last valid result stays and the
test is repeated soon (a never tested domain still never sends).
"""
import asyncio
import email.utils
import json
import secrets
import urllib.request

import dns.exception
import dns.resolver

from . import smtp
from .signing import sign

SMF_DOMAIN = {4: "ip4.smartmailflow.email", 6: "ip6.smartmailflow.email"}
SMF_API = "https://smartmailflow.de/api?token={}"
POLL_EVERY = 10
POLL_FOR = 240
CRITERIA_DEFAULT = {"spf": True, "dkim": True, "dmarc": True, "dmarc_policy": False, "blacklist": False}
TEST_LOCALPART = "mailrunner-domaintest"


def criteria_of(stored):
    out = dict(CRITERIA_DEFAULT)
    if isinstance(stored, str):
        try:
            stored = json.loads(stored)
        except ValueError:
            stored = None
    if isinstance(stored, dict):
        out.update({k: bool(v) for k, v in stored.items() if k in CRITERIA_DEFAULT})
    return out


def judge(data, family, criteria):
    """Checks of one SmartMailFlow result: {check: {"ok": bool, "required": bool, "value": ...}}."""
    dbg = (data or {}).get("debug") or {}
    dmarc = dbg.get("dmarc") or {}
    dnsbl = dbg.get("dnsbl") or {}
    blm = dbg.get("blacklistmaster") or {}
    listed = int(dnsbl.get("listed_count") or 0) + (int(blm.get("blacklist_cnt") or 0) if blm.get("status") == "Blacklisted" else 0)
    used = (dbg.get("ip_protocol") or {}).get("used")
    checks = {
        "family": {"ok": used == f"ipv{family}", "required": True, "value": used},
        "spf": {"ok": (dbg.get("spf") or {}).get("result") == "pass", "required": criteria["spf"], "value": (dbg.get("spf") or {}).get("result")},
        "dkim": {"ok": (dbg.get("dkim") or {}).get("result") == "pass", "required": criteria["dkim"], "value": (dbg.get("dkim") or {}).get("result")},
        "dmarc": {"ok": dmarc.get("result") == "pass", "required": criteria["dmarc"], "value": dmarc.get("result")},
        "dmarc_policy": {"ok": dmarc.get("effective_policy") in ("quarantine", "reject"), "required": criteria["dmarc_policy"],
                         "value": dmarc.get("effective_policy")},
        "blacklist": {"ok": listed == 0, "required": criteria["blacklist"], "value": listed,
                      "lists": sorted({b.get("blacklist") for b in blm.get("blacklists") or [] if b.get("blacklist")})},
        "tls": {"ok": ((dbg.get("smtp") or {}).get("tls") or {}).get("status") == "pass", "required": False,
                "value": ((dbg.get("smtp") or {}).get("tls") or {}).get("protocol")},
    }
    return checks


def failed_checks(families):
    return sorted({f"{name}.v{fam}" for fam, checks in families.items() for name, c in checks.items() if c["required"] and not c["ok"]})


def fetch_smf(token, timeout=15):
    req = urllib.request.Request(SMF_API.format(token), headers={"User-Agent": "MailRunner-domaintest"})
    with urllib.request.urlopen(req, timeout=timeout) as resp:
        return json.loads(resp.read().decode("utf-8", "replace"))


class DnsCheck:
    def __init__(self, nameserver):
        self.r = dns.resolver.Resolver(configure=False)
        self.r.nameservers = [nameserver]
        self.r.lifetime = 15

    def txt(self, name):
        """TXT strings joined per record, or [] if none; raises dns.exception.Timeout etc. on DNS failure."""
        try:
            return [b"".join(rr.strings).decode("utf-8", "replace") for rr in self.r.resolve(name, "TXT")]
        except (dns.resolver.NXDOMAIN, dns.resolver.NoAnswer):
            return []


def precheck(domain, selector, public_key, ips, helo, dns_check, spf_check=None, spf_domain=None):
    """{"spf": {ip_label: result}, "dkim": "pass"|"missing"|"mismatch"} or raises TemporaryDns. SPF is checked for
    spf_domain (the return-path domain of the profiles), else for the domain itself."""
    if spf_check is None:
        import spf
        spf_check = lambda ip: spf.check2(i=ip, s=f"{TEST_LOCALPART}@{spf_domain or domain}", h=helo)[0]  # noqa: E731
    out = {"spf": {}, "dkim": None}
    for fam, ip in ips:
        out["spf"][f"v{fam}"] = spf_check(ip)
    try:
        records = dns_check.txt(f"{selector}._domainkey.{domain}")
    except dns.exception.DNSException as e:
        raise TemporaryDns(f"DKIM lookup failed ({e.__class__.__name__})")
    keys = []
    for rec in records:
        tags = dict(part.strip().split("=", 1) for part in rec.split(";") if "=" in part)
        keys.append("".join(tags.get("p", "").split()))
    out["dkim"] = "missing" if not keys else "pass" if "".join(public_key.split()) in keys else "mismatch"
    return out


class TemporaryDns(Exception):
    pass


def test_message(domain, rcpt, run_id):
    now = email.utils.formatdate(usegmt=True)
    return (f"From: MailRunner Domain-Test <{TEST_LOCALPART}@{domain}>\r\nTo: {rcpt}\r\nSubject: MailRunner domain test {domain}\r\n"
            f"Date: {now}\r\nMessage-ID: <{run_id}.{secrets.token_hex(6)}@{domain}>\r\nMIME-Version: 1.0\r\n"
            f"Content-Type: text/plain; charset=utf-8\r\nAuto-Submitted: auto-generated\r\n\r\n"
            f"Domain test of the MailRunner send role for {domain}.\r\n").encode()


async def run_test(row, settings, private_key, dns_check, deliver=smtp.deliver, fetch=fetch_smf, resolver=None, sleep=asyncio.sleep, spf_check=None):
    """Runs one test. Returns (outcome, details) with outcome passed | failed | unavailable."""
    domain, criteria = row["domain"], criteria_of(row.get("send_test_criteria"))
    ips = ([(6, settings.ipv6[0])] if settings.ipv6 else []) + ([(4, settings.ipv4[0])] if settings.ipv4 else [])
    rp = row.get("return_path_domain")
    details = {"criteria": criteria, "families": [f"v{f}" for f, _ in ips], "return_path_domain": rp, "precheck": None, "smartmailflow": {}, "failed": []}
    if not ips:
        details["error"] = "node has no source addresses"
        return "unavailable", details
    try:
        pre = await asyncio.to_thread(precheck, domain, row["dkim_selector"], row["dkim_public"], ips, settings.helo, dns_check, spf_check, rp)
    except TemporaryDns as e:
        details["error"] = str(e)
        return "unavailable", details
    details["precheck"] = pre
    pre_failed = []
    if criteria["spf"]:
        pre_failed += [f"spf.{lab}" for lab, res in pre["spf"].items() if res != "pass"]
    if criteria["dkim"] and pre["dkim"] != "pass":
        pre_failed.append("dkim.record_" + pre["dkim"])
    if pre_failed:
        details["failed"] = pre_failed
        details["stage"] = "precheck"
        return "failed", details

    run_id = "mrdt" + secrets.token_hex(8)
    tokens = {}
    for fam, ip in ips:
        token = f"{run_id}{fam}"
        rcpt = f"{token}@{SMF_DOMAIN[fam]}"
        data = sign(test_message(domain, rcpt, run_id), row["dkim_selector"], domain, row["dkim_algorithm"], private_key)
        tries = await deliver(SMF_DOMAIN[fam], [rcpt], f"{TEST_LOCALPART}@{rp or domain}", data, settings.helo, {fam: ip}, resolver)
        last = smtp.outcome(tries, rcpt)
        details["smartmailflow"][f"v{fam}"] = {"delivery": last.result if last else None, "code": last.code if last else None,
                                               "stage": last.stage if last else None}
        if not last or last.result != "sent":
            details["error"] = f"test mail over IPv{fam} not delivered ({(last.response if last else '') or 'no answer'})"[:250]
            return "unavailable", details
        tokens[fam] = token

    families = {}
    pending = dict(tokens)
    for _ in range(POLL_FOR // POLL_EVERY):
        if not pending:
            break
        await sleep(POLL_EVERY)
        for fam, token in list(pending.items()):
            try:
                res = await asyncio.to_thread(fetch, token)
            except Exception:
                continue
            data = res.get("data") if isinstance(res, dict) else None
            if isinstance(data, dict) and data.get("debug"):
                families[fam] = judge(data, fam, criteria)
                del pending[fam]
    if pending:
        details["error"] = "no SmartMailFlow result for " + ", ".join(f"IPv{f}" for f in sorted(pending))
        return "unavailable", details
    details["checks"] = {f"v{fam}": checks for fam, checks in families.items()}
    details["failed"] = failed_checks(families)
    details["stage"] = "smartmailflow"
    return ("failed" if details["failed"] else "passed"), details
