#!/usr/bin/env bash
# Portal API smoke against LIVE desktop-synch MariaDB (not fixtures).
# Assumes stack `efacloud-desktop-synch` is already up (default http://127.0.0.1:18083).
#
# Ensures a local-only portalTrainer user (902) linked to a real PersonId, then:
#   login, boat views + seat filter, boat detail+damages, start→ONTHEWATER+EntryNo,
#   finish→AVAILABLE, damage tripAborted=false, ACK_REQUIRED on NOTAVAILABLE boat.
# Optional: --second-upload runs desktop-synch-lab --upload-only and asserts current_boats=58.
#
# Never enables EFACLOUD_PORTAL_FIXTURES. CapRover/device still out of scope.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

PROJECT="${COMPOSE_PROJECT_NAME:-efacloud-desktop-synch}"
BASE_URL="${PORTAL_LIVE_BASE_URL:-http://127.0.0.1:18083}"
PORTAL_ID="${PORTAL_LIVE_USER_ID:-902}"
PORTAL_PASSWORD="${PORTAL_LIVE_PASSWORD:-RehearsePortal1!}"
SECOND_UPLOAD=0
SKIP_ENSURE_USER=0
REMOTE=0
SKIP_FLEET_COUNTS=0

usage() {
  cat <<'EOF'
Usage: ./scripts/portal-live-synch-smoke.sh [options]

  Assumes docker compose project efacloud-desktop-synch (web on :18083) is up.

Options:
  --base-url URL       Default http://127.0.0.1:18083
  --project NAME       Compose project (default efacloud-desktop-synch)
  --portal-id N        efaCloudUserID (default 902)
  --password STR       Local-only portal password (default RehearsePortal1!)
  --second-upload      After portal checks, re-run SynchControl upload-only; assert boats=58
  --skip-ensure-user   Do not INSERT/UPDATE portal user (must already exist)
  --remote             CapRover HTTPS (no local compose; implies --skip-fleet-counts)
  --skip-fleet-counts  Skip MariaDB 58/241 assertions (API smoke only)
  -h, --help           This help
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --base-url) BASE_URL="${2:-}"; shift 2 ;;
    --project) PROJECT="${2:-}"; shift 2 ;;
    --portal-id) PORTAL_ID="${2:-}"; shift 2 ;;
    --password) PORTAL_PASSWORD="${2:-}"; shift 2 ;;
    --second-upload) SECOND_UPLOAD=1; shift ;;
    --skip-ensure-user) SKIP_ENSURE_USER=1; shift ;;
    --remote) REMOTE=1; SKIP_FLEET_COUNTS=1; shift ;;
    --skip-fleet-counts) SKIP_FLEET_COUNTS=1; shift ;;
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
  echo "== stack readiness @ $BASE_URL (project=$PROJECT remote=$REMOTE) =="
  if [[ "$REMOTE" -eq 0 ]]; then
    if ! "${COMPOSE[@]}" ps --status running 2>/dev/null | grep -q ' web '; then
      # fallback: any running services
      if ! "${COMPOSE[@]}" ps --status running 2>/dev/null | grep -q .; then
        fail "compose project $PROJECT has no running services"
        echo "Start with: ./scripts/desktop-synch-lab.sh --port 18083" >&2
        exit 1
      fi
    fi
  fi
  code="$(curl -sS -o /tmp/portal-live-session.json -w '%{http_code}' "$BASE_URL/api/portal/v1/session" || true)"
  if [[ "$code" != "200" ]]; then
    fail "GET /session HTTP $code"
    exit 1
  fi
  if grep -q '"fixture"' /tmp/portal-live-session.json 2>/dev/null; then
    fail "REFUSE: session looks fixture-backed"
    exit 2
  fi
  if [[ -n "${EFACLOUD_PORTAL_FIXTURES:-}" && "${EFACLOUD_PORTAL_FIXTURES}" =~ ^(1|true|TRUE)$ ]]; then
    fail "REFUSE: EFACLOUD_PORTAL_FIXTURES is set"
    exit 2
  fi
  pass "web reachable; fixtures off"
}

ensure_portal_user() {
  echo "== ensure portal user $PORTAL_ID (member + portalTrainer 131072) =="
  person_id="$(sql "
SELECT Id FROM efa2persons
WHERE CAST(ValidFrom AS UNSIGNED) <= UNIX_TIMESTAMP()*1000
  AND CAST(InvalidFrom AS UNSIGNED) > UNIX_TIMESTAMP()*1000
  AND FirstName NOT LIKE 'Gast%'
  AND LastName IS NOT NULL AND LastName <> ''
ORDER BY LastName, FirstName
LIMIT 1;
" | tr -d '\r' | head -n1)"
  if [[ -z "$person_id" ]]; then
    fail "no current PersonId to link"
    exit 1
  fi
  person_name="$(sql "
SELECT CONCAT(IFNULL(FirstName,''),' ',IFNULL(LastName,'')) FROM efa2persons
WHERE Id='${person_id}'
  AND CAST(ValidFrom AS UNSIGNED) <= UNIX_TIMESTAMP()*1000
  AND CAST(InvalidFrom AS UNSIGNED) > UNIX_TIMESTAMP()*1000
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
SELECT efaCloudUserID, Rolle, PersonId, Concessions FROM efaCloudUsers WHERE efaCloudUserID=${PORTAL_ID};
SQL
  pass "portal user $PORTAL_ID linked PersonId=${person_id} (${person_name}) concessions=131072"
  export PORTAL_LIVE_PERSON_ID="$person_id"
}

fleet_counts() {
  echo "== fleet counts (MariaDB) =="
  boats="$(sql "
SELECT COUNT(DISTINCT Id) FROM efa2boats
WHERE CAST(ValidFrom AS UNSIGNED) <= UNIX_TIMESTAMP()*1000
  AND CAST(InvalidFrom AS UNSIGNED) > UNIX_TIMESTAMP()*1000;
" | tr -d '\r' | head -n1)"
  persons="$(sql "
SELECT COUNT(DISTINCT Id) FROM efa2persons
WHERE CAST(ValidFrom AS UNSIGNED) <= UNIX_TIMESTAMP()*1000
  AND CAST(InvalidFrom AS UNSIGNED) > UNIX_TIMESTAMP()*1000;
" | tr -d '\r' | head -n1)"
  info "current_boats=$boats current_persons=$persons"
  if [[ "$boats" == "58" ]]; then
    pass "current boats = 58"
  else
    fail "current boats expected 58 got $boats"
  fi
  if [[ "$persons" == "241" ]]; then
    pass "current persons = 241"
  else
    fail "current persons expected 241 got $persons"
  fi
}

run_portal_python() {
  echo "== portal API exercise (DB store, not fixtures) =="
  export PORTAL_LIVE_BASE_URL="$BASE_URL"
  export PORTAL_LIVE_USER_ID="$PORTAL_ID"
  export PORTAL_LIVE_PASSWORD="$PORTAL_PASSWORD"
  export COMPOSE_PROJECT_NAME="$PROJECT"
  export EFACLOUD_DB_USER="$DB_USER"
  export EFACLOUD_DB_PASSWORD="$DB_PASS"
  export EFACLOUD_DB_NAME="$DB_NAME"
  if ! command -v python3 >/dev/null 2>&1; then
    fail "need python3 on host"
    return 1
  fi
  PYTHONPATH="$ROOT/lab/backup_rehearsal${PYTHONPATH:+:$PYTHONPATH}" \
    python3 "$ROOT/lab/desktop_synch/portal_live_smoke.py"
}

second_upload() {
  echo "== second SynchControl upload (no duplicate currents) =="
  before="$(sql "
SELECT COUNT(DISTINCT Id) FROM efa2boats
WHERE CAST(ValidFrom AS UNSIGNED) <= UNIX_TIMESTAMP()*1000
  AND CAST(InvalidFrom AS UNSIGNED) > UNIX_TIMESTAMP()*1000;
" | tr -d '\r' | head -n1)"
  info "boats before upload=$before"
  if ! ./scripts/desktop-synch-lab.sh --upload-only; then
    fail "desktop-synch-lab --upload-only"
    return 1
  fi
  after="$(sql "
SELECT COUNT(DISTINCT Id) FROM efa2boats
WHERE CAST(ValidFrom AS UNSIGNED) <= UNIX_TIMESTAMP()*1000
  AND CAST(InvalidFrom AS UNSIGNED) > UNIX_TIMESTAMP()*1000;
" | tr -d '\r' | head -n1)"
  info "boats after upload=$after"
  if [[ "$after" == "58" && "$before" == "58" ]]; then
    pass "second upload: current boats still 58 (no duplicates)"
  else
    fail "second upload boat count before=$before after=$after (want 58/58)"
  fi
}

require_stack
if [[ "$REMOTE" -eq 1 && "$SKIP_ENSURE_USER" -eq 0 ]]; then
  info "remote mode: use --skip-ensure-user (portal user must exist on staging DB)"
  SKIP_ENSURE_USER=1
fi
if [[ "$SKIP_ENSURE_USER" -eq 0 ]]; then
  ensure_portal_user
else
  info "skip ensure user"
fi
if [[ "$SKIP_FLEET_COUNTS" -eq 0 ]]; then
  fleet_counts
else
  info "skip fleet counts (remote / API-only)"
fi
# Write helper python if missing is handled by file we create alongside
if [[ ! -f "$ROOT/lab/desktop_synch/portal_live_smoke.py" ]]; then
  fail "missing lab/desktop_synch/portal_live_smoke.py"
  exit 1
fi

set +e
run_portal_python
py_rc=$?
set -e
if [[ $py_rc -eq 0 ]]; then
  pass "portal python smoke module exited 0"
else
  fail "portal python smoke module exited $py_rc"
fi

if [[ "$SECOND_UPLOAD" -eq 1 ]]; then
  second_upload
fi

echo
echo "==== SUMMARY ===="
echo "PASS=$PASS FAIL=$FAIL"
if [[ "$FAIL" -gt 0 ]]; then
  echo "OVERALL: FAIL"
  exit 1
fi
echo "OVERALL: PASS"
echo "CapRover / device QA still open (local desktop-synch only)."
exit 0
