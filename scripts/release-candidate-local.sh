#!/usr/bin/env bash
# Local release-candidate verification — fail-fast gates, summary table.
# Does NOT deploy CapRover. Heavy backup/restore drill is behind --full.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

FULL=0
SMOKE_PORT="${EFACLOUD_RC_SMOKE_PORT:-18084}"
DESKTOP_PROJECT="${COMPOSE_PROJECT_NAME_DESKTOP:-efacloud-desktop-synch}"
DESKTOP_BASE_URL="${PORTAL_LIVE_BASE_URL:-http://127.0.0.1:18083}"

usage() {
  cat <<'EOF'
Usage: ./scripts/release-candidate-local.sh [options]

Local release-candidate gates (fail-fast). CapRover deploys are out of scope.

  --full    Also run compose-backup-restore-drill.sh (heavier; optional)
  -h|--help This help

Default gates:
  1. ./tests/security/run.sh
  2. ./tests/portal/run.sh
  3. cd portal && npm run test && npm run build
  4. compose-local-smoke.sh --down (port EFACLOUD_RC_SMOKE_PORT, default 18084)
  5. SKIP backup/restore drill unless --full
  6. If efacloud-desktop-synch is up on :18083: live synch / conflict / browser E2E
     else SKIP with how to start the lab

Environment:
  EFACLOUD_RC_SMOKE_PORT   Host port for compose smoke (default 18084)
  PORTAL_LIVE_BASE_URL     Desktop-synch base URL (default http://127.0.0.1:18083)
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --full) FULL=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) echo "Unknown argument: $1" >&2; usage >&2; exit 2 ;;
  esac
done

# Results: name|status|detail (status = PASS|FAIL|SKIP)
declare -a RC_ROWS=()
RC_FAILED=0

rc_record() {
  local name="$1" status="$2" detail="${3:-}"
  RC_ROWS+=("${name}|${status}|${detail}")
  printf '[%s] %s' "$status" "$name"
  if [[ -n "$detail" ]]; then
    printf ' — %s' "$detail"
  fi
  printf '\n'
}

rc_run() {
  local name="$1"
  shift
  echo
  echo "======== $name ========"
  local start=$SECONDS
  set +e
  "$@"
  local code=$?
  set -e
  local elapsed=$((SECONDS - start))
  if [[ $code -eq 0 ]]; then
    rc_record "$name" "PASS" "${elapsed}s"
  else
    rc_record "$name" "FAIL" "exit $code (${elapsed}s)"
    RC_FAILED=1
    return 1
  fi
}

desktop_synch_up() {
  # Prefer compose project containers; also accept HTTP readiness on live URL.
  if docker compose -p "$DESKTOP_PROJECT" ps --status running 2>/dev/null | grep -qE 'web|db'; then
    local code
    code="$(curl -sS -o /dev/null -w '%{http_code}' "${DESKTOP_BASE_URL}/api/portal/v1/session" 2>/dev/null || true)"
    [[ "$code" == "200" ]]
    return $?
  fi
  return 1
}

print_summary() {
  echo
  echo "======== Release-candidate local summary ========"
  printf '%-44s %-6s %s\n' "GATE" "STATUS" "DETAIL"
  printf '%-44s %-6s %s\n' "--------------------------------------------" "------" "------"
  local row name status detail
  for row in "${RC_ROWS[@]}"; do
    IFS='|' read -r name status detail <<<"$row"
    printf '%-44s %-6s %s\n' "$name" "$status" "$detail"
  done
  echo
  if [[ $RC_FAILED -eq 0 ]]; then
    echo "OVERALL: PASS (local gates that ran). CapRover / device / ops still open."
  else
    echo "OVERALL: FAIL — fix FAIL rows above before claiming RC readiness."
  fi
  echo "Note: \`caprover list\` can show a machine while the API token is expired — re-run \`caprover login\`."
}

trap print_summary EXIT

echo "== release-candidate local =="
echo "ROOT=$ROOT FULL=$FULL SMOKE_PORT=$SMOKE_PORT"
echo "DESKTOP_BASE_URL=$DESKTOP_BASE_URL"
date -u +'%Y-%m-%dT%H:%M:%SZ'

# --- 1. Security suite ---
rc_run "tests/security/run.sh" ./tests/security/run.sh || exit 1

# --- 2. Portal unit suite ---
rc_run "tests/portal/run.sh" ./tests/portal/run.sh || exit 1

# --- 3. Portal npm test + build ---
rc_run "portal npm test" bash -c 'cd portal && npm run test' || exit 1
rc_run "portal npm build" bash -c 'cd portal && npm run build' || exit 1

# --- 4. Compose local smoke (dedicated free port; tear down) ---
if [[ ! -f .env ]]; then
  rc_record "compose-local-smoke" "FAIL" "missing .env"
  RC_FAILED=1
  exit 1
fi
rc_run "compose-local-smoke (:${SMOKE_PORT})" \
  env EFACLOUD_HTTP_PORT="$SMOKE_PORT" COMPOSE_PROJECT_NAME="efacloud-rc-smoke" \
  ./scripts/compose-local-smoke.sh --down || exit 1

# --- 5. Backup/restore drill (optional / --full) ---
if [[ "$FULL" == "1" ]]; then
  rc_run "compose-backup-restore-drill" ./scripts/compose-backup-restore-drill.sh || exit 1
else
  rc_record "compose-backup-restore-drill" "SKIP" "pass --full (heavy; ~few min)"
fi

# --- 6. Desktop-synch live gates (if stack up) ---
if desktop_synch_up; then
  echo
  echo "Desktop-synch stack detected at $DESKTOP_BASE_URL — running live gates."
  rc_run "portal-live-synch-smoke" ./scripts/portal-live-synch-smoke.sh || exit 1
  rc_run "portal-desktop-conflict-race" ./scripts/portal-desktop-conflict-race.sh || exit 1
  rc_run "portal-browser-e2e" ./scripts/portal-browser-e2e.sh || exit 1
  # CapRover-like TLS termination → Secure session cookie
  rc_run "https-cookie-drill" \
    env COMPOSE_PROJECT_NAME="$DESKTOP_PROJECT" ./scripts/https-cookie-drill.sh --down || exit 1
else
  local_skip_msg="stack ${DESKTOP_PROJECT} not up on ${DESKTOP_BASE_URL}; start: ./scripts/desktop-synch-lab.sh --port 18083 (see docs/deploy/desktop-synch-lab.md)"
  rc_record "portal-live-synch-smoke" "SKIP" "$local_skip_msg"
  rc_record "portal-desktop-conflict-race" "SKIP" "$local_skip_msg"
  rc_record "portal-browser-e2e" "SKIP" "$local_skip_msg"
  rc_record "https-cookie-drill" "SKIP" "$local_skip_msg"
fi

# --- 7. CapRover auth probe (informational; does not fail local RC) ---
if [[ -x ./scripts/caprover-staging-bootstrap.sh ]]; then
  set +e
  ./scripts/caprover-staging-bootstrap.sh --skip-deploy --skip-health >/tmp/rc-caprover-probe.out 2>&1
  cr_ec=$?
  set -e
  if [[ $cr_ec -eq 0 ]]; then
    rc_record "caprover-staging-auth" "PASS" "token valid; run bootstrap without --skip-deploy to deploy"
  elif [[ $cr_ec -eq 2 ]]; then
    rc_record "caprover-staging-auth" "SKIP" "re-login required: caprover login (captain-project-park)"
  else
    rc_record "caprover-staging-auth" "SKIP" "probe exit $cr_ec — see /tmp/rc-caprover-probe.out"
  fi
else
  rc_record "caprover-staging-auth" "SKIP" "bootstrap script missing"
fi

exit 0
