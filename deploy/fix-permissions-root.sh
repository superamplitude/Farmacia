#!/usr/bin/env bash
set -Eeuo pipefail

APP_USER="superamplitude"
RUNNER_USER="farmrunner"
APP_DIR="/home/superamplitude/htdocs/farmacia.superamplitude.com"
STATE_DIR="/home/superamplitude/.farmacia"

[[ $EUID -eq 0 ]] || { echo 'ROOT_HELPER_FAIL requires root' >&2; exit 1; }
id "$APP_USER" >/dev/null 2>&1 || { echo "ROOT_HELPER_FAIL missing user $APP_USER" >&2; exit 1; }
id "$RUNNER_USER" >/dev/null 2>&1 || { echo "ROOT_HELPER_FAIL missing user $RUNNER_USER" >&2; exit 1; }
command -v setfacl >/dev/null 2>&1 || { echo 'ROOT_HELPER_FAIL setfacl missing' >&2; exit 1; }

mkdir -p "$APP_DIR" "$STATE_DIR/uploads" "$STATE_DIR/backups"

# Somente travessia nos diretórios pais; não altera os modos existentes do CloudPanel.
setfacl -m "u:${RUNNER_USER}:--x" /home/superamplitude
setfacl -m "u:${RUNNER_USER}:r-x" /home/superamplitude/htdocs

# Código: runner escreve; usuário web somente lê/executa diretórios.
find "$APP_DIR" -type d -exec setfacl -m "u:${RUNNER_USER}:rwx,u:${APP_USER}:r-x,m:rwx" {} +
find "$APP_DIR" -type f -exec setfacl -m "u:${RUNNER_USER}:rw-,u:${APP_USER}:r--,m:rw-" {} +
find "$APP_DIR" -type d -exec setfacl -m "d:u:${RUNNER_USER}:rwx,d:u:${APP_USER}:r-x,d:m:rwx" {} +

# Estado privado: web e runner precisam ler/escrever DB e uploads.
find "$STATE_DIR" -type d -exec setfacl -m "u:${RUNNER_USER}:rwx,u:${APP_USER}:rwx,m:rwx" {} +
find "$STATE_DIR" -type f -exec setfacl -m "u:${RUNNER_USER}:rw-,u:${APP_USER}:rw-,m:rw-" {} +
find "$STATE_DIR" -type d -exec setfacl -m "d:u:${RUNNER_USER}:rwx,d:u:${APP_USER}:rwx,d:m:rwx" {} +

if [[ -f "$STATE_DIR/.env" ]]; then
  chown "$APP_USER:$APP_USER" "$STATE_DIR/.env"
  chmod 640 "$STATE_DIR/.env"
  setfacl -m "u:${RUNNER_USER}:rw-,u:${APP_USER}:rw-,m:rw-" "$STATE_DIR/.env"
fi
if [[ -f "$STATE_DIR/farmacia.sqlite" ]]; then
  chown "$APP_USER:$APP_USER" "$STATE_DIR/farmacia.sqlite"
  chmod 660 "$STATE_DIR/farmacia.sqlite"
  setfacl -m "u:${RUNNER_USER}:rw-,u:${APP_USER}:rw-,m:rw-" "$STATE_DIR/farmacia.sqlite"
fi
for f in "$STATE_DIR/farmacia.sqlite-wal" "$STATE_DIR/farmacia.sqlite-shm"; do
  if [[ -f "$f" ]]; then
    chown "$APP_USER:$APP_USER" "$f"
    chmod 660 "$f"
    setfacl -m "u:${RUNNER_USER}:rw-,u:${APP_USER}:rw-,m:rw-" "$f"
  fi
done

sudo -u "$RUNNER_USER" test -w "$APP_DIR"
sudo -u "$RUNNER_USER" test -w "$STATE_DIR"
sudo -u "$APP_USER" test -r "$APP_DIR" || true
sudo -u "$APP_USER" test -w "$STATE_DIR"

echo 'ROOT_PERMISSION_HELPER=OK'
