# Changelog

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
