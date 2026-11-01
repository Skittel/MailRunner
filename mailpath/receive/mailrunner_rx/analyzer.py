"""Runs the vendored SmartMailFlow analysis with the node resolver (unbound, TTL cache shared by
all messages) and optional blacklistmaster check, within a time limit."""
import os
import sys
import threading

from . import blacklistmaster, config

_VENDOR = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..", "vendor", "smartmailflow")
if _VENDOR not in sys.path:
    sys.path.insert(0, _VENDOR)

import dns.resolver  # noqa: E402
from smartmailflow import analysis as smf_analysis  # noqa: E402
from smartmailflow import dnsclient as smf_dnsclient  # noqa: E402


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
            result = smf_analysis.analyze(raw, envelope, smf_dnsclient.DNSClient(),
                                          {"spamhaus_dqs_key": creds.get("SPAMHAUS_DQS_KEY")})
            if creds.get("BLM_USER") and creds.get("BLM_PASS"):
                result["debug"]["blacklistmaster"] = blacklistmaster.check(
                    envelope["client_ip"], mail_id, creds["BLM_USER"], creds["BLM_PASS"])
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
