# Changelog MailRunner Admin

Admin and node are versioned separately since 0.1.26: `admin/VERSION` with tags `admin-v*` here, the node in
`VERSION` with tags `v*` (`CHANGELOG.md` in the repository root). An admin release only offers node updates when
the node version in the same checkout is newer. Up to 0.1.25 both shared one version: see `../CHANGELOG.md`.

## 0.1.27 - 2026-11-04
- API tokens are managed in the admin: created here (secret shown once, SHA-256 stored), pushed with the project to
  every node of the project (its node and, during a move, the old node). One bearer works on all of them.
- Existing node tokens are taken over once with their hash on the next poll (badge "übernommen"); they stay valid.
- Revoking a token pushes to every node of the project. A move starts only after the tokens are central;
  the application then only changes the API address.
- Self test projects (`system/…`) keep their local node tokens (shown, not managed).
- Migration: `api_tokens`, `projects.tokens_imported_at`.

## 0.1.26 - 2026-11-04
- Own version (`admin/VERSION`, tags `admin-v*`) and changelog; admin-only changes no longer trigger node updates.
  Automatic node updates and the enrollment command use the node version (`VERSION`, shipped as `NODE_VERSION`).
- Footer shows the admin and the node version.
