# Changelog

## 0.1.5 - 2026-11-02
- Admin, postmaster & abuse: self test mails (customer `system`) are hidden by default; switch
  "Selbsttests anzeigen" at the top. Empty lists show a centred "Keine Datensätze".
- Node admin API: `GET /admin/v1/role-mails?selftests=0` excludes the self test projects.

## 0.1.4 - 2026-11-02
- `mr:secrets:scan`: searches every text column of the node database and the log/data files
  (without the encrypted mail store and sessions) for secret values from `/etc/mailrunner/*.env` and the
  node key. Prints names and locations only, never values. Exit 1 on findings.
- Self test: new check `base.secret_leak` (same scan) - a leak makes the self test fail.

## 0.1.3 - 2026-11-02
- Admin: URLs use random public ids (`xxxx-xxxx`) for nodes, customers and projects instead of the
  numeric database ids (migration `PublicIds` backfills existing rows). Numeric ids give 404.
- Self test: secret values (secrets file, e.g. the Spamhaus DQS key inside DNS names of resolver errors)
  are redacted from check details. `node.selftest` is visible to every project.
- Node: project/customer updates journal only fields that really changed; an unchanged PUT from the admin
  creates no revision and no journal entry.

## 0.1.2 - 2026-11-02
- Admin: group membership is checked at sign-in with Microsoft Graph (`POST /me/checkMemberGroups`,
  transitive, delegated permission `User.Read` only). No groups claim / token configuration needed.
  A failed check denies the sign-in.

## 0.1.1 - 2026-11-02
- Admin: access through Entra security groups instead of the app role. `ENTRA_GROUP_RW` may change
  configuration, `ENTRA_GROUP_RO` only reads (pages, journal, postmaster/abuse mails). Forms are read-only
  and write requests answer 403 for RO users. The app registration must emit the groups claim.
- Admin: MailRunner logo, favicon and brand colours.

## 0.1.0 - 2026-11-01
- **MailRunner Admin** (decision E-19), a separate application in `admin/` with its own database:
  - CodeIgniter 4, Tabler (Bootstrap 5), htmx.
  - Sign-in with Entra ID (OpenID Connect, PKCE, ID token checked against the tenant keys). Administrators
    need the app role `MailRunner.Admin`.
  - Nodes: register a node, health (reachability, last self test, age), automatic refresh, newest journal
    events, take over the configuration that already exists on a node.
  - Customers and projects (desired state in the admin database): pushed to the node on every save.
    Shows the outcome of the push; API tokens can be created and revoked.
  - postmaster@/abuse@ mails of all nodes: list, safe display (text; HTML only in a sandboxed iframe without
    scripts or external content), download of the original. Every read is logged.
  - Audit log of all admin actions.
  - `install/install-admin.sh`: database, `/etc/mailrunner-admin/admin.env`, own CA and client certificate
    for mTLS, release, php-fpm, nginx (once the certificate exists), poll timer (every minute).
  - Commands `admin:poll`, `admin:node:add`, `admin:node:import`, `admin:project:push`.
- **Node admin API** `/admin/v1` (mTLS):
  - nginx verifies client certificates of the admin CA (`install.sh --admin-ca=...`) and answers 403
    without one.
  - Endpoints: node status with the full self test, node-wide journal (`latest=1`), customers (PUT),
    projects (idempotent PUT with routes and settings), tokens, postmaster/abuse mails with their originals.
  - Uses the same services as the CLI. A project changed by the admin GUI becomes `managed_by=admin`.
- Node installer: umask of the self test token block scoped to a subshell.

## 0.0.18 - 2026-11-01
- postmaster@ and abuse@ (RFC 2142) are accepted for every domain of every enabled project, independent of
  the recipient pattern (decision E-18). Domains that only have address routes get both addresses too.
- Their original mail is always kept (also with profile `analysis`); `role` in the message (API) and the
  journal event `receive.role_mail` (message id, role, recipient, sender, delivery setting).
- Project setting `role_mail` (`mr:project:set --role-mail=default|api|forward [--role-mail-forward-to=..]`):
  `default` = forward to the node operator, `api` = the customer collects them, `forward` = forward to an
  address. Forwarding needs the send role and is not implemented yet (TODO); until then the mails are kept
  and can be collected via the API.
- Self test scenario `role_abuse`: abuse@ accepted, original kept.

## 0.0.17 - 2026-11-01
- Journal `smtp.attempt` / `smtp.connection`: `duration_ms` (connect to disconnect) and `tls_ms`.
  `smtp.attempt` also has `timing` (ms after connect): `tls_ms`, `mail_from_ms`, `data_end_ms`, `reply_ms`,
  and `wait_ms` = time the sender waited for the reply after the data (store + analysis).

## 0.0.16 - 2026-11-01
- Self test checks every MX name of the node: node setting `MAILRUNNER_MX_NAMES` (installer option
  `--mx-names="mx.example.net=46 mx4.example.net=4 mx6.example.net=6"`). Per name: address families exactly
  as configured, all addresses on this node, STARTTLS with a valid certificate for that name over every
  family. Without the setting only the node name is checked.
- Self test warns about route domains whose MX does not point to this node.
- MX check sessions make a short transaction to the self test project, so they never show up as
  `smtp.connection` in other projects' journals.

## 0.0.15 - 2026-11-01
- The vendored SmartMailFlow analysis library and its tests (`mailpath/vendor/smartmailflow`) are now part
  of the repository. Before, the unanchored `.gitignore` rule `vendor/` (meant for Composer) also excluded
  `mailpath/vendor`, so a fresh clone had no analysis library. The rule is now `/vendor/`.

## 0.0.14 - 2026-11-01
- Self test (decision E-17).
  - Level 1: SmartMailFlow's 102 analysis tests (fixed DNS, no network) are vendored and run with the
    MailRunner tests; `install.sh` stops before changing anything if a test fails.
  - Level 2: `mr:selftest` (hourly timer `mailrunner-selftest.timer` and after every install) checks
    services, disk, mail queue, spool backlog, time sync, node key, DNSSEC validation, Spamhaus DQS key,
    reachability of ipregistry/blacklistmaster (no quota), SMTP over public IPv4/IPv6 (STARTTLS,
    certificate name and >= 14 days, recipient pattern, no open relay, postmaster, time to 250),
    7 analysis scenarios with fixed expectations (local zone `selftest.test` in unbound, test DKIM keys)
    and the API life cycle (collect, completeness, encryption at rest, isolation, ack, journal, deletion).
  - Result: journal event `node.selftest` (visible to every project, `scope: node`), `selftest` in
    `GET /v1/node/status`, last run in `/var/lib/mailrunner/selftest/last.{json,txt}`.
  - Self test configuration like any customer: projects `system/selftest` and `system/selftest-isolation`,
    tokens in `/etc/mailrunner/selftest.env` (created by `install.sh`).
- Mail from the node's own addresses does not query blacklistmaster/ipregistry (no quota).
- Fix: bursts of mail hit systemd's start limit of `mailrunner-import.service` and stopped the import
  path unit for good (found by the self test). Start limit raised, 1 s pause after each import run.
- `mr:maintenance` also deletes `node.selftest` journal entries after `MAILRUNNER_SMTP_JOURNAL_DAYS`.

## 0.0.13 - 2026-11-01
- mr-smtplog: read journalctl unbuffered. With a buffered pipe, select() did not report lines already
  read into the Python buffer, so the last line of a session (disconnect) waited for the next log line.
  Wall-clock closing of sessions only when idle (during a backlog the entry time is used).

## 0.0.12 - 2026-11-01
- Journal of delivery attempts: new service `mailrunner-smtplog` (`mr-smtplog`) follows the mail log
  (Postfix smtpd/cleanup + milter session lines), builds one record per SMTP session and hands it to
  `mr:import`. Journal events `smtp.attempt` (one per transaction and project, only own recipients and
  message ids) and `smtp.connection` (session without MAIL FROM, no project).
- Project setting `journal_scope` (`mr:project:set --journal-scope=own|connections|unassigned`):
  which events without a project a token sees. Default `own`.
- `GET /v1/journal`: field `scope` (`project` | `unassigned`), filter `event=a,b`.
- mr-milter logs `session helo|mail|rcpt client=[ip]:port ...`; Postfix `smtpd_client_port_logging = yes`.
- `mr:maintenance` deletes `smtp.*` journal entries after `MAILRUNNER_SMTP_JOURNAL_DAYS` (default 30).

## 0.0.11 - 2026-11-01
- Customer API: `GET /v1/messages?include=content` returns `record`, `debug` and `raw_headers` with
  every message (page size max 100), so a client collects with one request per page instead of one per message.
- OpenAPI 3.1 description of the customer API in `docs/api/openapi.yaml`.
- Feature tests for the collection flow (list with content, ack, project isolation, authentication).

## 0.0.10 - 2026-11-01
- ipregistry key name in `secrets.env` is `IPREGISTRYIO_TOKEN` (matches `IPREGISTRYIO_CACHE_SECONDS`).

## 0.0.9 - 2026-11-01
- ipregistry.co lookup of the client IP (key in `secrets.env`, see 0.0.10): AS number, organization,
  domain, route and type, country, city, continent, carrier, `security.is_threat`. Result in
  `debug.ipregistry`; cached 1 hour per IP (`IPREGISTRYIO_CACHE_SECONDS`), only successful answers.
  Loopback/private addresses are not sent; errors never contain the API key.
- Database and API (`envelope.client`): `client_asn`, `client_as_org`, `client_country`, `client_city`,
  `client_continent`, `client_carrier`, `client_is_threat`.

## 0.0.8 - 2026-11-01
- Spamhaus answers (Spamhaus TTL is 1 s) and blacklistmaster results are kept for 1 hour in the milter
  (in memory, shared by all messages). Only real answers are cached (listed / not listed), never timeouts
  or errors. Cached entries carry `cached: true` and `cached_at` in the analysis JSON.
  Lifetimes: `SPAMHAUS_CACHE_SECONDS`, `BLM_CACHE_SECONDS` in `/etc/mailrunner/secrets.env` (default 3600).

## 0.0.7 - 2026-11-01
- Receive decisions before the 250 (decision E-11): new `mr-milter` (Python, pymilter) replaces the pipe
  (`mr-deliver`, `mr-analyze`). At end of data it stores the message, runs the analysis for every message and
  only then answers 250 (milter DISCARD: Postfix drops its queue copy) or 451. Time limit 90 s: profile
  `analysis` is kept with `analysis_status = timeout`, other profiles get 451. Milter unavailable = 451.
- Per message id two encrypted files in `store/YYYY/MM/DD/`: `<id>.analysis.json.enc` (always) and
  `<id>.eml.enc` (not for profile `analysis`, decision E-12).
- Database keeps envelope, From, To, Subject, Date, Message-ID, attachments (name, size, type), SPF/DKIM status
  and analysis duration; the encrypted analysis blob in the database is gone.
- Raw message rebuilt byte-exactly from milter callbacks (`P_HDR_LEADSPC`); own Received header in Postfix
  format (Postfix does not pass its own to milters).
- One project per SMTP transaction (further recipients get 452 4.5.3 and are sent again by the client).
- Node resolver: unbound (recursive, DNSSEC, TTL cache shared by all analyses); systemd-resolved forwards to it
  without caching. `mr:dns:flush [<domain>]` empties the cache.
- API: `GET /v1/messages/{id}` returns envelope, header, attachments; `GET /v1/messages/{id}/raw` (if kept).
- blacklistmaster is not asked for loopback/private client addresses.

## 0.0.6 - 2026-11-01
- Analysis credentials are node-wide now: `/etc/mailrunner/secrets.env` (`SPAMHAUS_DQS_KEY`, `BLM_USER`,
  `BLM_PASS`, same names as SmartMailFlow). `install.sh` creates an empty template.
- Removed the project secrets of 0.0.5 (`mr:secret:*`, table `project_secrets` dropped by migration).

## 0.0.5 - 2026-11-01
- Project secrets (`project_secrets`), encrypted with the node key; only known names
  (`spamhaus_dqs_key`, `blacklistmaster_user`, `blacklistmaster_pass`). Values are read from stdin and never shown.
  CLI: `mr:secret:set`, `mr:secret:list`, `mr:secret:unset`.
- `mr:apply` writes per-project analysis settings encrypted (`analysis.json.enc`); `mr-analyze` uses the
  Spamhaus DQS key and, if configured, adds the blacklistmaster result (`debug.blacklistmaster`) like SmartMailFlow.
- Mail path tests no longer write to the system log.

## 0.0.4 - 2026-11-01
- Receive mail path, profile `analysis` (first end-to-end version):
  - Postfix (receive role): no local delivery, no outbound mail; recipients from generated maps;
    project recipient pattern checked at RCPT TO (`550 5.1.1`).
  - `mr-deliver` (Python, Postfix pipe): stores mail + SMTP data encrypted in the spool (fsync, json last), no DNS.
  - `mr-analyze` (Python, systemd path unit): runs the vendored SmartMailFlow analysis
    (`mailpath/vendor/smartmailflow`, see VENDOR.md), keeps header data + analysis JSON, deletes the raw mail.
  - `mr:import`: spool -> `receive_messages` (content encrypted), journal `receive.received`.
- Encryption at rest with a random per-node key (`mr:node:init`), XChaCha20-Poly1305, same format in PHP and
  Python (shared test vectors).
- `mr:apply`: generates Postfix maps and routes.json, activates atomically, reloads Postfix. Runs automatically
  after configuration changes (systemd path unit).
- Customer API (Bearer token): `GET /v1/node/status`, `GET /v1/journal`, `GET /v1/messages`,
  `GET /v1/messages/{id}` (record, analysis JSON, raw headers), `POST /v1/messages/ack`.
- `mr:maintenance`: deletes content after ack / expiry and old metadata (timer, every 15 minutes).
- `mr:spool:retry`: moves failed spool entries back for another attempt.
- `install/install.sh`: installs or updates a receive node (packages, user, directories, release with
  `current`/`previous` symlinks, database, node key, Postfix, systemd units, php-fpm pool, nginx).
- CLI wrapper `/usr/local/sbin/mailrunner` (runs spark as user `mailrunner`).

## 0.0.3 - 2026-11-01
- Database schema (CI4 migrations): journal with gap-free sequence, customers, projects
  (profiles `analysis`, `checked`, `checked_auth`, revision, `managed_by`), API tokens,
  SMTP credentials, receive routes.
- Configuration layer shared by CLI and the future Admin API: same validation, revision
  increment and journal event (`source` = cli/admin) for every change.
- Several API tokens per project, each with a public id (`mrt_…`) and a secret shown once
  (stored as SHA-256). Bearer format `<token_id>.<secret>`. Rotation with grace period, revoke.
- Several SMTP credentials per project (profile `checked_auth`), public id (`mrc_…`),
  Argon2id hash, TLS requirement, allowed networks. Password rotation with grace period
  (old password stays valid), revoke.
- Admin-managed projects can only be changed from the CLI with `--force` (journaled).
- CLI: `mr:customer:*`, `mr:project:*`, `mr:route:*`, `mr:token:*`, `mr:credential:*`, `mr:journal`.
  Values as `--option=value`, flags as `--flag`; `--json` for machine-readable output.
- Feature tests against a MariaDB test database.

## 0.0.2 - 2026-11-01
- License: MIT with attribution clause (see LICENSE).
- README links to the public documentation.

## 0.0.1 - 2026-11-01
- Project skeleton based on CodeIgniter 4.7.
- Directory layout for shared core, receive and send modules, mail path and installer.
- `Config\Mailrunner`: roles via `MAILRUNNER_RECEIVE_ENABLED` / `MAILRUNNER_SEND_ENABLED`, node id via `MAILRUNNER_NODE_ID`.
- `php spark mr:status [--json]` with monitoring exit codes (0 ok, 1 warning, 2 error).
