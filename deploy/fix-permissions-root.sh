#!/usr/bin/env bash
set -Eeuo pipefail

RUNNER_USER="farmrunner"
LAYOUT_FILE="/etc/farmacia-superamplitude/layout.env"

[[ $EUID -eq 0 ]] || { echo 'ROOT_HELPER_FAIL requires root' >&2; exit 1; }
[[ -r "$LAYOUT_FILE" ]] || { echo "ROOT_HELPER_FAIL missing layout $LAYOUT_FILE" >&2; exit 1; }
# shellcheck disable=SC1090
source "$LAYOUT_FILE"

[[ -n "${APP_USER:-}" && -n "${APP_HOME:-}" && -n "${APP_DIR:-}" && -n "${STATE_DIR:-}" ]] || { echo 'ROOT_HELPER_FAIL invalid layout' >&2; exit 1; }
id "$APP_USER" >/dev/null 2>&1 || { echo "ROOT_HELPER_FAIL missing CloudPanel site user $APP_USER" >&2; exit 1; }
id "$RUNNER_USER" >/dev/null 2>&1 || { echo "ROOT_HELPER_FAIL missing runner user $RUNNER_USER" >&2; exit 1; }
command -v setfacl >/dev/null 2>&1 || { echo 'ROOT_HELPER_FAIL setfacl missing' >&2; exit 1; }

mkdir -p "$APP_DIR" "$STATE_DIR/uploads" "$STATE_DIR/backups"
chown -R "$APP_USER:$APP_USER" "$STATE_DIR"

# Somente a travessia necessária até o document root real do site.
setfacl -m "u:${RUNNER_USER}:--x" "$APP_HOME"
mkdir -p "$APP_HOME/htdocs"
setfacl -m "u:${RUNNER_USER}:r-x" "$APP_HOME/htdocs"

# Código: o Site User continua dono; o runner pode sincronizar o repositório.
find "$APP_DIR" -type d -exec chown "$APP_USER:$APP_USER" {} +
find "$APP_DIR" -type f -exec chown "$APP_USER:$APP_USER" {} +
find "$APP_DIR" -type d -exec setfacl -m "u:${RUNNER_USER}:rwx,u:${APP_USER}:rwx,m:rwx" {} +
find "$APP_DIR" -type f -exec setfacl -m "u:${RUNNER_USER}:rw-,u:${APP_USER}:rw-,m:rw-" {} +
find "$APP_DIR" -type d -exec setfacl -m "d:u:${RUNNER_USER}:rwx,d:u:${APP_USER}:rwx,d:m:rwx" {} +

# Estado privado: Site User e runner possuem leitura/escrita.
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

echo "ROOT_PERMISSION_HELPER=OK site_user=${APP_USER} app=${APP_DIR} state=${STATE_DIR}"
