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
PHP_VERSION="8.2"
PHP_POOL_DIR="/etc/php/${PHP_VERSION}/fpm/pool.d"
PHP_POOL_FALLBACK="${PHP_POOL_DIR}/superamplitude-farmacia.conf"
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
  install -d -m 0755 -o root -g root "$(dirname "$LAYOUT_FILE")"
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
  sudo -u "$RUNNER_USER" env HOME="/home/${RUNNER_USER}" bash -c 'cd "$1" && pwd && test -w . && echo RUNNER_PATH_ACCESS=OK' _ "$APP_DIR" >&2 || true
}
probe_runner_write(){
  local target="$1" marker
  marker="${target}/.farmrunner-write-probe-$$"
  sudo -u "$RUNNER_USER" env HOME="/home/${RUNNER_USER}" bash -c 'set -e; cd "$1"; : > "$2"; rm -f "$2"' _ "$target" "$marker"
}
fix_permissions(){
  mkdir -p "$APP_DIR" "$STATE_DIR/uploads" "$STATE_DIR/backups"
  usermod -a -G "$APP_GROUP" "$RUNNER_USER" || true
  chown -R "$APP_USER:$APP_GROUP" "$STATE_DIR"
  chown "$APP_USER:$APP_GROUP" "$APP_HOME" "$APP_HOME/htdocs" "$APP_DIR"
  chmod g+rx "$APP_HOME" "$APP_HOME/htdocs"
  chmod g+rwx "$APP_DIR" "$STATE_DIR"
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
  probe_runner_write "$APP_DIR" || { permission_debug; fail 'runner cannot write app dir'; }
  probe_runner_write "$STATE_DIR" || { permission_debug; fail 'runner cannot write state dir'; }
  echo "VPS_PERMISSIONS=OK app=${APP_DIR} state=${STATE_DIR} group=${APP_GROUP}"
}

find_existing_php_pool(){
  local f user listen
  shopt -s nullglob
  for f in "$PHP_POOL_DIR"/*.conf; do
    user="$(awk -F= '/^[[:space:]]*user[[:space:]]*=/{gsub(/[[:space:]]/,"",$2);print $2;exit}' "$f" 2>/dev/null || true)"
    [[ "$user" == "$APP_USER" ]] || continue
    listen="$(awk -F= '/^[[:space:]]*listen[[:space:]]*=/{sub(/^[[:space:]]*/,"",$2);sub(/[[:space:]]*$/, "", $2);print $2;exit}' "$f" 2>/dev/null || true)"
    [[ -n "$listen" ]] || continue
    printf '%s|%s\n' "$f" "$listen"
    return 0
  done
  return 1
}
port_in_use(){ ss -H -ltn 2>/dev/null | awk '{print $4}' | grep -Eq "[:.]${1}$"; }
create_php_pool(){
  local port=19082
  while port_in_use "$port"; do port=$((port+1)); [[ "$port" -le 19182 ]] || fail 'no free PHP-FPM port in reserved range'; done
  mkdir -p "$PHP_POOL_DIR"
  cat > "$PHP_POOL_FALLBACK" <<EOF
[superamplitude-farmacia]
user = ${APP_USER}
group = ${APP_GROUP}
listen = 127.0.0.1:${port}
listen.allowed_clients = 127.0.0.1
pm = ondemand
pm.max_children = 12
pm.process_idle_timeout = 10s
pm.max_requests = 500
chdir = ${APP_DIR}
catch_workers_output = yes
clear_env = no
EOF
  chown root:root "$PHP_POOL_FALLBACK"
  chmod 0644 "$PHP_POOL_FALLBACK"
  echo "PHP_POOL_CREATED=${PHP_POOL_FALLBACK} listen=127.0.0.1:${port}" >&2
  printf '%s|%s\n' "$PHP_POOL_FALLBACK" "127.0.0.1:${port}"
}
listener_ready(){
  local listen="$1" host port
  case "$listen" in
    unix:*) [[ -S "${listen#unix:}" ]] ;;
    /*) [[ -S "$listen" ]] ;;
    *:*)
      host="${listen%:*}"; port="${listen##*:}"
      php -r '$s=@fsockopen($argv[1],(int)$argv[2],$e,$es,2); if(!$s){fwrite(STDERR,"FPM_CONNECT_FAIL {$e} {$es}\n"); exit(1);} fclose($s);' "$host" "$port"
      ;;
    *) return 1 ;;
  esac
}
ensure_php_pool(){
  local found pool listen testbin i
  found="$(find_existing_php_pool || true)"
  if [[ -z "$found" ]]; then found="$(create_php_pool)"; fi
  pool="${found%%|*}"
  listen="${found#*|}"
  [[ -f "$pool" && -n "$listen" ]] || fail 'could not determine PHP-FPM pool'
  testbin="$(command -v php-fpm${PHP_VERSION} || command -v php-fpm8.2 || true)"
  if [[ -n "$testbin" ]]; then "$testbin" -t >/tmp/farmacia-php-fpm-test.out 2>&1 || { cat /tmp/farmacia-php-fpm-test.out >&2; fail 'php-fpm config test failed'; }; fi
  systemctl restart "php${PHP_VERSION}-fpm"
  systemctl is-active --quiet "php${PHP_VERSION}-fpm" || fail 'php8.2-fpm did not become active'
  for i in $(seq 1 20); do
    if listener_ready "$listen" >/dev/null 2>&1; then
      echo "PHP_FPM_POOL=${pool}" >&2
      echo "PHP_FPM_LISTEN=${listen}" >&2
      echo "PHP_FPM_READY=1" >&2
      printf '%s' "$listen"
      return 0
    fi
    sleep 0.25
  done
  echo '--- php8.2-fpm journal ---' >&2
  journalctl -u php8.2-fpm -n 120 --no-pager >&2 || true
  echo '--- php8.2 listeners ---' >&2
  ss -ltnp >&2 || true
  fail "php8.2-fpm listener not reachable: ${listen}"
}
fastcgi_target(){
  local listen="$1"
  case "$listen" in
    /*) printf 'unix:%s' "$listen" ;;
    unix:*) printf '%s' "$listen" ;;
    *) printf '%s' "$listen" ;;
  esac
}
repair_php_vhost(){
  validate_contract
  local listen target ts backup tmp
  listen="$(ensure_php_pool)"
  target="$(fastcgi_target "$listen")"
  ts="$(date +%Y%m%d-%H%M%S)"
  backup="/root/farmacia-vhost-${ts}.conf.bak"
  cp -a "$VHOST" "$backup"
  tmp="$(mktemp)"
  VHOST_SRC="$VHOST" VHOST_DST="$tmp" FASTCGI_TARGET="$target" python3 <<'PY'
import os, re
src=os.environ['VHOST_SRC']; dst=os.environ['VHOST_DST']; target=os.environ['FASTCGI_TARGET']
text=open(src,encoding='utf-8').read()
root='root /home/superamplitude-farmacia/htdocs/farmacia.superamplitude.com;'
if root not in text:
    raise SystemExit('ROOT_CONTRACT_MISMATCH')
# Canonical index order for a PHP application.
if re.search(r'(?m)^\s*index\s+[^;]+;', text):
    text=re.sub(r'(?m)^\s*index\s+[^;]+;', '  index index.php index.html;', text, count=1)
else:
    text=text.replace(root, root+'\n  index index.php index.html;', 1)
needle='proxy_pass http://127.0.0.1:3005/;'
pos=text.find(needle)
if pos >= 0:
    line_start=text.rfind('\n',0,pos)+1
    block_start=None; depth=0
    for i in range(line_start-1,-1,-1):
        ch=text[i]
        if ch=='}': depth += 1
        elif ch=='{':
            if depth==0:
                prefix=text[max(0,text.rfind('\n',0,i)+1):i]
                if re.search(r'\blocation\b',prefix):
                    block_start=max(0,text.rfind('\n',0,i)+1)
                    break
            else: depth -= 1
    if block_start is None: raise SystemExit('PROXY_LOCATION_START_NOT_FOUND')
    brace=text.find('{',block_start); depth=0; block_end=None
    for i in range(brace,len(text)):
        if text[i]=='{': depth += 1
        elif text[i]=='}':
            depth -= 1
            if depth==0:
                block_end=i+1; break
    if block_end is None: raise SystemExit('PROXY_LOCATION_END_NOT_FOUND')
    replacement=f'''  location / {{\n    try_files $uri $uri/ /index.php?$query_string;\n  }}\n\n  location ~ \\.php$ {{\n    try_files $uri =404;\n    include fastcgi_params;\n    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;\n    fastcgi_param SCRIPT_NAME $fastcgi_script_name;\n    fastcgi_index index.php;\n    fastcgi_pass {target};\n  }}'''
    text=text[:block_start]+replacement+text[block_end:]
    print('VHOST_PROXY_REPLACED=1')
elif re.search(r'(?m)^\s*fastcgi_pass\s+[^;]+;', text):
    text=re.sub(r'(?m)^(\s*fastcgi_pass\s+)[^;]+;', lambda m: m.group(1)+target+';', text, count=1)
    print('VHOST_PHP_REFRESHED=1')
else:
    raise SystemExit('NO_PROXY_OR_FASTCGI_FOUND')
open(dst,'w',encoding='utf-8').write(text)
PY
  chown root:root "$tmp"
  chmod --reference="$VHOST" "$tmp" 2>/dev/null || chmod 0644 "$tmp"
  mv "$tmp" "$VHOST"
  if ! nginx -t >/tmp/farmacia-nginx-test.out 2>&1; then
    cat /tmp/farmacia-nginx-test.out >&2
    cp -a "$backup" "$VHOST"
    nginx -t >/dev/null 2>&1 || true
    fail "nginx test failed; vhost restored from ${backup}"
  fi
  systemctl reload nginx
  echo "VHOST_BACKUP=${backup}"
  echo "VHOST_MODE=php-fpm"
  echo "VHOST_FASTCGI_PASS=${target}"
  echo "VPS_VHOST_REPAIR=OK"
}
refresh_ca(){
  command -v update-ca-certificates >/dev/null 2>&1 || fail 'update-ca-certificates missing'
  update-ca-certificates >/tmp/farmacia-ca-refresh.out 2>&1 || { cat /tmp/farmacia-ca-refresh.out >&2; fail 'CA refresh failed'; }
  [[ -s /etc/ssl/certs/ca-certificates.crt ]] || fail 'system CA bundle missing after refresh'
  echo 'VPS_CA_CERTIFICATES=OK'
}
diagnose(){
  echo "VPS_CONTRACT domain=${DOMAIN} site_user=${APP_USER} site_group=${APP_GROUP} app=${APP_DIR} state=${STATE_DIR}"
  permission_debug
  echo '--- active vhost ---'
  sed -n '1,180p' "$VHOST" 2>/dev/null || true
  echo '--- php-fpm pools for site user ---'
  grep -RHE '^[[:space:]]*(user|group|listen)[[:space:]]*=' "$PHP_POOL_DIR"/*.conf 2>/dev/null | grep -E "${APP_USER}|listen" | head -100 || true
  echo '--- listeners ---'
  ss -ltnp 2>/dev/null | grep -E '(:443\b|:190[0-9]{2}\b|:191[0-9]{2}\b)' || true
  echo '--- nginx config test ---'
  nginx -t 2>&1 || true
  echo '--- services ---'
  systemctl is-active nginx 2>/dev/null || true
  systemctl is-active php8.2-fpm 2>/dev/null || true
  systemctl is-active github-actions-farmacia 2>/dev/null || true
  echo '--- php8.2-fpm recent journal ---'
  journalctl -u php8.2-fpm -n 100 --no-pager 2>/dev/null || true
  echo '--- origin health ---'
  curl -k -sS -D - --max-time 15 --resolve "${DOMAIN}:443:127.0.0.1" "https://${DOMAIN}/?health=1" -o /tmp/farmacia-origin-body || true
  head -c 1600 /tmp/farmacia-origin-body 2>/dev/null || true; echo
  echo '--- public health ---'
  curl -k -sS -D - --max-time 15 "https://${DOMAIN}/?health=1" -o /tmp/farmacia-public-body || true
  head -c 1600 /tmp/farmacia-public-body 2>/dev/null || true; echo
  echo '--- nginx recent errors ---'
  tail -n 160 /var/log/nginx/error.log 2>/dev/null || true
  local site_error
  site_error="$(awk '$1=="error_log" {gsub(/;/,"",$2); print $2; exit}' "$VHOST" 2>/dev/null || true)"
  if [[ -n "$site_error" && -f "$site_error" && "$site_error" != "/var/log/nginx/error.log" ]]; then
    echo "--- site error log ${site_error} ---"
    tail -n 160 "$site_error" 2>/dev/null || true
  fi
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
  repair-php-vhost)
    write_layout
    fix_permissions
    repair_php_vhost
    ;;
  refresh-ca)
    refresh_ca
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
    ensure_php_pool >/dev/null
    echo 'VPS_PHP82_RESTART=OK'
    ;;
  *) fail "unsupported command ${CMD}" ;;
esac
