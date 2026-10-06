# efaCloud dockside PWA (`/portal/`)

German phone-first React/TypeScript app for account-attributed boat checkout and individual return. Home shows only open trips personally started by the signed-in account, across logbooks.

## Develop

```bash
cd portal
npm install
npm run dev
```

Vite proxies `/api` → `http://127.0.0.1:8080` (override with `VITE_API_PROXY`).
The app is served under base `/portal/`.

## Build / test

```bash
npm run test
npm run build
```

Output: `portal/dist` (copied into the Docker image at `/var/www/html/portal`).

## Out of scope (v1)

Reservation creation, repair admin, bulk ops. Writes are online-only.


Dockside checks:

```bash
# From the repository root
./tests/portal/run.sh
# With host PHP (or the same PHP Docker image as run.sh)
php tests/portal/dockside.php
bash tests/portal/database.sh
# Built PWA + actual PHP/MariaDB + desktop HTTP protocol + phone browser tests
bash tests/portal/live.sh
# With Vite running and Chrome installed
node portal/e2e/dockside.mjs
```

`database.sh` uses an isolated, ephemeral MariaDB container and the locally built `efacloud-desktop-synch-web` PHP image (override `PORTAL_PHP_IMAGE`). `live.sh` uses a separate disposable network/database/web stack, sanitized accounts and Chrome; it builds the PWA and removes the containers on exit. Its default localhost port is 18090 (override `PORTAL_LIVE_PORT`). Install browser dependencies with `npm --prefix portal/e2e ci` first. `dockside.mjs` separately mocks API responses for deterministic mobile interaction, accessibility and retry checks. See `docs/testing/dockside-portal.md` for coverage and device checks.
