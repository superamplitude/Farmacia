#!/usr/bin/env bash
set -Eeuo pipefail

DOMAIN="farmacia.superamplitude.com"
PUBLIC_URL="https://${DOMAIN}"
IMAGE_URL="https://img.farmacia.superamplitude.com"
APP_DIR="/home/superamplitude/htdocs/${DOMAIN}"
FAIL=0

code(){
  curl -L -k -sS -o "$2" -w '%{http_code}' "$1" 2>/dev/null || printf '000'
}
origin_code(){
  curl -L -k -sS --resolve "${DOMAIN}:443:127.0.0.1" -o "$2" -w '%{http_code}' "$1" 2>/dev/null || printf '000'
}

SELFTEST=0
if php "$APP_DIR/scripts/self_test.php" >/tmp/farmacia-self-test.json 2>/tmp/farmacia-self-test.err; then SELFTEST=1; fi
cat /tmp/farmacia-self-test.json 2>/dev/null || true
cat /tmp/farmacia-self-test.err 2>/dev/null || true
[[ "$SELFTEST" -eq 1 ]] || FAIL=1

PUBLIC_HTTP="000"
ORIGIN_HTTP="000"
ADMIN_ORIGIN_HTTP="000"
for _ in $(seq 1 12); do
  ORIGIN_HTTP="$(origin_code "${PUBLIC_URL}/?health=1" /tmp/farmacia-origin-health.out)"
  PUBLIC_HTTP="$(code "${PUBLIC_URL}/?health=1" /tmp/farmacia-public-health.out)"
  [[ "$ORIGIN_HTTP" == "200" && "$PUBLIC_HTTP" == "200" ]] && break
  sleep 5
done
ADMIN_ORIGIN_HTTP="$(origin_code "${PUBLIC_URL}/admin.php" /tmp/farmacia-origin-admin.out)"
IMAGE_HTTP="$(code "${IMAGE_URL}/" /tmp/farmacia-image-root.out)"

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

if [[ "$ORIGIN_HTTP" != "200" ]]; then
  echo 'VERIFY_FAIL=origin_health'
  printf '%s\n' '--- Origin response ---'
  head -c 1200 /tmp/farmacia-origin-health.out 2>/dev/null || true
  echo
  FAIL=1
fi
if [[ "$PUBLIC_HTTP" != "200" ]]; then
  echo 'VERIFY_FAIL=public_health'
  printf '%s\n' '--- Public response ---'
  head -c 1200 /tmp/farmacia-public-health.out 2>/dev/null || true
  echo
  FAIL=1
fi
if [[ "$ADMIN_ORIGIN_HTTP" != "200" ]]; then
  echo 'VERIFY_FAIL=admin_origin'
  FAIL=1
fi
if [[ "$IMAGE_HTTP" == "000" ]]; then
  echo 'VERIFY_FAIL=image_domain_unreachable'
  FAIL=1
fi

if [[ "$FAIL" -eq 0 ]]; then
  echo 'VERIFY_STATUS=OK'
  exit 0
fi

echo 'VERIFY_STATUS=FAIL'
exit 1
