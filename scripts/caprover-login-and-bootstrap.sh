#!/usr/bin/env bash
# Non-interactive CapRover login + staging bootstrap (--auto-create).
# Password from CAPROVER_PASSWORD, --password-file, or /tmp/caprover-password (single line).
# Never commits secrets; var/caprover-staging-secrets.env is gitignored.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

MACHINE="${CAPROVER_NAME:-project-park}"
URL="${CAPROVER_URL:-https://captain.project-park.de}"
PASSWORD_FILE="${CAPROVER_PASSWORD_FILE:-/tmp/caprover-password}"
SKIP_BOOTSTRAP=0

usage() {
  cat <<EOF
Usage: ./scripts/caprover-login-and-bootstrap.sh [options]

  Logs in to CapRover and runs ./scripts/caprover-staging-bootstrap.sh --auto-create.

Options:
  --password-file PATH   Read password from file (default: ${PASSWORD_FILE})
  --skip-bootstrap       Login only; verify auth with bootstrap --skip-deploy
  -h, --help

Environment:
  CAPROVER_PASSWORD      Captain password (preferred for one-shot sessions)
  CAPROVER_NAME          Machine name (default: captain-project-park)
  CAPROVER_URL           Captain URL (default: https://captain.project-park.de)

Interactive alternative: caprover login
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --password-file) PASSWORD_FILE="${2:-}"; shift 2 ;;
    --skip-bootstrap) SKIP_BOOTSTRAP=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) echo "Unknown: $1" >&2; usage >&2; exit 2 ;;
  esac
done

require_cmd() { command -v "$1" >/dev/null 2>&1 || { echo "missing: $1" >&2; exit 1; }; }
require_cmd caprover

resolve_password() {
  if [[ -n "${CAPROVER_PASSWORD:-}" ]]; then
    printf '%s' "$CAPROVER_PASSWORD"
    return 0
  fi
  if [[ -f "${PASSWORD_FILE}" ]]; then
    head -1 "${PASSWORD_FILE}" | tr -d '\r\n'
    return 0
  fi
  echo "No CapRover password: set CAPROVER_PASSWORD or create ${PASSWORD_FILE}" >&2
  echo "Or run: caprover login" >&2
  exit 2
}

PW="$(resolve_password)"
[[ -n "$PW" ]] || { echo "empty password" >&2; exit 2; }

echo "== CapRover login (machine=${MACHINE}) =="
echo "Password source: $([[ -n "${CAPROVER_PASSWORD:-}" ]] && echo CAPROVER_PASSWORD || echo "${PASSWORD_FILE}")"

# Replace stale machine entry if present (CLI refuses overwrite without logout).
if caprover list 2>/dev/null | grep -q "${MACHINE}"; then
  echo "Logging out stale machine entry ${MACHINE}..."
  caprover logout -n "${MACHINE}" </dev/null 2>/dev/null || true
fi

set +e
LOGIN_OUT="$(caprover login \
  -n "${MACHINE}" \
  -u "${URL}" \
  -p "${PW}" 2>&1)"
LOGIN_EC=$?
set -e

if [[ $LOGIN_EC -ne 0 ]]; then
  echo "${LOGIN_OUT}" | sed 's/[Pp]assword[^[:space:]]*/<redacted>/g' | tail -30 >&2
  echo "caprover login failed (exit ${LOGIN_EC})" >&2
  exit 2
fi

echo "Login command completed."

# Confirm API auth (must pass -d '{}' — see caprover-staging-bootstrap.sh)
./scripts/caprover-staging-bootstrap.sh --skip-deploy --skip-health
echo "Auth probe OK."

if [[ $SKIP_BOOTSTRAP -eq 1 ]]; then
  echo "Skipping bootstrap (--skip-bootstrap)."
  exit 0
fi

exec ./scripts/caprover-staging-bootstrap.sh --auto-create --confirm-live
