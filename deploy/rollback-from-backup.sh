#!/usr/bin/env bash
set -Eeuo pipefail

DOMAIN="farmacia.superamplitude.com"
LEGACY_APP_DIR="/home/superamplitude/htdocs/${DOMAIN}"
LEGACY_STATE_DIR="/home/superamplitude/.farmacia"
RUNNER_SERVICE="github-actions-farmacia"
BACKUP_DIR="${1:-}"

fail(){ echo "ERRO: $*" >&2; exit 1; }
[[ $EUID -eq 0 ]] || fail 'execute como root'
[[ -n "$BACKUP_DIR" ]] || fail 'informe o diretório do backup'
case "$BACKUP_DIR" in /root/farmacia-backups/*) ;; *) fail 'backup fora do diretório autorizado' ;; esac
[[ -d "$BACKUP_DIR" ]] || fail 'backup não encontrado'

APP_USER=""
APP_DIR=""
STATE_DIR=""
if [[ -r "$BACKUP_DIR/layout.env" ]]; then
  # shellcheck disable=SC1090
  source "$BACKUP_DIR/layout.env"
elif [[ -r /etc/farmacia-superamplitude/layout.env ]]; then
  # shellcheck disable=SC1091
  source /etc/farmacia-superamplitude/layout.env
fi

systemctl stop "$RUNNER_SERVICE" 2>/dev/null || true

# Se o site foi criado por esta execução, removê-lo devolve o CloudPanel ao estado anterior.
if [[ -f "$BACKUP_DIR/cloudpanel-site-created" ]]; then
  if command -v clpctl >/dev/null 2>&1; then clpctl site:delete --domainName="$DOMAIN" --force || true; fi
else
  if [[ -n "$APP_DIR" && -f "$BACKUP_DIR/pre-app.tar.gz" ]]; then
    mkdir -p "$APP_DIR"
    find "$APP_DIR" -mindepth 1 -maxdepth 1 -exec rm -rf {} +
    tar -xzf "$BACKUP_DIR/pre-app.tar.gz" -C "$APP_DIR"
  fi
  if [[ -n "$STATE_DIR" ]]; then
    mkdir -p "$STATE_DIR"
    [[ -f "$BACKUP_DIR/pre.env" ]] && cp -a "$BACKUP_DIR/pre.env" "$STATE_DIR/.env"
    [[ -f "$BACKUP_DIR/pre-farmacia.sqlite" ]] && cp -a "$BACKUP_DIR/pre-farmacia.sqlite" "$STATE_DIR/farmacia.sqlite"
  fi
fi

# Cópia legada adicional, somente quando existia separadamente.
if [[ -f "$BACKUP_DIR/legacy-app.tar.gz" ]]; then
  mkdir -p "$LEGACY_APP_DIR"
  find "$LEGACY_APP_DIR" -mindepth 1 -maxdepth 1 -exec rm -rf {} +
  tar -xzf "$BACKUP_DIR/legacy-app.tar.gz" -C "$LEGACY_APP_DIR"
fi
if [[ -f "$BACKUP_DIR/legacy.env" ]]; then mkdir -p "$LEGACY_STATE_DIR"; cp -a "$BACKUP_DIR/legacy.env" "$LEGACY_STATE_DIR/.env"; fi
if [[ -f "$BACKUP_DIR/legacy-farmacia.sqlite" ]]; then mkdir -p "$LEGACY_STATE_DIR"; cp -a "$BACKUP_DIR/legacy-farmacia.sqlite" "$LEGACY_STATE_DIR/farmacia.sqlite"; fi

if [[ -x /usr/local/sbin/farmacia-fix-permissions && -r /etc/farmacia-superamplitude/layout.env ]]; then
  /usr/local/sbin/farmacia-fix-permissions || true
fi
systemctl start "$RUNNER_SERVICE" 2>/dev/null || true

echo "ROLLBACK_OK backup=${BACKUP_DIR}"
