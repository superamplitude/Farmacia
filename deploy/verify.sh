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

AUDIT=0
if [[ -f "$APP_DIR/scripts/production_audit.php" ]] && php "$APP_DIR/scripts/production_audit.php" >/tmp/farmacia-production-audit.json 2>/tmp/farmacia-production-audit.err; then AUDIT=1; fi
cat /tmp/farmacia-production-audit.json 2>/dev/null || true
cat /tmp/farmacia-production-audit.err 2>/dev/null || true
[[ "$AUDIT" -eq 1 ]] || { echo 'VERIFY_FAIL=production_audit'; FAIL=1; }

R2_STATE="unknown"
if [[ -f "$APP_DIR/scripts/r2_env_probe.php" ]]; then
  php "$APP_DIR/scripts/r2_env_probe.php" >/tmp/farmacia-r2-probe.out 2>/dev/null || true
  cat /tmp/farmacia-r2-probe.out || true
  R2_STATE="$(awk -F= '/^R2_CREDENTIAL_STATE=/{print $2}' /tmp/farmacia-r2-probe.out | tail -n1)"
fi

PUBLIC_HTTP=000
ORIGIN_HTTP=000
for _ in $(seq 1 12); do
  ORIGIN_HTTP="$(origin_code "${PUBLIC_URL}/?health=1" /tmp/farmacia-origin-health.out)"
  PUBLIC_HTTP="$(code "${PUBLIC_URL}/?health=1" /tmp/farmacia-public-health.out)"
  [[ "$ORIGIN_HTTP" == 200 && "$PUBLIC_HTTP" == 200 ]] && break
  sleep 5
done
ADMIN_ORIGIN_HTTP="$(origin_code "${PUBLIC_URL}/admin.php" /tmp/farmacia-origin-admin.out)"
SUPERADMIN_ORIGIN_HTTP="$(origin_code "${PUBLIC_URL}/superadmin.php" /tmp/farmacia-origin-superadmin.out)"
STOREADMIN_ORIGIN_HTTP="$(origin_code "${PUBLIC_URL}/farmacia-admin.php" /tmp/farmacia-origin-storeadmin.out)"
PANEL_ORIGIN_HTTP="$(origin_code "${PUBLIC_URL}/painel.php" /tmp/farmacia-origin-panel.out)"
CHAT_JS_ORIGIN_HTTP="$(origin_code "${PUBLIC_URL}/assets/chat.js" /tmp/farmacia-origin-chat.js)"
WEBHOOK_ORIGIN_HTTP="$(origin_code "${PUBLIC_URL}/api/payment_webhook.php?provider=mercadopago" /tmp/farmacia-origin-webhook.out)"
TRACKING_ORIGIN_HTTP="$(origin_code "${PUBLIC_URL}/pedido.php?t=invalid" /tmp/farmacia-origin-tracking.out)"
CATALOG_ORIGIN_HTTP="$(origin_code "${PUBLIC_URL}/" /tmp/farmacia-origin-catalog.out)"
CATEGORIES_ORIGIN_HTTP="$(origin_code "${PUBLIC_URL}/api/categories.php" /tmp/farmacia-origin-categories.out)"
IMAGE_HTTP="$(code "${IMAGE_URL}/" /tmp/farmacia-image-root.out)"

PRODUCT_CARD_COUNT="$(grep -o '<article class="card">' /tmp/farmacia-origin-catalog.out 2>/dev/null | wc -l | tr -d ' ' || true)"
CATEGORY_COUNT="$(php -r '$j=json_decode(file_get_contents("/tmp/farmacia-origin-categories.out"),true); echo is_array($j["categories"]??null)?count($j["categories"]):0;' 2>/dev/null || echo 0)"
CATALOG_TOTAL="$(php -r '$j=json_decode(file_get_contents("/tmp/farmacia-origin-categories.out"),true); echo (int)($j["total"]??0);' 2>/dev/null || echo 0)"
STORE_PRODUCT_TOTAL="$(php -r 'require $argv[1]."/src/bootstrap.php"; $p=Pharmacy::ensureDefault($db); $s=$db->prepare("SELECT COUNT(*) FROM pharmacy_products WHERE pharmacy_id=?");$s->execute([(int)$p["id"]]);echo (int)$s->fetchColumn();' "$APP_DIR" 2>/dev/null || echo 0)"
STORE_ADMIN_TOTAL="$(php -r 'require $argv[1]."/src/bootstrap.php"; $p=Pharmacy::ensureDefault($db); $s=$db->prepare("SELECT COUNT(*) FROM users WHERE pharmacy_id=? AND role=\"pharmacy_admin\" AND active=1");$s->execute([(int)$p["id"]]);echo (int)$s->fetchColumn();' "$APP_DIR" 2>/dev/null || echo 0)"
CHAT_CLOSE_COUNT="$(grep -c 'setOpen(false)' /tmp/farmacia-origin-chat.js 2>/dev/null || true)"

echo "VERIFY_SITE_USER=${APP_USER}"
echo "VERIFY_APP_DIR=${APP_DIR}"
echo "VERIFY_STATE_DIR=${STATE_DIR}"
echo "VERIFY_ORIGIN_HTTP=${ORIGIN_HTTP}"
echo "VERIFY_PUBLIC_HTTP=${PUBLIC_HTTP}"
echo "VERIFY_ADMIN_ORIGIN_HTTP=${ADMIN_ORIGIN_HTTP}"
echo "VERIFY_SUPERADMIN_ORIGIN_HTTP=${SUPERADMIN_ORIGIN_HTTP}"
echo "VERIFY_STOREADMIN_ORIGIN_HTTP=${STOREADMIN_ORIGIN_HTTP}"
echo "VERIFY_PANEL_ORIGIN_HTTP=${PANEL_ORIGIN_HTTP}"
echo "VERIFY_CHAT_JS_ORIGIN_HTTP=${CHAT_JS_ORIGIN_HTTP}"
echo "VERIFY_CHAT_CLOSE_COUNT=${CHAT_CLOSE_COUNT:-0}"
echo "VERIFY_PAYMENT_WEBHOOK_ORIGIN_HTTP=${WEBHOOK_ORIGIN_HTTP}"
echo "VERIFY_ORDER_TRACKING_INVALID_HTTP=${TRACKING_ORIGIN_HTTP}"
echo "VERIFY_CATALOG_ORIGIN_HTTP=${CATALOG_ORIGIN_HTTP}"
echo "VERIFY_CATEGORIES_ORIGIN_HTTP=${CATEGORIES_ORIGIN_HTTP}"
echo "VERIFY_PRODUCT_CARD_COUNT=${PRODUCT_CARD_COUNT:-0}"
echo "VERIFY_STORE_PRODUCT_TOTAL=${STORE_PRODUCT_TOTAL:-0}"
echo "VERIFY_STORE_ADMIN_TOTAL=${STORE_ADMIN_TOTAL:-0}"
echo "VERIFY_CATEGORY_COUNT=${CATEGORY_COUNT:-0}"
echo "VERIFY_CATALOG_TOTAL=${CATALOG_TOTAL:-0}"
echo "VERIFY_R2_CREDENTIAL_STATE=${R2_STATE:-unknown}"
echo "VERIFY_IMAGE_HTTP=${IMAGE_HTTP}"
echo "VERIFY_NGINX=$(systemctl is-active nginx 2>/dev/null || true)"
echo "VERIFY_RUNNER=$(systemctl is-active github-actions-farmacia 2>/dev/null || true)"

printf '%s\n' '--- PHP/FPM services ---'
systemctl list-units --type=service --state=running 2>/dev/null | grep -Ei 'php.*fpm|nginx' || true
printf '%s\n' '--- Domain vhost references ---'
grep -RIl "$DOMAIN" /etc/nginx/sites-enabled /etc/nginx/sites-available 2>/dev/null | head -20 || true

[[ -d "$APP_DIR/.git" ]] || { echo 'VERIFY_FAIL=production_git_missing'; FAIL=1; }
[[ -f "$STATE_DIR/.env" ]] || { echo 'VERIFY_FAIL=private_env_missing'; FAIL=1; }

if [[ "$ORIGIN_HTTP" != 200 ]]; then echo 'VERIFY_FAIL=origin_health'; FAIL=1; fi
if [[ "$PUBLIC_HTTP" != 200 ]]; then echo 'VERIFY_FAIL=public_health'; FAIL=1; fi
if [[ "$ADMIN_ORIGIN_HTTP" != 200 ]]; then echo 'VERIFY_FAIL=admin_origin'; FAIL=1; fi
if [[ "$SUPERADMIN_ORIGIN_HTTP" != 200 ]]; then echo 'VERIFY_FAIL=superadmin_origin'; FAIL=1; fi
if [[ "$STOREADMIN_ORIGIN_HTTP" != 200 ]]; then echo 'VERIFY_FAIL=storeadmin_origin'; FAIL=1; fi
if [[ "$PANEL_ORIGIN_HTTP" != 200 ]]; then echo 'VERIFY_FAIL=panel_router_origin'; FAIL=1; fi
if [[ "$CHAT_JS_ORIGIN_HTTP" != 200 || "${CHAT_CLOSE_COUNT:-0}" -lt 1 ]]; then echo 'VERIFY_FAIL=chat_close_contract'; FAIL=1; fi
if [[ "$WEBHOOK_ORIGIN_HTTP" != 200 ]]; then echo 'VERIFY_FAIL=payment_webhook_origin'; FAIL=1; fi
if [[ "$TRACKING_ORIGIN_HTTP" != 404 ]]; then echo 'VERIFY_FAIL=tracking_invalid_token_contract'; FAIL=1; fi
if [[ "$CATALOG_ORIGIN_HTTP" != 200 ]]; then echo 'VERIFY_FAIL=catalog_origin'; FAIL=1; fi
if [[ "$CATEGORIES_ORIGIN_HTTP" != 200 ]]; then echo 'VERIFY_FAIL=categories_origin'; FAIL=1; fi
if [[ "${PRODUCT_CARD_COUNT:-0}" -lt 1 ]]; then echo 'VERIFY_FAIL=products_not_rendered'; FAIL=1; fi
if [[ "${STORE_PRODUCT_TOTAL:-0}" -lt "${CATALOG_TOTAL:-0}" ]]; then echo 'VERIFY_FAIL=store_catalog_not_materialized'; FAIL=1; fi
if [[ "${STORE_ADMIN_TOTAL:-0}" -lt 1 ]]; then echo 'VERIFY_FAIL=store_admin_missing'; FAIL=1; fi
if [[ "${CATEGORY_COUNT:-0}" -lt 1 ]]; then echo 'VERIFY_FAIL=categories_empty'; FAIL=1; fi
if [[ "${CATALOG_TOTAL:-0}" -lt 1 ]]; then echo 'VERIFY_FAIL=catalog_total_empty'; FAIL=1; fi
if [[ "$R2_STATE" == "invalid_or_placeholder" ]]; then echo 'VERIFY_FAIL=r2_placeholder_credentials'; FAIL=1; fi
if [[ "$R2_STATE" == "missing" ]]; then echo 'VERIFY_EXTERNAL_BLOCKER_R2_WRITE=missing_credentials'; fi
if [[ "$IMAGE_HTTP" == 000 ]]; then echo 'VERIFY_FAIL=image_domain_unreachable'; FAIL=1; fi

if [[ "$FAIL" -eq 0 ]]; then echo 'VERIFY_STATUS=OK'; exit 0; fi
echo 'VERIFY_STATUS=FAIL'
exit 1
