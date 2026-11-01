#!/bin/bash
# MailRunner node installer. Idempotent: run again to deploy a new version.
#
#   sudo install/install.sh --role=receive --node-id=sirius.eu --hostname=sirius.eu.deepspacerelay.net
#
# Needs: Ubuntu 26.04, a Let's Encrypt certificate for --hostname (webroot /var/www/acme).
set -euo pipefail

ROLE=""; NODE_ID=""; HOST=""; DB_NAME="mailrunner"
SOURCE="$(cd "$(dirname "$(readlink -f "$0")")/.." && pwd)"
for arg in "$@"; do
    case "$arg" in
        --role=*) ROLE="${arg#*=}" ;;
        --node-id=*) NODE_ID="${arg#*=}" ;;
        --hostname=*) HOST="${arg#*=}" ;;
        --db-name=*) DB_NAME="${arg#*=}" ;;
        --source=*) SOURCE="${arg#*=}" ;;
        *) echo "unknown argument: $arg" >&2; exit 2 ;;
    esac
done

die() { echo "ERROR: $*" >&2; exit 1; }
log() { echo "==> $*"; }
render() { sed -e "s/{{HOSTNAME}}/${HOST}/g" "$1"; }

[[ $EUID -eq 0 ]] || die "run as root"
[[ "$ROLE" == "receive" ]] || die "--role=receive is required (send is not implemented yet)"
[[ "$NODE_ID" =~ ^[a-z0-9][a-z0-9.-]{1,62}$ ]] || die "--node-id is required, e.g. sirius.eu"
[[ "$HOST" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || die "--hostname is required (FQDN)"
[[ -f "/etc/letsencrypt/live/$HOST/fullchain.pem" ]] || die "no certificate for $HOST (certbot certonly --webroot -w /var/www/acme -d $HOST)"
[[ -f "$SOURCE/VERSION" ]] || die "source not found: $SOURCE"
T="$SOURCE/install/templates"
VERSION="$(cat "$SOURCE/VERSION")"

log "Packages"
echo "postfix postfix/main_mailer_type select No configuration" | debconf-set-selections
echo "postfix postfix/mailname string $HOST" | debconf-set-selections
DEBIAN_FRONTEND=noninteractive apt-get install -y -q \
    postfix postfix-pcre python3-nacl python3-dkim python3-spf python3-dnspython python3-cryptography openssl \
    php-cli php-fpm php-mysql php-intl php-mbstring php-curl php-xml composer mariadb-server nginx rsync >/dev/null

log "System user and directories"
getent passwd mailrunner >/dev/null || useradd --system --user-group --home-dir /var/lib/mailrunner --shell /usr/sbin/nologin mailrunner
install -d -o root -g mailrunner -m 0750 /etc/mailrunner /etc/mailrunner/generated
install -d -o mailrunner -g mailrunner -m 0750 /var/lib/mailrunner /var/lib/mailrunner/receive \
    /var/lib/mailrunner/receive/spool /var/lib/mailrunner/receive/spool/tmp /var/lib/mailrunner/receive/spool/new \
    /var/lib/mailrunner/receive/spool/analyzed /var/lib/mailrunner/receive/spool/failed \
    /var/lib/mailrunner/writable /var/lib/mailrunner/writable/cache /var/lib/mailrunner/writable/logs \
    /var/lib/mailrunner/writable/session /var/lib/mailrunner/writable/uploads /var/lib/mailrunner/writable/debugbar
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

if [[ ! -f /etc/mailrunner/secrets.env ]]; then
    log "/etc/mailrunner/secrets.env (empty template, fill in the values)"
    install -o root -g mailrunner -m 0640 /dev/null /etc/mailrunner/secrets.env
    cat > /etc/mailrunner/secrets.env <<'SECRETS'
# MailRunner node credentials for the analysis (root:mailrunner 0640). Never commit.
# Same names as SmartMailFlow's secrets.env. Empty = not used.

# Spamhaus Data Query Service key (DNSBL via zen.dq.spamhaus.net)
SPAMHAUS_DQS_KEY=

# blacklistmaster.com API (both needed; one API call per analysed mail)
BLM_USER=
BLM_PASS=
SECRETS
fi

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
sed -i '/^# --- MailRunner receive role/,$d' /etc/postfix/master.cf
cat "$T/receive/postfix-master-append.cf" >> /etc/postfix/master.cf
log "Database migrations, node key, mail path configuration"
runuser -u mailrunner -- php /opt/mailrunner/current/spark migrate --all
php /opt/mailrunner/current/spark mr:node:init
php /opt/mailrunner/current/spark mr:apply --no-reload

log "Start Postfix"

postfix check
systemctl enable --now postfix >/dev/null 2>&1
postfix reload >/dev/null 2>&1 || systemctl restart postfix

log "systemd units"
install -m 0644 "$T"/systemd/mailrunner-* /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now mailrunner-analyze.path mailrunner-import.path mailrunner-apply.path mailrunner-maintenance.timer >/dev/null 2>&1

log "API (php-fpm + nginx)"
FPM_DIR="$(ls -d /etc/php/*/fpm | sort -V | tail -1)"
FPM_SERVICE="php$(basename "$(dirname "$FPM_DIR")")-fpm"
install -m 0644 "$T/web/php-fpm-pool.conf" "$FPM_DIR/pool.d/mailrunner.conf"
systemctl restart "$FPM_SERVICE"
render "$T/web/nginx-api.conf" > /etc/nginx/sites-available/mailrunner-api
ln -sfn /etc/nginx/sites-available/mailrunner-api /etc/nginx/sites-enabled/mailrunner-api
nginx -t 2>/dev/null
systemctl reload nginx

log "Old releases"
KEEP_CUR="$(basename "$(readlink /opt/mailrunner/current)")"
KEEP_PREV="$(basename "$(readlink /opt/mailrunner/previous 2>/dev/null || echo none)")"
ls -1t /opt/mailrunner/releases | tail -n +4 | while read -r old; do
    [[ "$old" == "$KEEP_CUR" || "$old" == "$KEEP_PREV" || -z "$old" ]] && continue
    rm -rf -- "/opt/mailrunner/releases/${old:?}"
done

log "Status"
runuser -u mailrunner -- php /opt/mailrunner/current/spark mr:status || true
echo "Installed $VERSION as $REL. CLI: sudo mailrunner mr:..."
