#!/usr/bin/env bash
set -Eeuo pipefail

# Instalador dedicado do GitHub Actions Runner da Farmácia SuperAmplitude.
# Não interfere no runner do SMM.

REPO_URL="${FARMACIA_REPO_URL:-https://github.com/superamplitude/Farmacia}"
RUNNER_TOKEN="${FARMACIA_RUNNER_TOKEN:-${1:-}}"
RUNNER_NAME="${FARMACIA_RUNNER_NAME:-farmacia-production}"
RUNNER_USER="${FARMACIA_RUNNER_USER:-farmrunner}"
RUNNER_DIR="${FARMACIA_RUNNER_DIR:-/opt/actions-runner-farmacia}"
LABELS="${FARMACIA_RUNNER_LABELS:-farmacia,production}"
RUNNER_SERVICE="github-actions-farmacia"
STATE_DIR="/home/superamplitude/.farmacia"
PUBLIC_URL="https://farmacia.superamplitude.com"

log(){ printf '\n[%s] %s\n' "$(date +%H:%M:%S)" "$*"; }
fail(){ echo "ERRO: $*" >&2; exit 1; }

cleanup_secrets(){
  unset RUNNER_TOKEN FARMACIA_RUNNER_TOKEN R2_ACCESS_INPUT R2_SECRET_INPUT || true
}
trap cleanup_secrets EXIT

[[ $EUID -eq 0 ]] || fail "execute como root"

# Evita o erro getcwd quando o comando é disparado de um diretório que outro
# instalador acabou de remover/recriar.
cd /root

if [[ -z "$RUNNER_TOKEN" ]]; then
  printf 'Cole o token NOVO do GitHub Runner da Farmacia e pressione ENTER: '
  IFS= read -r -s RUNNER_TOKEN
  printf '\n'
fi
RUNNER_TOKEN="${RUNNER_TOKEN//$'\r'/}"
RUNNER_TOKEN="${RUNNER_TOKEN//$'\n'/}"
[[ -n "$RUNNER_TOKEN" ]] || fail "token do runner não informado"
[[ "$RUNNER_TOKEN" != *[[:space:]]* ]] || fail "token contém espaços; cole somente o token"

export DEBIAN_FRONTEND=noninteractive
log "Preparando dependências"
apt-get update -y >/dev/null
apt-get install -y curl jq tar gzip ca-certificates sudo git php-cli php-curl php-sqlite3 >/dev/null

id "$RUNNER_USER" >/dev/null 2>&1 || useradd --system --create-home --shell /bin/bash "$RUNNER_USER"

ARCH="$(uname -m)"
case "$ARCH" in
  x86_64|amd64) RUNNER_ARCH="x64" ;;
  aarch64|arm64) RUNNER_ARCH="arm64" ;;
  *) fail "arquitetura não suportada: $ARCH" ;;
esac

log "Parando instalação anterior da Farmacia, se existir"
systemctl stop "$RUNNER_SERVICE" 2>/dev/null || true
systemctl disable "$RUNNER_SERVICE" 2>/dev/null || true
rm -f "/etc/systemd/system/${RUNNER_SERVICE}.service"
systemctl daemon-reload
rm -rf "$RUNNER_DIR"
mkdir -p "$RUNNER_DIR"
chown "$RUNNER_USER:$RUNNER_USER" "$RUNNER_DIR"

log "Baixando versão atual do GitHub Actions Runner"
TAG="$(curl -fsSL https://api.github.com/repos/actions/runner/releases/latest | jq -r .tag_name)"
[[ -n "$TAG" && "$TAG" != "null" ]] || fail "não foi possível descobrir a versão do runner"
VERSION="${TAG#v}"
PKG="actions-runner-linux-${RUNNER_ARCH}-${VERSION}.tar.gz"
URL="https://github.com/actions/runner/releases/download/${TAG}/${PKG}"
curl -fL --retry 3 --retry-delay 2 "$URL" -o "/tmp/$PKG"
tar xzf "/tmp/$PKG" -C "$RUNNER_DIR"
rm -f "/tmp/$PKG"
chown -R "$RUNNER_USER:$RUNNER_USER" "$RUNNER_DIR"

[[ -x "$RUNNER_DIR/config.sh" ]] || fail "config.sh não foi instalado"
[[ -x "$RUNNER_DIR/run.sh" ]] || fail "run.sh não foi instalado"

log "Registrando runner exclusivo no repositório da Farmacia"
cd "$RUNNER_DIR"
sudo -u "$RUNNER_USER" ./config.sh \
  --url "$REPO_URL" \
  --token "$RUNNER_TOKEN" \
  --name "$RUNNER_NAME" \
  --labels "$LABELS" \
  --work "_work" \
  --unattended \
  --replace
unset RUNNER_TOKEN FARMACIA_RUNNER_TOKEN || true

log "Criando serviço systemd"
cat >"/etc/systemd/system/${RUNNER_SERVICE}.service" <<UNIT
[Unit]
Description=GitHub Actions Runner - Farmacia SuperAmplitude
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=$RUNNER_USER
WorkingDirectory=$RUNNER_DIR
ExecStart=$RUNNER_DIR/run.sh
Restart=always
RestartSec=5
KillSignal=SIGINT
TimeoutStopSec=120

[Install]
WantedBy=multi-user.target
UNIT

systemctl daemon-reload
systemctl enable --now "$RUNNER_SERVICE"
sleep 3
systemctl is-active --quiet "$RUNNER_SERVICE" || {
  journalctl -u "$RUNNER_SERVICE" -n 100 --no-pager || true
  fail "runner da Farmacia não iniciou"
}

log "Preparando estado privado da Farmacia"
mkdir -p "$STATE_DIR/uploads"
chmod 700 "$STATE_DIR" "$STATE_DIR/uploads" || true

# Se o .env já existir, apenas preserva. As credenciais nunca são gravadas no GitHub.
if [[ -f "$STATE_DIR/.env" ]]; then
  chmod 600 "$STATE_DIR/.env" || true
fi

log "Aguardando o workflow da Farmacia ser consumido pelo runner"
READY=0
HTTP_CODE="000"
for _ in $(seq 1 24); do
  HTTP_CODE="$(curl -L -k -sS -o /tmp/farmacia-public-health.out -w '%{http_code}' "${PUBLIC_URL}/?health=1" || true)"
  if [[ "$HTTP_CODE" == "200" ]]; then
    READY=1
    break
  fi
  sleep 5
done

printf '\n============================================================\n'
printf ' FARMACIA SUPERAMPLITUDE - RUNNER INSTALADO\n'
printf '============================================================\n'
printf 'RUNNER_VERSION=%s\n' "$VERSION"
printf 'RUNNER_NAME=%s\n' "$RUNNER_NAME"
printf 'RUNNER_DIR=%s\n' "$RUNNER_DIR"
printf 'RUNNER_SERVICE=%s\n' "$(systemctl is-active "$RUNNER_SERVICE")"
printf 'PUBLIC_HTTP=%s\n' "$HTTP_CODE"
if [[ "$READY" -eq 1 ]]; then
  printf 'PUBLIC_HEALTH=OK\n'
else
  printf 'PUBLIC_HEALTH=AGUARDANDO_DEPLOY_OU_ORIGIN\n'
fi
printf '\nRunners ativos:\n'
ps aux | grep -E 'Runner.Listener|Runner.Worker' | grep -v grep || true
printf '============================================================\n'
