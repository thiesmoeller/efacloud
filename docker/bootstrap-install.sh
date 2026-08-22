#!/bin/sh
set -eu

log() {
  printf 'efacloud-bootstrap: %s\n' "$1"
}

require_env() {
  name="$1"
  eval "value=\${$name:-}"
  if [ -z "$value" ]; then
    log "missing required environment variable: $name"
    exit 1
  fi
}

is_installed() {
  [ -f /var/www/html/config/settings_db ] || [ -f /var/www/html/config/settings/dbSettings ]
}

base_url="${EFACLOUD_BASE_URL:-http://127.0.0.1}"
db_host="${EFACLOUD_DB_HOST:-}"
db_name="${EFACLOUD_DB_NAME:-}"
db_user="${EFACLOUD_DB_USER:-}"
db_password="${EFACLOUD_DB_PASSWORD:-}"
admin_first="${EFACLOUD_ADMIN_FIRST:-}"
admin_last="${EFACLOUD_ADMIN_LAST:-}"
admin_email="${EFACLOUD_ADMIN_EMAIL:-}"
admin_id="${EFACLOUD_ADMIN_ID:-}"
admin_name="${EFACLOUD_ADMIN_NAME:-}"
admin_password="${EFACLOUD_ADMIN_PASSWORD:-}"

if is_installed; then
  log "already installed; skipping auto-install"
  exit 0
fi

if [ "${EFACLOUD_AUTO_INSTALL:-0}" != "1" ]; then
  log "EFACLOUD_AUTO_INSTALL is not enabled; skipping"
  exit 0
fi

for var in EFACLOUD_DB_HOST EFACLOUD_DB_NAME EFACLOUD_DB_USER EFACLOUD_DB_PASSWORD \
  EFACLOUD_ADMIN_FIRST EFACLOUD_ADMIN_LAST EFACLOUD_ADMIN_EMAIL EFACLOUD_ADMIN_ID \
  EFACLOUD_ADMIN_NAME EFACLOUD_ADMIN_PASSWORD; do
  require_env "$var"
done

wait_for_http() {
  for _ in $(seq 1 90); do
    if curl -fsS "$base_url/" >/dev/null 2>&1; then
      return 0
    fi
    sleep 2
  done
  log "application did not become reachable at $base_url"
  return 1
}

assert_http_ok() {
  url="$1"
  body_file="$2"
  if ! curl -fsS "$url" -o "$body_file"; then
    log "request failed: $url"
    return 1
  fi
  if grep -qi "Fehlgeschlagen\|hat leider nicht geklappt\|stimmen nicht überein\|unzulässig" "$body_file"; then
    log "installer reported an error for: $url"
    return 1
  fi
  return 0
}

wait_for_http

log "configuring database connection"
db_body="/tmp/efacloud-setup-db.html"
if ! curl -fsS -X POST "$base_url/install/setup_db_connection.php?done=1" \
  --data-urlencode "db_host=$db_host" \
  --data-urlencode "db_name=$db_name" \
  --data-urlencode "db_user=$db_user" \
  --data-urlencode "db_up=$db_password" \
  -o "$db_body"; then
  log "database configuration request failed"
  exit 1
fi
if ! grep -q "Erfolgreich abgeschlossen" "$db_body"; then
  log "database configuration did not succeed"
  exit 1
fi
if [ ! -f /var/www/html/config/settings_db ]; then
  log "settings_db was not created"
  exit 1
fi

log "initializing database and admin user"
clear_body="/tmp/efacloud-clear-db.html"
if ! curl -fsS -X POST "$base_url/install/setup_clear_db.php?done=1" \
  --data-urlencode "ecadmin_vorname=$admin_first" \
  --data-urlencode "ecadmin_nachname=$admin_last" \
  --data-urlencode "ecadmin_mail=$admin_email" \
  --data-urlencode "ecadmin_id=$admin_id" \
  --data-urlencode "ecadmin_Name=$admin_name" \
  --data-urlencode "ecadmin_password=$admin_password" \
  --data-urlencode "ecadmin_password_confirm=$admin_password" \
  -o "$clear_body"; then
  log "database initialization request failed"
  exit 1
fi
if ! grep -q ">Fertig<" "$clear_body"; then
  log "database initialization did not succeed"
  exit 1
fi

log "finishing installation"
finish_body="/tmp/efacloud-finish.html"
if ! assert_http_ok "$base_url/install/setup_finish.php" "$finish_body"; then
  exit 1
fi
if [ ! -f /var/www/html/install/.locked ]; then
  log "installer lock was not created"
  exit 1
fi

if ! curl -fsS "$base_url/forms/login.php" >/dev/null; then
  log "login page is not reachable after install"
  exit 1
fi

log "auto-install complete"
