#!/usr/bin/env bash
# Portal API smoke against sanitized fixtures (no club PII, no CapRover).
# Default: start a short-lived PHP server with EFACLOUD_PORTAL_FIXTURES=1.
# Or pass --base-url http://127.0.0.1:8080 to hit an already-running compose stack.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

BASE_URL=""
PORT="${PORTAL_FIXTURE_SMOKE_PORT:-8091}"
STARTED_SERVER=0
COOKIE_JAR=""
SERVER_PID=""
CONTAINER_ID=""

cleanup() {
  if [[ -n "$COOKIE_JAR" && -f "$COOKIE_JAR" ]]; then
    rm -f "$COOKIE_JAR"
  fi
  if [[ -n "$SERVER_PID" ]]; then
    kill "$SERVER_PID" 2>/dev/null || true
    wait "$SERVER_PID" 2>/dev/null || true
  fi
  if [[ -n "$CONTAINER_ID" ]]; then
    docker stop "$CONTAINER_ID" >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

usage() {
  cat <<'EOF'
Usage: ./scripts/portal-fixture-smoke.sh [--base-url URL]

  (default)  Start php -S with fixtures and smoke the portal API
  --base-url Hit an existing server (e.g. compose + portal-fixtures overlay)

Fixture accounts: 101 / fixture-pass (member), 102 / fixture-pass (trainer)
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --base-url)
      BASE_URL="${2:-}"
      shift 2
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "Unknown argument: $1" >&2
      usage >&2
      exit 2
      ;;
  esac
done

if [[ ! -f fixtures/sanitized/boats.json ]]; then
  echo "Missing fixtures/sanitized/boats.json" >&2
  exit 1
fi

start_local_server() {
  export EFACLOUD_PORTAL_FIXTURES=1
  if command -v php >/dev/null 2>&1; then
    php -S "127.0.0.1:${PORT}" -t "$ROOT" "$ROOT/scripts/portal-fixture-router.php" \
      >/tmp/portal-fixture-smoke-php.log 2>&1 &
    SERVER_PID=$!
  elif command -v docker >/dev/null 2>&1; then
    CONTAINER_ID="$(docker run -d --rm \
      -e EFACLOUD_PORTAL_FIXTURES=1 \
      -p "127.0.0.1:${PORT}:8091" \
      -v "$ROOT:/app" -w /app \
      php:8.2-cli \
      php -S 0.0.0.0:8091 -t /app /app/scripts/portal-fixture-router.php)"
  else
    echo "Need php or docker to start the fixture server" >&2
    exit 1
  fi
  BASE_URL="http://127.0.0.1:${PORT}"
  STARTED_SERVER=1
  # Wait for listen
  for _ in $(seq 1 60); do
    if curl -fsS -o /dev/null "$BASE_URL/api/portal/v1/session" 2>/dev/null; then
      return 0
    fi
    sleep 0.5
  done
  echo "Fixture server did not become ready at $BASE_URL" >&2
  if [[ -f /tmp/portal-fixture-smoke-php.log ]]; then
    tail -n 40 /tmp/portal-fixture-smoke-php.log >&2 || true
  fi
  exit 1
}

if [[ -z "$BASE_URL" ]]; then
  start_local_server
fi

COOKIE_JAR="$(mktemp)"
API="$BASE_URL/api/portal/v1"

json_get() {
  # json_get <file> <python-ish path via php -r or grep fallback>
  local file="$1"
  local key="$2"
  if command -v php >/dev/null 2>&1; then
    php -r '$j=json_decode(file_get_contents($argv[1]), true); $k=$argv[2]; $p=explode(".", $k); $c=$j; foreach($p as $x){ if(!is_array($c)||!array_key_exists($x,$c)){fwrite(STDERR,"missing $k\n"); exit(1);} $c=$c[$x]; } if(is_bool($c)){echo $c?"true":"false";} elseif(is_scalar($c)){echo $c;} else {echo json_encode($c);} ' "$file" "$key"
  elif command -v docker >/dev/null 2>&1; then
    docker run --rm -v "$file:/data.json:ro" php:8.2-cli \
      php -r '$j=json_decode(file_get_contents("/data.json"), true); $k=$argv[1]; $p=explode(".", $k); $c=$j; foreach($p as $x){ if(!is_array($c)||!array_key_exists($x,$c)){fwrite(STDERR,"missing\n"); exit(1);} $c=$c[$x]; } if(is_bool($c)){echo $c?"true":"false";} elseif(is_scalar($c)){echo $c;} else {echo json_encode($c);} ' "$key"
  else
    echo "Need php or docker to parse JSON" >&2
    exit 1
  fi
}

fail() {
  echo "FAIL: $*" >&2
  exit 1
}

pass() {
  echo "OK: $*"
}

echo "== portal fixture smoke against $API =="

# Unauthenticated session
curl -fsS -c "$COOKIE_JAR" -b "$COOKIE_JAR" "$API/session" -o /tmp/portal-smoke-session.json \
  || fail "GET /session"
AUTH="$(json_get /tmp/portal-smoke-session.json authenticated || true)"
[[ "$AUTH" == "false" ]] || fail "expected authenticated=false before login (got $AUTH)"
pass "GET /session (anonymous)"

# Login member 101
curl -fsS -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
  -H 'Content-Type: application/json' \
  -d '{"account":"101","password":"fixture-pass"}' \
  "$API/session/login" -o /tmp/portal-smoke-login.json \
  || fail "POST /session/login"
AUTH="$(json_get /tmp/portal-smoke-login.json authenticated)"
CSRF="$(json_get /tmp/portal-smoke-login.json csrfToken)"
[[ "$AUTH" == "true" ]] || fail "login authenticated"
[[ -n "$CSRF" && "$CSRF" != "null" ]] || fail "csrfToken missing"
pass "login member 101"

# Lookups
curl -fsS -c "$COOKIE_JAR" -b "$COOKIE_JAR" "$API/boats?view=available" -o /tmp/portal-smoke-boats.json \
  || fail "GET /boats"
curl -fsS -c "$COOKIE_JAR" -b "$COOKIE_JAR" "$API/boats?view=onwater" -o /tmp/portal-smoke-onwater.json \
  || fail "GET /boats?view=onwater"
curl -fsS -c "$COOKIE_JAR" -b "$COOKIE_JAR" "$API/persons" -o /tmp/portal-smoke-persons.json \
  || fail "GET /persons"
curl -fsS -c "$COOKIE_JAR" -b "$COOKIE_JAR" "$API/destinations" -o /tmp/portal-smoke-dest.json \
  || fail "GET /destinations"
curl -fsS -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
  "$API/boats/11111111-1111-4111-a111-111111111107/damages" \
  -o /tmp/portal-smoke-damages.json \
  || fail "GET boat damages"
TRIP_CODE="$(curl -sS -o /tmp/portal-smoke-trip.json -w '%{http_code}' -c "$COOKIE_JAR" -b "$COOKIE_JAR" "$API/trips/3")"
[[ "$TRIP_CODE" == "403" ]] || fail "unattributed trip must not be portal-managed (got $TRIP_CODE)"
curl -fsS -c "$COOKIE_JAR" -b "$COOKIE_JAR" "$API/trips?scope=started-by-me&status=open" -o /tmp/portal-smoke-my-trips.json \
  || fail "GET personal trips"
[[ "$(json_get /tmp/portal-smoke-my-trips.json trips)" == "[]" ]] || fail "fixture desktop trips must not enter personal list"
pass "unattributed trip forbidden; personal list empty"

# Minimal structural assertions (counts > 0)
python_assert() {
  if command -v php >/dev/null 2>&1; then
    php -r 'exit((($n=count(json_decode(file_get_contents($argv[1]),true)[$argv[2]]??[]))>0)?0:1);' "$1" "$2"
  else
    docker run --rm -v "$1:/data.json:ro" php:8.2-cli \
      php -r 'exit((($n=count(json_decode(file_get_contents("/data.json"),true)[$argv[1]]??[]))>0)?0:1);' "$2"
  fi
}

python_assert /tmp/portal-smoke-boats.json boats || fail "expected available boats"
python_assert /tmp/portal-smoke-onwater.json boats || fail "expected on-water boats"
python_assert /tmp/portal-smoke-persons.json persons || fail "expected persons"
python_assert /tmp/portal-smoke-dest.json destinations || fail "expected destinations"
pass "boats / persons / destinations / on-water"

# Damage report must not abort trips
curl -fsS -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
  -H 'Content-Type: application/json' \
  -H "X-CSRF-Token: $CSRF" \
  -d '{"severity":"LIMITEDUSEABLE","description":"Smoke Testschaden","csrfToken":"'"$CSRF"'"}' \
  "$API/boats/11111111-1111-4111-a111-111111111105/damages" \
  -o /tmp/portal-smoke-dmg-post.json \
  || fail "POST damage"
ABORTED="$(json_get /tmp/portal-smoke-dmg-post.json tripAborted)"
[[ "$ABORTED" == "false" ]] || fail "damage must not abort trips (tripAborted=$ABORTED)"
pass "damage report (tripAborted=false)"

# Start trip on available Albatros (member is crew).
# Note: Portal_fixture_store is per-request over HTTP, so mutations do not persist
# across calls and idempotent replay cannot be proven here — that stays in
# ./tests/portal/run.sh (in-process). This checks the start response shape only.
IDEMP="smoke-$(date +%s)-$$"
curl -fsS -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
  -H 'Content-Type: application/json' \
  -H "X-CSRF-Token: $CSRF" \
  -d '{"boatId":"11111111-1111-4111-a111-111111111101","crew":[{"id":"22222222-2222-4222-a222-222222222201"}],"destinationId":"33333333-3333-4333-a333-333333333302","destinationName":"Flussrunde","idempotencyKey":"'"$IDEMP"'","csrfToken":"'"$CSRF"'"}' \
  "$API/trips" \
  -o /tmp/portal-smoke-start.json \
  || fail "POST /trips start"
ENTRY="$(json_get /tmp/portal-smoke-start.json trip.entryId)"
STATUS="$(json_get /tmp/portal-smoke-start.json boatStatus.CurrentStatus)"
[[ -n "$ENTRY" && "$ENTRY" != "null" ]] || fail "start missing entryId"
[[ "$STATUS" == "ONTHEWATER" ]] || fail "expected boatStatus ONTHEWATER after start"
pass "trip start entryId=$ENTRY status=$STATUS"

# Logout
curl -fsS -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
  -H 'Content-Type: application/json' \
  -H "X-CSRF-Token: $CSRF" \
  -d '{"csrfToken":"'"$CSRF"'"}' \
  "$API/session/logout" -o /tmp/portal-smoke-logout.json \
  || fail "logout"
pass "logout"

echo
echo "Portal fixture smoke passed (sanitized fixtures only)."
if [[ "$STARTED_SERVER" -eq 1 ]]; then
  echo "Started ephemeral server at $BASE_URL (stopped on exit)."
fi
