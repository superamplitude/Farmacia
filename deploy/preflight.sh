#!/usr/bin/env bash
set -Eeuo pipefail

MODE="${1:-report}"
DOMAIN="farmacia.superamplitude.com"
FAILURES=0

ok(){ printf 'PREFLIGHT_OK %s\n' "$*"; }
warn(){ printf 'PREFLIGHT_WARN %s\n' "$*"; }
fail(){ printf 'PREFLIGHT_FAIL %s\n' "$*"; FAILURES=$((FAILURES+1)); }

# shellcheck disable=SC1091
source "$(dirname "$0")/layout.sh"
if ! farmacia_layout_load optional; then
  APP_USER=""
  APP_HOME=""
  APP_DIR=""
  STATE_DIR=""
fi
ENV_FILE="${STATE_DIR:-}/.env"

printf 'PREFLIGHT_USER=%s\n' "$(id -un)"
printf 'PREFLIGHT_UID=%s\n' "$(id -u)"
printf 'PREFLIGHT_GROUPS=%s\n' "$(id -Gn | tr ' ' ',')"
printf 'PREFLIGHT_HOST=%s\n' "$(hostname)"
printf 'PREFLIGHT_MODE=%s\n' "$MODE"

df -h / /home 2>/dev/null || true

for cmd in git php curl; do
  if command -v "$cmd" >/dev/null 2>&1; then ok "command_${cmd}=$(command -v "$cmd")"; else fail "command_${cmd}=missing"; fi
done

if command -v php >/dev/null 2>&1; then
  if php -r '$need=["pdo","pdo_sqlite","curl","mbstring","fileinfo"];foreach($need as $e){if(!extension_loaded($e)){fwrite(STDERR,"missing:$e\n");exit(1);}}' 2>/tmp/farmacia-php-ext.err; then
    ok 'php_extensions=pdo,pdo_sqlite,curl,mbstring,fileinfo'
  else
    fail "php_extensions=$(tr '\n' ',' </tmp/farmacia-php-ext.err)"
  fi
fi

if [[ -n "$APP_DIR" ]]; then
  ok "layout_site_user=${APP_USER}"
  ok "layout_app_dir=${APP_DIR}"
  ok "layout_state_dir=${STATE_DIR}"
  for p in "$APP_HOME" "$APP_HOME/htdocs" "$APP_DIR" "$STATE_DIR"; do
    if [[ -e "$p" ]]; then
      printf 'PREFLIGHT_PATH %s ' "$p"
      stat -c 'owner=%U group=%G mode=%a' "$p" 2>/dev/null || true
    else
      warn "path_missing=$p"
    fi
  done
  [[ -d "$APP_DIR" && -w "$APP_DIR" ]] && ok 'app_dir_writable=yes' || fail 'app_dir_writable=no'
  [[ -d "$STATE_DIR" && -w "$STATE_DIR" ]] && ok 'state_dir_writable=yes' || fail 'state_dir_writable=no'
else
  fail 'cloudpanel_layout=undetected'
fi

if [[ -n "$ENV_FILE" && -f "$ENV_FILE" ]]; then
  [[ -r "$ENV_FILE" ]] && ok 'private_env_readable=yes' || fail 'private_env_readable=no'
  printf 'PREFLIGHT_ENV owner='; stat -c '%U group=%G mode=%a' "$ENV_FILE" 2>/dev/null || true
else
  warn 'private_env=missing_will_be_created_on_first_deploy'
fi

if [[ -n "${VHOST_FILE:-}" && -f "$VHOST_FILE" ]]; then ok "cloudpanel_vhost=${VHOST_FILE}"; else warn 'cloudpanel_vhost=missing'; fi

if command -v sudo >/dev/null 2>&1 && sudo -n -l /usr/local/sbin/farmacia-fix-permissions >/dev/null 2>&1; then
  ok 'root_permission_helper=available'
else
  warn 'root_permission_helper=not_available'
fi

if command -v getfacl >/dev/null 2>&1 && [[ -n "$APP_DIR" ]]; then
  getfacl -cp "$APP_HOME" "$APP_HOME/htdocs" "$APP_DIR" "$STATE_DIR" 2>/dev/null | sed 's/^/PREFLIGHT_ACL /' || true
fi

printf 'PREFLIGHT_FAILURES=%d\n' "$FAILURES"
if [[ "$MODE" == "strict" && "$FAILURES" -gt 0 ]]; then exit 1; fi
exit 0
