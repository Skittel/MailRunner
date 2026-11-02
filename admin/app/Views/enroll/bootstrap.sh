#!/bin/bash
# MailRunner node bootstrap, served by MailRunner Admin ({{ADMIN_URL}}).
#   curl -fsSL {{ADMIN_URL}}/enroll/bootstrap.sh | sudo bash -s -- <enrollment token>
# Installs git, fetches the MailRunner release the admin runs and starts install/install.sh with the token.
# Node id, host name and MX names come from the admin (GET /enroll/config).
set -euo pipefail
TOKEN="${1:-}"
ADMIN="{{ADMIN_URL}}"
REPO="{{REPO}}"
SRC=/opt/src/MailRunner
[ -n "$TOKEN" ] || { echo "usage: curl -fsSL $ADMIN/enroll/bootstrap.sh | sudo bash -s -- <token>" >&2; exit 2; }
[ "$(id -u)" -eq 0 ] || { echo "run as root (sudo bash -s -- <token>)" >&2; exit 2; }
echo "==> Packages for the bootstrap (git, curl, python3)"
DEBIAN_FRONTEND=noninteractive apt-get update -q >/dev/null
DEBIAN_FRONTEND=noninteractive apt-get install -y -q git curl python3 ca-certificates >/dev/null
CFG="$(curl -fsS --max-time 20 -H "Authorization: Bearer $TOKEN" "$ADMIN/enroll/config")" \
    || { echo "ERROR: enrollment token invalid, expired or used - create a new one in MailRunner Admin" >&2; exit 1; }
VERSION="$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["version"])' "$CFG")"
echo "==> MailRunner $VERSION from $REPO"
if [ -d "$SRC/.git" ]; then git -C "$SRC" fetch -q --tags; else git clone -q "$REPO" "$SRC"; fi
git -C "$SRC" -c advice.detachedHead=false checkout -q "v$VERSION"
exec "$SRC/install/install.sh" --admin-url="$ADMIN" --enroll-token="$TOKEN"
