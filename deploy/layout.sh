#!/usr/bin/env bash

# Descobre o layout real do site sem presumir o Site User do CloudPanel.
# Pode ser source'd por scripts root ou pelo runner.

FARMACIA_DOMAIN="${FARMACIA_DOMAIN:-farmacia.superamplitude.com}"
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
  local vhost="${1:-}" root_path="" site_user=""
  [[ -f "$vhost" ]] || return 1
  root_path="$(awk '$1=="root" {gsub(/;/,"",$2); print $2; exit}' "$vhost" 2>/dev/null || true)"
  [[ -n "$root_path" ]] || return 2
  case "$root_path" in
    /home/*/htdocs/$FARMACIA_DOMAIN) ;;
    *) return 3 ;;
  esac
  site_user="$(printf '%s' "$root_path" | cut -d/ -f3)"
  [[ -n "$site_user" ]] || return 4
  id "$site_user" >/dev/null 2>&1 || return 5

  APP_USER="$site_user"
  APP_HOME="/home/${site_user}"
  APP_DIR="$root_path"
  STATE_DIR="${APP_HOME}/.farmacia"
  VHOST_FILE="$vhost"
  export APP_USER APP_HOME APP_DIR STATE_DIR VHOST_FILE
  return 0
}

farmacia_layout_load() {
  local mode="${1:-strict}" vhost=""
  if [[ -r "$FARMACIA_LAYOUT_FILE" ]]; then
    # shellcheck disable=SC1090
    source "$FARMACIA_LAYOUT_FILE"
    export APP_USER APP_HOME APP_DIR STATE_DIR VHOST_FILE
    [[ -n "${APP_USER:-}" && -n "${APP_DIR:-}" && -n "${STATE_DIR:-}" ]] && return 0
  fi

  vhost="$(farmacia_find_vhost "$FARMACIA_DOMAIN" || true)"
  if [[ -n "$vhost" ]] && farmacia_layout_from_vhost "$vhost"; then return 0; fi

  if [[ "$mode" == "optional" ]]; then return 1; fi
  echo "FARMACIA_LAYOUT_FAIL não foi possível determinar o Site User/document root de ${FARMACIA_DOMAIN}" >&2
  return 1
}

farmacia_layout_write() {
  [[ $EUID -eq 0 ]] || { echo 'FARMACIA_LAYOUT_FAIL gravação requer root' >&2; return 1; }
  [[ -n "${APP_USER:-}" && -n "${APP_HOME:-}" && -n "${APP_DIR:-}" && -n "${STATE_DIR:-}" && -n "${VHOST_FILE:-}" ]] || return 1
  mkdir -p "$(dirname "$FARMACIA_LAYOUT_FILE")"
  umask 0022
  cat > "$FARMACIA_LAYOUT_FILE" <<EOF
APP_USER=$(printf '%q' "$APP_USER")
APP_HOME=$(printf '%q' "$APP_HOME")
APP_DIR=$(printf '%q' "$APP_DIR")
STATE_DIR=$(printf '%q' "$STATE_DIR")
VHOST_FILE=$(printf '%q' "$VHOST_FILE")
EOF
  chown root:root "$FARMACIA_LAYOUT_FILE"
  chmod 0644 "$FARMACIA_LAYOUT_FILE"
}
