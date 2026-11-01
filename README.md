# MailRunner

Independent mail nodes for **sending** and **receiving** e-mail via API and SMTP.
Every node runs on its own (own MariaDB, own APIs, no central service at runtime) and can
prove for every message where it came from, how it was checked, what happened to it and
when it was delivered or collected.

- One code base; roles (send, receive or both) are enabled per node by configuration.
- Mail transport: Postfix. Mail path scripts: Python. Database, APIs and CLI: PHP 8.4+ /
  CodeIgniter 4 / MariaDB.
- Messages are stored encrypted on the node while they are needed.

**Status:** early development (0.0.x). The receive role is built first.

## Layout

| Path | Content |
|---|---|
| `app/Core/` | Shared code for both roles (journal, API base, auth, webhooks, secrets, node) |
| `app/Modules/Receive/`, `app/Modules/Send/` | Role modules |
| `app/Commands/` | CLI commands (`php spark mr:*`) |
| `mailpath/` | Python scripts running inside the Postfix mail path |
| `install/` | Node installation, update and configuration templates |
| `tests/` | PHP tests; `tests/shared/` holds test vectors shared with Python |

## Development

```bash
composer install
cp env .env          # adjust MAILRUNNER_* values
php spark mr:status
composer test
```

## Versioning

`n.n.n`, each part 0-999. The current version is in `VERSION`; changes are listed in `CHANGELOG.md`.
