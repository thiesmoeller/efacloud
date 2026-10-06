#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
NAME="portal-dockside-db-test-$$"
cleanup() { docker rm -f "$NAME" >/dev/null 2>&1 || true; }
trap cleanup EXIT
docker run -d --rm --name "$NAME" --tmpfs /var/lib/mysql -e MARIADB_ROOT_PASSWORD=dockside-test -e MARIADB_DATABASE=dockside mariadb:11.4 >/dev/null
for attempt in $(seq 1 30); do
  if docker exec "$NAME" mariadb -h127.0.0.1 -uroot -pdockside-test dockside -e "SELECT 1" >/dev/null 2>&1; then break; fi
  sleep 1
done
docker run --rm --network "container:$NAME" --entrypoint php -v "$ROOT:/app:ro" --tmpfs /app/log -w /app "${PORTAL_PHP_IMAGE:-efacloud-desktop-synch-web}" tests/portal/database.php
