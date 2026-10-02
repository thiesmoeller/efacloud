#!/usr/bin/env bash
# Run focused security regressions with host PHP or php:8.2-cli via Docker.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

if command -v php >/dev/null 2>&1; then
  exec php tests/security/run.php
fi

if command -v docker >/dev/null 2>&1; then
  exec docker run --rm -v "$ROOT:/app" -w /app php:8.2-cli php tests/security/run.php
fi

echo "Neither php nor docker is available to run tests/security/run.php" >&2
exit 1
