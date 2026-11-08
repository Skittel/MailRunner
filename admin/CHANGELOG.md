# Changelog MailRunner Admin

Admin and node are versioned separately since 0.1.26: `admin/VERSION` with tags `admin-v*` here, the node in
`VERSION` with tags `v*` (`CHANGELOG.md` in the repository root). An admin release only offers node updates when
the node version in the same checkout is newer. Up to 0.1.25 both shared one version: see `../CHANGELOG.md`.

## 0.1.30 - 2026-11-08
- Project page of send projects, card Versand (decision E-27 step 7): read live from the node, not stored here.
  Queue (open messages per status, finished in 24 h, oldest open mail), sender domains with DKIM selector and
  domain test (status, missing checks, last pass, next test, return-path domain), send profiles with limits (marked
  when the project limits them), retries, test interval, senders not allowed and their accesses (API token id, SMTP
  user, IP networks, last use), and the latest mails with recipient domain, status and last SMTP answer. During a
  move both nodes. Needs node 0.2.7 for the queue; older send nodes show domains and profiles.

## 0.1.29 - 2026-11-06
- Projects have a type, chosen when creating and fixed afterwards: Empfang (receive) or Versand (send, decision
  E-27). Send projects have no routes and no receive settings; instead mails per hour and per day for the whole
  project, the allowed sender domains (or all domains) and the checkboxes for what the domain test must pass.
  Pushed to the node as `type` and send settings; needs node 0.2.0 for send projects.

## 0.1.28 - 2026-11-04
- Project page, card API-Tokens: shows the webhook the customer set over the node API (shortened URL, cooldown,
  last ping with HTTP code or error), per node during a move. Needs node 0.1.30.

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
