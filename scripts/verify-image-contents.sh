#!/usr/bin/env bash
# Verify a built efaCloud image contains release-critical portal/security pieces.
# Usage:
#   ./scripts/verify-image-contents.sh [image-tag]
#   VERIFY_IMAGE_BUILD=0 ./scripts/verify-image-contents.sh efacloud:local   # skip rebuild
#
# Checks (must be present in the runtime image):
#   - Efa_boat_concurrency_guard (desktop↔portal split-brain prevention)
#   - portal in Tfyh_audit public allowlist (dockside SPA not chmod 0700)
#   - X-Forwarded-Proto / request_is_https Secure cookie detection
#   - built /portal/ assets; no fixtures / node_modules / .env
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

IMAGE="${1:-efacloud:verify-contents}"
DO_BUILD="${VERIFY_IMAGE_BUILD:-1}"

PASS=0
FAIL=0
pass() { PASS=$((PASS + 1)); echo "  PASS  $*"; }
fail() { FAIL=$((FAIL + 1)); echo "  FAIL  $*"; }

if [[ "$DO_BUILD" == "1" ]]; then
  echo "== docker build -t $IMAGE . =="
  docker build -t "$IMAGE" .
  pass "image built"
else
  echo "== skip build (VERIFY_IMAGE_BUILD=0); using $IMAGE =="
  if ! docker image inspect "$IMAGE" >/dev/null 2>&1; then
    fail "image $IMAGE not found"
    exit 1
  fi
  pass "image present"
fi

echo "== runtime content checks =="
# Run checks inside a throwaway container (entrypoint would need DB; override).
docker run --rm --entrypoint sh "$IMAGE" -c '
set -e
fail=0
check() {
  if "$@"; then echo "OK: $*"; else echo "MISSING: $*"; fail=1; fi
}

check test -f /var/www/html/classes/efa_boat_concurrency_guard.php
check grep -q "class Efa_boat_concurrency_guard" /var/www/html/classes/efa_boat_concurrency_guard.php
check grep -q "Efa_boat_concurrency_guard" /var/www/html/classes/efa_api.php

check grep -q "\"portal\"" /var/www/html/classes/tfyh_audit.php
check grep -n "portal" /var/www/html/classes/tfyh_audit.php | grep -q tfyh_public_dirs || \
  grep -A6 "tfyh_public_dirs" /var/www/html/classes/tfyh_audit.php | grep -q portal

check grep -q "function request_is_https" /var/www/html/classes/portal/Portal_session.php
check grep -q "X-Forwarded-Proto" /var/www/html/classes/portal/Portal_session.php

check test -f /var/www/html/portal/index.html
check test -f /var/www/html/classes/portal/Portal_admin.php
check test -f /var/www/html/api/portal/v1/index.php
check grep -q "password-reset" /var/www/html/api/portal/v1/index.php

# Must NOT be in production image
if [ -d /var/www/html/fixtures ]; then echo "LEAK: fixtures/"; fail=1; else echo "OK: no fixtures/"; fi
if [ -d /var/www/html/portal/node_modules ]; then echo "LEAK: portal/node_modules"; fail=1; else echo "OK: no portal/node_modules"; fi
if [ -f /var/www/html/.env ]; then echo "LEAK: .env"; fail=1; else echo "OK: no .env"; fi
if [ -f /var/www/html/config/settings_db ]; then echo "LEAK: settings_db"; fail=1; else echo "OK: no settings_db"; fi

exit $fail
' && pass "concurrency guard + audit portal allowlist + forwarded-proto + packaging" \
  || fail "one or more image content checks failed"

echo
echo "==== SUMMARY ===="
echo "PASS=$PASS FAIL=$FAIL"
if [[ "$FAIL" -gt 0 ]]; then
  echo "OVERALL: FAIL"
  exit 1
fi
echo "OVERALL: PASS"
exit 0
