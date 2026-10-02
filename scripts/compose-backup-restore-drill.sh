#!/usr/bin/env bash
# Local docker compose DB + persistent-files backup/restore drill.
# Proves application-data restore on compose (not CapRover). No club PII zip.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

HTTP_PORT="${EFACLOUD_HTTP_PORT:-18082}"
PROJECT="${COMPOSE_PROJECT_NAME:-efacloud-restore-drill}"
ART_ROOT="${RESTORE_DRILL_DIR:-$ROOT/var/restore-drill}"
TIMEOUT_SEC="${RESTORE_DRILL_TIMEOUT:-360}"
DOWN_AFTER=1
DOWN_VOLUMES=1
KEEP_ARTIFACTS=0
SEED_TOKEN="drill-$(date -u +%Y%m%dT%H%M%SZ)-$$"

PERSIST_PATHS=(config log uploads attachements pdfs resources)

usage() {
  cat <<'EOF'
Usage: ./scripts/compose-backup-restore-drill.sh [options]

  --port N            Host HTTP port (default 18082)
  --project NAME      compose project name (default efacloud-restore-drill)
  --art-dir PATH      artifact directory (default var/restore-drill/)
  --keep-artifacts    do not rm artifact dir contents on success
  --no-down           leave stack running after PASS
  --down              docker compose down after PASS (keeps volumes)
  --down-volumes      docker compose down -v after PASS (default)

Requires local .env (from .env.example). Does not use CapRover or club backup zips.
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --port) HTTP_PORT="${2:-}"; shift 2 ;;
    --project) PROJECT="${2:-}"; shift 2 ;;
    --art-dir) ART_ROOT="${2:-}"; shift 2 ;;
    --keep-artifacts) KEEP_ARTIFACTS=1; shift ;;
    --no-down) DOWN_AFTER=0; DOWN_VOLUMES=0; shift ;;
    --down) DOWN_AFTER=1; DOWN_VOLUMES=0; shift ;;
    --down-volumes) DOWN_AFTER=1; DOWN_VOLUMES=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) echo "Unknown argument: $1" >&2; usage >&2; exit 2 ;;
  esac
done

if [[ ! -f .env ]]; then
  echo "Missing .env — copy .env.example and set local-only passwords first." >&2
  exit 1
fi
if ! command -v docker >/dev/null 2>&1; then
  echo "docker is required" >&2
  exit 1
fi

# shellcheck disable=SC1091
set -a
# shellcheck source=/dev/null
source .env
set +a

DB_NAME="${EFACLOUD_DB_NAME:-efacloud}"
DB_USER="${EFACLOUD_DB_USER:-efacloud}"
DB_PASSWORD="${EFACLOUD_DB_PASSWORD:-efacloud_dev_password}"

unset EFACLOUD_PORTAL_FIXTURES || true
export EFACLOUD_HTTP_PORT="$HTTP_PORT"
export EFACLOUD_AUTO_INSTALL=1
export EFACLOUD_BASE_URL="${EFACLOUD_BASE_URL:-http://127.0.0.1}"
export COMPOSE_PROJECT_NAME="$PROJECT"
BASE_URL="http://127.0.0.1:${HTTP_PORT}"

STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
ART_DIR="${ART_ROOT}/${STAMP}"
mkdir -p "$ART_DIR"
DB_DUMP="$ART_DIR/efacloud-db.sql"
FILES_TGZ="$ART_DIR/efacloud-files.tgz"
LOG_FILE="$ART_DIR/drill.log"

pass=0
fail=0
log() { printf '%s\n' "$*" | tee -a "$LOG_FILE"; }
ok() { pass=$((pass + 1)); log "PASS: $*"; }
bad() { fail=$((fail + 1)); log "FAIL: $*"; }

compose() {
  docker compose -p "$PROJECT" -f docker-compose.yml "$@"
}

wait_ready() {
  local deadline=$((SECONDS + TIMEOUT_SEC))
  local portal_code="" login_code="" session_code="" install_code=""
  while (( SECONDS < deadline )); do
    portal_code="$(curl -sS -o /dev/null -w '%{http_code}' "$BASE_URL/portal/" || true)"
    login_code="$(curl -sS -o /dev/null -w '%{http_code}' "$BASE_URL/forms/login.php" || true)"
    session_code="$(curl -sS -o /dev/null -w '%{http_code}' "$BASE_URL/api/portal/v1/session" || true)"
    install_code="$(curl -sS -o /dev/null -w '%{http_code}' "$BASE_URL/install/setup_db_connection.php" || true)"
    if [[ "$portal_code" == "200" && "$login_code" == "200" && "$session_code" == "200" && "$install_code" == "403" ]]; then
      echo "$portal_code $login_code $session_code $install_code"
      return 0
    fi
    sleep 3
  done
  log "Last codes: portal=$portal_code login=$login_code session=$session_code install=$install_code"
  compose logs --tail=100 web db >&2 || true
  return 1
}

wait_db_healthy() {
  local deadline=$((SECONDS + TIMEOUT_SEC))
  while (( SECONDS < deadline )); do
    if compose exec -T db healthcheck.sh --connect --innodb_initialized >/dev/null 2>&1; then
      return 0
    fi
    # Fallback: try a trivial query
    if compose exec -T db mariadb -u"$DB_USER" -p"$DB_PASSWORD" -e "SELECT 1" >/dev/null 2>&1; then
      return 0
    fi
    sleep 2
  done
  return 1
}

sql() {
  compose exec -T db mariadb -u"$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" -N -B -e "$1"
}

volume_name() {
  # Compose v2 named volume: <project>_<volume>
  echo "${PROJECT}_efacloud-$1"
}

cleanup_stack() {
  if [[ "$DOWN_AFTER" == "1" ]]; then
    if [[ "$DOWN_VOLUMES" == "1" ]]; then
      log "Cleaning up: compose down -v"
      compose down -v --remove-orphans || true
    else
      log "Cleaning up: compose down (volumes kept)"
      compose down --remove-orphans || true
    fi
  else
    log "Leaving stack running (--no-down)"
  fi
}

log "== compose backup/restore drill =="
log "PROJECT=$PROJECT BASE_URL=$BASE_URL ART_DIR=$ART_DIR"
log "SEED_TOKEN=$SEED_TOKEN"

# Ensure a clean project before starting
compose down -v --remove-orphans >/dev/null 2>&1 || true

log "Starting compose (auto-install)..."
compose up --build -d

log "Waiting for install readiness (timeout ${TIMEOUT_SEC}s)..."
if ! codes="$(wait_ready)"; then
  bad "initial compose readiness"
  cleanup_stack
  exit 1
fi
ok "initial readiness ($codes)"

# --- Seed portal-visible / SQL + file markers ---
log "Seeding restore markers..."
sql "CREATE TABLE IF NOT EXISTS _restore_drill (
  k VARCHAR(64) PRIMARY KEY,
  v VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;"
sql "INSERT INTO _restore_drill (k, v) VALUES ('token', '${SEED_TOKEN}')
  ON DUPLICATE KEY UPDATE v=VALUES(v);"

for path in "${PERSIST_PATHS[@]}"; do
  compose exec -T web sh -c "mkdir -p /var/www/html/${path}/restore_drill && printf '%s\n' '${SEED_TOKEN}' > /var/www/html/${path}/restore_drill/marker.txt && chown -R www-data:www-data /var/www/html/${path}/restore_drill || true"
done
# Extra install-complete sanity (should already exist post auto-install)
compose exec -T web test -f /var/www/html/config/.install_complete
compose exec -T web test -f /var/www/html/config/settings_db
ok "seeded SQL token + file markers in ${#PERSIST_PATHS[@]} paths"

# --- Backup DB ---
# MariaDB 11 images ship mariadb-dump (mysqldump is often absent).
log "Backing up MariaDB (mariadb-dump --single-transaction)..."
compose exec -T db mariadb-dump --single-transaction --routines --triggers \
  -u"$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" > "$DB_DUMP"
if [[ ! -s "$DB_DUMP" ]]; then
  bad "mysqldump produced empty file"
  cleanup_stack
  exit 1
fi
if ! grep -q "_restore_drill\|CREATE TABLE" "$DB_DUMP"; then
  bad "mysqldump missing expected table content"
  cleanup_stack
  exit 1
fi
ok "DB dump $(wc -c < "$DB_DUMP") bytes → $DB_DUMP"

# --- Backup persistent files ---
log "Backing up persistent web paths..."
compose exec -T web tar -C /var/www/html -czf - "${PERSIST_PATHS[@]}" > "$FILES_TGZ"
if [[ ! -s "$FILES_TGZ" ]]; then
  bad "files archive empty"
  cleanup_stack
  exit 1
fi
ok "files archive $(wc -c < "$FILES_TGZ") bytes → $FILES_TGZ"

# --- Destroy including volumes ---
log "Destroying stack including volumes (down -v)..."
compose down -v --remove-orphans
ok "stack destroyed with volumes"

# --- Recreate + restore ---
log "Recreating DB service..."
compose up -d db
if ! wait_db_healthy; then
  bad "DB not healthy after recreate"
  cleanup_stack
  exit 1
fi
ok "DB healthy after recreate"

log "Restoring MariaDB dump into empty database..."
# MariaDB image creates empty DB + user on first boot; import into it.
compose exec -T db mariadb -u"$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" < "$DB_DUMP"
token_after_sql="$(sql "SELECT v FROM _restore_drill WHERE k='token';" | tr -d '\r' || true)"
if [[ "$token_after_sql" != "$SEED_TOKEN" ]]; then
  bad "SQL token after DB restore: got '${token_after_sql}' want '${SEED_TOKEN}'"
  cleanup_stack
  exit 1
fi
ok "DB restore verified (token=$token_after_sql)"

log "Restoring persistent files into named volumes (before web start)..."
# Create volumes by creating (not starting) the web service, then extract via alpine.
compose create web >/dev/null
RESTORE_MOUNTS=()
for path in "${PERSIST_PATHS[@]}"; do
  RESTORE_MOUNTS+=(-v "$(volume_name "$path"):/restore/${path}")
done
docker run --rm \
  "${RESTORE_MOUNTS[@]}" \
  -v "$ART_DIR:/backup:ro" \
  alpine:3.20 \
  sh -c 'apk add --no-cache tar >/dev/null && tar -xzf /backup/efacloud-files.tgz -C /restore'
ok "files extracted into compose volumes"

log "Starting web (AUTO_INSTALL=1 should skip; .install_complete restored)..."
compose up -d web

log "Waiting for post-restore readiness..."
if ! codes="$(wait_ready)"; then
  bad "post-restore readiness"
  cleanup_stack
  exit 1
fi
ok "post-restore readiness ($codes)"

# --- Verify install markers / app serves ---
if compose exec -T web test -f /var/www/html/config/.install_complete; then
  ok "config/.install_complete present"
else
  bad "config/.install_complete missing after restore"
fi
if compose exec -T web test -f /var/www/html/config/settings_db; then
  ok "config/settings_db present"
else
  bad "config/settings_db missing after restore"
fi

install_code="$(curl -sS -o /dev/null -w '%{http_code}' "$BASE_URL/install/setup_db_connection.php" || true)"
if [[ "$install_code" == "403" ]]; then
  ok "/install/ locked (HTTP $install_code)"
else
  bad "/install/ not locked (HTTP $install_code; want 403)"
fi

portal_code="$(curl -sS -o /dev/null -w '%{http_code}' "$BASE_URL/portal/" || true)"
login_code="$(curl -sS -o /dev/null -w '%{http_code}' "$BASE_URL/forms/login.php" || true)"
session_code="$(curl -sS -o /dev/null -w '%{http_code}' "$BASE_URL/api/portal/v1/session" || true)"
if [[ "$portal_code" == "200" && "$login_code" == "200" && "$session_code" == "200" ]]; then
  ok "app serves (portal/login/session 200)"
else
  bad "app HTTP portal=$portal_code login=$login_code session=$session_code"
fi

# Session JSON (unauthenticated is fine; proves PHP+DB path)
sess="$(curl -fsS "$BASE_URL/api/portal/v1/session" || true)"
if [[ -n "$sess" ]]; then
  ok "portal session endpoint body non-empty"
else
  bad "portal session empty"
fi

# --- Verify seeded data ---
token_final="$(sql "SELECT v FROM _restore_drill WHERE k='token';" | tr -d '\r' || true)"
if [[ "$token_final" == "$SEED_TOKEN" ]]; then
  ok "SQL seed token survived restore"
else
  bad "SQL seed token missing (got '${token_final}')"
fi

missing_files=0
for path in "${PERSIST_PATHS[@]}"; do
  got="$(compose exec -T web cat "/var/www/html/${path}/restore_drill/marker.txt" 2>/dev/null | tr -d '\r\n' || true)"
  if [[ "$got" == "$SEED_TOKEN" ]]; then
    ok "file marker ${path}/restore_drill/marker.txt"
  else
    bad "file marker ${path} (got '${got}')"
    missing_files=$((missing_files + 1))
  fi
done

log ""
if [[ "$fail" -eq 0 ]]; then
  log "OVERALL PASS (${pass} checks, 0 failures)"
  log "Artifacts: $ART_DIR"
  log "Note: CapRover staging restore + off-host backup jobs remain open."
  cleanup_stack
  if [[ "$KEEP_ARTIFACTS" != "1" && "$DOWN_VOLUMES" == "1" ]]; then
    # Keep latest dump/log for evidence; prune older stamp dirs under ART_ROOT
    find "$ART_ROOT" -mindepth 1 -maxdepth 1 -type d ! -path "$ART_DIR" -exec rm -rf {} + 2>/dev/null || true
  fi
  exit 0
fi

log "OVERALL FAIL (${fail} failures, ${pass} passes)"
cleanup_stack
exit 1
