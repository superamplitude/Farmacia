#!/usr/bin/env bash
set -Eeuo pipefail
APP_DIR="/home/superamplitude/htdocs/www.superamplitude.com/Farmacia"
STATE_DIR="/home/superamplitude/.farmacia"
REPO="https://github.com/superamplitude/Farmacia.git"
mkdir -p "$STATE_DIR/uploads" "$APP_DIR"
if [ ! -d "$APP_DIR/.git" ]; then rm -rf "$APP_DIR"/*; git clone "$REPO" "$APP_DIR"; else cd "$APP_DIR"; git fetch origin main; git reset --hard origin/main; fi
cd "$APP_DIR"
if [ ! -f "$STATE_DIR/.env" ]; then cp .env.example "$STATE_DIR/.env"; chmod 600 "$STATE_DIR/.env"; echo "ATENCAO: configure $STATE_DIR/.env antes de liberar produção."; fi
php -v
php -l index.php
php -l admin.php
php -l api/chat.php
php scripts/import_anvisa.php
php scripts/sync_images.php || true
curl -fsS "https://superamplitude.com/Farmacia/?health=1" >/dev/null || true
echo "FARMACIA_DEPLOY_OK commit=$(git rev-parse --short HEAD) app=$APP_DIR"
