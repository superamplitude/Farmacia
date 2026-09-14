#!/usr/bin/env bash
set -Eeuo pipefail

[[ $EUID -eq 0 ]] || { echo 'ERRO: execute como root' >&2; exit 1; }
cd /root

curl -fsSL https://raw.githubusercontent.com/superamplitude/Farmacia/main/deploy/root-bootstrap-once.sh \
  -o /root/farmacia-root-bootstrap-once.sh
chmod 0700 /root/farmacia-root-bootstrap-once.sh
exec /root/farmacia-root-bootstrap-once.sh
