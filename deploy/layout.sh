#!/usr/bin/env bash

# Contrato fixo aprovado para o subdominio da Farmacia.
# O subdominio publico e farmacia.superamplitude.com e o document root
# correspondente DEVE ser exatamente:
# /home/superamplitude-farmacia/htdocs/farmacia.superamplitude.com

FARMACIA_DOMAIN="${FARMACIA_DOMAIN:-farmacia.superamplitude.com}"
FARMACIA_SITE_USER="${FARMACIA_SITE_USER:-superamplitude-farmacia}"
FARMACIA_APP_HOME="${FARMACIA_APP_HOME:-/home/superamplitude-farmacia}"
FARMACIA_APP_DIR="${FARMACIA_APP_DIR:-/home/superamplitude-farmacia/htdocs/farmacia.superamplitude.com}"
FARMACIA_STATE_DIR="${FARMACIA_STATE_DIR:-/home/superamplitude-farmacia/.farmacia}"
FARMACIA_LAYOUT_FILE="${FARMACIA_LAYOUT_FILE:-/etc/farmacia-superamplitude/layout.env}"

farmacia_find_vhost() {
  local domain="${1:-$FARMACIA_DOMAIN}" candidate="" found=""
  for candidate in "/etc/nginx/sites-enabled/${domain}.conf" "/etc/nginx/sites-available/${domain}.conf"; do
    if [[ -f "$candidate" ]]; then printf '%s\n' "$candidate"; return 0; fi
  done
  found="$(grep -RIl --include='*.conf' "$domain" /etc/nginx/sites-enabled /etc/nginx/sites-available 2>/dev/null | head -n1 || true)"
  [[ -n "$found" ]] || return 1
  printf '%s\n' "$found"
}

farmacia_layout_from_vhost() {
  local vhost="${1:-}" root_path=""
  [[ -f "$vhost" ]] || return 1
  root_path="$(awk '$1=="root" {gsub(/;/,"",$2); print $2; exit}' "$vhost" 2>/dev/null || true)"
  [[ -n "$root_path" ]] || return 2
  [[ "$root_path" == "$FARMACIA_APP_DIR" ]] || return 3
  id "$FARMACIA_SITE_USER" >/dev/null 2>&1 || return 4

  APP_USER="$FARMACIA_SITE_USER"
  APP_HOME="$FARMACIA_APP_HOME"
  APP_DIR="$FARMACIA_APP_DIR"
  STATE_DIR="$FARMACIA_STATE_DIR"
  VHOST_FILE="$vhost"
  export APP_USER APP_HOME APP_DIR STATE_DIR VHOST_FILE
  return 0
}

farmacia_layout_load() {
  local mode="${1:-strict}" vhost=""

  # Um layout antigo so e aceito se coincidir exatamente com o contrato atual.
  if [[ -r "$FARMACIA_LAYOUT_FILE" ]]; then
    # shellcheck disable=SC1090
    source "$FARMACIA_LAYOUT_FILE"
    if [[ "${APP_USER:-}" == "$FARMACIA_SITE_USER" && \
          "${APP_HOME:-}" == "$FARMACIA_APP_HOME" && \
          "${APP_DIR:-}" == "$FARMACIA_APP_DIR" && \
          "${STATE_DIR:-}" == "$FARMACIA_STATE_DIR" ]]; then
      export APP_USER APP_HOME APP_DIR STATE_DIR VHOST_FILE
      return 0
    fi
    unset APP_USER APP_HOME APP_DIR STATE_DIR VHOST_FILE || true
  fi

  vhost="$(farmacia_find_vhost "$FARMACIA_DOMAIN" || true)"
  if [[ -n "$vhost" ]] && farmacia_layout_from_vhost "$vhost"; then return 0; fi

  if [[ "$mode" == "optional" ]]; then return 1; fi
  echo "FARMACIA_LAYOUT_FAIL esperado=${FARMACIA_APP_DIR} para ${FARMACIA_DOMAIN}" >&2
  return 1
}

farmacia_layout_write() {
  [[ $EUID -eq 0 ]] || { echo 'FARMACIA_LAYOUT_FAIL gravacao requer root' >&2; return 1; }
  [[ "${APP_USER:-}" == "$FARMACIA_SITE_USER" ]] || return 1
  [[ "${APP_HOME:-}" == "$FARMACIA_APP_HOME" ]] || return 1
  [[ "${APP_DIR:-}" == "$FARMACIA_APP_DIR" ]] || return 1
  [[ "${STATE_DIR:-}" == "$FARMACIA_STATE_DIR" ]] || return 1
  [[ -n "${VHOST_FILE:-}" ]] || return 1

  mkdir -p "$(dirname "$FARMACIA_LAYOUT_FILE")"
  umask 0022
  cat > "$FARMACIA_LAYOUT_FILE" <<EOF
APP_USER=$(printf '%q' "$FARMACIA_SITE_USER")
APP_HOME=$(printf '%q' "$FARMACIA_APP_HOME")
APP_DIR=$(printf '%q' "$FARMACIA_APP_DIR")
STATE_DIR=$(printf '%q' "$FARMACIA_STATE_DIR")
VHOST_FILE=$(printf '%q' "$VHOST_FILE")
EOF
  chown root:root "$FARMACIA_LAYOUT_FILE"
  chmod 0644 "$FARMACIA_LAYOUT_FILE"
}
