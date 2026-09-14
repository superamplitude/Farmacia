#!/usr/bin/env bash
set -Eeuo pipefail

DOMAIN="farmacia.superamplitude.com"
PUBLIC_URL="https://${DOMAIN}"
IMAGE_URL="https://img.farmacia.superamplitude.com"
FAIL=0

# shellcheck disable=SC1091
source "$(dirname "$0")/layout.sh"
if ! farmacia_layout_load optional; then
  echo 'VERIFY_FAIL=cloudpanel_layout_undetected'
  APP_USER="unknown"
  APP_DIR="/nonexistent"
  STATE_DIR="/nonexistent"
  FAIL=1
fi

code(){ local c; c="$(curl -L -k -sS -o "$2" -w '%{http_code}' "$1" 2>/dev/null || true)"; printf '%s' "${c:-000}"; }
origin_code(){ local c; c="$(curl -L -k -sS --resolve "${DOMAIN}:443:127.0.0.1" -o "$2" -w '%{http_code}' "$1" 2>/dev/null || true)"; printf '%s' "${c:-000}"; }

SELFTEST=0
if [[ -f "$APP_DIR/scripts/self_test.php" ]] && php "$APP_DIR/scripts/self_test.php" >/tmp/farmacia-self-test.json 2>/tmp/farmacia-self-test.err; then SELFTEST=1; fi
cat /tmp/farmacia-self-test.json 2>/dev/null || true
cat /tmp/farmacia-self-test.err 2>/dev/null || true
[[ "$SELFTEST" -eq 1 ]] || { echo 'VERIFY_FAIL=self_test'; FAIL=1; }

PUBLIC_HTTP=000
ORIGIN_HTTP=000
ADMIN_ORIGIN_HTTP=000
for _ in $(seq 1 12); do
  ORIGIN_HTTP="$(origin_code "${PUBLIC_URL}/?health=1" /tmp/farmacia-origin-health.out)"
  PUBLIC_HTTP="$(code "${PUBLIC_URL}/?health=1" /tmp/farmacia-public-health.out)"
  [[ "$ORIGIN_HTTP" == 200 && "$PUBLIC_HTTP" == 200 ]] && break
  sleep 5
done
ADMIN_ORIGIN_HTTP="$(origin_code "${PUBLIC_URL}/admin.php" /tmp/farmacia-origin-admin.out)"
IMAGE_HTTP="$(code "${IMAGE_URL}/" /tmp/farmacia-image-root.out)"

echo "VERIFY_SITE_USER=${APP_USER}"
echo "VERIFY_APP_DIR=${APP_DIR}"
echo "VERIFY_STATE_DIR=${STATE_DIR}"
echo "VERIFY_ORIGIN_HTTP=${ORIGIN_HTTP}"
echo "VERIFY_PUBLIC_HTTP=${PUBLIC_HTTP}"
echo "VERIFY_ADMIN_ORIGIN_HTTP=${ADMIN_ORIGIN_HTTP}"
echo "VERIFY_IMAGE_HTTP=${IMAGE_HTTP}"
echo "VERIFY_NGINX=$(systemctl is-active nginx 2>/dev/null || true)"
echo "VERIFY_RUNNER=$(systemctl is-active github-actions-farmacia 2>/dev/null || true)"

printf '%s\n' '--- PHP/FPM services ---'
systemctl list-units --type=service --state=running 2>/dev/null | grep -Ei 'php.*fpm|nginx' || true
printf '%s\n' '--- Domain vhost references ---'
grep -RIl "$DOMAIN" /etc/nginx/sites-enabled /etc/nginx/sites-available 2>/dev/null | head -20 || true

[[ -d "$APP_DIR/.git" ]] || { echo 'VERIFY_FAIL=production_git_missing'; FAIL=1; }
[[ -f "$STATE_DIR/.env" ]] || { echo 'VERIFY_FAIL=private_env_missing'; FAIL=1; }

if [[ "$ORIGIN_HTTP" != 200 ]]; then
  echo 'VERIFY_FAIL=origin_health'
  head -c 1200 /tmp/farmacia-origin-health.out 2>/dev/null || true; echo
  FAIL=1
fi
if [[ "$PUBLIC_HTTP" != 200 ]]; then
  echo 'VERIFY_FAIL=public_health'
  head -c 1200 /tmp/farmacia-public-health.out 2>/dev/null || true; echo
  FAIL=1
fi
if [[ "$ADMIN_ORIGIN_HTTP" != 200 ]]; then echo 'VERIFY_FAIL=admin_origin'; FAIL=1; fi
if [[ "$IMAGE_HTTP" == 000 ]]; then echo 'VERIFY_FAIL=image_domain_unreachable'; FAIL=1; fi

if [[ "$FAIL" -eq 0 ]]; then echo 'VERIFY_STATUS=OK'; exit 0; fi
echo 'VERIFY_STATUS=FAIL'
exit 1
