# Changelog MailRunner Admin

Admin and node are versioned separately since 0.1.26: `admin/VERSION` with tags `admin-v*` here, the node in
`VERSION` with tags `v*` (`CHANGELOG.md` in the repository root). An admin release only offers node updates when
the node version in the same checkout is newer. Up to 0.1.25 both shared one version: see `../CHANGELOG.md`.

## 0.1.26 - 2026-11-04
- Own version (`admin/VERSION`, tags `admin-v*`) and changelog; admin-only changes no longer trigger node updates.
  Automatic node updates and the enrollment command use the node version (`VERSION`, shipped as `NODE_VERSION`).
- Footer shows the admin and the node version.
