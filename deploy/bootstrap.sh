#!/usr/bin/env bash
set -Eeuo pipefail

DOMAIN="farmacia.superamplitude.com"
APP_DIR="/home/superamplitude/htdocs/${DOMAIN}"
STATE_DIR="/home/superamplitude/.farmacia"
REPO="https://github.com/superamplitude/Farmacia.git"

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
  echo "ATENCAO: configure $STATE_DIR/.env antes de liberar produção."
fi

# Garante que instalações antigas em /Farmacia não contaminem a URL do subdomínio.
if grep -q '^APP_BASE=/Farmacia$' "$STATE_DIR/.env" 2>/dev/null; then
  sed -i 's#^APP_BASE=/Farmacia$#APP_BASE=/#' "$STATE_DIR/.env"
fi
if grep -q '^APP_URL=' "$STATE_DIR/.env" 2>/dev/null; then
  sed -i 's#^APP_URL=.*#APP_URL=https://farmacia.superamplitude.com#' "$STATE_DIR/.env"
else
  printf '\nAPP_URL=https://farmacia.superamplitude.com\n' >> "$STATE_DIR/.env"
fi

php -v
php -l index.php
php -l admin.php
php -l api/chat.php
php scripts/import_anvisa.php
php scripts/sync_images.php || true

HTTP_CODE="$(curl -L -sS -o /tmp/farmacia_health.json -w '%{http_code}' "https://${DOMAIN}/?health=1" || true)"
echo "HEALTH_HTTP=${HTTP_CODE}"
if [ "$HTTP_CODE" != "200" ]; then
  echo "AVISO: deploy concluído, mas o health-check público ainda não retornou HTTP 200. Verifique DNS/vhost/SSL do subdomínio."
fi

echo "FARMACIA_DEPLOY_OK commit=$(git rev-parse --short HEAD) domain=${DOMAIN} app=${APP_DIR}"
