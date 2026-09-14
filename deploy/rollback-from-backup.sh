#!/usr/bin/env bash
set -Eeuo pipefail

DOMAIN="farmacia.superamplitude.com"
APP_USER="farmacia"
APP_DIR="/home/${APP_USER}/htdocs/${DOMAIN}"
STATE_DIR="/home/${APP_USER}/.farmacia"
LEGACY_APP_DIR="/home/superamplitude/htdocs/${DOMAIN}"
LEGACY_STATE_DIR="/home/superamplitude/.farmacia"
RUNNER_SERVICE="github-actions-farmacia"
BACKUP_DIR="${1:-}"

fail(){ echo "ERRO: $*" >&2; exit 1; }
[[ $EUID -eq 0 ]] || fail 'execute como root'
[[ -n "$BACKUP_DIR" ]] || fail 'informe o diretório do backup'
case "$BACKUP_DIR" in
  /root/farmacia-backups/*) ;;
  *) fail 'backup fora do diretório autorizado' ;;
esac
[[ -d "$BACKUP_DIR" ]] || fail 'backup não encontrado'

systemctl stop "$RUNNER_SERVICE" 2>/dev/null || true

if [[ -f "$BACKUP_DIR/.env" ]]; then
  mkdir -p "$STATE_DIR"
  cp -a "$BACKUP_DIR/.env" "$STATE_DIR/.env"
fi
if [[ -f "$BACKUP_DIR/farmacia.sqlite" ]]; then
  mkdir -p "$STATE_DIR"
  cp -a "$BACKUP_DIR/farmacia.sqlite" "$STATE_DIR/farmacia.sqlite"
fi
if [[ -f "$BACKUP_DIR/legacy.env" ]]; then
  mkdir -p "$LEGACY_STATE_DIR"
  cp -a "$BACKUP_DIR/legacy.env" "$LEGACY_STATE_DIR/.env"
fi
if [[ -f "$BACKUP_DIR/legacy-farmacia.sqlite" ]]; then
  mkdir -p "$LEGACY_STATE_DIR"
  cp -a "$BACKUP_DIR/legacy-farmacia.sqlite" "$LEGACY_STATE_DIR/farmacia.sqlite"
fi
if [[ -f "$BACKUP_DIR/legacy-app.tar.gz" ]]; then
  mkdir -p "$LEGACY_APP_DIR"
  tar -xzf "$BACKUP_DIR/legacy-app.tar.gz" -C "$LEGACY_APP_DIR"
fi

# Só remove o site CloudPanel se este backup comprovar que ele foi criado por aquela execução.
if [[ -f "$BACKUP_DIR/cloudpanel-site-created" ]]; then
  if command -v clpctl >/dev/null 2>&1; then
    clpctl site:delete --domainName="$DOMAIN" --force || true
  fi
else
  if [[ -x /usr/local/sbin/farmacia-fix-permissions ]] && id "$APP_USER" >/dev/null 2>&1; then
    /usr/local/sbin/farmacia-fix-permissions || true
  fi
fi

systemctl start "$RUNNER_SERVICE" 2>/dev/null || true

echo "ROLLBACK_OK backup=${BACKUP_DIR}"
