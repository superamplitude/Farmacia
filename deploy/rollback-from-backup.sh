#!/usr/bin/env bash
set -Eeuo pipefail

APP_DIR="/home/superamplitude/htdocs/farmacia.superamplitude.com"
STATE_DIR="/home/superamplitude/.farmacia"
RUNNER_SERVICE="github-actions-farmacia"
BACKUP_DIR="${1:-}"

fail(){ echo "ERRO: $*" >&2; exit 1; }
[[ $EUID -eq 0 ]] || fail 'execute como root'
[[ -n "$BACKUP_DIR" ]] || fail 'informe o diretório do backup'
case "$BACKUP_DIR" in
  "$STATE_DIR"/backups/*) ;;
  *) fail 'backup fora do diretório autorizado' ;;
esac
[[ -d "$BACKUP_DIR" ]] || fail 'backup não encontrado'

systemctl stop "$RUNNER_SERVICE" 2>/dev/null || true

if [[ -f "$BACKUP_DIR/app.tar.gz" ]]; then
  mkdir -p "$APP_DIR"
  find "$APP_DIR" -mindepth 1 -maxdepth 1 -exec rm -rf {} +
  tar -xzf "$BACKUP_DIR/app.tar.gz" -C "$APP_DIR"
fi
if [[ -f "$BACKUP_DIR/.env" ]]; then
  cp -a "$BACKUP_DIR/.env" "$STATE_DIR/.env"
fi
if [[ -f "$BACKUP_DIR/farmacia.sqlite" ]]; then
  cp -a "$BACKUP_DIR/farmacia.sqlite" "$STATE_DIR/farmacia.sqlite"
fi
if [[ -x /usr/local/sbin/farmacia-fix-permissions ]]; then
  /usr/local/sbin/farmacia-fix-permissions
fi

systemctl start "$RUNNER_SERVICE" 2>/dev/null || true

echo "ROLLBACK_OK backup=${BACKUP_DIR}"
