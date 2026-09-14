#!/usr/bin/env bash
set -Eeuo pipefail

DOMAIN="farmacia.superamplitude.com"
SITE_USER="superamplitude-farmacia"
APP_DIR="/home/${SITE_USER}/htdocs/${DOMAIN}"
STATE_DIR="/home/${SITE_USER}/.farmacia"
PHP_VERSION="${FARMACIA_PHP_VERSION:-8.2}"
CREDENTIAL_FILE="/root/.farmacia-cloudpanel-site-user"
MARKER_FILE="${FARMACIA_PROVISION_MARKER:-}"
LAYOUT_HELPER="/root/farmacia-layout.sh"

log(){ printf '\n[%s] %s\n' "$(date +%H:%M:%S)" "$*"; }
fail(){ echo "CLOUDPANEL_PROVISION_FAIL $*" >&2; exit 1; }

[[ $EUID -eq 0 ]] || fail 'execute como root'
command -v clpctl >/dev/null 2>&1 || fail 'clpctl nao encontrado'

curl -fsSL https://raw.githubusercontent.com/superamplitude/Farmacia/main/deploy/layout.sh -o "$LAYOUT_HELPER"
chmod 0700 "$LAYOUT_HELPER"
# shellcheck disable=SC1090
source "$LAYOUT_HELPER"

VHOST="$(farmacia_find_vhost "$DOMAIN" || true)"
if [[ -n "$VHOST" ]]; then
  log "Subdominio ja possui vhost: $VHOST"
  ROOT_HINT="$(awk '$1=="root" {gsub(/;/,"",$2); print $2; exit}' "$VHOST" 2>/dev/null || true)"
  if [[ "$ROOT_HINT" != "$APP_DIR" ]]; then
    fail "vhost existente aponta para ${ROOT_HINT:-indefinido}; esperado ${APP_DIR}. Nenhuma alteracao destrutiva foi feita."
  fi
  id "$SITE_USER" >/dev/null 2>&1 || fail "vhost aponta para o caminho correto, mas o Site User ${SITE_USER} nao existe"
  farmacia_layout_from_vhost "$VHOST" || fail 'vhost existente nao passou na validacao do contrato aprovado'
  farmacia_layout_write
  mkdir -p "$APP_DIR" "$STATE_DIR/uploads" "$STATE_DIR/backups"
  echo "CLOUDPANEL_SITE=existing_verified"
  echo "CLOUDPANEL_SITE_USER=${SITE_USER}"
  echo "CLOUDPANEL_APP_DIR=${APP_DIR}"
  echo "CLOUDPANEL_STATE_DIR=${STATE_DIR}"
  echo "CLOUDPANEL_VHOST=${VHOST}"
  exit 0
fi

if id "$SITE_USER" >/dev/null 2>&1; then
  fail "Site User ${SITE_USER} ja existe sem vhost para ${DOMAIN}; nao vou reutilizar sem validacao"
fi

clpctl vhost-templates:list 2>/dev/null | grep -qi 'Generic' || fail 'template CloudPanel Generic nao encontrado'
SITE_PASSWORD="F4rmA!$(openssl rand -hex 12)"
log "Criando o subdominio ${DOMAIN} com Site User ${SITE_USER}"
clpctl site:add:php \
  --domainName="$DOMAIN" \
  --phpVersion="$PHP_VERSION" \
  --vhostTemplate='Generic' \
  --siteUser="$SITE_USER" \
  --siteUserPassword="$SITE_PASSWORD"

VHOST="$(farmacia_find_vhost "$DOMAIN" || true)"
[[ -n "$VHOST" ]] || fail 'CloudPanel criou o site, mas nenhum vhost foi localizado'
ROOT_HINT="$(awk '$1=="root" {gsub(/;/,"",$2); print $2; exit}' "$VHOST" 2>/dev/null || true)"
[[ "$ROOT_HINT" == "$APP_DIR" ]] || fail "CloudPanel criou root inesperado: ${ROOT_HINT:-indefinido}; esperado ${APP_DIR}"
farmacia_layout_from_vhost "$VHOST" || fail 'vhost recem-criado nao passou na validacao'
farmacia_layout_write
[[ -d "$APP_DIR" ]] || fail "CloudPanel nao criou ${APP_DIR}"

umask 0077
{
  printf 'SITE_USER=%q\n' "$SITE_USER"
  printf 'SITE_PASSWORD=%q\n' "$SITE_PASSWORD"
  printf 'DOMAIN=%q\n' "$DOMAIN"
  printf 'APP_DIR=%q\n' "$APP_DIR"
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
echo "CLOUDPANEL_STATE_DIR=${STATE_DIR}"
echo "CLOUDPANEL_VHOST=${VHOST}"
