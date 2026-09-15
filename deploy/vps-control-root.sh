#!/usr/bin/env bash
set -Eeuo pipefail

DOMAIN="farmacia.superamplitude.com"
APP_USER="superamplitude-farmacia"
APP_GROUP="$(id -gn "$APP_USER" 2>/dev/null || true)"
APP_HOME="/home/${APP_USER}"
APP_DIR="${APP_HOME}/htdocs/${DOMAIN}"
STATE_DIR="${APP_HOME}/.farmacia"
LAYOUT_FILE="/etc/farmacia-superamplitude/layout.env"
VHOST="/etc/nginx/sites-enabled/${DOMAIN}.conf"
RUNNER_USER="farmrunner"
CMD="${1:-diagnose}"

fail(){ echo "VPS_CONTROL_FAIL $*" >&2; exit 1; }
require_root(){ [[ $EUID -eq 0 ]] || fail 'requires root'; }
validate_contract(){
  id "$APP_USER" >/dev/null 2>&1 || fail "missing site user ${APP_USER}"
  [[ -n "$APP_GROUP" ]] || fail "missing primary group for ${APP_USER}"
  id "$RUNNER_USER" >/dev/null 2>&1 || fail "missing runner user ${RUNNER_USER}"
  [[ -f "$VHOST" ]] || fail "missing vhost ${VHOST}"
  local root_path
  root_path="$(awk '$1=="root" {gsub(/;/,"",$2); print $2; exit}' "$VHOST" 2>/dev/null || true)"
  [[ "$root_path" == "$APP_DIR" ]] || fail "vhost root mismatch actual=${root_path:-missing} expected=${APP_DIR}"
}
write_layout(){
  mkdir -p "$(dirname "$LAYOUT_FILE")"
  cat >"$LAYOUT_FILE" <<EOF
APP_USER=${APP_USER}
APP_HOME=${APP_HOME}
APP_DIR=${APP_DIR}
STATE_DIR=${STATE_DIR}
VHOST_FILE=${VHOST}
EOF
  chown root:root "$LAYOUT_FILE"
  chmod 0644 "$LAYOUT_FILE"
}
permission_debug(){
  echo '--- permission debug ---' >&2
  id "$RUNNER_USER" >&2 || true
  namei -l "$APP_DIR" >&2 || true
  ls -ld /home "$APP_HOME" "$APP_HOME/htdocs" "$APP_DIR" "$STATE_DIR" >&2 || true
  getfacl -cp "$APP_HOME" "$APP_HOME/htdocs" "$APP_DIR" "$STATE_DIR" >&2 || true
  sudo -u "$RUNNER_USER" bash -lc 'id; pwd; cd "$1" && pwd && ls -ld . ' _ "$APP_DIR" >&2 || true
}
probe_runner_write(){
  local target="$1" marker
  marker="${target}/.farmrunner-write-probe-$$"
  sudo -u "$RUNNER_USER" env HOME="/home/${RUNNER_USER}" bash -c 'set -e; cd "$1"; : > "$2"; rm -f "$2"' _ "$target" "$marker"
}
fix_permissions(){
  mkdir -p "$APP_DIR" "$STATE_DIR/uploads" "$STATE_DIR/backups"

  usermod -a -G "$APP_GROUP" "$RUNNER_USER"

  chown -R "$APP_USER:$APP_GROUP" "$STATE_DIR"
  chown "$APP_USER:$APP_GROUP" "$APP_HOME" "$APP_HOME/htdocs" "$APP_DIR"

  chmod g+rx "$APP_HOME" "$APP_HOME/htdocs"
  chmod g+rwx "$APP_DIR" "$STATE_DIR"

  # O runner recebe leitura/travessia nos pais e rwx apenas no document root/estado.
  setfacl -m "u:${RUNNER_USER}:r-x,g:${APP_GROUP}:r-x,m::rwx" "$APP_HOME" "$APP_HOME/htdocs"
  setfacl -m "u:${RUNNER_USER}:rwx,g:${APP_GROUP}:rwx,m::rwx" "$APP_DIR" "$STATE_DIR"
  setfacl -m "d:u:${RUNNER_USER}:rwx,d:g:${APP_GROUP}:rwx,d:m::rwx" "$APP_DIR" "$STATE_DIR"

  find "$APP_DIR" -type d -exec chown "$APP_USER:$APP_GROUP" {} +
  find "$APP_DIR" -type f -exec chown "$APP_USER:$APP_GROUP" {} +
  find "$APP_DIR" -type d -exec setfacl -m "u:${RUNNER_USER}:rwx,g:${APP_GROUP}:rwx,m::rwx,d:u:${RUNNER_USER}:rwx,d:g:${APP_GROUP}:rwx,d:m::rwx" {} +
  find "$APP_DIR" -type f -exec setfacl -m "u:${RUNNER_USER}:rw-,g:${APP_GROUP}:rw-,m::rw-" {} +
  find "$STATE_DIR" -type d -exec chown "$APP_USER:$APP_GROUP" {} +
  find "$STATE_DIR" -type f -exec chown "$APP_USER:$APP_GROUP" {} +
  find "$STATE_DIR" -type d -exec setfacl -m "u:${RUNNER_USER}:rwx,g:${APP_GROUP}:rwx,m::rwx,d:u:${RUNNER_USER}:rwx,d:g:${APP_GROUP}:rwx,d:m::rwx" {} +
  find "$STATE_DIR" -type f -exec setfacl -m "u:${RUNNER_USER}:rw-,g:${APP_GROUP}:rw-,m::rw-" {} +

  if ! probe_runner_write "$APP_DIR"; then permission_debug; fail 'runner cannot write app dir'; fi
  if ! probe_runner_write "$STATE_DIR"; then permission_debug; fail 'runner cannot write state dir'; fi
  echo "VPS_PERMISSIONS=OK app=${APP_DIR} state=${STATE_DIR} group=${APP_GROUP}"
}
diagnose(){
  echo "VPS_CONTRACT domain=${DOMAIN} site_user=${APP_USER} site_group=${APP_GROUP} app=${APP_DIR} state=${STATE_DIR}"
  permission_debug
  echo '--- vhost root / upstream ---'
  grep -nE '^[[:space:]]*(root|fastcgi_pass|proxy_pass|server_name)[[:space:]]' "$VHOST" 2>/dev/null || true
  echo '--- nginx config test ---'
  nginx -t 2>&1 || true
  echo '--- services ---'
  systemctl is-active nginx 2>/dev/null || true
  systemctl is-active php8.2-fpm 2>/dev/null || true
  systemctl is-active github-actions-farmacia 2>/dev/null || true
  echo '--- php sockets ---'
  find /run /var/run -maxdepth 3 \( -type s -iname '*php*' -o -type s -iname '*fpm*sock' \) 2>/dev/null | sort -u | head -80 || true
  echo '--- origin health ---'
  curl -k -sS -D - --resolve "${DOMAIN}:443:127.0.0.1" "https://${DOMAIN}/?health=1" -o /tmp/farmacia-origin-body || true
  head -c 1000 /tmp/farmacia-origin-body 2>/dev/null || true; echo
  echo '--- public health ---'
  curl -k -sS -D - "https://${DOMAIN}/?health=1" -o /tmp/farmacia-public-body || true
  head -c 1000 /tmp/farmacia-public-body 2>/dev/null || true; echo
  echo '--- nginx recent errors ---'
  tail -n 120 /var/log/nginx/error.log 2>/dev/null || true
  for f in /home/${APP_USER}/logs/*error*.log /var/log/nginx/*${DOMAIN}*error*.log; do
    [[ -f "$f" ]] && { echo "--- $f ---"; tail -n 120 "$f"; }
  done
}

require_root
validate_contract
case "$CMD" in
  prepare)
    write_layout
    fix_permissions
    echo 'VPS_CONTROL=READY'
    ;;
  fix-permissions)
    write_layout
    fix_permissions
    ;;
  diagnose)
    diagnose
    ;;
  nginx-reload)
    nginx -t
    systemctl reload nginx
    echo 'VPS_NGINX_RELOAD=OK'
    ;;
  php82-restart)
    systemctl restart php8.2-fpm
    systemctl is-active --quiet php8.2-fpm
    echo 'VPS_PHP82_RESTART=OK'
    ;;
  *)
    fail "unsupported command ${CMD}"
    ;;
esac
