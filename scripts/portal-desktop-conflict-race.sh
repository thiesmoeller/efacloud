#!/usr/bin/env bash
# Portal ↔ desktop posttx conflict race on LIVE SynchControl MariaDB.
# Assumes stack efacloud-desktop-synch is up (default http://127.0.0.1:18083).
#
# Portal starts trip → desktop bths posttx conflicting checkout → assert no
# silent inconsistent status+logbook → portal finish with stale ChangeCount
# → STALE_STATE → cleanup.
#
# Never enables EFACLOUD_PORTAL_FIXTURES. CapRover still out of scope.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

PROJECT="${COMPOSE_PROJECT_NAME:-efacloud-desktop-synch}"
BASE_URL="${PORTAL_LIVE_BASE_URL:-http://127.0.0.1:18083}"
PORTAL_ID="${PORTAL_LIVE_USER_ID:-902}"
PORTAL_PASSWORD="${PORTAL_LIVE_PASSWORD:-RehearsePortal1!}"
BTHS_ID="${EFA_CLOUD_USERNAME:-901}"
BTHS_PASSWORD="${EFA_CLOUD_PASSWORD:-RehearseSync1!}"
SKIP_ENSURE_USER=0

usage() {
  cat <<'EOF'
Usage: ./scripts/portal-desktop-conflict-race.sh [options]

Options:
  --base-url URL       Default http://127.0.0.1:18083
  --project NAME       Compose project (default efacloud-desktop-synch)
  --portal-id N        Portal user (default 902)
  --password STR       Portal password
  --bths-id N          Desktop bths user (default 901)
  --bths-password S    Desktop bths password (default RehearseSync1!)
  --skip-ensure-user   Do not recreate portal user 902
  -h, --help
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --base-url) BASE_URL="${2:-}"; shift 2 ;;
    --project) PROJECT="${2:-}"; shift 2 ;;
    --portal-id) PORTAL_ID="${2:-}"; shift 2 ;;
    --password) PORTAL_PASSWORD="${2:-}"; shift 2 ;;
    --bths-id) BTHS_ID="${2:-}"; shift 2 ;;
    --bths-password) BTHS_PASSWORD="${2:-}"; shift 2 ;;
    --skip-ensure-user) SKIP_ENSURE_USER=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) echo "Unknown argument: $1" >&2; usage >&2; exit 2 ;;
  esac
done

# shellcheck disable=SC1091
set -a; source .env; set +a
DB_USER="${EFACLOUD_DB_USER:-efacloud}"
DB_PASS="${EFACLOUD_DB_PASSWORD:-efacloud_dev_password}"
DB_NAME="${EFACLOUD_DB_NAME:-efacloud}"
COMPOSE=(docker compose -p "$PROJECT")

PASS=0
FAIL=0
pass() { PASS=$((PASS + 1)); echo "  PASS  $*"; }
fail() { FAIL=$((FAIL + 1)); echo "  FAIL  $*"; }
info() { echo "  INFO  $*"; }

sql() {
  "${COMPOSE[@]}" exec -T db mariadb -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -N -B -e "$1"
}

require_stack() {
  echo "== stack readiness @ $BASE_URL (project=$PROJECT) =="
  if ! "${COMPOSE[@]}" ps --status running 2>/dev/null | grep -q .; then
    fail "compose project $PROJECT has no running services"
    exit 1
  fi
  code="$(curl -sS -o /tmp/portal-race-session.json -w '%{http_code}' "$BASE_URL/api/portal/v1/session" || true)"
  if [[ "$code" != "200" ]]; then
    fail "GET /session HTTP $code"
    exit 1
  fi
  if grep -q '"fixture"' /tmp/portal-race-session.json 2>/dev/null; then
    fail "REFUSE: fixtures"
    exit 2
  fi
  bths="$(sql "SELECT Rolle FROM efaCloudUsers WHERE efaCloudUserID=${BTHS_ID}" | tr -d '\r' | head -n1)"
  if [[ "$bths" != "bths" ]]; then
    fail "bths user $BTHS_ID missing (got Rolle=$bths) — run desktop-synch-lab.sh first"
    exit 1
  fi
  pass "stack up; bths=$BTHS_ID; fixtures off"
}

ensure_portal_user() {
  echo "== ensure portal user $PORTAL_ID =="
  person_id="$(sql "
SELECT Id FROM efa2persons
WHERE CAST(ValidFrom AS UNSIGNED) <= UNIX_TIMESTAMP()*1000
  AND CAST(InvalidFrom AS UNSIGNED) > UNIX_TIMESTAMP()*1000
  AND FirstName NOT LIKE 'Gast%'
  AND LastName IS NOT NULL AND LastName <> ''
ORDER BY LastName, FirstName
LIMIT 1;
" | tr -d '\r' | head -n1)"
  hash="$("${COMPOSE[@]}" exec -T -e "P=${PORTAL_PASSWORD}" web \
    php -r 'echo password_hash(getenv("P"), PASSWORD_DEFAULT);')"
  now_ms="$(($(date +%s) * 1000))"
  hash_sql="${hash//\'/\'\'}"
  "${COMPOSE[@]}" exec -T db mariadb -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" <<SQL
DELETE FROM efaCloudUsers WHERE efaCloudUserID = ${PORTAL_ID};
INSERT INTO efaCloudUsers
  (EMail, efaCloudUserID, efaAdminName, PersonId, Vorname, Nachname, Rolle,
   Subskriptionen, Workflows, Concessions, Passwort_Hash, LastModified)
VALUES
  ('portal-live-synch@example.test', ${PORTAL_ID}, 'portal_live_synch', '${person_id}',
   'Portal', 'LiveSynch', 'member', 0, 0, 131072, '${hash_sql}', ${now_ms});
SQL
  pass "portal user $PORTAL_ID ready"
}

require_stack
if [[ "$SKIP_ENSURE_USER" -eq 0 ]]; then
  ensure_portal_user
else
  info "skip ensure user"
fi

echo "== conflict race (python) =="
export PORTAL_LIVE_BASE_URL="$BASE_URL"
export PORTAL_LIVE_USER_ID="$PORTAL_ID"
export PORTAL_LIVE_PASSWORD="$PORTAL_PASSWORD"
export EFA_CLOUD_USERNAME="$BTHS_ID"
export EFA_CLOUD_PASSWORD="$BTHS_PASSWORD"
export COMPOSE_PROJECT_NAME="$PROJECT"
export EFACLOUD_DB_USER="$DB_USER"
export EFACLOUD_DB_PASSWORD="$DB_PASS"
export EFACLOUD_DB_NAME="$DB_NAME"

set +e
PYTHONPATH="$ROOT/lab/backup_rehearsal${PYTHONPATH:+:$PYTHONPATH}" \
  python3 "$ROOT/lab/desktop_synch/portal_desktop_conflict_race.py"
rc=$?
set -e
if [[ $rc -eq 0 ]]; then
  pass "conflict race module exited 0"
else
  fail "conflict race module exited $rc"
fi

echo
echo "==== SUMMARY ===="
echo "PASS=$PASS FAIL=$FAIL"
if [[ "$FAIL" -gt 0 || "$rc" -ne 0 ]]; then
  echo "OVERALL: FAIL"
  exit 1
fi
echo "OVERALL: PASS"
echo "CapRover desktop↔PWA still open (local compose posttx race only)."
exit 0
