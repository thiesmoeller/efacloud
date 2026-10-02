#!/usr/bin/env bash
# Poll CapRover CLI auth; when valid, run staging bootstrap automatically.
# Does not invent passwords — waits for interactive `caprover login` elsewhere
# (or CAPROVER_PASSWORD / refreshed token).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

MACHINE="${CAPROVER_NAME:-captain-project-park}"
URL="${CAPROVER_URL:-https://captain.project-park.de}"
INTERVAL="${CAPROVER_WAIT_INTERVAL:-15}"
MAX_ROUNDS="${CAPROVER_WAIT_ROUNDS:-240}" # 240*15s ≈ 1h
OPEN_BROWSER="${CAPROVER_OPEN_BROWSER:-1}"

echo "== wait-caprover-then-bootstrap =="
echo "MACHINE=$MACHINE URL=$URL INTERVAL=${INTERVAL}s MAX_ROUNDS=$MAX_ROUNDS"
echo "In another terminal run:  caprover login"
echo "  CapRover URL: $URL"
echo "  Machine name: $MACHINE"
echo "After login, full bootstrap runs with --auto-create (local-dir deploy)."
echo "Or: CAPROVER_PASSWORD=… ./scripts/caprover-login-and-bootstrap.sh"
echo "Or: echo '…' > /tmp/caprover-password && ./scripts/caprover-login-and-bootstrap.sh"
echo

# One-shot login if password already supplied
if [[ -n "${CAPROVER_PASSWORD:-}" ]] || [[ -f "${CAPROVER_PASSWORD_FILE:-/tmp/caprover-password}" ]]; then
  echo "Password available — running caprover-login-and-bootstrap.sh"
  exec "${ROOT}/scripts/caprover-login-and-bootstrap.sh"
fi

if [[ "$OPEN_BROWSER" == "1" ]] && command -v google-chrome >/dev/null 2>&1; then
  google-chrome --new-window "$URL" >/dev/null 2>&1 || true
  echo "Opened CapRover dashboard in Chrome (login there or via CLI)."
fi

auth_ok() {
  # Non-interactive probe; treat exit 0 (ready) or 3 (apps missing) as auth OK.
  ./scripts/caprover-staging-bootstrap.sh --skip-deploy --skip-health >/tmp/caprover-wait-probe.out 2>&1
}

round=0
while (( round < MAX_ROUNDS )); do
  round=$((round + 1))
  set +e
  auth_ok
  ec=$?
  set -e
  # 0 = auth OK + apps present; 3 = auth OK + apps missing (auto-create next)
  if [[ $ec -eq 0 || $ec -eq 3 ]]; then
    echo
    echo "Auth OK on round $round (probe exit $ec) — running full staging bootstrap with --auto-create."
    ./scripts/caprover-staging-bootstrap.sh --auto-create
    exit $?
  fi
  if [[ $ec -ne 2 ]]; then
    echo "Probe exit $ec (unexpected) — see /tmp/caprover-wait-probe.out" >&2
  fi
  printf '\r[%s] waiting for caprover login… round %d/%d (last exit %d)   ' \
    "$(date -u +%H:%M:%SZ)" "$round" "$MAX_ROUNDS" "$ec"
  sleep "$INTERVAL"
done

echo
echo "Timed out after $MAX_ROUNDS rounds without CapRover auth." >&2
echo "Run: caprover login   then: ./scripts/caprover-staging-bootstrap.sh --auto-create" >&2
exit 2
