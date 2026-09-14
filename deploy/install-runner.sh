#!/usr/bin/env bash
set -Eeuo pipefail

REPO_URL="${FARMACIA_REPO_URL:-https://github.com/superamplitude/Farmacia}"
RUNNER_TOKEN="${FARMACIA_RUNNER_TOKEN:-${1:-}}"
RUNNER_NAME="${FARMACIA_RUNNER_NAME:-farmacia-production}"
RUNNER_USER="${FARMACIA_RUNNER_USER:-farmrunner}"
RUNNER_DIR="${FARMACIA_RUNNER_DIR:-/opt/actions-runner-farmacia}"
LABELS="${FARMACIA_RUNNER_LABELS:-farmacia,production}"
RUNNER_SERVICE="github-actions-farmacia"
LAYOUT_FILE="/etc/farmacia-superamplitude/layout.env"
PUBLIC_URL="https://farmacia.superamplitude.com"

log(){ printf '\n[%s] %s\n' "$(date +%H:%M:%S)" "$*"; }
fail(){ echo "ERRO: $*" >&2; exit 1; }
cleanup(){ unset RUNNER_TOKEN FARMACIA_RUNNER_TOKEN || true; }
trap cleanup EXIT

[[ $EUID -eq 0 ]] || fail 'execute como root'
cd /root

if [[ -z "$RUNNER_TOKEN" ]]; then
  printf 'Cole o token do GitHub Runner da Farmacia e pressione ENTER: '
  IFS= read -r -s RUNNER_TOKEN
  printf '\n'
fi
RUNNER_TOKEN="${RUNNER_TOKEN//$'\r'/}"
RUNNER_TOKEN="${RUNNER_TOKEN//$'\n'/}"
[[ -n "$RUNNER_TOKEN" ]] || fail 'token do runner não informado'
[[ "$RUNNER_TOKEN" != *[[:space:]]* ]] || fail 'token contém espaços'

export DEBIAN_FRONTEND=noninteractive
log 'Preparando dependências'
apt-get update -y >/dev/null
apt-get install -y acl ca-certificates curl git jq sudo sqlite3 tar gzip php-cli php-curl php-sqlite3 php-mbstring >/dev/null
id "$RUNNER_USER" >/dev/null 2>&1 || useradd --system --create-home --shell /bin/bash "$RUNNER_USER"

case "$(uname -m)" in
  x86_64|amd64) RUNNER_ARCH=x64 ;;
  aarch64|arm64) RUNNER_ARCH=arm64 ;;
  *) fail "arquitetura não suportada: $(uname -m)" ;;
esac

log 'Parando instalação anterior da Farmácia'
systemctl stop "$RUNNER_SERVICE" 2>/dev/null || true
systemctl disable "$RUNNER_SERVICE" 2>/dev/null || true
rm -f "/etc/systemd/system/${RUNNER_SERVICE}.service"
systemctl daemon-reload
rm -rf "$RUNNER_DIR"
mkdir -p "$RUNNER_DIR"
chown "$RUNNER_USER:$RUNNER_USER" "$RUNNER_DIR"

log 'Baixando GitHub Actions Runner'
TAG="$(curl -fsSL https://api.github.com/repos/actions/runner/releases/latest | jq -r .tag_name)"
[[ -n "$TAG" && "$TAG" != null ]] || fail 'não foi possível descobrir a versão do runner'
VERSION="${TAG#v}"
PKG="actions-runner-linux-${RUNNER_ARCH}-${VERSION}.tar.gz"
curl -fL --retry 3 --retry-delay 2 "https://github.com/actions/runner/releases/download/${TAG}/${PKG}" -o "/tmp/$PKG"
tar xzf "/tmp/$PKG" -C "$RUNNER_DIR"
rm -f "/tmp/$PKG"
chown -R "$RUNNER_USER:$RUNNER_USER" "$RUNNER_DIR"

log 'Registrando runner exclusivo da Farmácia'
cd "$RUNNER_DIR"
sudo -u "$RUNNER_USER" ./config.sh \
  --url "$REPO_URL" \
  --token "$RUNNER_TOKEN" \
  --name "$RUNNER_NAME" \
  --labels "$LABELS" \
  --work _work \
  --unattended \
  --replace
cleanup

log 'Criando serviço systemd'
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
systemctl is-active --quiet "$RUNNER_SERVICE" || fail 'runner não iniciou'

# Permissões do site só são aplicadas quando o bootstrap root já validou o vhost real.
ROOT_HELPER_STATUS=pending_cloudpanel_bootstrap
if [[ -r "$LAYOUT_FILE" ]]; then
  log 'Instalando helper de permissões restrito'
  curl -fsSL https://raw.githubusercontent.com/superamplitude/Farmacia/main/deploy/fix-permissions-root.sh -o /usr/local/sbin/farmacia-fix-permissions.new
  chown root:root /usr/local/sbin/farmacia-fix-permissions.new
  chmod 0755 /usr/local/sbin/farmacia-fix-permissions.new
  mv /usr/local/sbin/farmacia-fix-permissions.new /usr/local/sbin/farmacia-fix-permissions
  printf '%s\n' 'farmrunner ALL=(root) NOPASSWD: /usr/local/sbin/farmacia-fix-permissions' > /etc/sudoers.d/farmacia-runner-permissions
  chmod 0440 /etc/sudoers.d/farmacia-runner-permissions
  visudo -cf /etc/sudoers.d/farmacia-runner-permissions >/dev/null
  /usr/local/sbin/farmacia-fix-permissions
  ROOT_HELPER_STATUS=installed
fi

HTTP_CODE="$(curl -L -k -sS -o /tmp/farmacia-runner-health.out -w '%{http_code}' "${PUBLIC_URL}/?health=1" || true)"
printf '\n============================================================\n'
printf ' FARMACIA SUPERAMPLITUDE - RUNNER INSTALADO\n'
printf '============================================================\n'
printf 'RUNNER_VERSION=%s\n' "$VERSION"
printf 'RUNNER_NAME=%s\n' "$RUNNER_NAME"
printf 'RUNNER_DIR=%s\n' "$RUNNER_DIR"
printf 'RUNNER_SERVICE=%s\n' "$(systemctl is-active "$RUNNER_SERVICE")"
printf 'ROOT_PERMISSION_HELPER=%s\n' "$ROOT_HELPER_STATUS"
printf 'PUBLIC_HTTP=%s\n' "$HTTP_CODE"
printf 'NEXT_BOOTSTRAP=deploy/repair-permissions.sh\n'
printf 'STATUS=READY\n'
printf '============================================================\n'
