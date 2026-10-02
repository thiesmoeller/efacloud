#!/usr/bin/env bash
# Local EFA desktop → thiesmoeller-efacloud SynchControl lab (plan §4–5).
# Wires production-fork compose + desktop; does NOT touch parent docker-compose.test.yml.
# Prepares project URL/creds, ensures a bths sync account, verifies desktop→posttx.
# Optional --upload drives real RQ_QUEUE_START_SYNCH_UPLOAD_ALL via xdotool in the
# desktop container (see lab/desktop_synch/gui-synch-upload-all.sh).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PARENT="$(cd "$ROOT/.." && pwd)"
cd "$ROOT"

BACKUP_ZIP="${BACKUP_ZIP:-$PARENT/test_backup/efaBackup_20260607_095337.zip}"
BACKUP_DIR="$(dirname "$BACKUP_ZIP")"
HTTP_PORT="${EFACLOUD_HTTP_PORT:-18083}"
NOVNC_PORT="${EFA_NOVNC_PORT:-6081}"
VNC_PORT="${EFA_VNC_PORT:-5901}"
PROJECT="${COMPOSE_PROJECT_NAME:-efacloud-desktop-synch}"
BTHS_ID="${EFA_CLOUD_USERNAME:-901}"
BTHS_PASSWORD="${EFA_CLOUD_PASSWORD:-RehearseSync1!}"
TIMEOUT_SEC="${SYNCH_LAB_TIMEOUT:-420}"
DOWN_AFTER=0
DOWN_VOLUMES=0
BUILD_DESKTOP=1
SKIP_DESKTOP=0
PROBE_ONLY=0
UPLOAD_ONLY=0
DO_UPLOAD=0
VERIFY_ONLY=0

usage() {
  cat <<'EOF'
Usage: ./scripts/desktop-synch-lab.sh [options]

  --backup PATH     Desktop efaBackup_*.zip (default: parent test_backup zip)
  --port N          Host HTTP port for efaCloud web (default 18083; avoid 18081 rehearsal / 18082 restore-drill)
  --novnc-port N    Host noVNC port (default 6081)
  --project NAME    compose project name (default efacloud-desktop-synch)
  --bths-id N       Numeric efaCloud UserID / Rolle bths (default 901)
  --bths-password S Password for bths account (default RehearseSync1!)
  --no-build-desktop  Reuse existing efa-desktop:synch-lab image
  --skip-desktop    Start web/db only (cloud + bths user + host posttx probe)
  --probe-only      Assume stack is up; run posttx probe from desktop container
  --upload          After start (or with --upload-only): xdotool SYNCH_UPLOAD_ALL in desktop
  --upload-only     Assume stack is up; run GUI upload automation only
  --verify-only     Print MariaDB boat/person counts (expect ~58 / ~241 after upload)
  --down            docker compose down after printing next steps
  --down-volumes    docker compose down -v after run

Requires local .env. Never sets EFACLOUD_PORTAL_FIXTURES. Never bakes backup into images.
See docs/deploy/desktop-synch-lab.md.
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --backup) BACKUP_ZIP="${2:-}"; BACKUP_DIR="$(dirname "$BACKUP_ZIP")"; shift 2 ;;
    --port) HTTP_PORT="${2:-}"; shift 2 ;;
    --novnc-port) NOVNC_PORT="${2:-}"; shift 2 ;;
    --project) PROJECT="${2:-}"; shift 2 ;;
    --bths-id) BTHS_ID="${2:-}"; shift 2 ;;
    --bths-password) BTHS_PASSWORD="${2:-}"; shift 2 ;;
    --no-build-desktop) BUILD_DESKTOP=0; shift ;;
    --skip-desktop) SKIP_DESKTOP=1; shift ;;
    --probe-only) PROBE_ONLY=1; shift ;;
    --upload) DO_UPLOAD=1; shift ;;
    --upload-only) UPLOAD_ONLY=1; shift ;;
    --verify-only) VERIFY_ONLY=1; shift ;;
    --down) DOWN_AFTER=1; shift ;;
    --down-volumes) DOWN_AFTER=1; DOWN_VOLUMES=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) echo "Unknown argument: $1" >&2; usage >&2; exit 2 ;;
  esac
done

if [[ ! -f .env ]]; then
  echo "Missing .env — copy .env.example and set local-only passwords first." >&2
  exit 1
fi
if [[ ! -f "$BACKUP_ZIP" ]]; then
  echo "Backup zip not found: $BACKUP_ZIP" >&2
  exit 1
fi
if ! command -v docker >/dev/null 2>&1; then
  echo "docker is required" >&2
  exit 1
fi

unset EFACLOUD_PORTAL_FIXTURES || true
export EFACLOUD_HTTP_PORT="$HTTP_PORT"
export EFACLOUD_AUTO_INSTALL=1
export EFACLOUD_BASE_URL="${EFACLOUD_BASE_URL:-http://web}"
export COMPOSE_PROJECT_NAME="$PROJECT"
export EFA_TEST_BACKUP_DIR="$BACKUP_DIR"
export EFA_CLOUD_URL="${EFA_CLOUD_URL:-http://web/}"
export EFA_CLOUD_USERNAME="$BTHS_ID"
export EFA_CLOUD_PASSWORD="$BTHS_PASSWORD"
export EFA_NOVNC_PORT="$NOVNC_PORT"
export EFA_VNC_PORT="$VNC_PORT"
export EFA_DESKTOP_IMAGE="${EFA_DESKTOP_IMAGE:-efa-desktop:synch-lab}"

COMPOSE=(docker compose -p "$PROJECT" -f docker-compose.yml -f docker-compose.desktop-synch.yml)
BASE_URL="http://127.0.0.1:${HTTP_PORT}"

ensure_bths_user() {
  echo "== ensuring bths user $BTHS_ID (Rolle bths) =="
  # shellcheck disable=SC1091
  set -a; source .env; set +a
  DB_USER="${EFACLOUD_DB_USER:-efacloud}"
  DB_PASS="${EFACLOUD_DB_PASSWORD:-efacloud_dev_password}"
  DB_NAME="${EFACLOUD_DB_NAME:-efacloud}"
  hash="$("${COMPOSE[@]}" exec -T -e "P=${BTHS_PASSWORD}" web \
    php -r 'echo password_hash(getenv("P"), PASSWORD_DEFAULT);')"
  now_ms="$(($(date +%s) * 1000))"
  # Escape single quotes for SQL string literal
  hash_sql="${hash//\'/\'\'}"
  "${COMPOSE[@]}" exec -T db mariadb -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" <<SQL
DELETE FROM efaCloudUsers WHERE efaCloudUserID = ${BTHS_ID};
INSERT INTO efaCloudUsers
  (EMail, efaCloudUserID, efaAdminName, PersonId, Vorname, Nachname, Rolle,
   Subskriptionen, Workflows, Concessions, Passwort_Hash, LastModified)
VALUES
  ('bths-desktop-synch@example.test', ${BTHS_ID}, 'bths_desktop_synch', '',
   'Bths', 'DesktopSynch', 'bths', 0, 0, 0, '${hash_sql}', ${now_ms});
SELECT efaCloudUserID, Rolle, efaAdminName FROM efaCloudUsers WHERE efaCloudUserID=${BTHS_ID};
SQL
  echo "bths user ready: id=$BTHS_ID"
}

wait_web_ready() {
  echo "Waiting for efaCloud install readiness at $BASE_URL (timeout ${TIMEOUT_SEC}s)..."
  deadline=$((SECONDS + TIMEOUT_SEC))
  portal_code=""; login_code=""; session_code=""; install_code=""
  while (( SECONDS < deadline )); do
    portal_code="$(curl -sS -o /dev/null -w '%{http_code}' "$BASE_URL/portal/" || true)"
    login_code="$(curl -sS -o /dev/null -w '%{http_code}' "$BASE_URL/forms/login.php" || true)"
    session_code="$(curl -sS -o /dev/null -w '%{http_code}' "$BASE_URL/api/portal/v1/session" || true)"
    install_code="$(curl -sS -o /dev/null -w '%{http_code}' "$BASE_URL/install/setup_db_connection.php" || true)"
    # Static /portal/ can be 200 before bootstrap finishes — require installer locked (403).
    if [[ "$portal_code" == "200" && "$login_code" == "200" && "$session_code" == "200" && "$install_code" == "403" ]]; then
      echo "web ready (portal=$portal_code login=$login_code session=$session_code install=$install_code)"
      return 0
    fi
    sleep 3
  done
  echo "Timed out waiting for web readiness" >&2
  echo "Last codes: portal=$portal_code login=$login_code session=$session_code install=$install_code" >&2
  "${COMPOSE[@]}" ps || true
  "${COMPOSE[@]}" logs --tail 80 web || true
  return 1
}

print_next_steps() {
  cat <<EOF

== desktop SynchControl lab is up ==
  efaCloud:  $BASE_URL/forms/login.php
  noVNC:     http://127.0.0.1:${NOVNC_PORT}/vnc.html
  VNC:       127.0.0.1:${VNC_PORT}
  posttx:    ${EFA_CLOUD_URL}api/posttx.php  (from desktop network)
  account:   UserID ${BTHS_ID} / Rolle bths / password lab default

Project file was retargeted to ${EFA_CLOUD_URL} with StorageType=file/efaCloud
(backup already had efaCloud activated against production — URL/creds rewritten).

SYNCH_UPLOAD_ALL (real desktop path):
  ./scripts/desktop-synch-lab.sh --upload-only
  # or noVNC: docs/deploy/desktop-synch-lab.md

Verify counts:
  ./scripts/desktop-synch-lab.sh --verify-only

Tear down:
  ${COMPOSE[*]} down
  ${COMPOSE[*]} down -v   # wipe MariaDB + desktop home volumes
EOF
}

verify_mariadb_counts() {
  # shellcheck disable=SC1091
  set -a
  # Prefer compose env; fall back to .env
  # shellcheck source=/dev/null
  source .env
  set +a
  echo "== MariaDB counts (project $PROJECT) =="
  "${COMPOSE[@]}" exec -T db \
    mariadb -u"$EFACLOUD_DB_USER" -p"$EFACLOUD_DB_PASSWORD" "$EFACLOUD_DB_NAME" -e "
SELECT COUNT(*) AS boat_rows FROM efa2boats;
SELECT COUNT(DISTINCT Id) AS current_boats FROM efa2boats
  WHERE CAST(ValidFrom AS UNSIGNED) <= UNIX_TIMESTAMP()*1000
    AND CAST(InvalidFrom AS UNSIGNED) > UNIX_TIMESTAMP()*1000;
SELECT COUNT(*) AS person_rows FROM efa2persons;
SELECT COUNT(DISTINCT Id) AS current_persons FROM efa2persons
  WHERE CAST(ValidFrom AS UNSIGNED) <= UNIX_TIMESTAMP()*1000
    AND CAST(InvalidFrom AS UNSIGNED) > UNIX_TIMESTAMP()*1000;
"
}

run_gui_upload() {
  echo "== GUI SYNCH_UPLOAD_ALL (xdotool in efa-desktop) =="
  # Ensure helpers exist (fresh containers may lack apt packages)
  "${COMPOSE[@]}" exec -T efa-desktop bash -lc '
    export DEBIAN_FRONTEND=noninteractive
    if ! command -v xdotool >/dev/null 2>&1; then
      apt-get update -qq && apt-get install -y -qq xdotool imagemagick tesseract-ocr
    fi
    if [[ -x /usr/local/bin/gui-synch-upload-all ]]; then
      sh /usr/local/bin/gui-synch-upload-all
    elif [[ -x /usr/local/bin/gui-synch-upload-all.sh ]]; then
      sh /usr/local/bin/gui-synch-upload-all.sh
    else
      echo "missing gui-synch-upload-all(.sh) in container" >&2
      ls -la /usr/local/bin/ >&2 || true
      exit 1
    fi
  '
  verify_mariadb_counts
}

if [[ "$VERIFY_ONLY" == "1" ]]; then
  verify_mariadb_counts
  exit 0
fi

if [[ "$PROBE_ONLY" == "1" ]]; then
  echo "== probe-only =="
  "${COMPOSE[@]}" exec -T \
    -e "EFA_CLOUD_URL=${EFA_CLOUD_URL}" \
    -e "EFA_CLOUD_USERNAME=${BTHS_ID}" \
    -e "EFA_CLOUD_PASSWORD=${BTHS_PASSWORD}" \
    efa-desktop sh /usr/local/bin/efa-posttx-probe
  exit $?
fi

if [[ "$UPLOAD_ONLY" == "1" ]]; then
  run_gui_upload
  exit $?
fi

echo "== desktop synch lab =="
echo "PROJECT=$PROJECT BASE_URL=$BASE_URL BACKUP_ZIP=$BACKUP_ZIP BTHS=$BTHS_ID"

if [[ "$BUILD_DESKTOP" == "1" && "$SKIP_DESKTOP" != "1" ]]; then
  # Ensure JRE-compatible posttx probe class exists
  if [[ ! -f "$ROOT/lab/desktop_synch/bin/PosttxProbe.class" ]]; then
    echo "Compiling lab/desktop_synch/PosttxProbe.java for Java 17 ..."
    mkdir -p "$ROOT/lab/desktop_synch/bin"
    # Always compile with Temurin 17 — host javac may be newer than desktop JRE.
    docker run --rm \
      -v "$ROOT/lab/desktop_synch/PosttxProbe.java:/src/PosttxProbe.java:ro" \
      -v "$ROOT/lab/desktop_synch/bin:/out" \
      eclipse-temurin:17-jdk-jammy \
      javac --release 17 -d /out /src/PosttxProbe.java
  fi
  if ! docker image inspect "$EFA_DESKTOP_IMAGE" >/dev/null 2>&1; then
    echo "Building desktop image $EFA_DESKTOP_IMAGE (javac of efa/ — can take several minutes)..."
    docker build -t "$EFA_DESKTOP_IMAGE" -f "$PARENT/docker/efa-desktop/Dockerfile" "$PARENT"
  else
    echo "Reusing existing image $EFA_DESKTOP_IMAGE"
  fi
fi

echo "Starting compose (auto-install web + optional desktop)..."
if [[ "$SKIP_DESKTOP" == "1" ]]; then
  "${COMPOSE[@]}" up --build -d db web
else
  "${COMPOSE[@]}" up --build -d
fi

wait_web_ready
ensure_bths_user

# Host-side posttx probe (confirms credentials before/without desktop)
echo "== host posttx auth probe =="
HOST_PROBE_RC=0
python3 - <<PY || HOST_PROBE_RC=$?
import os, sys
sys.path.insert(0, "$ROOT/lab/backup_rehearsal")
from posttx_client import PosttxClient, parse_container_response
c = PosttxClient("$BASE_URL", user_id=int("$BTHS_ID"), password="$BTHS_PASSWORD")
raw = c.nop_session()
parsed = parse_container_response(raw)
print("decoded:", (parsed.get("decoded") or raw)[:400])
if parsed.get("ok") or "Authentication failed" not in (parsed.get("decoded") or raw):
    # ok flag checks tx result codes; NOP may still auth
    if "Authentication failed" in (parsed.get("decoded") or raw):
        print("FAIL auth")
        sys.exit(2)
    print("PASS host posttx auth")
else:
    print("FAIL", parsed)
    sys.exit(2)
PY

if [[ "$SKIP_DESKTOP" != "1" ]]; then
  echo "Waiting for desktop container to finish prepare/patch..."
  # Give entrypoint time to prepare backup + probe
  sleep 8
  echo "== desktop container posttx probe =="
  "${COMPOSE[@]}" exec -T \
    -e "EFA_CLOUD_URL=${EFA_CLOUD_URL}" \
    -e "EFA_CLOUD_USERNAME=${BTHS_ID}" \
    -e "EFA_CLOUD_PASSWORD=${BTHS_PASSWORD}" \
    efa-desktop sh /usr/local/bin/efa-posttx-probe || true

  echo "== patched project fields inside desktop volume =="
  "${COMPOSE[@]}" exec -T efa-desktop sh -lc \
    'grep -E "<StorageType>|<StorageUsername>|<EfaCloudURL>" /home/efa/efa2/data/*.efa2project 2>/dev/null || true'
fi

if [[ "$DO_UPLOAD" == "1" ]]; then
  # Give TxRequestQueue time to AUTHENTICATE → WORKING after first boot
  echo "Waiting for desktop TxRequestQueue IDLE/WORKING before GUI upload…"
  sleep 25
  run_gui_upload
fi

print_next_steps

if [[ "$DOWN_AFTER" == "1" ]]; then
  if [[ "$DOWN_VOLUMES" == "1" ]]; then
    "${COMPOSE[@]}" down -v
  else
    "${COMPOSE[@]}" down
  fi
fi
