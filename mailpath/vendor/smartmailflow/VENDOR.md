# Vendored: SmartMailFlow analysis library

| | |
|---|---|
| Source | https://github.com/Skittel/smartmailflow, `app/smartmailflow/` |
| Commit | `cc75b5965a2dcf801eae373153121d76d088d8ee` |
| Version | 2.4.0 |
| Copied | 2026-11-01 |
| Permission | Included in MailRunner with the permission of the SmartMailFlow owner (only the parts MailRunner uses). |

## What is used

`analysis.analyze(raw_bytes, envelope, dnsc, config)` and the modules it needs:
`auth`, `dnsanalysis`, `dnsclient`, `domainsec`, `net`, `smime`.
MailRunner runs it in `mailpath/receive/bin/mr-milter` (via `mailrunner_rx/analyzer.py`) for every received message;
the result has the same structure as SmartMailFlow's own records.

Also copied (level 1 of the self test, decision E-17): `tests/test_analysis.py`, `tests/mailtools.py` and
`tests/keys/` (test-only DKIM keys and S/MIME certificates for zones that do not exist on the Internet)
from commit `683f14788926cf77ea26edd2300ddc135cf318e5` (2.5.0; the library itself is unchanged since 2.4.0
except the version string). Run by `mailpath/tests/test_vendor_analysis.py`; `install.sh` stops if a test fails.

Not copied: the SmartMailFlow receive pipe (`process_email.py`), other tests, web, configs.

## Local changes

None. Do not edit these files here; change SmartMailFlow and copy again (update this file).

Known differences when running on a MailRunner node:
- `analysis.OWN_AUTHSERV` names the SmartMailFlow MX; MailRunner runs no milters, so
  `mta_checks` stays empty (it is only a cross-check).
- Queue id and TLS are read from the topmost `Received` header. Postfix does not pass its own Received
  header to milters, so `mr-milter` prepends one in Postfix format (from the milter macros).
- DNS: `mr-milter` points `dns.resolver` and `dnsclient.upstream_resolvers` to the node resolver (unbound).
- `dnsclient.DNSClient.smtp_tls()` greets sender MX hosts with the fixed name `mx-de-01.smartmailflow.email`
  (to be made configurable in SmartMailFlow).
