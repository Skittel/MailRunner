"""Self test of a receive node (level 2, decision E-17). Called by `spark mr:selftest` via bin/mr-selftest.

Groups:
  base     services, disk, mail queue, spool backlog, time sync, node key, DNSSEC, Spamhaus DQS key,
           reachability of the external APIs (no quota), local test zone
  mx       every MX name of the node (MAILRUNNER_MX_NAMES, e.g. "mx.example=46 mx4.example=4"): address
           families exactly as configured, all addresses on this node, STARTTLS + certificate per family;
           mx.ipv6_route: with an AAAA record the node needs a working IPv6 route to the outside (a local
           connection to the own address passes even without one, senders then get "no route to host");
           route domains whose MX does not point to this node (warning)
  smtp     delivery over the node's public IPv4/IPv6 on port 25: STARTTLS, certificate (name, >= 14 days),
           rejections (recipient pattern, no open relay), postmaster accepted, time until 250
  analysis fixed scenarios with sender domains from the local zone selftest.test (expected results)
  api      collection over the public HTTPS API with the self test token, completeness, encryption at rest,
           project isolation, ack, journal (receive.received/acked, smtp.attempt), deletion after ack

Every check: {"name", "group", "status": ok|warn|fail|skip, "detail", "ms"}. Details never contain secrets.
The mails go to project system/selftest (route rx.selftest.test, profile analysis, delete after ack 0 h).
"""
import datetime
import glob
import json
import os
import secrets as pysecrets
import shutil
import smtplib
import socket
import ssl
import subprocess
import sys
import time
import urllib.error
import urllib.request

from . import config, crypto, secrets

VENDOR_TESTS = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..", "vendor", "smartmailflow", "tests")
ZONE = "selftest.test"
RCPT_DOMAIN = f"rx.{ZONE}"
HELO = f"client.{ZONE}"
SELFTEST_ENV = os.path.join(config.CONFIG_DIR, "selftest.env")
SERVICES = ["postfix", "mailrunner-milter", "mailrunner-smtplog", "unbound", "nginx", "mariadb"]
DEBUG_SECTIONS = ["smtp", "header", "spf", "dkim", "dmarc", "arc", "return_path_dns", "fcrdns", "helo",
                  "ip_protocol", "dnsbl", "domain_security", "dns_analysis", "smime"]
WARN_250_S, FAIL_250_S = 15, 60
CERT_MIN_DAYS = 14


class Run:
    def __init__(self, hostname):
        self.hostname = hostname
        self.run_id = time.strftime("%H%M", time.gmtime()) + pysecrets.token_hex(2)
        self.checks = []
        self.started = time.time()

    def add(self, group, name, status, detail="", ms=None):
        self.checks.append({"name": name, "group": group, "status": status, "detail": redact(str(detail))[:300],
                            "ms": None if ms is None else round(ms)})

    def check(self, group, name, fn):
        """fn() -> (status, detail). Exceptions are a failed check, never a crashed run."""
        t0 = time.monotonic()
        try:
            status, detail = fn()
        except Exception as e:
            status, detail = "fail", f"{e.__class__.__name__}: {e}"
        self.add(group, name, status, detail, (time.monotonic() - t0) * 1000)
        return status

    def result(self):
        failed = [c["name"] for c in self.checks if c["status"] == "fail"]
        warned = [c["name"] for c in self.checks if c["status"] == "warn"]
        return {
            "status": "fail" if failed else "pass",
            "started_at": iso(self.started), "finished_at": iso(time.time()),
            "duration_ms": round((time.time() - self.started) * 1000),
            "ok": sum(1 for c in self.checks if c["status"] == "ok"),
            "failed": failed, "warnings": warned, "checks": self.checks,
        }


def redact(text, values=None):
    """Removes secret values (secrets file, e.g. the DQS key inside DNS names of resolver errors) from a detail.
    The result goes to the journal, which every project can read for node.selftest."""
    if values is None:
        try:
            values = secrets.load().values()
        except OSError:
            values = []
    for v in sorted((v for v in values if len(v) >= 8), key=len, reverse=True):
        text = text.replace(v, "<redacted>")
    return text


def iso(ts):
    return datetime.datetime.fromtimestamp(ts, datetime.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def resolver():
    import dns.resolver
    r = dns.resolver.Resolver(configure=False)
    r.nameservers = [config.RESOLVER]
    r.lifetime = 8
    return r


def load_env(path):
    out = {}
    try:
        with open(path) as fh:
            for line in fh:
                line = line.strip()
                if line and not line.startswith("#") and "=" in line:
                    k, v = line.split("=", 1)
                    out[k.strip()] = v.strip()
    except FileNotFoundError:
        pass
    return out


# --------------------------------------------------------------------------- base
def base_checks(run):
    def services():
        bad = [s for s in SERVICES
               if subprocess.run(["systemctl", "is-active", "--quiet", s]).returncode != 0]
        return ("fail", "not active: " + ", ".join(bad)) if bad else ("ok", f"{len(SERVICES)} active")

    def disk():
        worst = []
        for path in ("/", config.DATA_DIR):
            u = shutil.disk_usage(path)
            worst.append((u.free * 100 / u.total, path))
        free, path = min(worst)
        return ("fail" if free < 15 else "warn" if free < 25 else "ok"), f"{free:.0f} % free on {path}"

    def queue():
        out = subprocess.run(["postqueue", "-j"], capture_output=True, text=True, timeout=20).stdout
        n = len([l for l in out.splitlines() if l.strip()])
        return ("fail" if n > 200 else "warn" if n > 50 else "ok"), f"{n} in queue"

    def spool():
        now = time.time()
        stuck = [f for d in ("analyzed", "smtp") for f in glob.glob(os.path.join(config.SPOOL_DIR, d, "*.json"))
                 if now - os.path.getmtime(f) > 300]
        failed = [f for f in glob.glob(os.path.join(config.SPOOL_DIR, "failed", "*.reason"))
                  if now - os.path.getmtime(f) < 86400]
        if stuck:
            return "fail", f"{len(stuck)} spool files older than 5 min (import not running?)"
        return ("warn", f"{len(failed)} failed spool entries in 24 h") if failed else ("ok", "no backlog")

    def timesync():
        out = subprocess.run(["timedatectl", "show", "-p", "NTPSynchronized", "--value"],
                             capture_output=True, text=True, timeout=10).stdout.strip()
        return ("ok", "NTP synchronized") if out == "yes" else ("fail", f"NTPSynchronized={out or '?'}")

    def node_key():
        key = crypto.load_key(config.NODE_KEY_FILE)
        blob = crypto.encrypt(key, b"selftest", "selftest:probe")
        return ("ok", "encrypt/decrypt") if crypto.decrypt(key, blob, "selftest:probe") == b"selftest" else ("fail", "mismatch")

    def dnssec():
        import dns.flags
        import dns.message
        import dns.query
        import dns.rcode
        q = dns.message.make_query("dnssec-failed.org", "A", want_dnssec=True)
        bad = dns.query.udp(q, config.RESOLVER, timeout=8)
        q = dns.message.make_query("isc.org", "A", want_dnssec=True)
        good = dns.query.udp(q, config.RESOLVER, timeout=8)
        if bad.rcode() != dns.rcode.SERVFAIL:
            return "fail", f"dnssec-failed.org answered {dns.rcode.to_text(bad.rcode())}, expected SERVFAIL"
        if not good.flags & dns.flags.AD:
            return "fail", "isc.org without AD bit (no validation?)"
        return "ok", "bogus -> SERVFAIL, signed -> AD"

    def dqs():
        key = secrets.load().get("SPAMHAUS_DQS_KEY")
        if not key:
            return "skip", "no SPAMHAUS_DQS_KEY"
        r = resolver()
        import dns.resolver
        # right after an install unbound may answer SERVFAIL for a while (cold cache, upstream probing): ~20 s of retries
        waits = (3, 6, 10)
        for attempt in range(len(waits) + 1):
            try:
                listed = [a.to_text() for a in r.resolve(f"2.0.0.127.{key}.zen.dq.spamhaus.net", "A")]
                break
            except dns.resolver.NXDOMAIN:
                return "fail", "test entry 127.0.0.2 not listed (key invalid or expired?)"
            except (dns.resolver.NoNameservers, dns.exception.Timeout):
                if attempt == len(waits):
                    raise
                time.sleep(waits[attempt])
        try:
            r.resolve(f"1.0.0.127.{key}.zen.dq.spamhaus.net", "A")
            return "fail", "127.0.0.1 listed (unexpected answer)"
        except dns.resolver.NXDOMAIN:
            pass
        return "ok", "127.0.0.2 listed (" + ",".join(listed) + "), 127.0.0.1 not"

    def reach(host, needed):
        def fn():
            if not needed:
                return "skip", "not configured"
            ctx = ssl.create_default_context()
            with socket.create_connection((host, 443), timeout=8) as s, ctx.wrap_socket(s, server_hostname=host):
                pass
            return "ok", f"TLS to {host}:443"
        return fn

    def zone():
        txt = [a.to_text() for a in resolver().resolve(f"pass.{ZONE}", "TXT")]
        return ("ok", "local zone answers") if any("v=spf1" in t for t in txt) else ("fail", "no SPF in local zone")

    creds = secrets.load()
    run.check("base", "base.services", services)
    run.check("base", "base.disk", disk)
    run.check("base", "base.mail_queue", queue)
    run.check("base", "base.spool", spool)
    run.check("base", "base.time_sync", timesync)
    run.check("base", "base.node_key", node_key)
    run.check("base", "base.dnssec", dnssec)
    run.check("base", "base.spamhaus_dqs", dqs)
    run.check("base", "base.reach_ipregistry", reach("api.ipregistry.co", bool(creds.get("IPREGISTRYIO_TOKEN"))))
    run.check("base", "base.reach_blacklistmaster", reach("www.blacklistmaster.com", bool(creds.get("BLM_USER"))))
    return run.check("base", "base.selftest_zone", zone) == "ok"


# --------------------------------------------------------------------------- mails
def mailtools():
    if VENDOR_TESTS not in sys.path:
        sys.path.insert(0, VENDOR_TESTS)
    import mailtools as mt
    return mt


def scenarios(run):
    """name -> dict(mail_from, rcpt, raw, family, tls, expect(debug, record) -> list of problems)."""
    mt = mailtools()
    subject = f"MailRunner selftest {run.run_id}"
    out = {}

    def rcpt(name):
        return f"st-{run.run_id}-{name}@{RCPT_DOMAIN}"

    def mail(name, hdr_from, outlook=False, sign=None, tamper=False, mail_from=None, family=4, tls=True, expect=None, to_addr=None):
        to_addr = to_addr or rcpt(name)
        raw = mt.build(hdr_from, "PLACEHOLDER-TO" if outlook else to_addr, subject=f"{subject} {name}", domain=f"pass.{ZONE}")
        if outlook:   # like Outlook/M365: display name = quoted address, To header folded over two lines
            raw = raw.replace(b"PLACEHOLDER-TO", f"\"'{to_addr}'\"\r\n\t<{to_addr}>".encode(), 1)
        if sign:
            raw = mt.sign(raw, sign[0], sign[1], f"pass.{ZONE}")
        if tamper:
            raw = raw.replace(b"von einem Test erzeugt", b"von einem Test VERAENDERT")
        out[name] = {"mail_from": hdr_from.split("<")[-1].strip(">") if mail_from is None else mail_from,
                     "rcpt": to_addr, "raw": raw, "family": family, "tls": tls, "expect": expect,
                     "subject": f"{subject} {name}"}

    def want(**exp):
        def check(debug, record, message=None):
            problems = []
            for path, value in exp.items():
                node = {"debug": debug, "record": record, "message": message or {}}
                for part in path.split("__"):
                    node = node.get(part) if isinstance(node, dict) else (node[int(part)] if isinstance(node, list) and part.isdigit() and int(part) < len(node) else None)
                if node != value:
                    problems.append(f"{path.replace('__', '.')}={node!r} expected {value!r}")
            return problems
        return check

    ok_from = f"ok@pass.{ZONE}"
    mail("pass4", ok_from, sign=("s2048", "s2048.key"),
         expect=want(debug__spf__result="pass", debug__dkim__result="pass", debug__dmarc__result="pass",
                     debug__smtp__tls__status="pass", debug__smtp__ip_version=4))
    mail("pass6", ok_from, sign=("ed25519", "ed25519.key"), family=6,
         expect=want(debug__spf__result="pass", debug__dkim__result="pass", debug__dmarc__result="pass",
                     debug__smtp__tls__status="pass", debug__smtp__ip_version=6))
    mail("dkim_tampered", ok_from, sign=("s2048", "s2048.key"), tamper=True,
         expect=want(debug__spf__result="pass", debug__dkim__result="fail"))
    mail("spf_fail", f"x@spffail.{ZONE}",
         expect=want(debug__spf__result="fail", debug__dmarc__result="fail"))
    mail("no_tls", ok_from, sign=("s2048", "s2048.key"), tls=False,
         expect=want(debug__smtp__tls__status="fail", debug__dkim__result="pass"))
    mail("null_sender", ok_from, sign=("s2048", "s2048.key"), mail_from="",
         expect=want(record__smtp_mail_from="", debug__spf__identity="helo", debug__spf__result="pass",
                     debug__dmarc__result="pass"))
    to = rcpt("outlook_to")
    mail("outlook_to", ok_from, outlook=True, sign=("s2048", "s2048.key"),
         expect=want(debug__header__to__0__address=to, debug__header__to__0__display_name_is_address=True,
                     debug__dkim__result="pass"))
    # E-18: abuse@ is accepted for every project domain (no pattern match needed) and its original is kept
    mail("role_abuse", ok_from, sign=("s2048", "s2048.key"), to_addr=f"abuse@{RCPT_DOMAIN}",
         expect=want(message__role="abuse", message__raw_available=True, debug__dkim__result="pass"))
    return out, subject


# --------------------------------------------------------------------------- smtp
def own_addresses(hostname):
    import dns.resolver
    r = resolver()
    out = {}
    for fam, rtype in ((4, "A"), (6, "AAAA")):
        try:
            out[fam] = [a.to_text() for a in r.resolve(hostname, rtype)]
        except (dns.resolver.NoAnswer, dns.resolver.NXDOMAIN):
            out[fam] = []
    return out


def smtp_session(run, ip, family, items, tls, extra_checks=False):
    """One SMTP session to ip:25 delivering items (list of scenario dicts). Returns {name: seconds_to_250}."""
    times = {}
    group = "smtp"
    label = f"v{family}" + ("" if tls else "-plain")
    t0 = time.monotonic()
    s = smtplib.SMTP(timeout=FAIL_250_S + 30)
    s.connect(ip, 25)
    s._host = run.hostname   # name for SNI and certificate check (connected by address)
    s.ehlo(HELO)
    if tls:
        if not s.has_extn("starttls"):
            run.add(group, f"smtp.{label}.starttls", "fail", "STARTTLS not offered")
            s.quit()
            return times
        ctx = ssl.create_default_context()
        s.starttls(context=ctx)   # verifies chain and name (s._host)
        cert = s.sock.getpeercert()
        days = (ssl.cert_time_to_seconds(cert["notAfter"]) - time.time()) / 86400
        version = s.sock.version()
        run.add(group, f"smtp.{label}.starttls", "ok" if version in ("TLSv1.2", "TLSv1.3") else "fail",
                f"{version}, certificate valid for {run.hostname}", (time.monotonic() - t0) * 1000)
        run.add(group, f"smtp.{label}.certificate", "ok" if days >= CERT_MIN_DAYS else "fail", f"{days:.0f} days left")
        s.ehlo(HELO)
    if extra_checks:
        s.mail(f"ok@pass.{ZONE}")
        code, msg = s.rcpt(f"nomatch.selftest@{RCPT_DOMAIN}")
        run.add(group, f"smtp.{label}.reject_pattern", "ok" if 500 <= code < 600 else "fail", f"{code} {msg[:80].decode(errors='replace')}")
        code, msg = s.rcpt("relay-test@unrouted.invalid")
        run.add(group, f"smtp.{label}.no_open_relay", "ok" if 500 <= code < 600 else "fail", f"{code} {msg[:80].decode(errors='replace')}")
        code, msg = s.rcpt(f"postmaster@{RCPT_DOMAIN}")
        run.add(group, f"smtp.{label}.postmaster_accepted", "ok" if code == 250 else "fail", f"{code}")
        s.rset()
    for name, sc in items:
        code, _ = s.mail(sc["mail_from"])
        rc, rmsg = s.rcpt(sc["rcpt"])
        if code != 250 or rc != 250:
            run.add("analysis", f"analysis.{name}", "fail", f"MAIL {code} / RCPT {rc} {rmsg[:80].decode(errors='replace')}")
            s.rset()
            continue
        t = time.monotonic()
        try:
            dcode, dmsg = s.data(sc["raw"])
        except smtplib.SMTPResponseException as e:
            dcode, dmsg = e.smtp_code, e.smtp_error
        dt = time.monotonic() - t
        if dcode != 250:
            run.add("analysis", f"analysis.{name}", "fail", f"DATA {dcode} {dmsg[:80].decode(errors='replace')}")
            continue
        times[name] = dt
    s.quit()
    return times


def smtp_checks(run, items):
    addrs = own_addresses(run.hostname)
    times = {}
    v4 = addrs.get(4) or []
    v6 = addrs.get(6) or []
    if not v4:
        run.add("smtp", "smtp.v4.address", "fail", f"no A record for {run.hostname}")
        return times
    by = {k: v for k, v in items.items()}
    tls4 = [(n, by[n]) for n in by if by[n]["family"] == 4 and by[n]["tls"]]
    plain4 = [(n, by[n]) for n in by if by[n]["family"] == 4 and not by[n]["tls"]]
    six = [(n, by[n]) for n in by if by[n]["family"] == 6]
    for label, ip, batch, tls, extra in (("v4", v4[0], tls4, True, True), ("v4-plain", v4[0], plain4, False, False)):
        try:
            times.update(smtp_session(run, ip, 4, batch, tls, extra))
        except Exception as e:
            run.add("smtp", f"smtp.{label}.session", "fail", f"{e.__class__.__name__}: {e}")
    if v6:
        try:
            times.update(smtp_session(run, v6[0], 6, six, True, False))
        except Exception as e:
            run.add("smtp", "smtp.v6.session", "fail", f"{e.__class__.__name__}: {e}")
    else:
        for n, _ in six:
            run.add("analysis", f"analysis.{n}", "skip", "no AAAA record")
    if times:
        worst = max(times.values())
        run.add("smtp", "smtp.time_to_250", "fail" if worst > FAIL_250_S else "warn" if worst > WARN_250_S else "ok",
                f"max {worst:.1f} s over {len(times)} mails")
    return times


# --------------------------------------------------------------------------- mx names
def parse_mx_names(spec, hostname):
    """'a.example=46 b.example=4' -> {name: {4, 6}}. Empty -> the node name with the families it has in DNS."""
    out = {}
    for item in (spec or "").replace(",", " ").split():
        name, _, fams = item.partition("=")
        out[name.strip().lower().rstrip(".")] = {int(c) for c in (fams or "46") if c in "46"}
    return out or {hostname: None}


def mx_checks(run, spec):
    """Per MX name of the node: address families exactly as configured, all addresses belong to the node,
    STARTTLS with a valid certificate for that name over every family. Each check session makes one short
    transaction to the self test project (no smtp.connection entries in other projects' journals)."""
    from . import analyzer
    own = analyzer.node_addresses()
    names = parse_mx_names(spec, run.hostname)
    for name, expected in names.items():
        addrs = own_addresses(name)
        have = {f for f in (4, 6) if addrs.get(f)}
        if expected is not None:
            ok = have == expected
            run.add("mx", f"mx.{name}.dns", "ok" if ok else "fail",
                    f"families {sorted(have)}" + ("" if ok else f", expected {sorted(expected)}"))
        foreign = [a for f in (4, 6) for a in addrs.get(f, []) if a not in own]
        if foreign:
            run.add("mx", f"mx.{name}.addresses", "fail", f"{len(foreign)} address(es) not on this node")
        for fam in sorted(have):
            label = f"mx.{name}.v{fam}"
            t0 = time.monotonic()
            try:
                s = smtplib.SMTP(timeout=30)
                s.connect(addrs[fam][0], 25)
                s._host = name   # SNI and certificate name
                s.ehlo(HELO)
                s.starttls(context=ssl.create_default_context())
                version = s.sock.version()
                s.ehlo(HELO)
                s.mail(f"ok@pass.{ZONE}")
                code, _ = s.rcpt(f"postmaster@{RCPT_DOMAIN}")
                s.rset()
                s.quit()
                run.add("mx", label, "ok" if code == 250 else "fail",
                        f"{version}, certificate valid for {name}" + ("" if code == 250 else f", RCPT {code}"),
                        (time.monotonic() - t0) * 1000)
            except Exception as e:
                run.add("mx", label, "fail", f"{e.__class__.__name__}: {getattr(e, 'verify_message', None) or e}",
                        (time.monotonic() - t0) * 1000)


# Public IPv6 addresses of root servers (a, k, l): stable, answer over UDP 53 without a key.
ROOT_V6 = ["2001:503:ba3e::2:30", "2001:7fd::1", "2001:500:9f::42"]


def ipv6_route_check(run, spec):
    """mx.ipv6_route: if an MX name of the node has an AAAA record, the node must reach the outside over IPv6
    (default route + answer of a root server). Details name no node address."""
    names = parse_mx_names(spec, run.hostname)
    with_v6 = [n for n, fams in names.items() if (fams is None or 6 in fams) and own_addresses(n).get(6)]

    def check():
        return ipv6_route_status(with_v6)
    run.check("mx", "mx.ipv6_route", check)


def ipv6_route_status(with_v6, roots=None, query=None):
    if not with_v6:
        return "skip", "no AAAA record"
    roots = roots or ROOT_V6
    try:
        s = socket.socket(socket.AF_INET6, socket.SOCK_DGRAM)
        try:
            s.connect((roots[0], 53))   # UDP connect only selects the route, sends nothing
        finally:
            s.close()
    except OSError as e:
        return "fail", (f"AAAA published for {', '.join(with_v6)} but no IPv6 route to the outside "
                        f"({e.strerror or e}); senders cannot reach the node over IPv6")
    if query is None:
        import dns.message
        import dns.query

        def query(ip):
            dns.query.udp(dns.message.make_query(".", "NS"), ip, timeout=3)
    errors = []
    for ip in roots:
        try:
            query(ip)
            return "ok", f"IPv6 route works (root server answered), AAAA for {', '.join(with_v6)}"
        except Exception as e:
            errors.append(e.__class__.__name__)
    return "fail", (f"AAAA published for {', '.join(with_v6)} but no answer over IPv6 from {len(roots)} root servers "
                    f"({', '.join(sorted(set(errors)))}); check the IPv6 gateway and firewall")


def route_mx_checks(run):
    """Warn about route domains whose MX does not point to this node (mail would not arrive here)."""
    from . import analyzer
    import dns.resolver
    own = analyzer.node_addresses()
    try:
        with open(config.ROUTES_FILE) as fh:
            routes = json.load(fh).get("routes", {})
    except (OSError, ValueError) as e:
        run.add("mx", "mx.routes", "fail", f"routes.json not readable: {e}")
        return
    domains = sorted({r for r in routes if "@" not in r and not r.endswith("." + ZONE)})
    bad = []
    r = resolver()
    for d in domains:
        try:
            targets = [str(x.exchange).rstrip(".") for x in r.resolve(d, "MX")]
        except (dns.resolver.NoAnswer, dns.resolver.NXDOMAIN):
            targets = [d]   # implicit MX
        except Exception:
            bad.append(f"{d} (MX lookup failed)")
            continue
        points_here = any(a in own for t in targets for fam in (4, 6) for a in own_addresses(t).get(fam, []))
        if not points_here:
            bad.append(d)
    run.add("mx", "mx.routes", "warn" if bad else "ok",
            ("MX not pointing to this node: " + ", ".join(bad)) if bad else f"{len(domains)} route domains point here")


# --------------------------------------------------------------------------- api
class Api:
    def __init__(self, hostname, token):
        self.base = f"https://{hostname}/v1/"
        self.token = token

    def call(self, path, body=None, token=None):
        data = None if body is None else json.dumps(body).encode()
        req = urllib.request.Request(self.base + path, data=data, method="POST" if body is not None else "GET",
                                     headers={"Authorization": f"Bearer {token or self.token}",
                                              "Content-Type": "application/json"})
        def obj(raw):  # API answers are JSON objects; anything else (e.g. a proxy page during a restart) -> {}
            try:
                body = json.loads(raw or b"{}")
            except ValueError:
                return {}
            return body if isinstance(body, dict) else {}
        try:
            with urllib.request.urlopen(req, timeout=30) as resp:
                return resp.status, obj(resp.read())
        except urllib.error.HTTPError as e:
            return e.code, obj(e.read())


def api_checks(run, items, times, subject, journal_after):
    env = load_env(SELFTEST_ENV)
    if not env.get("SELFTEST_TOKEN"):
        run.add("api", "api.token", "fail", f"SELFTEST_TOKEN missing in {SELFTEST_ENV}")
        return
    api = Api(run.hostname, env["SELFTEST_TOKEN"])
    expected = {sc["subject"]: name for name, sc in items.items() if name in times}   # subject is unique per run

    # 1. all delivered mails collectable within 60 s, with complete analysis
    found = {}
    t0 = time.monotonic()
    while time.monotonic() - t0 < 60 and len(found) < len(expected):
        status, body = api.call("messages?acked=0&include=content&limit=100")
        if status != 200:
            run.add("api", "api.collect", "fail", f"HTTP {status} {err.get('code', '') if isinstance(err := body.get('error'), dict) else (err or '')}")
            return
        for m in body.get("data", []):
            key = (m.get("header") or {}).get("subject")
            if key in expected:
                found[key] = m
        if len(found) < len(expected):
            time.sleep(2)
    missing = sorted(set(expected) - set(found))
    run.add("api", "api.collect", "fail" if missing else "ok",
            f"missing: {', '.join(expected[m] for m in missing)}" if missing else f"{len(found)} mails after {time.monotonic() - t0:.1f} s",
            (time.monotonic() - t0) * 1000)

    # 2. expectations per scenario + completeness + no external quota for own address
    for key, m in found.items():
        name = expected[key]
        debug = m.get("debug") or {}
        problems = [f"missing section {s}" for s in DEBUG_SECTIONS if s not in debug]
        problems += items[name]["expect"](debug, m.get("record") or {}, m)
        for ext in ("blacklistmaster", "ipregistry"):
            if ext in debug and (debug[ext] or {}).get("status") != "skipped":
                problems.append(f"{ext} queried for the node's own address")
        run.add("analysis", f"analysis.{name}", "fail" if problems else "ok",
                "; ".join(problems) if problems else f"{times[name]:.1f} s to 250")

    # 3. encryption at rest: no store file of this run contains the subject in plain text
    needle = subject.encode()
    leaked = []
    for f in glob.glob(os.path.join(config.STORE_DIR, "*", "*", "*", "*")):
        if os.path.getmtime(f) >= run.started - 5:
            with open(f, "rb") as fh:
                if needle in fh.read():
                    leaked.append(os.path.basename(f))
    run.add("api", "api.encrypted_at_rest", "fail" if leaked else "ok",
            f"plain text in {len(leaked)} files" if leaked else "no plain text subject in store files")

    # 4. isolation: another project's token does not see the messages
    if env.get("SELFTEST_OTHER_TOKEN") and found:
        some = next(iter(found.values()))["message_id"]
        status, _ = api.call(f"messages/{some}", token=env["SELFTEST_OTHER_TOKEN"])
        run.add("api", "api.isolation", "ok" if status == 404 else "fail", f"other project: HTTP {status}")
    else:
        run.add("api", "api.isolation", "skip", "SELFTEST_OTHER_TOKEN missing")

    # 5. ack everything open in the self test project (incl. postmaster copies)
    status, body = api.call("messages?acked=0&limit=1000")
    open_ids = [m["message_id"] for m in body.get("data", [])] if status == 200 else []
    if open_ids:
        status, body = api.call("messages/ack", {"message_ids": open_ids})
        bad = status != 200 or sorted(body.get("acked", [])) != sorted(open_ids)
        run.add("api", "api.ack", "fail" if bad else "ok", f"HTTP {status}, {len(body.get('acked', []))}/{len(open_ids)} acked")
    else:
        run.add("api", "api.ack", "fail" if found else "skip", "nothing to acknowledge")

    # 6. journal: received + acked per message, smtp.attempt with the message ids (mr-smtplog ~10 s after the session)
    ids = {m["message_id"] for m in found.values()}
    t0 = time.monotonic()
    while True:
        events = []
        after = journal_after
        while True:
            status, body = api.call(f"journal?after_id={after}&limit=1000&event=receive.received,receive.acked,smtp.attempt")
            if status != 200:
                break
            events += body.get("data", [])
            if not body.get("has_more"):
                break
            after = body["next_after_id"]
        received = {e["details"].get("message_id") for e in events if e["event"] == "receive.received"}
        acked = {e["details"].get("message_id") for e in events if e["event"] == "receive.acked"}
        attempted = {i for e in events if e["event"] == "smtp.attempt" for i in (e["details"].get("message_ids") or [])}
        missing = {"received": ids - received, "acked": ids - acked, "smtp.attempt": ids - attempted}
        if not any(missing.values()) or time.monotonic() - t0 > 45:
            break
        time.sleep(3)
    problems = [f"{k}: {len(v)} missing" for k, v in missing.items() if v]
    run.add("api", "api.journal", "fail" if problems else "ok", "; ".join(problems) or f"{len(ids)} mails complete",
            (time.monotonic() - t0) * 1000)

    # 7. deletion after ack (maintenance every 15 min, delete_after_ack_hours = 0)
    status, body = api.call("messages?acked=1&limit=1000")
    now = time.time()
    overdue = []
    for m in body.get("data", []) if status == 200 else []:
        acked_at = m.get("acked_at")
        if acked_at and not m.get("content_deleted_at"):
            age = now - datetime.datetime.fromisoformat(acked_at.replace("Z", "+00:00")).timestamp()
            if age > 30 * 60:
                overdue.append(m["message_id"])
    run.add("api", "api.deleted_after_ack", "fail" if overdue else "ok",
            f"{len(overdue)} acked > 30 min ago still with content" if overdue else "content deleted after ack")


# --------------------------------------------------------------------------- main
def run_all(hostname, journal_after=0, skip_mail=False, mx_names=""):
    run = Run(hostname)
    zone_ok = base_checks(run)
    ipv6_route_check(run, mx_names)
    if zone_ok:
        mx_checks(run, mx_names)
    route_mx_checks(run)
    if skip_mail:
        return run.result()
    if not zone_ok:
        run.add("analysis", "analysis.all", "fail", "local test zone missing, no mails sent")
        return run.result()
    items, subject = scenarios(run)
    times = smtp_checks(run, items)
    api_checks(run, items, times, subject, journal_after)
    return run.result()
