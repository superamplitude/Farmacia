#!/usr/bin/env bash
set -Eeuo pipefail

MODE="${1:-report}"
DOMAIN="farmacia.superamplitude.com"
APP_DIR="/home/superamplitude/htdocs/${DOMAIN}"
STATE_DIR="/home/superamplitude/.farmacia"
ENV_FILE="${STATE_DIR}/.env"
FAILURES=0

ok(){ printf 'PREFLIGHT_OK %s\n' "$*"; }
warn(){ printf 'PREFLIGHT_WARN %s\n' "$*"; }
fail(){ printf 'PREFLIGHT_FAIL %s\n' "$*"; FAILURES=$((FAILURES+1)); }

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

for p in /home/superamplitude /home/superamplitude/htdocs "$APP_DIR" "$STATE_DIR"; do
  if [[ -e "$p" ]]; then
    printf 'PREFLIGHT_PATH %s ' "$p"
    stat -c 'owner=%U group=%G mode=%a' "$p" 2>/dev/null || true
  else
    warn "path_missing=$p"
  fi
done

if [[ -d "$APP_DIR" && -w "$APP_DIR" ]]; then ok 'app_dir_writable=yes'; else fail 'app_dir_writable=no'; fi
if [[ -d "$STATE_DIR" && -w "$STATE_DIR" ]]; then ok 'state_dir_writable=yes'; else fail 'state_dir_writable=no'; fi
if [[ -f "$ENV_FILE" ]]; then
  [[ -r "$ENV_FILE" ]] && ok 'private_env_readable=yes' || fail 'private_env_readable=no'
  printf 'PREFLIGHT_ENV owner='; stat -c '%U group=%G mode=%a' "$ENV_FILE" 2>/dev/null || true
else
  warn 'private_env=missing_will_be_created_on_first_deploy'
fi

if command -v sudo >/dev/null 2>&1 && sudo -n -l /usr/local/sbin/farmacia-fix-permissions >/dev/null 2>&1; then
  ok 'root_permission_helper=available'
else
  warn 'root_permission_helper=not_available'
fi

if command -v getfacl >/dev/null 2>&1; then
  getfacl -cp /home/superamplitude /home/superamplitude/htdocs "$APP_DIR" "$STATE_DIR" 2>/dev/null | sed 's/^/PREFLIGHT_ACL /' || true
fi

printf 'PREFLIGHT_FAILURES=%d\n' "$FAILURES"
if [[ "$MODE" == "strict" && "$FAILURES" -gt 0 ]]; then
  exit 1
fi
exit 0
