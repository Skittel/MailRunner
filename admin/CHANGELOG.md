# Changelog MailRunner Admin

Admin and node are versioned separately since 0.1.26: `admin/VERSION` with tags `admin-v*` here, the node in
`VERSION` with tags `v*` (`CHANGELOG.md` in the repository root). An admin release only offers node updates when
the node version in the same checkout is newer. Up to 0.1.25 both shared one version: see `../CHANGELOG.md`.

## 0.1.33 - 2026-11-08
- Central distribution also for receive projects: the SMTP logins and the webhook the customer's application sets
  up on a node are taken over (encrypted, like the send configuration) and put on the other nodes of the project;
  the webhook of send projects as well. Needs node 0.2.10 (`access-config`).
- A move of any project (not only send projects) starts only after the central copy exists and puts it on the new
  node right away.
- The poll asks every node once for its project list with the hash per project and reconciles only projects whose
  hash changed (before: two calls per send project and minute).
- Receive projects get the card "Zugänge der Anwendung": SMTP logins (user, networks, TLS, last use, state) and the
  webhook (shortened URL, last delivery) per node, with the central state and "Jetzt abgleichen".
- Library `SendConfigs` is now `AccessConfigs`; audit `access_config.sync_requested`.

## 0.1.32 - 2026-11-08
- Send card, actions (users with write access): "Sperren" locks a send access with a reason (the customer sees the
  reason and cannot lift the lock), "Entsperren" lifts it. The lock is set on the project's node and distributed at
  once to the other nodes of the project; a node in manual mode first waits for approval, the next poll then
  distributes it. An access lock set on a node the admin has not reconciled yet is taken over, never overwritten.
- "Test anfordern" per domain and node brings the domain test forward on that node.
- Audit `send.access_locked` (with reason), `send.access_unlocked`, `send.domain_test_requested`.
- Accesses switched off by the customer show "aus", operator locks "vom Betreiber gesperrt". Needs node 0.2.9 for
  the lock buttons (older nodes show none).

## 0.1.31 - 2026-11-08
- Central send configuration (decision E-27): the customer's application changes domains, profiles and accesses on
  a node; the admin notices it by the configuration hash on every poll, takes the full set over and puts it on the
  other nodes of the project (move: the new node gets it before the application switches). The set incl. DKIM
  private keys and token/password hashes is stored encrypted with the admin secrets key (operator decision
  08.11.2026), never shown, not in the audit log.
- Per node it remembers the hash after the last sync: a different hash is a local change (taken over), an old one a
  stale node (pushed with `if_hash`). Two nodes changed at once: the later change wins (audit
  `send_config.conflict`). Manual nodes get the set once and wait for approval.
- A move of a send project starts only after the send configuration is central.
- Card Versand: central state (taken over when and from which node, counts), per node in sync / differs / waits for
  approval / error, button "Jetzt abgleichen". Needs node 0.2.8.
- Migration: `send_configs`, `send_config_nodes`.

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
