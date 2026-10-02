#!/usr/bin/env bash
# Post-deploy release gates for CapRover staging (HTTPS).
# Requires valid `caprover login` and a running efacloud-staging app.
# Portal trainer user + SynchControl data must exist on staging (see GO_LIVE.md §3).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

STAGING_BASE="${EFACLOUD_STAGING_BASE_URL:-https://efacloud-staging.project-park.de}"
PORTAL_ID="${PORTAL_LIVE_USER_ID:-902}"
PORTAL_PASSWORD="${PORTAL_LIVE_PASSWORD:-RehearsePortal1!}"
WITH_DAMAGE=0
WITH_ACK=0
SKIP_BROWSER=0

usage() {
  cat <<EOF
Usage: ./scripts/caprover-staging-release-gates.sh [options]

Runs CapRover staging health probes + remote portal API/Chrome gates.

Options:
  --base-url URL     Staging HTTPS base (default: ${STAGING_BASE})
  --portal-id N      Portal user (default ${PORTAL_ID})
  --password STR     Portal password
  --with-damage      Extended browser E2E (damage severities)
  --with-ack         Extended browser E2E (ACK + ACK_STALE)
  --skip-browser     API smoke + HTTPS cookies only (no Chrome)
  -h, --help

Prerequisites:
  1. ./scripts/caprover-staging-bootstrap.sh --auto-create  (deploy done)
  2. Staging DB: bths + portal trainer; desktop SynchControl upload completed
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --base-url) STAGING_BASE="${2:-}"; shift 2 ;;
    --portal-id) PORTAL_ID="${2:-}"; shift 2 ;;
    --password) PORTAL_PASSWORD="${2:-}"; shift 2 ;;
    --with-damage) WITH_DAMAGE=1; shift ;;
    --with-ack) WITH_ACK=1; shift ;;
    --skip-browser) SKIP_BROWSER=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) echo "Unknown argument: $1" >&2; usage >&2; exit 2 ;;
  esac
done

STAGING_BASE="${STAGING_BASE%/}"
export EFACLOUD_STAGING_BASE_URL="$STAGING_BASE"
export PORTAL_LIVE_BASE_URL="$STAGING_BASE"
export PORTAL_LIVE_USER_ID="$PORTAL_ID"
export PORTAL_LIVE_PASSWORD="$PORTAL_PASSWORD"

echo "== caprover-staging-release-gates =="
echo "STAGING_BASE=$STAGING_BASE PORTAL_ID=$PORTAL_ID"
date -u +'%Y-%m-%dT%H:%M:%SZ'

echo
echo "== CapRover auth + staging health =="
EFACLOUD_STAGING_BASE_URL="$STAGING_BASE" \
  ./scripts/caprover-staging-bootstrap.sh --skip-deploy

echo
echo "== HTTPS Secure session cookie =="
hdr="$(curl -sS -D - -o /tmp/staging-login.json \
  -X POST "$STAGING_BASE/api/portal/v1/login" \
  -H 'Content-Type: application/json' \
  -d "{\"userId\":${PORTAL_ID},\"password\":\"${PORTAL_PASSWORD}\"}" || true)"
if echo "$hdr" | grep -qi 'Set-Cookie:.*Secure'; then
  echo "  PASS  login Set-Cookie includes Secure"
else
  echo "  FAIL  login Set-Cookie missing Secure (check Force HTTPS / proxy)" >&2
  echo "$hdr" | grep -i set-cookie || true
  exit 1
fi
if grep -q '"ok"[[:space:]]*:[[:space:]]*true' /tmp/staging-login.json 2>/dev/null; then
  echo "  PASS  login JSON ok"
else
  echo "  FAIL  login JSON (create portal user on staging first)" >&2
  head -c 400 /tmp/staging-login.json >&2 || true
  exit 1
fi

echo
echo "== portal API smoke (remote) =="
./scripts/portal-live-synch-smoke.sh \
  --base-url "$STAGING_BASE" \
  --remote \
  --skip-ensure-user

if [[ "$SKIP_BROWSER" -eq 0 ]]; then
  echo
  echo "== portal Chrome E2E (remote HTTPS) =="
  extra=()
  [[ "$WITH_DAMAGE" -eq 1 ]] && extra+=(--with-damage)
  [[ "$WITH_ACK" -eq 1 ]] && extra+=(--with-ack)
  ./scripts/portal-browser-e2e.sh \
    --base-url "$STAGING_BASE" \
    --remote \
    --skip-ensure-user \
    "${extra[@]}"
fi

echo
echo "OVERALL: PASS (CapRover staging software gates)."
echo "Still open: physical iOS/Android PWA QA, production promote, cutover."
