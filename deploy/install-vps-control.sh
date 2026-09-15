#!/usr/bin/env bash
set -Eeuo pipefail

DOMAIN="farmacia.superamplitude.com"
APP_USER="superamplitude-farmacia"
APP_DIR="/home/${APP_USER}/htdocs/${DOMAIN}"
RUNNER_USER="farmrunner"
HELPER="/usr/local/sbin/farmacia-vps-control"
SUDOERS="/etc/sudoers.d/farmacia-vps-control"
VHOST="/etc/nginx/sites-enabled/${DOMAIN}.conf"
SRC="https://raw.githubusercontent.com/superamplitude/Farmacia/main/deploy/vps-control-root.sh"

fail(){ echo "VPS_CONTROL_INSTALL_FAIL $*" >&2; exit 1; }
[[ $EUID -eq 0 ]] || fail 'execute como root dentro da VPS'
id "$APP_USER" >/dev/null 2>&1 || fail "site user ausente: ${APP_USER}"
id "$RUNNER_USER" >/dev/null 2>&1 || fail "runner user ausente: ${RUNNER_USER}"
[[ -f "$VHOST" ]] || fail "vhost ausente: ${VHOST}"
ROOT_PATH="$(awk '$1=="root" {gsub(/;/,"",$2); print $2; exit}' "$VHOST" 2>/dev/null || true)"
[[ "$ROOT_PATH" == "$APP_DIR" ]] || fail "vhost aponta para ${ROOT_PATH:-indefinido}; esperado ${APP_DIR}"

export DEBIAN_FRONTEND=noninteractive
apt-get update -y >/dev/null
apt-get install -y acl sudo curl >/dev/null

curl -fsSL "$SRC" -o "${HELPER}.new"
chown root:root "${HELPER}.new"
chmod 0755 "${HELPER}.new"
mv "${HELPER}.new" "$HELPER"

printf '%s\n' "${RUNNER_USER} ALL=(root) NOPASSWD: ${HELPER}" > "$SUDOERS"
chown root:root "$SUDOERS"
chmod 0440 "$SUDOERS"
visudo -cf "$SUDOERS" >/dev/null

"$HELPER" prepare
"$HELPER" diagnose

systemctl restart github-actions-farmacia
sleep 2
systemctl is-active --quiet github-actions-farmacia || fail 'runner não ficou ativo'

echo '============================================================'
echo ' FARMACIA VPS CONTROL INSTALADO'
echo '============================================================'
echo "DOMAIN=${DOMAIN}"
echo "SITE_USER=${APP_USER}"
echo "APP_DIR=${APP_DIR}"
echo "RUNNER_USER=${RUNNER_USER}"
echo "HELPER=${HELPER}"
echo 'RUNNER_SERVICE=active'
echo 'VPS_CONTROL=READY'
echo '============================================================'
