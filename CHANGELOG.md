# Changelog

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
