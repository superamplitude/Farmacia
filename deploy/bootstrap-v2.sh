#!/usr/bin/env bash
set -Eeuo pipefail
umask 0007

DOMAIN="farmacia.superamplitude.com"
REPO="https://github.com/superamplitude/Farmacia.git"
R2_ACCOUNT_ID="a26bcc0f570221207e6e66981adae363"
R2_BUCKET="superamplitude"
R2_ENDPOINT="https://${R2_ACCOUNT_ID}.r2.cloudflarestorage.com"
R2_CATALOG_URL="https://catalog.cloudflarestorage.com/${R2_ACCOUNT_ID}/${R2_BUCKET}"
IMAGE_BASE_URL="https://img.farmacia.superamplitude.com"

log(){ printf '\n[%s] %s\n' "$(date +%H:%M:%S)" "$*"; }
fail(){ echo "DEPLOY_FAIL $*" >&2; exit 1; }

# shellcheck disable=SC1091
source "$(dirname "$0")/layout.sh"
farmacia_layout_load strict

log "Preflight"
bash "$(dirname "$0")/preflight.sh" strict

[[ "$APP_USER" == "superamplitude-farmacia" ]] || fail "site user inesperado: $APP_USER"
[[ "$APP_DIR" == "/home/superamplitude-farmacia/htdocs/farmacia.superamplitude.com" ]] || fail "app dir inesperado: $APP_DIR"
[[ "$STATE_DIR" == "/home/superamplitude-farmacia/.farmacia" ]] || fail "state dir inesperado: $STATE_DIR"
mkdir -p "$STATE_DIR/backups" "$STATE_DIR/uploads"

if [[ ! -d "$APP_DIR/.git" ]]; then
  log "Inicializando árvore Git de produção"
  TS="$(date +%Y%m%d-%H%M%S)"
  if find "$APP_DIR" -mindepth 1 -maxdepth 1 -print -quit | grep -q .; then
    tar -czf "$STATE_DIR/backups/pre-git-init-${TS}.tar.gz" -C "$APP_DIR" .
    echo "PRE_GIT_BACKUP=$STATE_DIR/backups/pre-git-init-${TS}.tar.gz"
  fi
  find "$APP_DIR" -mindepth 1 -maxdepth 1 -exec rm -rf {} +
  git clone "$REPO" "$APP_DIR"
fi

git config --global --add safe.directory "$APP_DIR" 2>/dev/null || true

log "Sincronizando produção com main"
git -C "$APP_DIR" fetch origin main
git -C "$APP_DIR" reset --hard origin/main
git -C "$APP_DIR" clean -fd
cd "$APP_DIR"

log "Preparando configuração privada"
if [[ ! -f "$STATE_DIR/.env" ]]; then cp .env.example "$STATE_DIR/.env"; fi
chmod 640 "$STATE_DIR/.env" 2>/dev/null || true
[[ -r "$STATE_DIR/.env" && -w "$STATE_DIR/.env" ]] || fail "runner não consegue ler/escrever o .env privado"

set_env() {
  local key="$1" value="$2" file="$STATE_DIR/.env"
  if grep -q "^${key}=" "$file"; then sed -i "s#^${key}=.*#${key}=${value}#" "$file"; else printf '%s=%s\n' "$key" "$value" >> "$file"; fi
}
ensure_env() {
  local key="$1" value="${2:-}" file="$STATE_DIR/.env"
  grep -q "^${key}=" "$file" || printf '%s=%s\n' "$key" "$value" >> "$file"
}
get_env(){ grep "^${1}=" "$STATE_DIR/.env" 2>/dev/null | tail -n1 | cut -d= -f2- || true; }

set_env APP_BASE "/"
set_env APP_URL "https://${DOMAIN}"
set_env PRIVATE_STATE_DIR "$STATE_DIR"
set_env DB_DSN "sqlite:${STATE_DIR}/farmacia.sqlite"
set_env IMAGE_BASE_URL "$IMAGE_BASE_URL"
set_env R2_ACCOUNT_ID "$R2_ACCOUNT_ID"
set_env R2_BUCKET "$R2_BUCKET"
set_env R2_ENDPOINT "$R2_ENDPOINT"
set_env R2_CATALOG_URL "$R2_CATALOG_URL"

ensure_env PAYMENT_PROVIDER "delivery"
ensure_env MERCADOPAGO_API_BASE "https://api.mercadopago.com"
ensure_env MERCADOPAGO_ACCESS_TOKEN ""
ensure_env MERCADOPAGO_WEBHOOK_SECRET ""
ensure_env AI_ENABLED "0"
ensure_env AI_PROVIDER "openai-compatible"
ensure_env AI_API_URL ""
ensure_env AI_API_KEY ""
ensure_env AI_MODEL ""
ensure_env SNCR_ENABLED "0"
ensure_env SNCR_BASE_URL ""
ensure_env SNCR_CLIENT_ID ""
ensure_env SNCR_CLIENT_SECRET ""
ensure_env R2_ACCESS_KEY_ID ""
ensure_env R2_SECRET_ACCESS_KEY ""

if [[ -z "$(get_env APP_KEY)" ]]; then set_env APP_KEY "$(openssl rand -hex 32)"; fi
if [[ -z "$(get_env SUPERADMIN_EMAIL)" ]]; then set_env SUPERADMIN_EMAIL "admin@superamplitude.com"; fi
if [[ -z "$(get_env SUPERADMIN_PASSWORD)" ]]; then
  ADMIN_PASSWORD="F4rmA!$(openssl rand -hex 16)"
  set_env SUPERADMIN_PASSWORD "$ADMIN_PASSWORD"
  {
    printf 'SUPERADMIN_EMAIL=%q\n' "$(get_env SUPERADMIN_EMAIL)"
    printf 'SUPERADMIN_PASSWORD=%q\n' "$ADMIN_PASSWORD"
    printf 'CREATED_AT=%q\n' "$(date -Is)"
  } > "$STATE_DIR/superadmin.credentials"
  chmod 600 "$STATE_DIR/superadmin.credentials" 2>/dev/null || true
  unset ADMIN_PASSWORD
  echo "SUPERADMIN_CREDENTIALS=$STATE_DIR/superadmin.credentials"
fi

log "Validando runtime PHP"
php -r '$need=["pdo","pdo_sqlite","curl","mbstring","fileinfo"];foreach($need as $e){if(!extension_loaded($e)){fwrite(STDERR,"PHP_EXT_MISSING=$e\n");exit(1);}}echo "PHP_EXTENSIONS_OK\n";'
while IFS= read -r file; do php -l "$file" >/dev/null; done < <(find . -type f -name '*.php' -not -path './.git/*' | sort)
echo "PHP_LINT_ALL=ok"

log "Sincronizando base Anvisa"
php scripts/import_anvisa.php

log "Sincronizando imagens"
php scripts/sync_images.php || true

log "Executando self-test"
php scripts/self_test.php

R2_ACCESS="$(get_env R2_ACCESS_KEY_ID)"
R2_SECRET="$(get_env R2_SECRET_ACCESS_KEY)"
if [[ -n "$R2_ACCESS" && -n "$R2_SECRET" ]]; then
  log "Validando escrita R2"
  php scripts/r2_check.php
  echo "R2_WRITE=ok"
else
  echo "R2_WRITE=pending_private_credentials"
fi

if [[ "$(get_env PAYMENT_PROVIDER)" == "mercadopago" && -n "$(get_env MERCADOPAGO_ACCESS_TOKEN)" ]]; then
  echo "PAYMENT_GATEWAY=mercadopago_configured"
else
  echo "PAYMENT_GATEWAY=delivery_methods_active_pix_pending_credentials"
fi

if [[ "$(get_env AI_ENABLED)" == "1" ]]; then
  echo "AI_MODE=provider_enabled"
else
  echo "AI_MODE=safe_catalog_fallback"
fi

echo "FARMACIA_DEPLOY_OK commit=$(git rev-parse --short HEAD) domain=${DOMAIN} site_user=${APP_USER} app=${APP_DIR} state=${STATE_DIR}"
