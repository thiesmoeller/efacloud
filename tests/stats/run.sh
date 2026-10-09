#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

if command -v php >/dev/null 2>&1; then
  exec php tests/stats/run.php
fi

if command -v docker >/dev/null 2>&1; then
  exec docker run --rm -v "$ROOT:/app" -w /app php:8.2-cli php tests/stats/run.php
fi

echo "Neither php nor docker is available to run tests/stats/run.php" >&2
exit 1
