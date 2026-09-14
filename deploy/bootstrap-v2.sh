#!/usr/bin/env bash
set -Eeuo pipefail
umask 0007

DOMAIN="farmacia.superamplitude.com"
APP_USER="farmacia"
APP_HOME="/home/${APP_USER}"
APP_DIR="${APP_HOME}/htdocs/${DOMAIN}"
STATE_DIR="${APP_HOME}/.farmacia"
R2_ACCOUNT_ID="a26bcc0f570221207e6e66981adae363"
R2_BUCKET="superamplitude"
R2_ENDPOINT="https://${R2_ACCOUNT_ID}.r2.cloudflarestorage.com"
R2_CATALOG_URL="https://catalog.cloudflarestorage.com/${R2_ACCOUNT_ID}/${R2_BUCKET}"
IMAGE_BASE_URL="https://img.farmacia.superamplitude.com"

log(){ printf '\n[%s] %s\n' "$(date +%H:%M:%S)" "$*"; }
fail(){ echo "DEPLOY_FAIL $*" >&2; exit 1; }

log "Preflight"
bash deploy/preflight.sh strict

[[ -d "$APP_DIR/.git" ]] || fail "produção não inicializada; execute deploy/repair-permissions.sh como root uma única vez"
[[ -d "$STATE_DIR" ]] || fail "estado privado ausente: $STATE_DIR"

log "Sincronizando produção com main"
git -C "$APP_DIR" fetch origin main
git -C "$APP_DIR" reset --hard origin/main
git -C "$APP_DIR" clean -fd
cd "$APP_DIR"

log "Preparando configuração privada"
if [[ ! -f "$STATE_DIR/.env" ]]; then cp .env.example "$STATE_DIR/.env"; fi
chmod 640 "$STATE_DIR/.env" || true
if command -v setfacl >/dev/null 2>&1; then setfacl -m "u:${APP_USER}:rw-,m:rw" "$STATE_DIR/.env" || true; fi
[[ -r "$STATE_DIR/.env" ]] || fail "runner não consegue ler o .env privado"

set_env() {
  local key="$1" value="$2" file="$STATE_DIR/.env"
  if grep -q "^${key}=" "$file"; then sed -i "s#^${key}=.*#${key}=${value}#" "$file"; else printf '%s=%s\n' "$key" "$value" >> "$file"; fi
}

set_env APP_BASE "/"
set_env APP_URL "https://${DOMAIN}"
set_env PRIVATE_STATE_DIR "$STATE_DIR"
set_env DB_DSN "sqlite:${STATE_DIR}/farmacia.sqlite"
set_env IMAGE_BASE_URL "$IMAGE_BASE_URL"
set_env R2_ACCOUNT_ID "$R2_ACCOUNT_ID"
set_env R2_BUCKET "$R2_BUCKET"
set_env R2_ENDPOINT "$R2_ENDPOINT"
set_env R2_CATALOG_URL "$R2_CATALOG_URL"

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

R2_ACCESS="$(grep '^R2_ACCESS_KEY_ID=' "$STATE_DIR/.env" | cut -d= -f2- || true)"
R2_SECRET="$(grep '^R2_SECRET_ACCESS_KEY=' "$STATE_DIR/.env" | cut -d= -f2- || true)"
if [[ -n "$R2_ACCESS" && -n "$R2_SECRET" ]]; then
  log "Validando escrita R2"
  php scripts/r2_check.php
  echo "R2_WRITE=ok"
else
  echo "R2_WRITE=pending_private_credentials"
fi

echo "FARMACIA_DEPLOY_OK commit=$(git rev-parse --short HEAD) domain=${DOMAIN} app=${APP_DIR} state=${STATE_DIR}"
