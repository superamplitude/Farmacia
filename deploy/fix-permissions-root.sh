#!/usr/bin/env bash
set -Eeuo pipefail

APP_USER="farmacia"
RUNNER_USER="farmrunner"
APP_HOME="/home/${APP_USER}"
APP_DIR="${APP_HOME}/htdocs/farmacia.superamplitude.com"
STATE_DIR="${APP_HOME}/.farmacia"

[[ $EUID -eq 0 ]] || { echo 'ROOT_HELPER_FAIL requires root' >&2; exit 1; }
id "$APP_USER" >/dev/null 2>&1 || { echo "ROOT_HELPER_FAIL missing CloudPanel site user $APP_USER" >&2; exit 1; }
id "$RUNNER_USER" >/dev/null 2>&1 || { echo "ROOT_HELPER_FAIL missing runner user $RUNNER_USER" >&2; exit 1; }
command -v setfacl >/dev/null 2>&1 || { echo 'ROOT_HELPER_FAIL setfacl missing' >&2; exit 1; }

mkdir -p "$APP_DIR" "$STATE_DIR/uploads" "$STATE_DIR/backups"
chown -R "$APP_USER:$APP_USER" "$STATE_DIR"

# O runner recebe somente a travessia necessária até o site isolado.
setfacl -m "u:${RUNNER_USER}:--x" "$APP_HOME"
setfacl -m "u:${RUNNER_USER}:r-x" "$APP_HOME/htdocs"

# Código: dono CloudPanel mantém controle; runner pode sincronizar o repositório.
find "$APP_DIR" -type d -exec chown "$APP_USER:$APP_USER" {} +
find "$APP_DIR" -type f -exec chown "$APP_USER:$APP_USER" {} +
find "$APP_DIR" -type d -exec setfacl -m "u:${RUNNER_USER}:rwx,u:${APP_USER}:rwx,m:rwx" {} +
find "$APP_DIR" -type f -exec setfacl -m "u:${RUNNER_USER}:rw-,u:${APP_USER}:rw-,m:rw-" {} +
find "$APP_DIR" -type d -exec setfacl -m "d:u:${RUNNER_USER}:rwx,d:u:${APP_USER}:rwx,d:m:rwx" {} +

# Estado privado: somente o site e seu runner precisam de leitura/escrita.
find "$STATE_DIR" -type d -exec setfacl -m "u:${RUNNER_USER}:rwx,u:${APP_USER}:rwx,m:rwx" {} +
find "$STATE_DIR" -type f -exec setfacl -m "u:${RUNNER_USER}:rw-,u:${APP_USER}:rw-,m:rw-" {} +
find "$STATE_DIR" -type d -exec setfacl -m "d:u:${RUNNER_USER}:rwx,d:u:${APP_USER}:rwx,d:m:rwx" {} +

if [[ -f "$STATE_DIR/.env" ]]; then
  chown "$APP_USER:$APP_USER" "$STATE_DIR/.env"
  chmod 640 "$STATE_DIR/.env"
  setfacl -m "u:${RUNNER_USER}:rw-,u:${APP_USER}:rw-,m:rw-" "$STATE_DIR/.env"
fi
for f in "$STATE_DIR/farmacia.sqlite" "$STATE_DIR/farmacia.sqlite-wal" "$STATE_DIR/farmacia.sqlite-shm"; do
  if [[ -f "$f" ]]; then
    chown "$APP_USER:$APP_USER" "$f"
    chmod 660 "$f"
    setfacl -m "u:${RUNNER_USER}:rw-,u:${APP_USER}:rw-,m:rw-" "$f"
  fi
done

sudo -u "$RUNNER_USER" test -w "$APP_DIR"
sudo -u "$RUNNER_USER" test -w "$STATE_DIR"
sudo -u "$APP_USER" test -w "$STATE_DIR"

echo 'ROOT_PERMISSION_HELPER=OK'
