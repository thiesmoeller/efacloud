#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
NET="efa-dockside-live-$$"
PORT="${PORTAL_LIVE_PORT:-18090}"
cleanup() {
  docker rm -f "$NET-web" "$NET-db" >/dev/null 2>&1 || true
  docker network rm "$NET" >/dev/null 2>&1 || true
}
trap cleanup EXIT
npm --prefix "$ROOT/portal" run build
docker network create "$NET" >/dev/null
docker run -d --rm --name "$NET-db" --network "$NET" --network-alias db --tmpfs /var/lib/mysql -e MARIADB_DATABASE=efacloud -e MARIADB_USER=efacloud -e MARIADB_PASSWORD=dockside-test -e MARIADB_ROOT_PASSWORD=dockside-root mariadb:11.4 >/dev/null
for attempt in $(seq 1 45); do
  if docker exec "$NET-db" mariadb -h127.0.0.1 -uefacloud -pdockside-test efacloud -e 'SELECT 1' >/dev/null 2>&1; then break; fi
  sleep 1
done
docker run -d --rm --name "$NET-web" --network "$NET" --network-alias web -p 127.0.0.1:$PORT:80 \
  -e EFACLOUD_AUTO_INSTALL=1 -e EFACLOUD_BASE_URL=http://web \
  -e EFACLOUD_DB_HOST=db -e EFACLOUD_DB_NAME=efacloud -e EFACLOUD_DB_USER=efacloud -e EFACLOUD_DB_PASSWORD=dockside-test \
  -e EFACLOUD_ADMIN_FIRST=Acceptance -e EFACLOUD_ADMIN_LAST=Admin -e EFACLOUD_ADMIN_EMAIL=acceptance@example.test \
  -e EFACLOUD_ADMIN_ID=1142 -e EFACLOUD_ADMIN_NAME=acceptance -e EFACLOUD_ADMIN_PASSWORD=DocksideAdmin1! \
  -e EFACLOUD_DOCKSIDE_ACCEPTANCE=1 -e TZ=Europe/Berlin \
  -v "$ROOT/classes:/var/www/html/classes:ro" -v "$ROOT/api:/var/www/html/api:ro" \
  -v "$ROOT/portal/dist:/var/www/html/portal:ro" -v "$ROOT/tests/portal:/acceptance:ro" \
  -v "$ROOT/fixtures/sanitized:/test-fixtures:ro" "${PORTAL_PHP_IMAGE:-efacloud-desktop-synch-web}" >/dev/null
for attempt in $(seq 1 120); do
  if docker exec "$NET-web" test -f /var/www/html/config/.install_complete; then
    docker exec "$NET-web" php /acceptance/seed-live.php
    export PORTAL_LIVE_BASE_URL="http://127.0.0.1:$PORT"
    export PORTAL_LIVE_CONTAINER="$NET-web"
    python3 "$ROOT/tests/portal/live.py"
    python3 "$ROOT/tests/portal/live-race.py"
    node "$ROOT/portal/e2e/dockside-live.mjs"
    exit 0
  fi
  sleep 1
done
printf 'Installer did not finish.\n' >&2
exit 1
