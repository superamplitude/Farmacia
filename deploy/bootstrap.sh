#!/usr/bin/env bash
set -Eeuo pipefail

DOMAIN="farmacia.superamplitude.com"
APP_DIR="/home/superamplitude/htdocs/${DOMAIN}"
STATE_DIR="/home/superamplitude/.farmacia"
REPO="https://github.com/superamplitude/Farmacia.git"
R2_ACCOUNT_ID="a26bcc0f570221207e6e66981adae363"
R2_BUCKET="superamplitude"
R2_ENDPOINT="https://${R2_ACCOUNT_ID}.r2.cloudflarestorage.com"
R2_CATALOG_URL="https://catalog.cloudflarestorage.com/${R2_ACCOUNT_ID}/${R2_BUCKET}"
IMAGE_BASE_URL="https://img.farmacia.superamplitude.com"

mkdir -p "$STATE_DIR/uploads" "$APP_DIR"
chmod 700 "$STATE_DIR" "$STATE_DIR/uploads" || true

if [ ! -d "$APP_DIR/.git" ]; then
  find "$APP_DIR" -mindepth 1 -maxdepth 1 -exec rm -rf {} +
  git clone "$REPO" "$APP_DIR"
else
  cd "$APP_DIR"
  git fetch origin main
  git reset --hard origin/main
  git clean -fd
fi

cd "$APP_DIR"

if [ ! -f "$STATE_DIR/.env" ]; then
  cp .env.example "$STATE_DIR/.env"
  chmod 600 "$STATE_DIR/.env"
fi

set_env() {
  local key="$1" value="$2" file="$STATE_DIR/.env"
  if grep -q "^${key}=" "$file"; then
    sed -i "s#^${key}=.*#${key}=${value}#" "$file"
  else
    printf '%s=%s\n' "$key" "$value" >> "$file"
  fi
}

set_env APP_BASE "/"
set_env APP_URL "https://${DOMAIN}"
set_env IMAGE_BASE_URL "$IMAGE_BASE_URL"
set_env R2_ACCOUNT_ID "$R2_ACCOUNT_ID"
set_env R2_BUCKET "$R2_BUCKET"
set_env R2_ENDPOINT "$R2_ENDPOINT"
set_env R2_CATALOG_URL "$R2_CATALOG_URL"

php -v
php -l index.php
php -l admin.php
php -l api/chat.php
php -l src/R2Storage.php
php -l scripts/r2_check.php
php scripts/import_anvisa.php
php scripts/sync_images.php || true

R2_ACCESS="$(grep '^R2_ACCESS_KEY_ID=' "$STATE_DIR/.env" | cut -d= -f2- || true)"
R2_SECRET="$(grep '^R2_SECRET_ACCESS_KEY=' "$STATE_DIR/.env" | cut -d= -f2- || true)"
if [ -n "$R2_ACCESS" ] && [ -n "$R2_SECRET" ]; then
  if php scripts/r2_check.php; then
    echo "R2_WRITE=ok"
  else
    echo "R2_WRITE=failed"
  fi
else
  echo "R2_WRITE=pending_private_credentials"
fi

HTTP_CODE="$(curl -L -sS -o /tmp/farmacia_health.json -w '%{http_code}' "https://${DOMAIN}/?health=1" || true)"
echo "HEALTH_HTTP=${HTTP_CODE}"
IMAGE_HTTP="$(curl -L -sS -o /dev/null -w '%{http_code}' "${IMAGE_BASE_URL}/" || true)"
echo "IMAGE_CDN_HTTP=${IMAGE_HTTP}"
if [ "$HTTP_CODE" != "200" ]; then
  echo "AVISO: health-check do portal não retornou HTTP 200. Verifique DNS/vhost/SSL."
fi
if [ "$IMAGE_HTTP" = "000" ]; then
  echo "AVISO: o domínio de imagens ainda não respondeu. Verifique o custom domain do R2 no Cloudflare."
fi

echo "FARMACIA_DEPLOY_OK commit=$(git rev-parse --short HEAD) domain=${DOMAIN} image_domain=${IMAGE_BASE_URL} app=${APP_DIR}"
