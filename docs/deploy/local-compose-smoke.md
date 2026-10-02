# Local compose and portal fixture smoke

Local rehearsal **without** CapRover credentials, club domains, or production
backup destinations. Uses only this fork's compose file and sanitized fixtures.

**Do not** follow parent-workspace `TEST_ENV.md` / `docker-compose.test.yml` for
this release — that harness targets ROW R/8 migration, not this efaCloud fork.

## What stays out of images

- `fixtures/` and `test_backup/` / `efaBackup_*.zip` are `.dockerignore`d
- Never set `EFACLOUD_PORTAL_FIXTURES=1` on CapRover
- Dev passwords live in local `.env` (from `.env.example`); not for production

## 1. Compose install / health smoke

Prerequisites: Docker, compose plugin, copy of `.env.example` → `.env`.

```bash
cp -n .env.example .env   # if needed; set local-only passwords
./scripts/compose-local-smoke.sh
# If host port 8080 is already taken:
EFACLOUD_HTTP_PORT=18080 ./scripts/compose-local-smoke.sh
```

The script:

1. Builds and starts `docker compose` (`db` healthy → `web`)
2. Enables `EFACLOUD_AUTO_INSTALL=1` for the smoke run if you pass `--auto-install`
   (default)
3. Waits until install completion markers exist **or** `/portal/` returns 200
4. Probes `/portal/`, `/forms/login.php`, and (when install-complete)
   `/api/portal/v1/session`

Tear down when finished:

```bash
docker compose down
# optional: wipe volumes after a dirty install experiment
docker compose down -v
```

This validates packaging + bootstrap + HTTP readiness. It does **not** import a
club desktop backup and does **not** prove CapRover networking/HTTPS.

## 2. Portal API smoke with sanitized fixtures

Uses committed `fixtures/sanitized/*.json` (synthetic names only). Default
password for fixture users: `fixture-pass` (accounts `101` member, `102` trainer).

### Fast path (PHP built-in server — no image fixtures needed)

```bash
./scripts/portal-fixture-smoke.sh
```

Starts a short-lived `php -S` (or Docker `php:8.2-cli`) with
`EFACLOUD_PORTAL_FIXTURES=1`, then:

- `POST /session/login`
- `GET /boats`, `/persons`, `/destinations`
- `GET` open trip / damages sample
- Damage report + trip start response shape against fixtures
- Logout

In-process idempotency / multi-step mutation persistence remain covered by
`./tests/portal/run.sh` (fixture store is per-request over HTTP).

### Optional: fixtures mounted on compose web

For same-origin `/portal/` + API against fixtures **after** a completed install:

```bash
docker compose -f docker-compose.yml -f docker-compose.portal-fixtures.yml \
  up --build -d
# wait for install if AUTO_INSTALL=1, then:
./scripts/portal-fixture-smoke.sh --base-url http://127.0.0.1:8080
```

`docker-compose.portal-fixtures.yml` bind-mounts `fixtures/sanitized` into the
container and sets `EFACLOUD_PORTAL_FIXTURES=1`. Production CapRover builds never
include that overlay.

## 3. Desktop backup rehearsal (local file only)

Club `efaBackup_*.zip` may sit in the parent workspace `test_backup/` for
**manual** EFA desktop → staging cloud activation (see
`docs/deploy/cutover-runbook.md`). That path requires a reachable efaCloud URL
and desktop client — not covered by the scripts above. Never copy the zip into
`Dockerfile` context.

## Related

- Isolation / promote: `caprover-staging-production.md`
- Cutover: `cutover-runbook.md`
- Acceptance mapping: `acceptance-checklist.md`
