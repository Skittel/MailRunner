#!/bin/bash
# MailRunner Admin installer (decision E-19). Idempotent: run again to deploy a new version.
#
#   sudo install/install-admin.sh --hostname=admin.example.net
#
# Needs: a Let's Encrypt certificate for --hostname (certbot certonly --webroot -w /var/www/acme -d <host>),
# PHP/MariaDB/nginx as installed by install.sh. Afterwards:
#   - Entra ID values in /etc/mailrunner-admin/admin.env (ENTRA_TENANT_ID, ENTRA_CLIENT_ID, ENTRA_CLIENT_SECRET)
#   - every node: install.sh ... --admin-ca=/etc/mailrunner-admin/mtls/ca.pem (copy the file to remote nodes)
set -euo pipefail

HOST=""; DB_NAME="mailrunner_admin"
SOURCE="$(cd "$(dirname "$(readlink -f "$0")")/.." && pwd)"
for arg in "$@"; do
    case "$arg" in
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
[[ "$HOST" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || die "--hostname is required (FQDN)"
HAVE_CERT=1
[[ -f "/etc/letsencrypt/live/$HOST/fullchain.pem" ]] || HAVE_CERT=0
VERSION="$(tr -d '[:space:]' < "$SOURCE/VERSION")"
T="$SOURCE/install/templates/admin"
CONF=/etc/mailrunner-admin
DATA=/var/lib/mailrunner-admin

log "System user and directories"
getent passwd mailrunner-admin >/dev/null || useradd --system --user-group --home-dir "$DATA" --shell /usr/sbin/nologin mailrunner-admin
install -d -o root -g mailrunner-admin -m 0750 "$CONF" "$CONF/mtls"
install -d -o mailrunner-admin -g mailrunner-admin -m 0750 "$DATA" "$DATA/writable" "$DATA/writable/cache" "$DATA/writable/logs" \
    "$DATA/writable/session" "$DATA/writable/uploads" "$DATA/writable/debugbar"
install -d -m 0755 /opt/mailrunner-admin /opt/mailrunner-admin/releases

if [[ ! -f "$CONF/admin.env" ]]; then
    log "Database and $CONF/admin.env"
    DB_PASS="$(openssl rand -hex 24)"
    mariadb -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_bin;
        CREATE USER IF NOT EXISTS 'mailrunner_admin'@'localhost' IDENTIFIED BY '$DB_PASS';
        ALTER USER 'mailrunner_admin'@'localhost' IDENTIFIED BY '$DB_PASS';
        GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO 'mailrunner_admin'@'localhost';"
    umask 027
    cat > "$CONF/admin.env" <<ENV
# MailRunner Admin configuration (install-admin.sh). Never commit.
CI_ENVIRONMENT = production
app.baseURL = 'https://${HOST}/'
database.default.hostname = localhost
database.default.database = ${DB_NAME}
database.default.username = mailrunner_admin
database.default.password = ${DB_PASS}
database.default.DBDriver = MySQLi
session.savePath = ${DATA}/writable/session

# Entra ID app registration (redirect URI https://${HOST}/auth/callback, app role ADMIN_ROLE)
ENTRA_TENANT_ID =
ENTRA_CLIENT_ID =
ENTRA_CLIENT_SECRET =
ADMIN_ROLE = MailRunner.Admin
ENV
    chown root:mailrunner-admin "$CONF/admin.env"
    chmod 0640 "$CONF/admin.env"
fi

if [[ ! -f "$CONF/mtls/ca.pem" ]]; then
    log "mTLS: MailRunner Admin CA and client certificate (for the node admin APIs)"
    ( cd "$CONF/mtls"
      umask 077
      openssl genpkey -algorithm EC -pkeyopt ec_paramgen_curve:P-256 -out ca.key
      openssl req -x509 -new -key ca.key -sha256 -days 3650 -subj "/O=MailRunner Admin/CN=MailRunner Admin CA ${HOST}" \
          -addext "basicConstraints=critical,CA:TRUE,pathlen:0" -addext "keyUsage=critical,keyCertSign,cRLSign" -out ca.pem
      openssl genpkey -algorithm EC -pkeyopt ec_paramgen_curve:P-256 -out client.key
      openssl req -new -key client.key -subj "/O=MailRunner Admin/CN=mailrunner-admin" -out client.csr
      printf 'basicConstraints=CA:FALSE\nkeyUsage=critical,digitalSignature\nextendedKeyUsage=clientAuth\n' > client.ext
      openssl x509 -req -in client.csr -CA ca.pem -CAkey ca.key -CAcreateserial -sha256 -days 825 -extfile client.ext -out client.pem
      rm -f client.csr client.ext )
    chown root:root "$CONF/mtls/ca.key"; chmod 0600 "$CONF/mtls/ca.key"
    chmod 0644 "$CONF/mtls/ca.pem" "$CONF/mtls/client.pem"
    chown root:mailrunner-admin "$CONF/mtls/client.key"; chmod 0640 "$CONF/mtls/client.key"
fi

log "Release $VERSION"
umask 022
REL="/opt/mailrunner-admin/releases/${VERSION}-$(date -u +%Y%m%d%H%M%S)"
rsync -a --exclude /vendor --exclude /.env --exclude /writable --exclude /tests --exclude /phpunit.dist.xml "$SOURCE/admin/" "$REL/"
install -m 0644 "$SOURCE/VERSION" "$REL/VERSION"
(cd "$REL" && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --no-interaction --optimize-autoloader -q)
ln -s "$DATA/writable" "$REL/writable"
ln -s "$CONF/admin.env" "$REL/.env"
chown -R root:root "$REL"
chmod -R u=rwX,go=rX "$REL"
runuser -u mailrunner-admin -- php "$REL/spark" migrate --all
if [[ -L /opt/mailrunner-admin/current ]]; then
    ln -sfn "$(readlink /opt/mailrunner-admin/current)" /opt/mailrunner-admin/previous
fi
ln -sfn "$REL" /opt/mailrunner-admin/current

log "Web (php-fpm + nginx)"
FPM_DIR="$(ls -d /etc/php/*/fpm | sort -V | tail -1)"
FPM_SERVICE="php$(basename "$(dirname "$FPM_DIR")")-fpm"
install -m 0644 "$T/php-fpm-pool.conf" "$FPM_DIR/pool.d/mailrunner-admin.conf"
systemctl reload "$FPM_SERVICE" || systemctl restart "$FPM_SERVICE"
if [[ $HAVE_CERT -eq 1 ]]; then
    render "$T/nginx-admin.conf" > /etc/nginx/sites-available/mailrunner-admin
    ln -sfn /etc/nginx/sites-available/mailrunner-admin /etc/nginx/sites-enabled/mailrunner-admin
    nginx -t 2>/dev/null
    systemctl reload nginx
else
    echo "NOTE: no certificate for $HOST yet - web server not configured."
    echo "      certbot certonly --webroot -w /var/www/acme -d $HOST   then run this installer again"
fi

log "Node polling (every minute)"
install -m 0644 "$T/mailrunner-admin-poll.service" "$T/mailrunner-admin-poll.timer" /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now mailrunner-admin-poll.timer >/dev/null 2>&1

log "Old releases"
KEEP_CUR="$(basename "$(readlink /opt/mailrunner-admin/current)")"
KEEP_PREV="$(basename "$(readlink /opt/mailrunner-admin/previous 2>/dev/null || echo none)")"
ls -1t /opt/mailrunner-admin/releases | tail -n +4 | while read -r old; do
    [[ "$old" == "$KEEP_CUR" || "$old" == "$KEEP_PREV" || -z "$old" ]] && continue
    rm -rf -- "/opt/mailrunner-admin/releases/${old:?}"
done

grep -q '^ENTRA_CLIENT_SECRET = .\+' "$CONF/admin.env" || echo "NOTE: Entra ID not configured yet - fill in ENTRA_* in $CONF/admin.env"
echo "Installed MailRunner Admin $VERSION as $REL: https://$HOST/  (node trust: --admin-ca=$CONF/mtls/ca.pem)"
