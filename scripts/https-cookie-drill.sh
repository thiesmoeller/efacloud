#!/usr/bin/env bash
# CapRover-style TLS-termination drill: HTTPS reverse proxy → web:80.
# Asserts portal login Set-Cookie includes Secure when X-Forwarded-Proto=https,
# and documents plain-HTTP lab behavior (Secure absent; login still works).
#
# Default: attach proxy to existing compose project efacloud-desktop-synch (:18083).
# Does not require CapRover login.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

PROJECT="${COMPOSE_PROJECT_NAME:-efacloud-desktop-synch}"
HTTPS_PORT="${EFACLOUD_HTTPS_PORT:-18443}"
HTTP_PROXY_PORT="${EFACLOUD_HTTP_PROXY_PORT:-18080}"
HTTP_DIRECT_PORT="${EFACLOUD_HTTP_PORT:-18083}"
ACCOUNT="${HTTPS_DRILL_ACCOUNT:-902}"
PASSWORD="${HTTPS_DRILL_PASSWORD:-RehearsePortal1!}"
DOWN_PROXY=0
SYNC_CODE=1
DISPOSABLE=0
KEEP_CERTS=0

CERT_DIR="$ROOT/docker/https-proxy/certs"
CERT_PEM="$CERT_DIR/cert.pem"
KEY_PEM="$CERT_DIR/key.pem"

usage() {
  cat <<'EOF'
Usage: ./scripts/https-cookie-drill.sh [options]

  CapRover-style local HTTPS proxy drill for portal Secure cookies.

Options:
  --project NAME         Compose project (default efacloud-desktop-synch)
  --https-port N         Host HTTPS port (default 18443)
  --http-proxy-port N    Host HTTP port on proxy without Secure claim (default 18080)
  --http-port N          Direct web HTTP port for lab baseline (default 18083)
  --account STR          Login account (default 902)
  --password STR         Login password (default RehearsePortal1!)
  --down                 Tear down https-proxy after PASS (keeps web/db)
  --no-sync-code         Do not docker-cp Portal_session.php into web
  --disposable           Bring up a short-lived compose stack (auto-install), then down -v
  --keep-certs           Keep generated self-signed certs after --down
  -h, --help             This help
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --project) PROJECT="${2:-}"; shift 2 ;;
    --https-port) HTTPS_PORT="${2:-}"; shift 2 ;;
    --http-proxy-port) HTTP_PROXY_PORT="${2:-}"; shift 2 ;;
    --http-port) HTTP_DIRECT_PORT="${2:-}"; shift 2 ;;
    --account) ACCOUNT="${2:-}"; shift 2 ;;
    --password) PASSWORD="${2:-}"; shift 2 ;;
    --down) DOWN_PROXY=1; shift ;;
    --no-sync-code) SYNC_CODE=0; shift ;;
    --disposable) DISPOSABLE=1; DOWN_PROXY=1; shift ;;
    --keep-certs) KEEP_CERTS=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) echo "Unknown argument: $1" >&2; usage >&2; exit 2 ;;
  esac
done

if [[ ! -f .env ]]; then
  echo "Missing .env — copy .env.example first." >&2
  exit 1
fi
if ! command -v docker >/dev/null 2>&1; then
  echo "docker is required" >&2
  exit 1
fi
if ! command -v openssl >/dev/null 2>&1; then
  echo "openssl is required to mint a self-signed cert" >&2
  exit 1
fi

# shellcheck disable=SC1091
set -a
# shellcheck source=/dev/null
source .env
set +a

export EFACLOUD_HTTPS_PORT="$HTTPS_PORT"
export EFACLOUD_HTTP_PROXY_PORT="$HTTP_PROXY_PORT"
export COMPOSE_PROJECT_NAME="$PROJECT"

PASS=0
FAIL=0
pass() { PASS=$((PASS + 1)); echo "  PASS  $*"; }
fail() { FAIL=$((FAIL + 1)); echo "  FAIL  $*"; }
info() { echo "  INFO  $*"; }

COMPOSE_BASE=(docker compose -p "$PROJECT")
COMPOSE_FILES=(-f docker-compose.yml)
if [[ -f docker-compose.desktop-synch.yml && "$DISPOSABLE" -eq 0 ]]; then
  # Prefer attaching to the live desktop-synch overlay when present.
  if "${COMPOSE_BASE[@]}" -f docker-compose.yml -f docker-compose.desktop-synch.yml ps --status running 2>/dev/null | grep -q .; then
    COMPOSE_FILES=(-f docker-compose.yml -f docker-compose.desktop-synch.yml)
  fi
fi
COMPOSE_PROXY=("${COMPOSE_BASE[@]}" "${COMPOSE_FILES[@]}" -f docker-compose.https-proxy.yml)
COMPOSE_STACK=("${COMPOSE_BASE[@]}" "${COMPOSE_FILES[@]}")

cleanup() {
  if [[ "$DOWN_PROXY" -eq 1 ]]; then
    info "tearing down https-proxy (--down)"
    "${COMPOSE_PROXY[@]}" stop https-proxy >/dev/null 2>&1 || true
    "${COMPOSE_PROXY[@]}" rm -f https-proxy >/dev/null 2>&1 || true
    if [[ "$DISPOSABLE" -eq 1 ]]; then
      info "disposable stack: compose down -v"
      "${COMPOSE_STACK[@]}" down -v >/dev/null 2>&1 || true
    fi
  fi
  if [[ "$KEEP_CERTS" -eq 0 && "$DOWN_PROXY" -eq 1 ]]; then
    rm -f "$CERT_PEM" "$KEY_PEM"
  fi
}
trap cleanup EXIT

ensure_certs() {
  mkdir -p "$CERT_DIR"
  if [[ -f "$CERT_PEM" && -f "$KEY_PEM" ]]; then
    info "reusing self-signed certs in docker/https-proxy/certs"
    return
  fi
  info "generating self-signed cert (CN=localhost)"
  openssl req -x509 -nodes -newkey rsa:2048 -days 30 \
    -keyout "$KEY_PEM" -out "$CERT_PEM" \
    -subj "/CN=localhost/O=efaCloud HTTPS cookie drill" >/dev/null 2>&1
}

sync_portal_session() {
  if [[ "$SYNC_CODE" -eq 0 ]]; then
    info "skipping Portal_session.php sync (--no-sync-code)"
    return
  fi
  local cid
  cid="$("${COMPOSE_STACK[@]}" ps -q web 2>/dev/null | head -1 || true)"
  if [[ -z "$cid" ]]; then
    fail "web container not running; cannot sync Portal_session.php"
    exit 1
  fi
  docker cp "$ROOT/classes/portal/Portal_session.php" \
    "$cid":/var/www/html/classes/portal/Portal_session.php
  # No apache restart needed for PHP source; next request picks it up.
  pass "synced Portal_session.php into web container"
}

bring_up_disposable() {
  echo "== disposable stack on :$HTTP_DIRECT_PORT =="
  export EFACLOUD_HTTP_PORT="$HTTP_DIRECT_PORT"
  export EFACLOUD_AUTO_INSTALL=1
  "${COMPOSE_STACK[@]}" up -d --build db web
  local deadline=$((SECONDS + 180))
  while (( SECONDS < deadline )); do
    code="$(curl -sS -o /dev/null -w '%{http_code}' "http://127.0.0.1:${HTTP_DIRECT_PORT}/api/portal/v1/session" || true)"
    if [[ "$code" == "200" ]]; then
      pass "disposable web ready (HTTP $code)"
      ACCOUNT="${EFACLOUD_ADMIN_NAME:-clubadmin}"
      PASSWORD="${EFACLOUD_ADMIN_PASSWORD:-ClubAdmin2026!}"
      return
    fi
    sleep 2
  done
  fail "disposable web did not become ready"
  exit 1
}

require_stack() {
  echo "== stack readiness (project=$PROJECT) =="
  if [[ "$DISPOSABLE" -eq 1 ]]; then
    bring_up_disposable
    return
  fi
  if ! "${COMPOSE_STACK[@]}" ps --status running 2>/dev/null | grep -q .; then
    fail "compose project $PROJECT has no running services"
    echo "Start with: ./scripts/desktop-synch-lab.sh --port $HTTP_DIRECT_PORT" >&2
    echo "Or re-run with --disposable" >&2
    exit 1
  fi
  code="$(curl -sS -o /tmp/https-drill-session.json -w '%{http_code}' \
    "http://127.0.0.1:${HTTP_DIRECT_PORT}/api/portal/v1/session" || true)"
  if [[ "$code" != "200" ]]; then
    fail "GET /session on :$HTTP_DIRECT_PORT → HTTP $code"
    exit 1
  fi
  pass "direct HTTP web reachable on :$HTTP_DIRECT_PORT"
}

bring_up_proxy() {
  echo "== https-proxy on :$HTTPS_PORT (TLS) and :$HTTP_PROXY_PORT (plain) =="
  ensure_certs
  "${COMPOSE_PROXY[@]}" up -d https-proxy
  local deadline=$((SECONDS + 60))
  while (( SECONDS < deadline )); do
    code="$(curl -skS -o /dev/null -w '%{http_code}' \
      "https://127.0.0.1:${HTTPS_PORT}/api/portal/v1/session" || true)"
    if [[ "$code" == "200" ]]; then
      pass "https-proxy ready (HTTPS session HTTP $code)"
      return
    fi
    sleep 1
  done
  fail "https-proxy did not become ready"
  "${COMPOSE_PROXY[@]}" logs --tail 40 https-proxy || true
  exit 1
}

# Login and return response headers file path via stdout var pattern.
login_headers() {
  local url="$1"
  local hdr="$2"
  local body="$3"
  local curl_opts=()
  if [[ "$url" == https://* ]]; then
    curl_opts+=(-k)
  fi
  curl -sS "${curl_opts[@]}" -D "$hdr" -o "$body" \
    -H 'Content-Type: application/json' \
    -d "{\"account\":\"${ACCOUNT}\",\"password\":\"${PASSWORD}\"}" \
    "$url/api/portal/v1/session/login"
}

assert_secure_present() {
  local hdr="$1"
  local label="$2"
  local set_cookie
  set_cookie="$(grep -i '^Set-Cookie:' "$hdr" | grep -i 'EFA_PORTAL' || true)"
  echo "  EVIDENCE  $label Set-Cookie: ${set_cookie:-<none>}"
  if [[ -z "$set_cookie" ]]; then
    fail "$label: no EFA_PORTAL Set-Cookie"
    return
  fi
  if echo "$set_cookie" | grep -qi 'Secure'; then
    pass "$label: Set-Cookie contains Secure"
  else
    fail "$label: Set-Cookie missing Secure"
  fi
}

assert_secure_absent() {
  local hdr="$1"
  local label="$2"
  local set_cookie
  set_cookie="$(grep -i '^Set-Cookie:' "$hdr" | grep -i 'EFA_PORTAL' || true)"
  echo "  EVIDENCE  $label Set-Cookie: ${set_cookie:-<none>}"
  if [[ -z "$set_cookie" ]]; then
    fail "$label: no EFA_PORTAL Set-Cookie (login may have failed)"
    return
  fi
  if echo "$set_cookie" | grep -qi 'Secure'; then
    fail "$label: unexpected Secure on plain HTTP lab"
  else
    pass "$label: Secure absent (expected for plain HTTP lab)"
  fi
}

assert_login_ok() {
  local body="$1"
  local label="$2"
  if grep -q '"authenticated"[[:space:]]*:[[:space:]]*true\|"csrfToken"' "$body" 2>/dev/null \
      && ! grep -q '"error"' "$body" 2>/dev/null; then
    pass "$label: login JSON ok"
  elif grep -q '"csrfToken"' "$body" && grep -q '"user"' "$body"; then
    pass "$label: login JSON ok"
  else
    fail "$label: login body unexpected: $(head -c 200 "$body")"
  fi
}

# --- main ---
require_stack
sync_portal_session
bring_up_proxy

HTTPS_URL="https://127.0.0.1:${HTTPS_PORT}"
HTTP_PROXY_URL="http://127.0.0.1:${HTTP_PROXY_PORT}"
HTTP_DIRECT_URL="http://127.0.0.1:${HTTP_DIRECT_PORT}"

echo "== HTTPS login via proxy (expect Secure) =="
HDR_HTTPS="$(mktemp)"
BODY_HTTPS="$(mktemp)"
login_headers "$HTTPS_URL" "$HDR_HTTPS" "$BODY_HTTPS"
assert_login_ok "$BODY_HTTPS" "HTTPS proxy"
assert_secure_present "$HDR_HTTPS" "HTTPS proxy"

echo "== plain HTTP via proxy (X-Forwarded-Proto: http — expect no Secure) =="
HDR_PROXY_HTTP="$(mktemp)"
BODY_PROXY_HTTP="$(mktemp)"
login_headers "$HTTP_PROXY_URL" "$HDR_PROXY_HTTP" "$BODY_PROXY_HTTP"
assert_login_ok "$BODY_PROXY_HTTP" "HTTP proxy"
assert_secure_absent "$HDR_PROXY_HTTP" "HTTP proxy"

echo "== plain HTTP direct to web (local lab — expect no Secure; login works) =="
HDR_DIRECT="$(mktemp)"
BODY_DIRECT="$(mktemp)"
login_headers "$HTTP_DIRECT_URL" "$HDR_DIRECT" "$BODY_DIRECT"
assert_login_ok "$BODY_DIRECT" "HTTP direct"
assert_secure_absent "$HDR_DIRECT" "HTTP direct"
info "Expected lab behavior: Secure absent on plain HTTP so browsers still store the cookie."

rm -f "$HDR_HTTPS" "$BODY_HTTPS" "$HDR_PROXY_HTTP" "$BODY_PROXY_HTTP" "$HDR_DIRECT" "$BODY_DIRECT"

echo
if [[ "$FAIL" -eq 0 ]]; then
  echo "PASS  https-cookie-drill ($PASS checks)"
  if [[ "$DOWN_PROXY" -eq 0 ]]; then
    info "proxy left up on :$HTTPS_PORT — re-run with --down to tear down"
  fi
  exit 0
fi
echo "FAIL  https-cookie-drill ($FAIL failed, $PASS passed)" >&2
exit 1
