#!/usr/bin/env bash
# Local docker compose install/health smoke — no CapRover credentials required.
# Uses this fork's docker-compose.yml only (not parent TEST_ENV / ROW R/8 harness).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

HTTP_PORT="${EFACLOUD_HTTP_PORT:-8080}"
BASE_URL="${COMPOSE_SMOKE_BASE_URL:-http://127.0.0.1:${HTTP_PORT}}"
AUTO_INSTALL=1
DOWN_AFTER=0
TIMEOUT_SEC="${COMPOSE_SMOKE_TIMEOUT:-300}"
export EFACLOUD_HTTP_PORT="$HTTP_PORT"

usage() {
  cat <<'EOF'
Usage: ./scripts/compose-local-smoke.sh [options]

  --auto-install     Set EFACLOUD_AUTO_INSTALL=1 for the smoke (default)
  --no-auto-install  Leave installer manual; only wait for HTTP on /install or /portal
  --down             docker compose down after success
  --base-url URL     Default http://127.0.0.1:8080

Requires a local .env (copy from .env.example). Does not import club backups.
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --auto-install) AUTO_INSTALL=1; shift ;;
    --no-auto-install) AUTO_INSTALL=0; shift ;;
    --down) DOWN_AFTER=1; shift ;;
    --base-url) BASE_URL="${2:-}"; shift 2 ;;
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

export EFACLOUD_AUTO_INSTALL="$AUTO_INSTALL"
# Inside the web container, bootstrap curls localhost; host probes use BASE_URL.
export EFACLOUD_BASE_URL="${EFACLOUD_BASE_URL:-http://127.0.0.1}"

echo "== compose local smoke =="
echo "BASE_URL=$BASE_URL AUTO_INSTALL=$AUTO_INSTALL"

docker compose up --build -d

echo "Waiting for readiness (timeout ${TIMEOUT_SEC}s)..."
deadline=$((SECONDS + TIMEOUT_SEC))
ready=0
while (( SECONDS < deadline )); do
  portal_code="$(curl -sS -o /dev/null -w '%{http_code}' "$BASE_URL/portal/" || true)"
  login_code="$(curl -sS -o /dev/null -w '%{http_code}' "$BASE_URL/forms/login.php" || true)"
  session_code="$(curl -sS -o /dev/null -w '%{http_code}' "$BASE_URL/api/portal/v1/session" || true)"
  install_code="$(curl -sS -o /dev/null -w '%{http_code}' "$BASE_URL/install/setup_db_connection.php" || true)"

  if [[ "$AUTO_INSTALL" == "1" ]]; then
    if [[ "$portal_code" == "200" && "$login_code" == "200" && "$session_code" == "200" ]]; then
      ready=1
      break
    fi
  else
    # Manual install path: installer reachable OR app already complete
    if [[ "$install_code" == "200" || "$portal_code" == "200" ]]; then
      ready=1
      break
    fi
  fi
  sleep 3
done

if [[ "$ready" != "1" ]]; then
  echo "Compose smoke timed out." >&2
  echo "Last codes: portal=$portal_code login=$login_code session=$session_code install=$install_code" >&2
  docker compose logs --tail=80 web db >&2 || true
  exit 1
fi

echo "OK: HTTP readiness (portal=$portal_code login=$login_code session=$session_code install=$install_code)"

if [[ "$AUTO_INSTALL" == "1" ]]; then
  # Confirm installer is locked
  if [[ "$install_code" == "200" ]]; then
    echo "WARN: /install/ still reachable after auto-install — check lock markers" >&2
  else
    echo "OK: installer not publicly serving setup (code=$install_code)"
  fi
  # Session JSON shape
  curl -fsS "$BASE_URL/api/portal/v1/session" -o /tmp/compose-smoke-session.json
  echo "OK: portal session endpoint responded"
fi

echo
echo "Compose local smoke passed."
echo "Note: this does not prove CapRover HTTPS, backups, or desktop sync."
echo "Fixture API smoke (separate): ./scripts/portal-fixture-smoke.sh"

if [[ "$DOWN_AFTER" == "1" ]]; then
  docker compose down
fi
