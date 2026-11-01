"""DNS-Zugriff für die Analyse – echt (dnspython) oder mit festen Antworten (Tests).

Einheitliche Schnittstelle:  query(name, rtype) -> (antworten, fehler)
  antworten  Liste von Strings: A/AAAA = Adresse, MX = "10 host.", TXT = zusammengesetzter Text,
             CNAME/PTR = Zielname mit Punkt
  fehler     None | "nxdomain" | "nodata" | "servfail" | "timeout" | "error:<Typ>"
"""
import time

import dns.exception
import dns.flags
import dns.message
import dns.query
import dns.rcode
import dns.rdatatype
import dns.resolver


def classify(e):
    if isinstance(e, dns.resolver.NXDOMAIN):
        return "nxdomain"
    if isinstance(e, dns.resolver.NoAnswer):
        return "nodata"
    if isinstance(e, dns.resolver.NoNameservers):
        return "servfail"
    if isinstance(e, (dns.exception.Timeout, dns.resolver.LifetimeTimeout)):
        return "timeout"
    return f"error:{e.__class__.__name__}"


def _rdata_text(rtype, r):
    if rtype == "TXT":
        return b"".join(r.strings).decode(errors="replace")
    if rtype in ("A", "AAAA"):
        return r.address
    if rtype == "MX":
        return f"{r.preference} {r.exchange.to_text()}"
    if rtype in ("CNAME", "PTR"):
        return r.target.to_text()
    return r.to_text()


# Validierende Upstream-Resolver (systemd-resolved selbst gibt das AD-Bit nicht weiter)
UPSTREAM_RESOLV_CONF = "/run/systemd/resolve/resolv.conf"


def upstream_resolvers(path=UPSTREAM_RESOLV_CONF):
    try:
        with open(path) as fh:
            return [l.split()[1] for l in fh if l.startswith("nameserver") and len(l.split()) > 1]
    except OSError:
        return []


class DNSClient:
    """Echter Resolver (System-Resolver aus /etc/resolv.conf) mit Cache pro Mail."""

    def __init__(self, lifetime=5.0):
        self.lifetime = lifetime
        self.cache = {}
        self.log = []          # (name, rtype, fehler) – Nachweis für das Debug-JSON
        self.last_ms = 0       # Dauer der letzten echten Abfrage (für DNSBL-Nachweis)

    def _lookup(self, name, rtype):
        t = time.monotonic()
        try:
            ans = dns.resolver.resolve(name, rtype, lifetime=self.lifetime)
            return [_rdata_text(rtype, r) for r in ans], None
        except Exception as e:
            return [], classify(e)
        finally:
            self.last_ms = round((time.monotonic() - t) * 1000)

    def query(self, name, rtype):
        key = (name.lower().rstrip("."), rtype.upper())
        if key not in self.cache:
            self.cache[key] = self._lookup(key[0], key[1])
            self.log.append((key[0], key[1], self.cache[key][1]))
        return self.cache[key]

    def query_dnssec(self, name, rtype, timeout=3.0):
        """Abfrage bei einem validierenden Resolver: (antworten, fehler, validiert).
        validiert = AD-Bit gesetzt (DNSSEC-Kette gültig). SERVFAIL bei signierter Zone = Validierungsfehler."""
        key = (name.lower().rstrip("."), rtype.upper(), "dnssec")
        if key in self.cache:
            return self.cache[key]
        result = ([], "timeout", False)
        for ns in upstream_resolvers():
            try:
                q = dns.message.make_query(key[0], key[1], want_dnssec=True)
                q.flags |= dns.flags.AD
                resp = dns.query.udp(q, ns, timeout=timeout)
                if resp.flags & dns.flags.TC:
                    resp = dns.query.tcp(q, ns, timeout=timeout)
            except Exception:
                continue
            rc, ad = resp.rcode(), bool(resp.flags & dns.flags.AD)
            if rc == dns.rcode.NXDOMAIN:
                result = ([], "nxdomain", ad)
            elif rc != dns.rcode.NOERROR:
                result = ([], "servfail", False)
            else:
                ans = [_rdata_text(key[1], rd) for rrset in resp.answer for rd in rrset
                       if rrset.rdtype == dns.rdatatype.from_text(key[1])]
                result = (ans, None if ans else "nodata", ad)
            break
        self.cache[key] = result
        self.log.append((key[0], key[1] + "@dnssec", result[1]))
        return result

    def fetch(self, url, timeout=4.0, max_bytes=65536):
        """HTTPS-Abruf (MTA-STS-Policy): (http_status, text, fehler). Zertifikat wird geprüft."""
        import requests
        try:
            r = requests.get(url, timeout=timeout, allow_redirects=False, stream=True,
                             headers={"User-Agent": "SmartMailFlow"})
            body = r.raw.read(max_bytes, decode_content=True).decode("utf-8", "replace")
            return r.status_code, body, None
        except requests.exceptions.SSLError:
            return None, "", "tls_error"
        except requests.exceptions.Timeout:
            return None, "", "timeout"
        except Exception as e:
            return None, "", f"error:{e.__class__.__name__}"

    def smtp_tls(self, host, timeout=8.0):
        """STARTTLS bei einem MX (Port 25): Protokoll, Cipher, Zertifikat, Gültigkeit der Kette.
        status: pass = gültig | warn = Zertifikat ungültig (abgelaufen, selbstsigniert, falscher Name)
                fail = kein STARTTLS | error = nicht erreichbar"""
        import smtplib
        import ssl
        from .dnsanalysis import cert_summary
        res = {"starttls": None, "protocol": None, "cipher": None, "verified": None, "verify_error": None,
               "certificate": None, "status": "error", "reason": ""}

        def connect(ctx):
            s = smtplib.SMTP(host, 25, timeout=timeout, local_hostname="mx-de-01.smartmailflow.email")
            s.ehlo()
            if not s.has_extn("starttls"):
                s.quit()
                return None
            s.starttls(context=ctx)
            return s

        try:
            try:
                s = connect(ssl.create_default_context())
                res["verified"] = True if s else None
            except ssl.SSLCertVerificationError as e:
                res.update(verified=False, verify_error=e.verify_message)
                ctx = ssl.create_default_context()
                ctx.check_hostname, ctx.verify_mode = False, ssl.CERT_NONE
                s = connect(ctx)
            if s is None:
                res.update(starttls=False, status="fail", reason="Server bietet kein STARTTLS an")
                return res
            res.update(starttls=True, protocol=s.sock.version(), cipher=s.sock.cipher()[0])
            der = s.sock.getpeercert(binary_form=True)
            try:
                s.quit()
            except Exception:
                pass
            if der:
                res["certificate"] = cert_summary(der, host)
            if res["verified"]:
                res["status"] = "pass"
            else:
                res.update(status="warn", reason=f"Zertifikat ungültig: {res['verify_error']}")
        except (OSError, smtplib.SMTPException, ssl.SSLError) as e:
            res.update(status="error", reason=f"nicht erreichbar ({e.__class__.__name__})")
        return res

    def query_direct(self, name, rtype, nameservers, timeout=1.5):
        """Direkt bei autoritativen Servern fragen (für Listen, die große Resolver ignorieren).
        Erster Server mit Antwort gewinnt; antwortet keiner → "timeout"."""
        key = (name.lower().rstrip("."), rtype.upper(), "direct")
        if key in self.cache:
            return self.cache[key]
        result = ([], "timeout")
        for ns in nameservers:
            addrs, _ = self.query(ns, "A")
            for addr in addrs[:1]:
                try:
                    resp = dns.query.udp(dns.message.make_query(key[0], key[1]), addr, timeout=timeout)
                except Exception:
                    continue
                rc = resp.rcode()
                if rc == dns.rcode.NXDOMAIN:
                    result = ([], "nxdomain")
                elif rc != dns.rcode.NOERROR:
                    result = ([], "servfail")
                else:
                    ans = [_rdata_text(key[1], rd) for rrset in resp.answer for rd in rrset
                           if rrset.rdtype == dns.rdatatype.from_text(key[1])]
                    result = (ans, None if ans else "nodata")
                self.cache[key] = result
                self.log.append((key[0], key[1] + "@" + ns, result[1]))
                return result
        self.cache[key] = result
        self.log.append((key[0], key[1] + "@direct", "timeout"))
        return result


class FakeDNS(DNSClient):
    """Feste DNS-Antworten für Tests.

    zone = {("name", "TXT"): ["v=spf1 -all"], ("x", "A"): "timeout", ...}
    Fehler als String: "nxdomain" | "servfail" | "timeout" | "nodata".
    Ein Name ohne jeden Eintrag ergibt nxdomain, ein Name mit anderen Typen nodata.
    """

    def __init__(self, zone):
        super().__init__()
        self.zone = {(n if t.upper() == "HTTP" else n.lower().rstrip("."), t.upper()): v
                     for (n, t), v in zone.items()}
        self.names = {n for n, _ in self.zone}

    def _lookup(self, name, rtype):
        v = self.zone.get((name, rtype))
        if isinstance(v, str):
            return [], v
        if v:
            return list(v), None
        # CNAME wie ein Resolver auflösen (außer bei direkter CNAME-Abfrage)
        cname = self.zone.get((name, "CNAME"))
        if rtype != "CNAME" and isinstance(cname, list) and cname:
            return self._lookup(cname[0].lower().rstrip("."), rtype)
        return [], ("nodata" if name in self.names else "nxdomain")

    def query_direct(self, name, rtype, nameservers, timeout=1.5):
        return self.query(name, rtype)

    def query_dnssec(self, name, rtype, timeout=3.0):
        """Validiert = Zone (oder Elternzone) steht als ("zone", "DNSSEC"): True in der Testzone;
        ("zone", "DNSSEC"): "bogus" ergibt servfail wie bei einem validierenden Resolver."""
        n = name.lower().rstrip(".")
        flag = None
        labels = n.split(".")
        for i in range(len(labels)):
            v = self.zone.get((".".join(labels[i:]), "DNSSEC"))
            if v is not None:
                flag = v
                break
        if flag == "bogus":
            return [], "servfail", False
        answers, err = self.query(n, rtype)
        return answers, err, flag is True

    def smtp_tls(self, host, timeout=8.0):
        v = self.zone.get((host.lower().rstrip("."), "SMTPTLS"))
        if v is None:
            return {"starttls": None, "protocol": None, "cipher": None, "verified": None, "verify_error": None,
                    "certificate": None, "status": "error", "reason": "nicht erreichbar (Test)"}
        return v

    def fetch(self, url, timeout=4.0, max_bytes=65536):
        v = self.zone.get((url, "HTTP"))
        if v is None:
            return None, "", "error:ConnectionError"
        if isinstance(v, str) and v in ("timeout", "tls_error"):
            return None, "", v
        return v[0], v[1], None
