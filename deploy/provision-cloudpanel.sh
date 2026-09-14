#!/usr/bin/env bash
set -Eeuo pipefail

DOMAIN="farmacia.superamplitude.com"
SITE_USER="farmacia"
PHP_VERSION="${FARMACIA_PHP_VERSION:-8.2}"
APP_DIR="/home/${SITE_USER}/htdocs/${DOMAIN}"
CREDENTIAL_FILE="/root/.farmacia-cloudpanel-site-user"
MARKER_FILE="${FARMACIA_PROVISION_MARKER:-}"

log(){ printf '\n[%s] %s\n' "$(date +%H:%M:%S)" "$*"; }
fail(){ echo "CLOUDPANEL_PROVISION_FAIL $*" >&2; exit 1; }

[[ $EUID -eq 0 ]] || fail 'execute como root'
command -v clpctl >/dev/null 2>&1 || fail 'clpctl não encontrado'

VHOST=""
for candidate in "/etc/nginx/sites-enabled/${DOMAIN}.conf" "/etc/nginx/sites-available/${DOMAIN}.conf"; do
  [[ -f "$candidate" ]] && VHOST="$candidate" && break
done
if [[ -z "$VHOST" ]]; then
  VHOST="$(grep -RIl --include='*.conf' "$DOMAIN" /etc/nginx/sites-enabled /etc/nginx/sites-available 2>/dev/null | head -n1 || true)"
fi

if [[ -n "$VHOST" ]]; then
  log "Site CloudPanel já possui vhost: $VHOST"
  if [[ ! -d "/home/${SITE_USER}" ]]; then
    fail "vhost existe, mas o usuário isolado esperado ${SITE_USER} não existe; não vou alterar um site desconhecido"
  fi
  mkdir -p "$APP_DIR"
  echo "CLOUDPANEL_SITE=existing"
  echo "CLOUDPANEL_SITE_USER=${SITE_USER}"
  echo "CLOUDPANEL_APP_DIR=${APP_DIR}"
  exit 0
fi

if id "$SITE_USER" >/dev/null 2>&1; then
  fail "usuário ${SITE_USER} já existe sem vhost para ${DOMAIN}; conflito requer inspeção antes de provisionar"
fi

clpctl vhost-templates:list 2>/dev/null | grep -qi 'Generic' || fail "template CloudPanel Generic não encontrado"

SITE_PASSWORD="F4rmA!$(openssl rand -hex 12)"
log "Criando site PHP isolado no CloudPanel"
clpctl site:add:php \
  --domainName="$DOMAIN" \
  --phpVersion="$PHP_VERSION" \
  --vhostTemplate='Generic' \
  --siteUser="$SITE_USER" \
  --siteUserPassword="$SITE_PASSWORD"

[[ -d "$APP_DIR" ]] || fail "CloudPanel não criou ${APP_DIR}"
VHOST="$(grep -RIl --include='*.conf' "$DOMAIN" /etc/nginx/sites-enabled /etc/nginx/sites-available 2>/dev/null | head -n1 || true)"
[[ -n "$VHOST" ]] || fail 'CloudPanel criou o diretório, mas nenhum vhost foi localizado'

umask 0077
{
  printf 'SITE_USER=%q\n' "$SITE_USER"
  printf 'SITE_PASSWORD=%q\n' "$SITE_PASSWORD"
  printf 'DOMAIN=%q\n' "$DOMAIN"
  printf 'CREATED_AT=%q\n' "$(date -Is)"
} > "$CREDENTIAL_FILE"
chmod 600 "$CREDENTIAL_FILE"
if [[ -n "$MARKER_FILE" ]]; then
  mkdir -p "$(dirname "$MARKER_FILE")"
  printf '%s\n' "$DOMAIN" > "$MARKER_FILE"
  chmod 600 "$MARKER_FILE"
fi
unset SITE_PASSWORD

nginx -t
systemctl reload nginx

log "Solicitando certificado Let's Encrypt pelo CloudPanel"
clpctl lets-encrypt:install:certificate --domainName="$DOMAIN"
nginx -t
systemctl reload nginx

echo "CLOUDPANEL_SITE=created"
echo "CLOUDPANEL_SITE_USER=${SITE_USER}"
echo "CLOUDPANEL_PHP_VERSION=${PHP_VERSION}"
echo "CLOUDPANEL_APP_DIR=${APP_DIR}"
echo "CLOUDPANEL_VHOST=${VHOST}"
