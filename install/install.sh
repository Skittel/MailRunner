#!/bin/bash
# MailRunner node installer. Idempotent: run again to deploy a new version.
#
# With MailRunner Admin (node id, host name and MX names come from the admin; normally started by
# curl -fsSL https://<admin>/enroll/bootstrap.sh | sudo bash -s -- <token>):
#   sudo install/install.sh --admin-url=https://admin.example.net --enroll-token=TOKEN
#
# Without MailRunner Admin (standalone node, configured with the CLI):
#   sudo install/install.sh --role=receive --node-id=node1.eu --hostname=mx.example.net \
#        [--mx-names="mx.example.net=46 mx4.example.net=4 mx6.example.net=6"] [--le-email=ops@example.net]
#
# Needs: Ubuntu 26.04, DNS (A/AAAA) of the host name and the MX names pointing to this server, port 80 reachable.
# Without a certificate the installer checks DNS (install/lib/dnscheck.py, --skip-dns-check e.g. behind NAT) and
# gets one from Let's Encrypt (webroot /var/www/acme) for the host name and all MX names.
# The enrollment token (MailRunner Admin -> Nodes -> "Neue Node einrichten", 60 min, single use) also fetches
# the admin CA and registers the node in MailRunner Admin.
set -euo pipefail

ROLE=""; NODE_ID=""; HOST=""; DB_NAME="mailrunner"; MX_NAMES=""; ADMIN_CA=""; LE_EMAIL=""; ADMIN_URL=""; ENROLL_TOKEN=""; SKIP_DNS=0
SOURCE="$(cd "$(dirname "$(readlink -f "$0")")/.." && pwd)"
for arg in "$@"; do
    case "$arg" in
        --role=*) ROLE="${arg#*=}" ;;
        --node-id=*) NODE_ID="${arg#*=}" ;;
        --hostname=*) HOST="${arg#*=}" ;;
        --db-name=*) DB_NAME="${arg#*=}" ;;
        --source=*) SOURCE="${arg#*=}" ;;
        --mx-names=*) MX_NAMES="${arg#*=}" ;;   # e.g. "mx.example.net=46 mx4.example.net=4" (self test)
        --admin-ca=*) ADMIN_CA="${arg#*=}" ;;   # CA certificate of MailRunner Admin (mTLS for /admin/v1)
        --le-email=*) LE_EMAIL="${arg#*=}" ;;   # contact for Let's Encrypt (optional)
        --admin-url=*) ADMIN_URL="${arg#*=}" ;; # MailRunner Admin, e.g. https://admin.example.net (enrollment)
        --enroll-token=*) ENROLL_TOKEN="${arg#*=}" ;; # one-time enrollment token from MailRunner Admin
        --skip-dns-check) SKIP_DNS=1 ;;
        *) echo "unknown argument: $arg" >&2; exit 2 ;;
    esac
done

die() { echo "ERROR: $*" >&2; exit 1; }
log() { echo "==> $*"; }
render() { sed -e "s/{{HOSTNAME}}/${HOST}/g" -e "s/{{CERT_NAMES}}/${CERT_NAMES:-$HOST}/g" "$1"; }

[[ $EUID -eq 0 ]] || die "run as root"
if [[ -n "$ENROLL_TOKEN" ]]; then
    [[ "$ADMIN_URL" =~ ^https://[a-z0-9.-]+(:[0-9]+)?$ ]] || die "--enroll-token needs --admin-url=https://<admin host>"
    [[ "$ENROLL_TOKEN" =~ ^[A-Za-z0-9_-]{20,80}$ ]] || die "--enroll-token: invalid format"
    log "Enrollment: node configuration from MailRunner Admin"
    ENROLL_JSON="$(curl -fsS --max-time 20 -H "Authorization: Bearer $ENROLL_TOKEN" "$ADMIN_URL/enroll/config")" \
        || die "enrollment: token invalid, expired or used - create a new one in MailRunner Admin"
    cfg() { python3 -c 'import json,sys; v=json.loads(sys.argv[1]).get(sys.argv[2]) or ""; print(v)' "$ENROLL_JSON" "$1"; }
    [[ -z "$ROLE" ]] && ROLE="$(cfg role)"
    [[ -z "$NODE_ID" ]] && NODE_ID="$(cfg node_id)"
    [[ -z "$HOST" ]] && HOST="$(cfg hostname)"
    [[ -z "$MX_NAMES" ]] && MX_NAMES="$(cfg mx_names)"
    [[ -z "$LE_EMAIL" ]] && LE_EMAIL="$(cfg le_email)"
    ENROLL_CA="$(mktemp)"
    trap 'rm -f "$ENROLL_CA"' EXIT
    cfg admin_ca > "$ENROLL_CA"
    ADMIN_CA="$ENROLL_CA"
    echo "node $NODE_ID, host $HOST${MX_NAMES:+, MX names: $MX_NAMES}"
fi
[[ "$ROLE" == "receive" ]] || die "--role=receive is required (send is not implemented yet)"
[[ "$NODE_ID" =~ ^[a-z0-9][a-z0-9.-]{1,62}$ ]] || die "--node-id is required, e.g. node1.eu"
[[ "$HOST" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || die "--hostname is required (FQDN)"
[[ -f "$SOURCE/VERSION" ]] || die "source not found: $SOURCE"
T="$SOURCE/install/templates"
VERSION="$(cat "$SOURCE/VERSION")"

log "Packages"
echo "postfix postfix/main_mailer_type select No configuration" | debconf-set-selections
echo "postfix postfix/mailname string $HOST" | debconf-set-selections
DEBIAN_FRONTEND=noninteractive apt-get install -y -q \
    postfix postfix-pcre python3-milter python3-nacl python3-dkim python3-spf python3-dnspython python3-cryptography \
    python3-requests openssl unbound certbot curl \
    php-cli php-fpm php-mysql php-intl php-mbstring php-curl php-xml composer mariadb-server nginx rsync >/dev/null

log "Certificate (Let's Encrypt, webroot /var/www/acme)"
CERT_NAMES="$HOST"
KNOWN_MX="$MX_NAMES"
[[ -z "$KNOWN_MX" && -f /etc/mailrunner/app.env ]] && KNOWN_MX="$(sed -n 's/^MAILRUNNER_MX_NAMES = "\(.*\)"$/\1/p' /etc/mailrunner/app.env)"
KNOWN_MX="${KNOWN_MX//,/ }"   # comma and/or spaces as separator
for entry in $KNOWN_MX; do
    name="${entry%%=*}"; [[ " $CERT_NAMES " == *" $name "* ]] || CERT_NAMES="$CERT_NAMES $name"
done
install -d -m 0755 /var/www/acme
if [[ ! -f "/etc/letsencrypt/live/$HOST/fullchain.pem" ]]; then
    if [[ $SKIP_DNS -eq 0 ]]; then
        DNS_ARGS=("$HOST=46"); for entry in $KNOWN_MX; do [[ "${entry%%=*}" == "$HOST" ]] && DNS_ARGS[0]="$entry" || DNS_ARGS+=("$entry"); done
        python3 -I "$SOURCE/install/lib/dnscheck.py" "${DNS_ARGS[@]}" \
            || die "DNS does not point to this server yet (fix the records, or --skip-dns-check e.g. behind NAT)"
    fi
    # port 80 only, for the ACME challenge; the full API vhost follows once the certificate exists
    if [[ ! -e /etc/nginx/sites-enabled/mailrunner-api ]]; then
        sed -e "s/{{HOSTNAME}}/${CERT_NAMES}/g" "$T/web/nginx-acme.conf" > /etc/nginx/sites-available/mailrunner-acme
        ln -sfn /etc/nginx/sites-available/mailrunner-acme /etc/nginx/sites-enabled/mailrunner-acme
        rm -f /etc/nginx/sites-enabled/default
        nginx -t 2>/dev/null && systemctl reload nginx
    fi
    LE_ARGS=(--register-unsafely-without-email)
    [[ -n "$LE_EMAIL" ]] && LE_ARGS=(--email "$LE_EMAIL")
    D_ARGS=(); for n in $CERT_NAMES; do D_ARGS+=(-d "$n"); done
    certbot certonly --webroot -w /var/www/acme --cert-name "$HOST" --non-interactive --agree-tos "${LE_ARGS[@]}" "${D_ARGS[@]}" \
        || die "Let's Encrypt failed: do the DNS names point to this server and is port 80 reachable?"
fi
install -d /etc/letsencrypt/renewal-hooks/deploy
install -m 0755 "$T/web/letsencrypt-reload.sh" /etc/letsencrypt/renewal-hooks/deploy/reload-services.sh

log "Self test level 1 (mail path and analysis without network)"
# A failing test stops the installation before anything is changed.
if ! (cd "$SOURCE" && MR_NO_SYSLOG=1 python3 -I -B -m unittest discover -s mailpath/tests -q 2>&1 | tail -n 3); then
    echo "ERROR: tests failed, nothing installed" >&2
    exit 1
fi

log "System user and directories"
getent passwd mailrunner >/dev/null || useradd --system --user-group --home-dir /var/lib/mailrunner --shell /usr/sbin/nologin mailrunner
install -d -o root -g mailrunner -m 0750 /etc/mailrunner /etc/mailrunner/generated
install -d -o mailrunner -g mailrunner -m 0750 /var/lib/mailrunner /var/lib/mailrunner/receive \
    /var/lib/mailrunner/receive/spool /var/lib/mailrunner/receive/spool/tmp /var/lib/mailrunner/receive/spool/new \
    /var/lib/mailrunner/receive/spool/analyzed /var/lib/mailrunner/receive/spool/failed /var/lib/mailrunner/receive/store \
    /var/lib/mailrunner/receive/spool/smtp \
    /var/lib/mailrunner/writable /var/lib/mailrunner/writable/cache /var/lib/mailrunner/writable/logs \
    /var/lib/mailrunner/writable/session /var/lib/mailrunner/writable/uploads /var/lib/mailrunner/writable/debugbar \
    /var/lib/mailrunner/update
install -d -m 0755 /opt/mailrunner /opt/mailrunner/releases

if [[ ! -f /etc/mailrunner/app.env ]]; then
    log "Database and /etc/mailrunner/app.env"
    DB_PASS="$(openssl rand -hex 24)"
    mariadb <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_bin;
CREATE USER IF NOT EXISTS 'mailrunner_app'@'localhost' IDENTIFIED BY '${DB_PASS}';
ALTER USER 'mailrunner_app'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO 'mailrunner_app'@'localhost';
SQL
    umask 027
    cat > /etc/mailrunner/app.env <<ENV
# MailRunner node configuration (install.sh). Never commit.
CI_ENVIRONMENT = production
app.baseURL = 'https://${HOST}/'
MAILRUNNER_NODE_ID = ${NODE_ID}
MAILRUNNER_RECEIVE_ENABLED = true
MAILRUNNER_SEND_ENABLED = false
database.default.hostname = localhost
database.default.database = ${DB_NAME}
database.default.username = mailrunner_app
database.default.password = ${DB_PASS}
database.default.DBDriver = MySQLi
ENV
    umask 022
    chown root:mailrunner /etc/mailrunner/app.env
    chmod 0640 /etc/mailrunner/app.env
fi
# What MailRunner Admin may do on this node; kept on updates, defaults only when missing.
if ! grep -q '^MAILRUNNER_ADMIN_UPDATES' /etc/mailrunner/app.env; then
    cat >> /etc/mailrunner/app.env <<'ENV'

# MailRunner Admin may (auto) or may not (manual) install software updates / apply configuration on this node.
# manual: updates with "sudo mailrunner mr:update", configuration with "sudo mailrunner mr:config:apply"
# (both show the changes and ask; --silent for RMM). The code always comes from the git tags of the source.
MAILRUNNER_ADMIN_UPDATES = auto
MAILRUNNER_ADMIN_CONFIG = auto
ENV
fi
sed -i '/^MAILRUNNER_SOURCE_DIR = /d' /etc/mailrunner/app.env
echo "MAILRUNNER_SOURCE_DIR = \"$SOURCE\"" >> /etc/mailrunner/app.env
MX_NAMES="$(echo "$MX_NAMES" | tr ',' ' ' | xargs)"   # stored space-separated
if [[ -n "$MX_NAMES" ]]; then
    [[ "$MX_NAMES" =~ ^[a-z0-9.=46\ ,-]+$ ]] || die "--mx-names: invalid characters"
    sed -i '/^MAILRUNNER_MX_NAMES = /d' /etc/mailrunner/app.env
    echo "MAILRUNNER_MX_NAMES = \"$MX_NAMES\"" >> /etc/mailrunner/app.env
fi

if [[ ! -f /etc/mailrunner/secrets.env ]]; then
    log "/etc/mailrunner/secrets.env (empty template, fill in the values)"
    install -o root -g mailrunner -m 0640 /dev/null /etc/mailrunner/secrets.env
    cat > /etc/mailrunner/secrets.env <<'SECRETS'
# MailRunner node credentials for the analysis (root:mailrunner 0640). Never commit.
# Same names as SmartMailFlow's secrets.env. Empty = not used.

# Spamhaus Data Query Service key (DNSBL via zen.dq.spamhaus.net)
SPAMHAUS_DQS_KEY=

# blacklistmaster.com API (both needed; one API call per analysed mail and IP, see cache)
BLM_USER=
BLM_PASS=

# ipregistry.co API key (AS, country, city, continent, carrier, threat of the client IP)
IPREGISTRYIO_TOKEN=

# Cache lifetimes in seconds (default 3600)
SPAMHAUS_CACHE_SECONDS=3600
BLM_CACHE_SECONDS=3600
IPREGISTRYIO_CACHE_SECONDS=3600
SECRETS
fi

log "DNS resolver (unbound, shared TTL cache)"
install -m 0644 "$T/dns/unbound-mailrunner.conf" /etc/unbound/unbound.conf.d/mailrunner.conf
unbound-checkconf >/dev/null
systemctl enable --now unbound >/dev/null 2>&1
systemctl restart unbound
# local self test zone selftest.test (needs the running resolver to look up the node's addresses)
python3 -I -B "$SOURCE/install/gen-selftest-zone.py" "$HOST" /etc/unbound/unbound.conf.d/mailrunner-selftest.conf
chmod 0644 /etc/unbound/unbound.conf.d/mailrunner-selftest.conf
unbound-checkconf >/dev/null
systemctl restart unbound
install -d /etc/systemd/resolved.conf.d
install -m 0644 "$T/dns/resolved-mailrunner.conf" /etc/systemd/resolved.conf.d/mailrunner.conf
systemctl restart systemd-resolved

log "Release $VERSION"
REL="/opt/mailrunner/releases/${VERSION}-$(date -u +%Y%m%d%H%M%S)"
rsync -a --exclude /.git --exclude /vendor --exclude /.env --exclude /writable --exclude __pycache__ \
    --exclude /tests --exclude /phpunit.dist.xml "$SOURCE/" "$REL/"
(cd "$REL" && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --no-interaction --optimize-autoloader -q)
ln -s /var/lib/mailrunner/writable "$REL/writable"
ln -s /etc/mailrunner/app.env "$REL/.env"
chown -R root:root "$REL"
chmod -R go-w "$REL"
if [[ -L /opt/mailrunner/current ]]; then
    ln -sfn "$(readlink /opt/mailrunner/current)" /opt/mailrunner/previous
fi
ln -sfn "$REL" /opt/mailrunner/current.new
mv -T /opt/mailrunner/current.new /opt/mailrunner/current
install -m 0755 "$SOURCE/install/mailrunner-cli" /usr/local/sbin/mailrunner

log "Postfix configuration"
[[ -f /etc/postfix/main.cf.orig-mailrunner || ! -f /etc/postfix/main.cf ]] || cp -a /etc/postfix/main.cf /etc/postfix/main.cf.orig-mailrunner
[[ -f /etc/postfix/master.cf.orig-mailrunner ]] || cp -a /etc/postfix/master.cf /etc/postfix/master.cf.orig-mailrunner
render "$T/receive/postfix-main.cf" > /etc/postfix/main.cf
sed -i '/^# --- MailRunner receive role/,$d' /etc/postfix/master.cf   # old pipe transport (<= 0.0.6)
log "Database migrations, node key, mail path configuration"
runuser -u mailrunner -- php /opt/mailrunner/current/spark migrate --all
php /opt/mailrunner/current/spark mr:node:init
php /opt/mailrunner/current/spark mr:apply --no-reload

log "Milter"
install -m 0644 "$T/systemd/mailrunner-milter.service" /etc/systemd/system/
systemctl daemon-reload
systemctl enable mailrunner-milter >/dev/null 2>&1
systemctl restart mailrunner-milter

log "Start Postfix"

# Postfix copies /etc/resolv.conf into its chroot keeping the owner (systemd-resolve) -> "not owned by root" warning
[[ -f /var/spool/postfix/etc/resolv.conf ]] && chown root:root /var/spool/postfix/etc/resolv.conf
postfix check
systemctl enable --now postfix >/dev/null 2>&1
postfix reload >/dev/null 2>&1 || systemctl restart postfix
[[ -f /var/spool/postfix/etc/resolv.conf ]] && chown root:root /var/spool/postfix/etc/resolv.conf   # fresh install: copied at start

log "systemd units"
# units of the old pipe path (<= 0.0.6)
systemctl disable --now mailrunner-analyze.path >/dev/null 2>&1 || true
rm -f /etc/systemd/system/mailrunner-analyze.path /etc/systemd/system/mailrunner-analyze.service
install -m 0644 "$T"/systemd/mailrunner-* /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now mailrunner-import.path mailrunner-apply.path mailrunner-maintenance.timer mailrunner-update.path >/dev/null 2>&1
# journal of delivery attempts (reads the mail log; restart picks up a new release)
systemctl enable mailrunner-smtplog >/dev/null 2>&1
systemctl reset-failed mailrunner-import.service mailrunner-import.path >/dev/null 2>&1 || true
systemctl restart mailrunner-smtplog mailrunner-import.path

log "API (php-fpm + nginx)"
# Admin API trust: CA of MailRunner Admin (public certificate only). Without it /admin/ answers 403.
if [[ -n "$ADMIN_CA" ]]; then
    openssl x509 -in "$ADMIN_CA" -noout >/dev/null 2>&1 || die "--admin-ca: not a PEM certificate"
    install -m 0644 "$ADMIN_CA" /etc/mailrunner/admin-ca.pem
fi
install -d /etc/nginx/snippets
if [[ -f /etc/mailrunner/admin-ca.pem ]]; then
    printf 'ssl_client_certificate /etc/mailrunner/admin-ca.pem;\nssl_verify_client optional;\nssl_verify_depth 2;\n' > /etc/nginx/snippets/mailrunner-admin-mtls.conf
else
    echo "# no admin CA installed (install.sh --admin-ca=...)" > /etc/nginx/snippets/mailrunner-admin-mtls.conf
fi
FPM_DIR="$(ls -d /etc/php/*/fpm | sort -V | tail -1)"
FPM_SERVICE="php$(basename "$(dirname "$FPM_DIR")")-fpm"
install -m 0644 "$T/web/php-fpm-pool.conf" "$FPM_DIR/pool.d/mailrunner.conf"
systemctl restart "$FPM_SERVICE"
render "$T/web/nginx-api.conf" > /etc/nginx/sites-available/mailrunner-api
ln -sfn /etc/nginx/sites-available/mailrunner-api /etc/nginx/sites-enabled/mailrunner-api
rm -f /etc/nginx/sites-enabled/mailrunner-acme
nginx -t 2>/dev/null
systemctl reload nginx

log "Self test project (configuration only, like any customer)"
SPARK=(runuser -u mailrunner -- php /opt/mailrunner/current/spark)
if ! "${SPARK[@]}" mr:project:show system/selftest >/dev/null 2>&1; then
    "${SPARK[@]}" mr:customer:create system --name="Node system" >/dev/null 2>&1 || true
    "${SPARK[@]}" mr:project:create system/selftest --profile=analysis --name="Self test" >/dev/null
    "${SPARK[@]}" mr:project:set system/selftest --recipient-pattern='st-[a-z0-9_-]+' --always-allow=postmaster,abuse \
        --delete-after-ack-hours=0 --unacked-days=1 --metadata-days=2 >/dev/null
    "${SPARK[@]}" mr:route:set system/selftest rx.selftest.test >/dev/null
    "${SPARK[@]}" mr:project:create system/selftest-isolation --profile=analysis --name="Self test: isolation check" >/dev/null
fi
if [[ ! -s /etc/mailrunner/selftest.env ]]; then
    tok() { "${SPARK[@]}" mr:token:create "$1" --label="selftest" --json | sed -n '/^{/,$p' | python3 -c 'import sys,json;print(json.load(sys.stdin)["bearer"])'; }
    ( umask 027
      { echo "# Tokens of the self test (install.sh). Never commit."
        echo "SELFTEST_TOKEN=$(tok system/selftest)"
        echo "SELFTEST_OTHER_TOKEN=$(tok system/selftest-isolation)"; } > /etc/mailrunner/selftest.env )
    chown root:mailrunner /etc/mailrunner/selftest.env
    chmod 0640 /etc/mailrunner/selftest.env
fi
php /opt/mailrunner/current/spark mr:apply >/dev/null
systemctl enable --now mailrunner-selftest.timer >/dev/null 2>&1

log "Old releases"
KEEP_CUR="$(basename "$(readlink /opt/mailrunner/current)")"
KEEP_PREV="$(basename "$(readlink /opt/mailrunner/previous 2>/dev/null || echo none)")"
ls -1t /opt/mailrunner/releases | tail -n +4 | while read -r old; do
    [[ "$old" == "$KEEP_CUR" || "$old" == "$KEEP_PREV" || -z "$old" ]] && continue
    rm -rf -- "/opt/mailrunner/releases/${old:?}"
done

if [[ -n "$ENROLL_TOKEN" ]]; then
    log "Enrollment: register node in MailRunner Admin"
    body="$(printf '{"node_id":"%s","base_url":"https://%s","version":"%s"}' "$NODE_ID" "$HOST" "$VERSION")"
    curl -fsS --max-time 20 -X POST -H "Authorization: Bearer $ENROLL_TOKEN" -H 'Content-Type: application/json' \
        --data "$body" "$ADMIN_URL/enroll" >/dev/null \
        && echo "registered as $NODE_ID in $ADMIN_URL" \
        || echo "WARNING: registration failed - add the node in MailRunner Admin by hand (Nodes -> Node anlegen)"
    rm -f "$ENROLL_CA"
fi

log "Status"
runuser -u mailrunner -- php /opt/mailrunner/current/spark mr:status || true

log "Self test (result also in the journal: node.selftest)"
runuser -u mailrunner -- php /opt/mailrunner/current/spark mr:selftest | tail -n 1 || true
echo "Installed $VERSION as $REL. CLI: sudo mailrunner mr:..."
