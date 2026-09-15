#!/usr/bin/env bash
set -Eeuo pipefail
umask 0077

BACKUP_DIR="${1:-}"
APP_DIR="/home/superamplitude-farmacia/htdocs/farmacia.superamplitude.com"
STATE_DIR="/home/superamplitude-farmacia/.farmacia"

[[ -n "$BACKUP_DIR" ]] || { echo 'ROLLBACK_FAIL backup path required' >&2; exit 2; }
case "$BACKUP_DIR" in
  "$STATE_DIR"/backups/pre-deploy-*) ;;
  *) echo 'ROLLBACK_FAIL invalid backup path' >&2; exit 3 ;;
esac
[[ -d "$BACKUP_DIR" ]] || { echo 'ROLLBACK_FAIL backup directory missing' >&2; exit 4; }
[[ -f "$BACKUP_DIR/app.tar.gz" ]] || { echo 'ROLLBACK_FAIL app archive missing' >&2; exit 5; }

TS="$(date +%Y%m%d-%H%M%S)"
SAFETY="$STATE_DIR/backups/pre-rollback-${TS}"
mkdir -p "$SAFETY"
tar -czf "$SAFETY/app.tar.gz" --exclude='.git' -C "$APP_DIR" .
[[ -f "$STATE_DIR/.env" ]] && cp -a "$STATE_DIR/.env" "$SAFETY/.env"
[[ -f "$STATE_DIR/farmacia.sqlite" ]] && cp -a "$STATE_DIR/farmacia.sqlite" "$SAFETY/farmacia.sqlite"

find "$APP_DIR" -mindepth 1 -maxdepth 1 ! -name .git -exec rm -rf {} +
tar -xzf "$BACKUP_DIR/app.tar.gz" -C "$APP_DIR"

if [[ -f "$BACKUP_DIR/.env" ]]; then cp -a "$BACKUP_DIR/.env" "$STATE_DIR/.env"; fi
if [[ -f "$BACKUP_DIR/farmacia.sqlite" ]]; then cp -a "$BACKUP_DIR/farmacia.sqlite" "$STATE_DIR/farmacia.sqlite"; fi

chmod 660 "$STATE_DIR/.env" 2>/dev/null || true
chmod 660 "$STATE_DIR/farmacia.sqlite" 2>/dev/null || true

php "$APP_DIR/scripts/self_test.php" >/tmp/farmacia-rollback-selftest.json
cat /tmp/farmacia-rollback-selftest.json

echo "ROLLBACK_OK source=$BACKUP_DIR safety=$SAFETY"
