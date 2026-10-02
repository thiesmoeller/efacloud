#!/usr/bin/env bash
# Chrome browser E2E for dockside portal on LIVE desktop-synch stack.
# Assumes project efacloud-desktop-synch is up (default http://127.0.0.1:18083).
# Uses system google-chrome + playwright-core (no Chromium download).
#
# Never enables EFACLOUD_PORTAL_FIXTURES. CapRover / physical device still open.
# Default path stays fast; pass --with-damage / --with-ack for extended parity.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

PROJECT="${COMPOSE_PROJECT_NAME:-efacloud-desktop-synch}"
BASE_URL="${PORTAL_E2E_BASE_URL:-${PORTAL_LIVE_BASE_URL:-http://127.0.0.1:18083}}"
PORTAL_ID="${PORTAL_LIVE_USER_ID:-902}"
PORTAL_PASSWORD="${PORTAL_LIVE_PASSWORD:-RehearsePortal1!}"
CHROME_PATH="${CHROME_PATH:-${GOOGLE_CHROME_BIN:-/usr/bin/google-chrome}}"
SKIP_ENSURE_USER=0
WITH_DAMAGE=0
WITH_ACK=0
SKIP_MOBILE=0
SKIP_CORRECT_ABORT=0
REMOTE=0

usage() {
  cat <<'EOF'
Usage: ./scripts/portal-browser-e2e.sh [options]

  Chrome E2E: German login → boats/chips → start → Auf Fahrt correct →
  abort-with-damage → offline banner → service worker → Pixel 7 / iPhone
  login+list+start. Against live MariaDB (not fixtures).

Options:
  --base-url URL       Default http://127.0.0.1:18083
  --project NAME       Compose project (default efacloud-desktop-synch)
  --portal-id N        efaCloudUserID (default 902)
  --password STR       Portal password (default RehearsePortal1!)
  --chrome PATH        google-chrome binary
  --skip-ensure-user   Do not INSERT/UPDATE portal user
  --skip-offline       Skip CDP offline assertions
  --skip-mobile        Skip Pixel 7 / iPhone emulation pass
  --skip-correct-abort Use finish-only path (legacy); skip correct+abort-with-damage
  --with-damage        Report form: three severity labels + tripAborted=false UI
  --with-ack           NOTAVAILABLE ACK dialog → confirm → start → abort cleanup;
                       also ACK_STALE renew (SQL Comment mutate between ack/start)
  --headed             Show browser window
  --remote             HTTPS CapRover staging (curl checks only; use --skip-ensure-user)
  -h, --help
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --base-url) BASE_URL="${2:-}"; shift 2 ;;
    --project) PROJECT="${2:-}"; shift 2 ;;
    --portal-id) PORTAL_ID="${2:-}"; shift 2 ;;
    --password) PORTAL_PASSWORD="${2:-}"; shift 2 ;;
    --chrome) CHROME_PATH="${2:-}"; shift 2 ;;
    --skip-ensure-user) SKIP_ENSURE_USER=1; shift ;;
    --skip-offline) export PORTAL_E2E_SKIP_OFFLINE=1; shift ;;
    --skip-mobile) SKIP_MOBILE=1; shift ;;
    --skip-correct-abort) SKIP_CORRECT_ABORT=1; shift ;;
    --with-damage) WITH_DAMAGE=1; shift ;;
    --with-ack) WITH_ACK=1; shift ;;
    --headed) export PORTAL_E2E_HEADED=1; shift ;;
    --remote) REMOTE=1; shift ;;
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
    if ! "${COMPOSE[@]}" ps --status running 2>/dev/null | grep -q .; then
      fail "compose project $PROJECT has no running services"
      echo "Start with: ./scripts/desktop-synch-lab.sh --port 18083" >&2
      exit 1
    fi
  fi
  code="$(curl -sS -o /tmp/portal-e2e-session.json -w '%{http_code}' "$BASE_URL/api/portal/v1/session" || true)"
  if [[ "$code" != "200" ]]; then
    fail "GET /session HTTP $code"
    exit 1
  fi
  if grep -q '"fixture"' /tmp/portal-e2e-session.json 2>/dev/null; then
    fail "REFUSE: session looks fixture-backed"
    exit 2
  fi
  pcode="$(curl -sS -o /dev/null -w '%{http_code}' "$BASE_URL/portal/" || true)"
  if [[ "$pcode" != "200" ]]; then
    fail "GET /portal/ HTTP $pcode"
    exit 1
  fi
  if [[ ! -x "$CHROME_PATH" ]] && ! command -v "$CHROME_PATH" >/dev/null 2>&1; then
    fail "chrome not found at $CHROME_PATH"
    exit 1
  fi
  pass "web+portal reachable; chrome=$CHROME_PATH; fixtures off"
}

ensure_portal_user() {
  echo "== ensure portal user $PORTAL_ID =="
  # Reuse same semantics as portal-live-synch-smoke.sh
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

lookup_person_query() {
  # Prefer linked person's last name for PersonPicker search
  q="$(sql "
SELECT IFNULL(p.LastName, p.FirstName) FROM efaCloudUsers u
JOIN efa2persons p ON p.Id = u.PersonId
  AND CAST(p.ValidFrom AS UNSIGNED) <= UNIX_TIMESTAMP()*1000
  AND CAST(p.InvalidFrom AS UNSIGNED) > UNIX_TIMESTAMP()*1000
WHERE u.efaCloudUserID=${PORTAL_ID}
LIMIT 1;
" | tr -d '\r' | head -n1)"
  if [[ -n "$q" ]]; then
    export PORTAL_E2E_PERSON_QUERY="$q"
    info "person search query=$q"
  fi
}

lookup_ack_boat_hint() {
  # Prefer a NOTAVAILABLE single for ACK UI (one crew seat).
  name="$(sql "
SELECT b.Name
FROM efa2boatstatus bs
JOIN efa2boats b ON b.Id = bs.BoatId
  AND CAST(b.ValidFrom AS UNSIGNED) <= UNIX_TIMESTAMP()*1000
  AND CAST(b.InvalidFrom AS UNSIGNED) > UNIX_TIMESTAMP()*1000
WHERE bs.CurrentStatus = 'NOTAVAILABLE'
  AND b.TypeSeats = '1'
ORDER BY b.Name
LIMIT 1;
" | tr -d '\r' | head -n1)"
  if [[ -n "$name" ]]; then
    export PORTAL_E2E_ACK_BOAT_HINT="$name"
    info "ACK boat hint=$name"
  fi
}

install_e2e_deps() {
  echo "== playwright-core (portal/e2e) =="
  if [[ ! -d "$ROOT/portal/e2e/node_modules/playwright-core" ]]; then
    if ! command -v npm >/dev/null 2>&1; then
      fail "npm required to install playwright-core"
      exit 1
    fi
    (cd "$ROOT/portal/e2e" && npm install --no-fund --no-audit --no-progress)
  fi
  pass "playwright-core present"
}

run_e2e() {
  echo "== Chrome E2E =="
  export PORTAL_E2E_BASE_URL="$BASE_URL"
  export PORTAL_LIVE_USER_ID="$PORTAL_ID"
  export PORTAL_LIVE_PASSWORD="$PORTAL_PASSWORD"
  export CHROME_PATH
  export COMPOSE_PROJECT_NAME="$PROJECT"
  export EFACLOUD_DB_USER="$DB_USER"
  export EFACLOUD_DB_PASSWORD="$DB_PASS"
  export EFACLOUD_DB_NAME="$DB_NAME"
  if [[ "$WITH_DAMAGE" -eq 1 ]]; then
    export PORTAL_E2E_WITH_DAMAGE=1
  else
    unset PORTAL_E2E_WITH_DAMAGE || true
  fi
  if [[ "$WITH_ACK" -eq 1 ]]; then
    export PORTAL_E2E_WITH_ACK=1
  else
    unset PORTAL_E2E_WITH_ACK || true
  fi
  if [[ "$SKIP_MOBILE" -eq 1 ]]; then
    export PORTAL_E2E_SKIP_MOBILE=1
  else
    unset PORTAL_E2E_SKIP_MOBILE || true
  fi
  if [[ "$SKIP_CORRECT_ABORT" -eq 1 ]]; then
    export PORTAL_E2E_SKIP_CORRECT_ABORT=1
  else
    unset PORTAL_E2E_SKIP_CORRECT_ABORT || true
  fi
  NODE_BIN="$(command -v node || true)"
  if [[ -z "$NODE_BIN" ]]; then
    # Prefer host node; fall back to cursor-agent node if present
    for cand in /usr/bin/node /usr/local/bin/node \
      "$HOME/.local/share/cursor-agent/versions"/*/node; do
      if [[ -x "$cand" ]]; then NODE_BIN="$cand"; break; fi
    done
  fi
  if [[ -z "${NODE_BIN:-}" ]]; then
    fail "node not found"
    return 1
  fi
  info "node=$NODE_BIN damage=$WITH_DAMAGE ack=$WITH_ACK mobile=$((1-SKIP_MOBILE)) correct_abort=$((1-SKIP_CORRECT_ABORT))"
  "$NODE_BIN" "$ROOT/portal/e2e/browser-e2e.mjs"
}

require_stack
if [[ "$SKIP_ENSURE_USER" -eq 0 ]]; then
  ensure_portal_user
else
  info "skip ensure user"
fi
lookup_person_query
if [[ "$WITH_ACK" -eq 1 ]]; then
  lookup_ack_boat_hint
fi
install_e2e_deps

set +e
run_e2e
rc=$?
set -e
if [[ $rc -eq 0 ]]; then
  pass "browser e2e module exited 0"
else
  fail "browser e2e module exited $rc"
fi

echo
echo "==== SUMMARY ===="
echo "PASS=$PASS FAIL=$FAIL"
if [[ "$FAIL" -gt 0 || "$rc" -ne 0 ]]; then
  echo "OVERALL: FAIL"
  echo "CapRover / physical iOS/Android device QA still open."
  exit 1
fi
echo "OVERALL: PASS"
echo "CapRover / physical iOS/Android device QA still open (host Chrome E2E only)."
exit 0
