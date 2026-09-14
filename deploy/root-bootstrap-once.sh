#!/usr/bin/env bash
set -Eeuo pipefail
umask 0077

DOMAIN="farmacia.superamplitude.com"
APP_USER="farmacia"
RUNNER_USER="farmrunner"
APP_HOME="/home/${APP_USER}"
APP_DIR="${APP_HOME}/htdocs/${DOMAIN}"
STATE_DIR="${APP_HOME}/.farmacia"
LEGACY_APP_DIR="/home/superamplitude/htdocs/${DOMAIN}"
LEGACY_STATE_DIR="/home/superamplitude/.farmacia"
REPO="https://github.com/superamplitude/Farmacia.git"
RUNNER_SERVICE="github-actions-farmacia"
TS="$(date +%Y%m%d-%H%M%S)"
BACKUP_DIR="/root/farmacia-backups/${TS}"
BACKUP_READY=0

log(){ printf '\n[%s] %s\n' "$(date +%H:%M:%S)" "$*"; }
fail(){ echo "ROOT_BOOTSTRAP_FAIL $*" >&2; exit 1; }
on_error(){
  rc=$?
  echo "ROOT_BOOTSTRAP_ERROR exit=${rc}"
  if [[ "$BACKUP_READY" -eq 1 ]]; then echo "ROLLBACK_READY=bash /root/farmacia-rollback.sh ${BACKUP_DIR}"; fi
  exit "$rc"
}
trap on_error ERR

[[ $EUID -eq 0 ]] || fail 'execute como root'
id "$RUNNER_USER" >/dev/null 2>&1 || fail "usuário $RUNNER_USER não existe; o runner precisa estar instalado"
cd /root
export DEBIAN_FRONTEND=noninteractive

log "Instalando dependências obrigatórias"
apt-get update -y >/dev/null
apt-get install -y acl ca-certificates curl git jq openssl sudo sqlite3 tar gzip php-cli php-curl php-sqlite3 php-mbstring >/dev/null

log "Criando backup pré-mudança"
mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"
if [[ -f "$LEGACY_STATE_DIR/.env" ]]; then cp -a "$LEGACY_STATE_DIR/.env" "$BACKUP_DIR/legacy.env"; fi
if [[ -f "$LEGACY_STATE_DIR/farmacia.sqlite" ]]; then sqlite3 "$LEGACY_STATE_DIR/farmacia.sqlite" ".backup '$BACKUP_DIR/legacy-farmacia.sqlite'" || cp -a "$LEGACY_STATE_DIR/farmacia.sqlite" "$BACKUP_DIR/legacy-farmacia.sqlite"; fi
if [[ -d "$LEGACY_APP_DIR" ]]; then tar -czf "$BACKUP_DIR/legacy-app.tar.gz" -C "$LEGACY_APP_DIR" . || true; fi
if [[ -f "$STATE_DIR/.env" ]]; then cp -a "$STATE_DIR/.env" "$BACKUP_DIR/.env"; fi
if [[ -f "$STATE_DIR/farmacia.sqlite" ]]; then sqlite3 "$STATE_DIR/farmacia.sqlite" ".backup '$BACKUP_DIR/farmacia.sqlite'" || cp -a "$STATE_DIR/farmacia.sqlite" "$BACKUP_DIR/farmacia.sqlite"; fi
BACKUP_READY=1
echo "BACKUP_DIR=${BACKUP_DIR}"

log "Provisionando site PHP isolado no CloudPanel"
curl -fsSL https://raw.githubusercontent.com/superamplitude/Farmacia/main/deploy/provision-cloudpanel.sh -o /root/farmacia-provision-cloudpanel.sh
chmod 0700 /root/farmacia-provision-cloudpanel.sh
FARMACIA_PROVISION_MARKER="$BACKUP_DIR/cloudpanel-site-created" bash /root/farmacia-provision-cloudpanel.sh
id "$APP_USER" >/dev/null 2>&1 || fail "CloudPanel não criou o usuário isolado $APP_USER"
[[ -d "$APP_DIR" ]] || fail "CloudPanel não criou o document root $APP_DIR"

log "Instalando helper root restrito"
curl -fsSL https://raw.githubusercontent.com/superamplitude/Farmacia/main/deploy/fix-permissions-root.sh -o /usr/local/sbin/farmacia-fix-permissions.new
chown root:root /usr/local/sbin/farmacia-fix-permissions.new
chmod 0755 /usr/local/sbin/farmacia-fix-permissions.new
mv /usr/local/sbin/farmacia-fix-permissions.new /usr/local/sbin/farmacia-fix-permissions
printf '%s\n' 'farmrunner ALL=(root) NOPASSWD: /usr/local/sbin/farmacia-fix-permissions' > /etc/sudoers.d/farmacia-runner-permissions
chmod 0440 /etc/sudoers.d/farmacia-runner-permissions
visudo -cf /etc/sudoers.d/farmacia-runner-permissions >/dev/null
mkdir -p "$STATE_DIR/uploads" "$STATE_DIR/backups"
chown -R "$APP_USER:$APP_USER" "$STATE_DIR"
/usr/local/sbin/farmacia-fix-permissions

log "Migrando estado legado somente se existir e o novo estado estiver vazio"
if [[ ! -f "$STATE_DIR/.env" && -f "$LEGACY_STATE_DIR/.env" ]]; then cp -a "$LEGACY_STATE_DIR/.env" "$STATE_DIR/.env"; fi
if [[ ! -f "$STATE_DIR/farmacia.sqlite" && -f "$LEGACY_STATE_DIR/farmacia.sqlite" ]]; then cp -a "$LEGACY_STATE_DIR/farmacia.sqlite" "$STATE_DIR/farmacia.sqlite"; fi
if [[ -d "$LEGACY_STATE_DIR/uploads" ]]; then cp -an "$LEGACY_STATE_DIR/uploads/." "$STATE_DIR/uploads/" 2>/dev/null || true; fi
/usr/local/sbin/farmacia-fix-permissions

log "Inicializando ou sincronizando repositório de produção"
if [[ ! -d "$APP_DIR/.git" ]]; then
  find "$APP_DIR" -mindepth 1 -maxdepth 1 -exec rm -rf {} +
  sudo -u "$RUNNER_USER" git clone "$REPO" "$APP_DIR"
else
  sudo -u "$RUNNER_USER" git -C "$APP_DIR" fetch origin main
  sudo -u "$RUNNER_USER" git -C "$APP_DIR" reset --hard origin/main
  sudo -u "$RUNNER_USER" git -C "$APP_DIR" clean -fd
fi
/usr/local/sbin/farmacia-fix-permissions

log "Preparando .env privado sem sobrescrever segredos existentes"
if [[ ! -f "$STATE_DIR/.env" ]]; then cp "$APP_DIR/.env.example" "$STATE_DIR/.env"; fi
set_env_private(){
  local key="$1" value="$2"
  if grep -q "^${key}=" "$STATE_DIR/.env"; then sed -i "s#^${key}=.*#${key}=${value}#" "$STATE_DIR/.env"; else printf '%s=%s\n' "$key" "$value" >> "$STATE_DIR/.env"; fi
}
get_env_private(){ grep "^${1}=" "$STATE_DIR/.env" 2>/dev/null | tail -n1 | cut -d= -f2- || true; }
set_env_private PRIVATE_STATE_DIR "$STATE_DIR"
set_env_private DB_DSN "sqlite:${STATE_DIR}/farmacia.sqlite"

if [[ -z "$(get_env_private APP_KEY)" ]]; then set_env_private APP_KEY "$(openssl rand -hex 32)"; fi
if [[ -z "$(get_env_private SUPERADMIN_EMAIL)" ]]; then set_env_private SUPERADMIN_EMAIL 'admin@superamplitude.com'; fi
if [[ -z "$(get_env_private SUPERADMIN_PASSWORD)" ]]; then
  ADMIN_PASSWORD="F4rmA!$(openssl rand -hex 14)"
  set_env_private SUPERADMIN_PASSWORD "$ADMIN_PASSWORD"
  {
    printf 'SUPERADMIN_EMAIL=%q\n' "$(get_env_private SUPERADMIN_EMAIL)"
    printf 'SUPERADMIN_PASSWORD=%q\n' "$ADMIN_PASSWORD"
    printf 'CREATED_AT=%q\n' "$(date -Is)"
  } > /root/.farmacia-superadmin
  chmod 600 /root/.farmacia-superadmin
  unset ADMIN_PASSWORD
  echo 'SUPERADMIN_CREDENTIALS=/root/.farmacia-superadmin'
fi
/usr/local/sbin/farmacia-fix-permissions

log "Instalando rollback root local"
cp "$APP_DIR/deploy/rollback-from-backup.sh" /root/farmacia-rollback.sh
chown root:root /root/farmacia-rollback.sh
chmod 0700 /root/farmacia-rollback.sh

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
printf 'CLOUDPANEL_SITE_USER=%s\n' "$APP_USER"
printf 'APP_DIR=%s\n' "$APP_DIR"
printf 'STATE_DIR=%s\n' "$STATE_DIR"
printf 'BACKUP_DIR=%s\n' "$BACKUP_DIR"
printf 'RUNNER_SERVICE=%s\n' "$(systemctl is-active "$RUNNER_SERVICE")"
printf 'ROLLBACK_READY=bash /root/farmacia-rollback.sh %s\n' "$BACKUP_DIR"
printf 'STATUS=VERIFIED\n'
printf '============================================================\n'
