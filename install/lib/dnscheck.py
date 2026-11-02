#!/usr/bin/env python3
"""DNS check before installing a node: do the host name and the MX names point to this server?

    install/lib/dnscheck.py node1.example.net=46 node1-ip4.example.net=4 node1-ip6.example.net=6

Each name must resolve to exactly the given IP versions (46 = IPv4 and IPv6), and every address must belong to
this server. Also shows the reverse DNS (PTR) of the server addresses. Exit 0 = ok, 1 = problem.
Used by install.sh (skip with --skip-dns-check, e.g. behind NAT); can be run on its own.
"""
import json
import socket

import dns.exception
import subprocess
import sys


def local_ips():
    out = subprocess.run(["ip", "-j", "addr", "show", "scope", "global"], capture_output=True, text=True).stdout
    ips = set()
    for iface in json.loads(out or "[]"):
        for a in iface.get("addr_info", []):
            ip = a.get("local")
            if ip and not a.get("deprecated") and not ip.startswith("fe80"):
                ips.add(ip)
    return ips


def authoritative(name):
    """IP addresses of the authoritative name servers of the zone of name (walks up the labels)."""
    import dns.resolver
    labels = name.rstrip(".").split(".")
    for i in range(len(labels) - 1):
        zone = ".".join(labels[i:])
        try:
            ns = [r.to_text() for r in dns.resolver.resolve(zone, "NS", lifetime=10)]
        except (dns.resolver.NXDOMAIN, dns.resolver.NoAnswer, dns.resolver.NoNameservers, dns.exception.Timeout):
            continue
        ips = []
        for host in ns:
            for rr in ("A", "AAAA"):
                try:
                    ips += [r.to_text() for r in dns.resolver.resolve(host, rr, lifetime=10)]
                except Exception:
                    pass
        if ips:
            return ips
    return []


def resolve(name, family):
    """As the world sees it: asks the authoritative name servers directly. Neither /etc/hosts nor the local
    resolver (both may map the own host name to 127.0.1.1) are used."""
    import dns.message
    import dns.query
    import dns.rdatatype
    rtype = dns.rdatatype.A if family == socket.AF_INET else dns.rdatatype.AAAA
    for server in authoritative(name):
        try:
            resp = dns.query.udp(dns.message.make_query(name, rtype), server, timeout=5)
        except Exception:
            continue
        return sorted({r.to_text() for rrset in resp.answer if rrset.rdtype == rtype for r in rrset})
    return []


def ptr(ip):
    import dns.resolver
    import dns.reversename
    try:
        return dns.resolver.resolve(dns.reversename.from_address(ip), "PTR", lifetime=10)[0].to_text().rstrip(".")
    except Exception:
        return "-"


def check(entries, own):
    problems, lines = [], []
    for entry in entries:
        name, _, fams = entry.partition("=")
        fams = fams or "46"
        v4, v6 = resolve(name, socket.AF_INET), resolve(name, socket.AF_INET6)
        lines.append(f"{name:45} A: {', '.join(v4) or '-':30} AAAA: {', '.join(v6) or '-'}")
        for fam, addrs, label in (("4", v4, "A"), ("6", v6, "AAAA")):
            if fam in fams and not addrs:
                problems.append(f"{name}: {label} record missing")
            if fam not in fams and addrs:
                problems.append(f"{name}: has {label} record, expected only IPv{fams}")
            for a in addrs:
                if a not in own:
                    problems.append(f"{name}: {a} is not an address of this server")
    return problems, lines


def main(argv):
    entries = [a for a in argv if not a.startswith("-")]
    if not entries:
        print(__doc__)
        return 2
    own = local_ips()
    problems, lines = check(entries, own)
    print("\n".join(lines))
    for ip in sorted(own):
        print(f"PTR {ip:39} -> {ptr(ip)}")
    for p in problems:
        print("PROBLEM: " + p)
    print("DNS ok" if not problems else "DNS not ready")
    return 1 if problems else 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
