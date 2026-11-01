"""Runs the vendored SmartMailFlow analysis with the node resolver (unbound, TTL cache shared by
all messages) and optional blacklistmaster check, within a time limit.

Spamhaus answers (TTL 1 s), blacklistmaster and ipregistry results are additionally kept for
1 hour in memory (decision 01.11.26); cached entries are marked with "cached": true and "cached_at".
"""
import os
import sys
import threading
import time

from . import blacklistmaster, config, ipregistry
from .cache import TTLCache

_VENDOR = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..", "vendor", "smartmailflow")
if _VENDOR not in sys.path:
    sys.path.insert(0, _VENDOR)

import dns.resolver  # noqa: E402
from smartmailflow import analysis as smf_analysis  # noqa: E402
from smartmailflow import dnsclient as smf_dnsclient  # noqa: E402


SPAMHAUS_SUFFIXES = (".spamhaus.net", ".spamhaus.org")
DEFAULT_CACHE_SECONDS = 3600
SPAMHAUS_CACHE = TTLCache(DEFAULT_CACHE_SECONDS)
BLM_CACHE = TTLCache(DEFAULT_CACHE_SECONDS)
IPREGISTRY_CACHE = TTLCache(DEFAULT_CACHE_SECONDS)
CACHEABLE_DNS_ERRORS = (None, "nxdomain", "nodata")   # listed / not listed; never timeouts or failures


def configure_caches(creds):
    """Lifetimes from secrets.env (SPAMHAUS_CACHE_SECONDS, BLM_CACHE_SECONDS, IPREGISTRYIO_CACHE_SECONDS), default 3600."""
    for cache, name in ((SPAMHAUS_CACHE, "SPAMHAUS_CACHE_SECONDS"), (BLM_CACHE, "BLM_CACHE_SECONDS"),
                        (IPREGISTRY_CACHE, "IPREGISTRYIO_CACHE_SECONDS")):
        try:
            cache.ttl = int(creds.get(name, DEFAULT_CACHE_SECONDS))
        except ValueError:
            cache.ttl = DEFAULT_CACHE_SECONDS


def iso(ts):
    return time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime(ts))


class CachingDNSClient(smf_dnsclient.DNSClient):
    """DNSClient that keeps Spamhaus answers in SPAMHAUS_CACHE and records which ones came from it."""

    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        self.cache_hits = {}   # zone -> stored_at

    def _lookup(self, name, rtype):
        lname = name.lower().rstrip(".")
        if not lname.endswith(SPAMHAUS_SUFFIXES):
            return super()._lookup(name, rtype)
        key = (lname, rtype.upper())
        hit = SPAMHAUS_CACHE.get(key)
        if hit is not None:
            self.last_ms = 0
            self.cache_hits[lname] = hit[1]
            return hit[0]
        result = super()._lookup(name, rtype)
        if result[1] in CACHEABLE_DNS_ERRORS:
            SPAMHAUS_CACHE.put(key, result)
        return result


def mark_cached_dnsbl(debug, client):
    """Adds cached/cached_at to the Spamhaus entries of debug["dnsbl"]["lists"]."""
    if not client.cache_hits:
        return
    stored_at = min(client.cache_hits.values())
    for entry in (debug.get("dnsbl") or {}).get("lists") or []:
        if "spamhaus" in (entry.get("zone") or "").lower() or "spamhaus" in (entry.get("name") or "").lower():
            entry["cached"] = True
            entry["cached_at"] = iso(stored_at)


def blacklistmaster_cached(ip, mail_id, user, password):
    hit = BLM_CACHE.get(ip)
    if hit is not None:
        return dict(hit[0], cached=True, cached_at=iso(hit[1])) if isinstance(hit[0], dict) else hit[0]
    result = blacklistmaster.check(ip, mail_id, user, password)
    # cache only real answers from the API (dict without "skipped"); errors are strings like "http_500"
    if isinstance(result, dict) and result.get("status") != "skipped":
        BLM_CACHE.put(ip, result)
    return result


def ipregistry_cached(ip, api_key):
    hit = IPREGISTRY_CACHE.get(ip)
    if hit is not None:
        return dict(hit[0], cached=True, cached_at=iso(hit[1]))
    result = ipregistry.lookup(ip, api_key)
    if result.get("status") == "ok":
        IPREGISTRY_CACHE.put(ip, result)
    return result


_OWN = {"at": 0.0, "addresses": frozenset()}


def node_addresses(ttl=300):
    """IP addresses of this node (all interfaces), refreshed every ttl seconds."""
    import ipaddress
    import json as _json
    import subprocess
    now = time.monotonic()
    if now - _OWN["at"] >= ttl:
        found = set()
        try:
            out = subprocess.run(["ip", "-j", "addr", "show"], capture_output=True, text=True, timeout=5).stdout
            for iface in _json.loads(out or "[]"):
                for a in iface.get("addr_info", []):
                    found.add(str(ipaddress.ip_address(a["local"])))
        except Exception:
            pass
        _OWN.update(at=now, addresses=frozenset(found))
    return _OWN["addresses"]


def is_own_address(ip):
    """Mail from the node itself (self test, local tools) uses no external API quota."""
    import ipaddress
    try:
        return str(ipaddress.ip_address(ip)) in node_addresses()
    except ValueError:
        return False


SKIPPED_OWN = {"status": "skipped", "reason": "node's own address"}


def use_node_resolver(address=None):
    """All lookups of the analysis go to the local unbound: normal queries and the DNSSEC (AD bit) ones."""
    address = address or config.RESOLVER
    resolver = dns.resolver.Resolver(configure=False)
    resolver.nameservers = [address]
    dns.resolver.default_resolver = resolver
    smf_dnsclient.upstream_resolvers = lambda path=None: [address]


class AnalysisTimeout(Exception):
    pass


def analyze(raw, envelope, creds, mail_id, timeout):
    """Returns the SmartMailFlow result dict (record fields + debug). Raises AnalysisTimeout."""
    box = {}

    def run():
        try:
            client = CachingDNSClient()
            result = smf_analysis.analyze(raw, envelope, client, {"spamhaus_dqs_key": creds.get("SPAMHAUS_DQS_KEY")})
            mark_cached_dnsbl(result["debug"], client)
            own = is_own_address(envelope["client_ip"])
            if creds.get("BLM_USER") and creds.get("BLM_PASS"):
                result["debug"]["blacklistmaster"] = dict(SKIPPED_OWN) if own else blacklistmaster_cached(
                    envelope["client_ip"], mail_id, creds["BLM_USER"], creds["BLM_PASS"])
            if creds.get("IPREGISTRYIO_TOKEN"):
                result["debug"]["ipregistry"] = dict(SKIPPED_OWN) if own else ipregistry_cached(
                    envelope["client_ip"], creds["IPREGISTRYIO_TOKEN"])
            box["result"] = result
        except Exception as e:  # reported to the caller
            box["error"] = e

    worker = threading.Thread(target=run, daemon=True)
    worker.start()
    worker.join(max(1.0, timeout))
    if worker.is_alive():
        raise AnalysisTimeout(f"analysis not finished within {timeout:.0f} s")
    if "error" in box:
        raise box["error"]
    return box["result"]
