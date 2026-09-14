#!/usr/bin/env bash
set -Eeuo pipefail

APP_USER="superamplitude"
RUNNER_USER="farmrunner"
APP_DIR="/home/superamplitude/htdocs/farmacia.superamplitude.com"
STATE_DIR="/home/superamplitude/.farmacia"
RUNNER_SERVICE="github-actions-farmacia"
REPO="https://github.com/superamplitude/Farmacia.git"
PUBLIC_URL="https://farmacia.superamplitude.com"

log(){ printf '\n[%s] %s\n' "$(date +%H:%M:%S)" "$*"; }
fail(){ echo "ERRO: $*" >&2; exit 1; }

[[ $EUID -eq 0 ]] || fail "execute como root"
id "$APP_USER" >/dev/null 2>&1 || fail "usuario $APP_USER nao existe"
id "$RUNNER_USER" >/dev/null 2>&1 || fail "usuario $RUNNER_USER nao existe"
cd /root
export DEBIAN_FRONTEND=noninteractive

log "Instalando dependencias de deploy"
apt-get update -y >/dev/null
apt-get install -y acl curl git php-cli php-curl php-sqlite3 sudo >/dev/null

log "Criando diretorios da Farmacia"
mkdir -p "$APP_DIR" "$STATE_DIR/uploads"
chown "$APP_USER:$APP_USER" "$APP_DIR" "$STATE_DIR" "$STATE_DIR/uploads"
chmod 750 /home/superamplitude || true
chmod 750 /home/superamplitude/htdocs || true
chmod 2770 "$APP_DIR" "$STATE_DIR" "$STATE_DIR/uploads"

log "Liberando acesso somente ao runner da Farmacia"
setfacl -m "u:${RUNNER_USER}:--x" /home/superamplitude
setfacl -m "u:${RUNNER_USER}:r-x" /home/superamplitude/htdocs
setfacl -R -m "u:${RUNNER_USER}:rwX,u:${APP_USER}:rwX" "$APP_DIR" "$STATE_DIR"
find "$APP_DIR" "$STATE_DIR" -type d -exec setfacl -m "d:u:${RUNNER_USER}:rwx,d:u:${APP_USER}:rwx" {} +

log "Validando escrita do runner"
sudo -u "$RUNNER_USER" touch "$APP_DIR/.farmrunner-write-test" "$STATE_DIR/.farmrunner-write-test"
rm -f "$APP_DIR/.farmrunner-write-test" "$STATE_DIR/.farmrunner-write-test"

log "Sincronizando codigo atual da Farmacia"
if [[ ! -d "$APP_DIR/.git" ]]; then
  find "$APP_DIR" -mindepth 1 -maxdepth 1 -exec rm -rf {} +
  sudo -u "$RUNNER_USER" git clone "$REPO" "$APP_DIR"
else
  sudo -u "$RUNNER_USER" git -C "$APP_DIR" fetch origin main
  sudo -u "$RUNNER_USER" git -C "$APP_DIR" reset --hard origin/main
  sudo -u "$RUNNER_USER" git -C "$APP_DIR" clean -fd
fi

log "Preparando .env privado sem sobrescrever segredos existentes"
if [[ ! -f "$STATE_DIR/.env" ]]; then
  cp "$APP_DIR/.env.example" "$STATE_DIR/.env"
fi
chown "$APP_USER:$APP_USER" "$STATE_DIR/.env"
chmod 640 "$STATE_DIR/.env"
setfacl -m "u:${RUNNER_USER}:rw,u:${APP_USER}:rw" "$STATE_DIR/.env"

log "Executando deploy completo"
sudo -u "$RUNNER_USER" bash "$APP_DIR/deploy/bootstrap.sh"

log "Reiniciando runner da Farmacia"
systemctl restart "$RUNNER_SERVICE"
sleep 3
systemctl is-active --quiet "$RUNNER_SERVICE" || {
  journalctl -u "$RUNNER_SERVICE" -n 80 --no-pager || true
  fail "runner da Farmacia nao iniciou"
}

HTTP_CODE="$(curl -L -k -sS -o /tmp/farmacia-final-health.out -w '%{http_code}' "${PUBLIC_URL}/?health=1" || true)"

printf '\n============================================================\n'
printf ' FARMACIA SUPERAMPLITUDE - REPARO E DEPLOY FINALIZADOS\n'
printf '============================================================\n'
printf 'APP_DIR=%s\n' "$APP_DIR"
printf 'STATE_DIR=%s\n' "$STATE_DIR"
printf 'RUNNER_SERVICE=%s\n' "$(systemctl is-active "$RUNNER_SERVICE")"
printf 'RUNNER_WRITE=OK\n'
printf 'PUBLIC_HTTP=%s\n' "$HTTP_CODE"
if [[ "$HTTP_CODE" == "200" ]]; then
  printf 'PUBLIC_HEALTH=OK\n'
else
  printf 'PUBLIC_HEALTH=ORIGIN_AINDA_REQUER_DIAGNOSTICO\n'
fi
printf 'STATUS=DEPLOY_EXECUTED\n'
printf '============================================================\n'
