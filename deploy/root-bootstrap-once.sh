#!/usr/bin/env bash
set -Eeuo pipefail

APP_USER="superamplitude"
RUNNER_USER="farmrunner"
APP_DIR="/home/superamplitude/htdocs/farmacia.superamplitude.com"
STATE_DIR="/home/superamplitude/.farmacia"
REPO="https://github.com/superamplitude/Farmacia.git"
RUNNER_SERVICE="github-actions-farmacia"
TS="$(date +%Y%m%d-%H%M%S)"
BACKUP_DIR="${STATE_DIR}/backups/${TS}"
BACKUP_READY=0

log(){ printf '\n[%s] %s\n' "$(date +%H:%M:%S)" "$*"; }
fail(){ echo "ROOT_BOOTSTRAP_FAIL $*" >&2; exit 1; }
on_error(){
  rc=$?
  echo "ROOT_BOOTSTRAP_ERROR exit=${rc}"
  if [[ "$BACKUP_READY" -eq 1 ]]; then
    echo "ROLLBACK_READY=sudo bash ${APP_DIR}/deploy/rollback-from-backup.sh ${BACKUP_DIR}"
  fi
  exit "$rc"
}
trap on_error ERR

[[ $EUID -eq 0 ]] || fail 'execute como root'
id "$APP_USER" >/dev/null 2>&1 || fail "usuário $APP_USER não existe"
id "$RUNNER_USER" >/dev/null 2>&1 || fail "usuário $RUNNER_USER não existe; o runner precisa estar instalado"
cd /root
export DEBIAN_FRONTEND=noninteractive

log "Instalando dependências obrigatórias"
apt-get update -y >/dev/null
apt-get install -y acl ca-certificates curl git jq sudo sqlite3 php-cli php-curl php-sqlite3 php-mbstring >/dev/null

log "Criando backup antes de qualquer alteração"
mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"
if [[ -f "$STATE_DIR/.env" ]]; then cp -a "$STATE_DIR/.env" "$BACKUP_DIR/.env"; fi
if [[ -f "$STATE_DIR/farmacia.sqlite" ]]; then
  if sqlite3 "$STATE_DIR/farmacia.sqlite" ".backup '$BACKUP_DIR/farmacia.sqlite'"; then :; else cp -a "$STATE_DIR/farmacia.sqlite" "$BACKUP_DIR/farmacia.sqlite"; fi
fi
if [[ -d "$APP_DIR/.git" ]]; then
  tar -czf "$BACKUP_DIR/app.tar.gz" -C "$APP_DIR" .
fi
BACKUP_READY=1
echo "BACKUP_DIR=${BACKUP_DIR}"

log "Instalando helper root restrito"
curl -fsSL https://raw.githubusercontent.com/superamplitude/Farmacia/main/deploy/fix-permissions-root.sh -o /usr/local/sbin/farmacia-fix-permissions.new
chown root:root /usr/local/sbin/farmacia-fix-permissions.new
chmod 0755 /usr/local/sbin/farmacia-fix-permissions.new
mv /usr/local/sbin/farmacia-fix-permissions.new /usr/local/sbin/farmacia-fix-permissions
printf '%s\n' 'farmrunner ALL=(root) NOPASSWD: /usr/local/sbin/farmacia-fix-permissions' > /etc/sudoers.d/farmacia-runner-permissions
chmod 0440 /etc/sudoers.d/farmacia-runner-permissions
visudo -cf /etc/sudoers.d/farmacia-runner-permissions >/dev/null

mkdir -p "$APP_DIR" "$STATE_DIR/uploads" "$STATE_DIR/backups"
/usr/local/sbin/farmacia-fix-permissions

log "Inicializando ou sincronizando repositório de produção"
if [[ ! -d "$APP_DIR/.git" ]]; then
  if find "$APP_DIR" -mindepth 1 -maxdepth 1 -print -quit | grep -q .; then
    mv "$APP_DIR" "$BACKUP_DIR/app-original"
    mkdir -p "$APP_DIR"
    /usr/local/sbin/farmacia-fix-permissions
  fi
  sudo -u "$RUNNER_USER" git clone "$REPO" "$APP_DIR"
else
  sudo -u "$RUNNER_USER" git -C "$APP_DIR" fetch origin main
  sudo -u "$RUNNER_USER" git -C "$APP_DIR" reset --hard origin/main
  sudo -u "$RUNNER_USER" git -C "$APP_DIR" clean -fd
fi
/usr/local/sbin/farmacia-fix-permissions

log "Preparando .env privado sem sobrescrever segredos"
if [[ ! -f "$STATE_DIR/.env" ]]; then
  cp "$APP_DIR/.env.example" "$STATE_DIR/.env"
fi
/usr/local/sbin/farmacia-fix-permissions

log "Executando deploy integral"
sudo -u "$RUNNER_USER" bash "$APP_DIR/deploy/bootstrap-v2.sh"
/usr/local/sbin/farmacia-fix-permissions

log "Executando verificação end-to-end"
sudo -u "$RUNNER_USER" bash "$APP_DIR/deploy/verify.sh"

log "Reiniciando e validando runner"
systemctl restart "$RUNNER_SERVICE"
sleep 3
systemctl is-active --quiet "$RUNNER_SERVICE" || fail 'runner da Farmácia não ficou ativo'

printf '\n============================================================\n'
printf ' FARMACIA SUPERAMPLITUDE - BOOTSTRAP CONCLUÍDO\n'
printf '============================================================\n'
printf 'BACKUP_DIR=%s\n' "$BACKUP_DIR"
printf 'RUNNER_SERVICE=%s\n' "$(systemctl is-active "$RUNNER_SERVICE")"
printf 'ROLLBACK_READY=sudo bash %s/deploy/rollback-from-backup.sh %s\n' "$APP_DIR" "$BACKUP_DIR"
printf 'STATUS=VERIFIED\n'
printf '============================================================\n'
