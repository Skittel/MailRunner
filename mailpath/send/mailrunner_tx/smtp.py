"""Delivery of one submission to the recipients of one domain (E-27): MX lookup, SMTP session, every try recorded.

MX by preference (equal preferences shuffled), no MX -> the domain itself (implicit MX), null MX -> hardfail.
Per MX the IPv6 and IPv4 addresses are tried with the node's source address of that family (IPv6 first). STARTTLS
is used when offered (certificates not verified yet: MTA-STS/DANE later); a failed handshake is retried once in
plain text, like Postfix with security level "may". Several recipients share one transaction, each with its own
RCPT answer. Connection and greeting problems move on to the next address/MX; an answer inside the transaction
ends the delivery attempt.

Result: a list of Try records, each with the recipients it applies to; the last Try of a recipient decides:
  2xx after DATA -> sent;  5xx -> bounced;  4xx, timeouts, no connection -> deferred.
"""
import asyncio
import random
import re
import time
from dataclasses import dataclass, field

import aiosmtplib
import dns.asyncresolver
import dns.exception
import dns.resolver

CONNECT_TIMEOUT = 30
COMMAND_TIMEOUT = 120
DATA_TIMEOUT = 300
MAX_CONNECTIONS = 6          # addresses tried per delivery attempt
ENHANCED = re.compile(r"^([245]\.\d{1,3}\.\d{1,3})\b")


@dataclass
class Try:
    stage: str               # dns, connect, greeting, ehlo, starttls, mail, rcpt, data, local
    result: str              # sent, deferred, bounced, failed
    rcpts: list
    code: int = None
    enhanced: str = None
    response: str = None
    mx_host: str = None
    mx_ip: str = None
    local_ip: str = None
    tls_version: str = None
    tls_cipher: str = None
    started: float = field(default_factory=time.time)
    duration_ms: int = 0


def clean(text, limit=500):
    text = text.decode("utf-8", "replace") if isinstance(text, bytes) else str(text or "")
    return " ".join("".join(ch if ch.isprintable() else " " for ch in text).split())[:limit]


def enhanced_of(message):
    m = ENHANCED.match(clean(message))
    return m.group(1) if m else None


def result_of(code):
    return "sent" if 200 <= code < 300 else "bounced" if 500 <= code < 600 else "deferred"


class Permanent(Exception):
    def __init__(self, code, enhanced, text):
        super().__init__(text)
        self.code, self.enhanced, self.text = code, enhanced, text


class Temporary(Exception):
    pass


def make_resolver(nameserver):
    r = dns.asyncresolver.Resolver(configure=False)
    r.nameservers = [nameserver]
    r.lifetime = 20
    return r


async def mx_hosts(resolver, domain):
    """[(preference, host)] in the order to try. Raises Permanent (no such domain, null MX) or Temporary."""
    try:
        answer = await resolver.resolve(domain, "MX")
    except dns.resolver.NXDOMAIN:
        raise Permanent(550, "5.1.2", f"Domain {domain} does not exist")
    except dns.resolver.NoAnswer:
        return [(0, domain)]   # RFC 5321 5.1: implicit MX
    except (dns.exception.Timeout, dns.resolver.NoNameservers) as e:
        raise Temporary(f"MX lookup failed ({e.__class__.__name__})")
    hosts = [(rr.preference, rr.exchange.to_text(omit_final_dot=True).lower()) for rr in answer]
    if any(h in ("", ".") for _, h in hosts):
        raise Permanent(556, "5.1.10", f"Domain {domain} accepts no mail (null MX)")
    random.shuffle(hosts)
    return sorted(hosts, key=lambda x: x[0])


async def addresses(resolver, host, families):
    out = []
    for fam in families:   # 6 before 4
        try:
            answer = await resolver.resolve(host, "AAAA" if fam == 6 else "A")
            out += [(fam, rr.to_text()) for rr in answer]
        except (dns.resolver.NXDOMAIN, dns.resolver.NoAnswer):
            continue
        except (dns.exception.Timeout, dns.resolver.NoNameservers):
            continue
    return out


async def deliver(domain, rcpts, envelope_from, data, helo, sources, resolver=None, port=25, targets=None):
    """Delivers data to rcpts (all in domain). sources = {6: "ip", 4: "ip"} (only families the node sends with).
    targets = [(mx_host, [(family, ip)])] overrides DNS (tests). Returns the list of Try records."""
    tries = []
    if targets is None:
        t0 = time.time()
        try:
            hosts = await mx_hosts(resolver, domain)
        except Permanent as e:
            return [Try("dns", "bounced", list(rcpts), e.code, e.enhanced, e.text, started=t0, duration_ms=_ms(t0))]
        except Temporary as e:
            return [Try("dns", "deferred", list(rcpts), None, None, str(e), started=t0, duration_ms=_ms(t0))]
        families = [f for f in (6, 4) if sources.get(f)]
        targets = [(h, await addresses(resolver, h, families)) for _, h in hosts]
    connections = 0
    for host, addrs in targets:
        if not addrs:
            tries.append(Try("dns", "deferred", list(rcpts), None, None, f"No usable address for MX {host}", mx_host=host))
            continue
        for fam, ip in addrs:
            if connections >= MAX_CONNECTIONS:
                break
            connections += 1
            done = await _session(tries, host, ip, sources.get(fam), rcpts, envelope_from, data, helo, port, tls=True)
            if done:
                return tries
        if connections >= MAX_CONNECTIONS:
            break
    if not tries:
        tries.append(Try("dns", "deferred", list(rcpts), None, None, f"No MX address reachable for {domain}"))
    # only connection-level failures: permanent if every server refused with 5xx, else try again later
    if all(t.result == "bounced" for t in tries if t.stage in ("greeting", "ehlo")) and any(t.stage in ("greeting", "ehlo") for t in tries) \
            and not any(t.stage in ("connect", "dns", "starttls") for t in tries):
        return tries
    for t in tries:
        if t.result == "bounced" and t.stage in ("greeting", "ehlo"):
            t.result = "deferred"
    return tries


def _ms(t0):
    return int((time.time() - t0) * 1000)


async def _session(tries, host, ip, src, rcpts, envelope_from, data, helo, port, tls):
    """One SMTP connection. Appends Try records; True if the transaction got an answer (delivery attempt over)."""
    t0 = time.time()
    base = {"mx_host": host, "mx_ip": ip, "local_ip": src}
    smtp = aiosmtplib.SMTP(hostname=ip, port=port, local_hostname=helo or None, source_address=(src, 0) if src else None,
                           timeout=COMMAND_TIMEOUT, start_tls=False, use_tls=False, validate_certs=False)
    stage = "connect"
    tls_info = {}
    try:
        try:
            await asyncio.wait_for(smtp.connect(timeout=CONNECT_TIMEOUT), CONNECT_TIMEOUT + 5)
        except aiosmtplib.SMTPConnectResponseError as e:
            tries.append(Try("greeting", result_of(e.code), list(rcpts), e.code, enhanced_of(e.message), clean(e.message), started=t0, duration_ms=_ms(t0), **base))
            return False
        stage = "ehlo"
        try:
            await smtp.ehlo()
        except aiosmtplib.SMTPHeloError:
            await smtp.helo()
        if tls and smtp.supports_extension("starttls"):
            stage = "starttls"
            try:
                await smtp.starttls(server_hostname=host, validate_certs=False)
            except (aiosmtplib.SMTPException, OSError, asyncio.TimeoutError) as e:
                code = getattr(e, "code", None)
                tries.append(Try("starttls", "deferred", list(rcpts), code, None, f"STARTTLS failed: {clean(getattr(e, 'message', e))}", started=t0, duration_ms=_ms(t0), **base))
                await _close(smtp)
                return await _session(tries, host, ip, src, rcpts, envelope_from, data, helo, port, tls=False)
            ssl_obj = smtp.transport.get_extra_info("ssl_object") if smtp.transport else None
            if ssl_obj is not None:
                tls_info = {"tls_version": ssl_obj.version(), "tls_cipher": (ssl_obj.cipher() or [None])[0]}
        stage = "mail"
        options = [f"SIZE={len(data)}"] if smtp.supports_extension("size") else []
        try:
            await smtp.mail(envelope_from, options=options)
        except aiosmtplib.SMTPResponseException as e:
            tries.append(Try("mail", result_of(e.code), list(rcpts), e.code, enhanced_of(e.message), clean(e.message), started=t0, duration_ms=_ms(t0), **base, **tls_info))
            return True
        stage = "rcpt"
        accepted = []
        for r in rcpts:
            try:
                await smtp.rcpt(r)
                accepted.append(r)
            except aiosmtplib.SMTPResponseException as e:
                tries.append(Try("rcpt", result_of(e.code), [r], e.code, enhanced_of(e.message), clean(e.message), started=t0, duration_ms=_ms(t0), **base, **tls_info))
        if not accepted:
            return True
        stage = "data"
        try:
            resp = await smtp.data(data, timeout=DATA_TIMEOUT)
            tries.append(Try("data", result_of(resp.code), accepted, resp.code, enhanced_of(resp.message), clean(resp.message), started=t0, duration_ms=_ms(t0), **base, **tls_info))
        except aiosmtplib.SMTPResponseException as e:
            tries.append(Try("data", result_of(e.code), accepted, e.code, enhanced_of(e.message), clean(e.message), started=t0, duration_ms=_ms(t0), **base, **tls_info))
        return True
    except aiosmtplib.SMTPResponseException as e:
        tries.append(Try(stage, result_of(e.code) if stage != "connect" else "deferred", list(rcpts), e.code, enhanced_of(e.message), clean(e.message), started=t0, duration_ms=_ms(t0), **base, **tls_info))
        return stage in ("mail", "rcpt", "data")
    except (aiosmtplib.SMTPException, OSError, asyncio.TimeoutError) as e:
        text = clean(getattr(e, "message", None) or str(e) or e.__class__.__name__)
        tries.append(Try(stage, "deferred", list(rcpts), None, None, f"{e.__class__.__name__}: {text}", started=t0, duration_ms=_ms(t0), **base, **tls_info))
        # connection lost inside the transaction: the delivery attempt is over (do not risk a duplicate elsewhere)
        return stage in ("mail", "rcpt", "data")
    finally:
        await _close(smtp)


async def _close(smtp):
    try:
        if smtp.is_connected:
            await asyncio.wait_for(smtp.quit(), 10)
    except Exception:
        try:
            smtp.close()
        except Exception:
            pass


def outcome(tries, rcpt):
    """The deciding Try for a recipient (the last one that applies to it)."""
    for t in reversed(tries):
        if rcpt in t.rcpts:
            return t
    return None
