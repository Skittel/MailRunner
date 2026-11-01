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

**Documentation:** https://wiki.skit.center/books/mailrunnerpub · API: [`docs/api/openapi.yaml`](docs/api/openapi.yaml)

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
cp env .env          # set MAILRUNNER_* and database.default.* / database.tests.*
php spark migrate --all
php spark mr:status
composer test        # needs the MariaDB test database (database.tests.*)
```

## CLI example

```bash
php spark mr:customer:create acme --name="ACME"
php spark mr:project:create acme/inbox --profile=checked
php spark mr:route:set acme/inbox inbox.example.com
php spark mr:token:create acme/inbox --label="ticket system"   # secret shown once
php spark mr:token:rotate mrt_xxxxxxxxxxxxxxxx --grace=24h
php spark mr:project:show acme/inbox
```

Run `php spark list` for all `mr:` commands.

## Versioning

`n.n.n`, each part 0-999. The current version is in `VERSION`; changes are listed in `CHANGELOG.md`.

## License

Free for private and commercial use, modification and redistribution
(MIT-style). The only condition beyond the MIT terms: projects that use or
are derived from MailRunner must mention MailRunner with a link to
https://github.com/Skittel/MailRunner in their README. See [LICENSE](LICENSE).
