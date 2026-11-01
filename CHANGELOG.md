# Changelog

## 0.0.1 - 2026-11-01
- Project skeleton based on CodeIgniter 4.7.
- Directory layout for shared core, receive and send modules, mail path and installer.
- `Config\Mailrunner`: roles via `MAILRUNNER_RECEIVE_ENABLED` / `MAILRUNNER_SEND_ENABLED`, node id via `MAILRUNNER_NODE_ID`.
- `php spark mr:status [--json]` with monitoring exit codes (0 ok, 1 warning, 2 error).
