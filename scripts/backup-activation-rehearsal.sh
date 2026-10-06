#!/usr/bin/env bash
# Local desktop-backup → cloud activation rehearsal on docker compose MariaDB.
# Does NOT enable EFACLOUD_PORTAL_FIXTURES. Does NOT claim CapRover production.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

BACKUP_ZIP="${BACKUP_ZIP:-/home/thies/Projects/efa_new/test_backup/efaBackup_20260607_095337.zip}"
EXTRACT_DIR="${EXTRACT_DIR:-}"
HTTP_PORT="${EFACLOUD_HTTP_PORT:-18081}"
PROJECT="${COMPOSE_PROJECT_NAME:-efacloud-rehearsal}"
DOWN_AFTER=0
DOWN_VOLUMES=0
TIMEOUT_SEC="${REHEARSAL_TIMEOUT:-420}"
KEEP_EXTRACT=0
SKIP_LOAD=0
OVERRIDE_FILE="$ROOT/.compose.rehearsal.override.yml"

usage() {
  cat <<'EOF'
Usage: ./scripts/backup-activation-rehearsal.sh [options]

  --backup PATH       Desktop efaBackup_*.zip (default: parent test_backup zip)
  --extract-dir PATH  Already-extracted backup root (skips unzip)
  --port N            Host HTTP port (default 18081)
  --project NAME      compose project name (default efacloud-rehearsal)
  --down              docker compose down after run
  --down-volumes      docker compose down -v after run (clean CI-ish)
  --keep-extract      do not rm temp extract dir
  --skip-load         skip DB load (exercise only)

Requires local .env (from .env.example). Never sets EFACLOUD_PORTAL_FIXTURES.
Never commits backup zip / club PII.
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --backup) BACKUP_ZIP="${2:-}"; shift 2 ;;
    --extract-dir) EXTRACT_DIR="${2:-}"; shift 2 ;;
    --port) HTTP_PORT="${2:-}"; shift 2 ;;
    --project) PROJECT="${2:-}"; shift 2 ;;
    --down) DOWN_AFTER=1; shift ;;
    --down-volumes) DOWN_AFTER=1; DOWN_VOLUMES=1; shift ;;
    --keep-extract) KEEP_EXTRACT=1; shift ;;
    --skip-load) SKIP_LOAD=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) echo "Unknown argument: $1" >&2; usage >&2; exit 2 ;;
  esac
done

if [[ ! -f .env ]]; then
  echo "Missing .env — copy .env.example and set local-only passwords first." >&2
  exit 1
fi
if [[ -z "$EXTRACT_DIR" && ! -f "$BACKUP_ZIP" ]]; then
  echo "Backup zip not found: $BACKUP_ZIP" >&2
  exit 1
fi
if ! command -v docker >/dev/null 2>&1; then
  echo "docker is required" >&2
  exit 1
fi
if ! command -v python3 >/dev/null 2>&1; then
  echo "python3 is required" >&2
  exit 1
fi

# Hard refuse fixtures for this path
unset EFACLOUD_PORTAL_FIXTURES || true
export EFACLOUD_HTTP_PORT="$HTTP_PORT"
export EFACLOUD_AUTO_INSTALL=1
export EFACLOUD_BASE_URL="${EFACLOUD_BASE_URL:-http://127.0.0.1}"
export COMPOSE_PROJECT_NAME="$PROJECT"
BASE_URL="http://127.0.0.1:${HTTP_PORT}"

# Local mounts: sanitized club-config for portal defaults; lab helpers for exec if needed.
# Fixtures are mounted read-only for club-config.json ONLY — portal fixtures mode stays OFF.
cat > "$OVERRIDE_FILE" <<EOF
services:
  web:
    volumes:
      - ${ROOT}/fixtures/sanitized:/var/www/html/fixtures/sanitized:ro
      - ${ROOT}/lab:/var/www/html/lab:ro
    environment:
      EFACLOUD_PORTAL_FIXTURES: "0"
EOF

cleanup() {
  rm -f "$OVERRIDE_FILE"
  if [[ "$KEEP_EXTRACT" != "1" && -n "${TMP_EXTRACT:-}" && -d "${TMP_EXTRACT:-}" ]]; then
    rm -rf "$TMP_EXTRACT"
  fi
}
trap cleanup EXIT

echo "== backup activation rehearsal =="
echo "PROJECT=$PROJECT BASE_URL=$BASE_URL BACKUP_ZIP=$BACKUP_ZIP"

if [[ -z "$EXTRACT_DIR" ]]; then
  TMP_EXTRACT="$(mktemp -d /tmp/efa_bk_rehearsal_XXXXXX)"
  echo "Extracting backup to $TMP_EXTRACT (not committed; normalize Windows paths)..."
  python3 - <<PY
from pathlib import Path
import sys
sys.path.insert(0, ${ROOT@Q} + "/lab/backup_rehearsal")
from efa2xml import locate_extract_or_unzip
locate_extract_or_unzip(Path(${BACKUP_ZIP@Q}), Path(${TMP_EXTRACT@Q}))
print("extracted ok")
PY
  EXTRACT_DIR="$TMP_EXTRACT"
else
  echo "Using extract dir $EXTRACT_DIR"
fi

echo "Starting compose (auto-install)..."
docker compose -p "$PROJECT" -f docker-compose.yml -f "$OVERRIDE_FILE" up --build -d

echo "Waiting for install readiness (timeout ${TIMEOUT_SEC}s)..."
deadline=$((SECONDS + TIMEOUT_SEC))
ready=0
portal_code=""; login_code=""; session_code=""; install_code=""
while (( SECONDS < deadline )); do
  portal_code="$(curl -sS -o /dev/null -w '%{http_code}' "$BASE_URL/portal/" || true)"
  login_code="$(curl -sS -o /dev/null -w '%{http_code}' "$BASE_URL/forms/login.php" || true)"
  session_code="$(curl -sS -o /dev/null -w '%{http_code}' "$BASE_URL/api/portal/v1/session" || true)"
  install_code="$(curl -sS -o /dev/null -w '%{http_code}' "$BASE_URL/install/setup_db_connection.php" || true)"
  # Static /portal/ can be 200 before bootstrap finishes — require installer locked (403)
  # and public login + portal session API.
  if [[ "$portal_code" == "200" && "$login_code" == "200" && "$session_code" == "200" && "$install_code" == "403" ]]; then
    ready=1
    break
  fi
  sleep 3
done

if [[ "$ready" != "1" ]]; then
  echo "Compose readiness timed out (need portal/login/session 200 and install 403)." >&2
  echo "Last codes: portal=$portal_code login=$login_code session=$session_code install=$install_code" >&2
  docker compose -p "$PROJECT" logs --tail=120 web db >&2 || true
  exit 1
fi
echo "OK: compose ready (portal=$portal_code session=$session_code install=$install_code)"

# Sanity: fixtures mode must not be active (session without auth should be unauthenticated JSON)
sess_json="$(curl -fsS "$BASE_URL/api/portal/v1/session")"
if echo "$sess_json" | grep -qi 'fixture'; then
  echo "REFUSE: portal session looks fixture-backed" >&2
  exit 2
fi

PY_ARGS=(
  --backup-zip "$BACKUP_ZIP"
  --extract-dir "$EXTRACT_DIR"
  --base-url "$BASE_URL"
  --compose-project "$PROJECT"
)
if [[ "$SKIP_LOAD" == "1" ]]; then
  PY_ARGS+=(--skip-load)
fi

set +e
python3 "$ROOT/lab/backup_rehearsal/rehearse.py" "${PY_ARGS[@]}"
RC=$?
set -e

if [[ "$DOWN_AFTER" == "1" ]]; then
  if [[ "$DOWN_VOLUMES" == "1" ]]; then
    docker compose -p "$PROJECT" -f docker-compose.yml -f "$OVERRIDE_FILE" down -v
  else
    docker compose -p "$PROJECT" -f docker-compose.yml -f "$OVERRIDE_FILE" down
  fi
fi

exit "$RC"
