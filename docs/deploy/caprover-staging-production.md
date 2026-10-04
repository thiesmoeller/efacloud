# CapRover staging and production isolation

Keep club domains, deployment credentials, and backup destinations outside this
repository. Use CapRover app config / secrets for those values.

**Production is not released** until step-5 acceptance gates pass (see
`docs/deploy/acceptance-checklist.md`). This document prepares staging/production
isolation and promotion; it does not authorize go-live.

## CLI machine status (ops — re-login required)

| Machine name | URL | Status (2026-10-02) |
|--------------|-----|---------------------|
| `captain-project-park` | https://captain.project-park.de | **Listed locally but auth token expired** (`Invalid credentials`) |

Before any staging deploy or CapRover API call:

```bash
caprover login
# follow prompts for https://captain.project-park.de (or your captain root URL)
# do not invent or commit passwords
```

Or start a poller that runs staging bootstrap with `--auto-create` once login succeeds:

```bash
./scripts/wait-caprover-then-bootstrap.sh
# other terminal: caprover login
```

Until re-login succeeds, CapRover staging isolation / image promote / HTTPS cutover
remain **ops-blocked**. Local compose rehearsal and
`./scripts/release-candidate-local.sh` do **not** require CapRover credentials.

After a successful login, run staging bootstrap (fail-closed if auth still bad):

```bash
./scripts/caprover-staging-bootstrap.sh --auto-create
# expect exit 2 with re-login text if the token is still expired
# default deploy mode: local-dir (cwd tarball), not git branch
```

## Staging one-click + bootstrap script

Preferred path for **staging only** (`efacloud-staging` + `efacloud-staging-db`).
Do not use this flow to create production automatically.

### 1. One-click template (manual UI)

File: `caprover-one-click.yml` (repo root).

1. CapRover → Apps → One Click Apps/Databases → `>> TEMPLATE <<`
2. Paste the YAML
3. App name: `efacloud-staging` (creates `efacloud-staging` + `efacloud-staging-db`)
4. Review generated DB/admin passwords; store them outside the repo
5. Enable HTTPS / Force HTTPS on the web app

The template sets:

- MariaDB **11.4**, `notExposeAsWebApp`, persistent DB volume
- Web image default `ghcr.io/thiesmoeller/efacloud:feature-caprover` — **prefer**
  an immutable `ghcr.io/thiesmoeller/efacloud:sha-…` (or `@sha256:…`) from Actions
  for staging and the **same** ref for production promote
  (`docs/deploy/image-promote.md`). GHCR tags are multi-arch (amd64+arm64); older
  amd64-only tags fail on arm64 CapRover with `exec format error`.
- Env (`TZ`, `EFACLOUD_AUTO_INSTALL`, `EFACLOUD_BASE_URL`,
  `EFACLOUD_DB_HOST=srv-captain--$$cap_appname-db`, DB + admin vars)
- Persistent volumes: `config`, `log`, `uploads`, `attachements`, `pdfs`, `resources`
- `containerHttpPort` **80**
- **Never** sets `EFACLOUD_PORTAL_FIXTURES`

### 2. Bootstrap script

After `caprover login` succeeds:

```bash
# Preferred: API-provision if needed, then deploy local working tree
./scripts/caprover-staging-bootstrap.sh --auto-create

# Or only deploy when apps already exist (missing apps → exit 3 + one-click steps)
./scripts/caprover-staging-bootstrap.sh
```

Behavior:

| Step | Result |
|------|--------|
| Auth probe | `caprover api` GET `/user/system/info` (non-interactive). Invalid/expired → exit **2** with exact `caprover login` instructions |
| Apps missing (no `--auto-create`) | Prints one-click paste steps; exit **3** |
| Apps missing (`--auto-create`) | Registers `efacloud-staging-db` + `efacloud-staging` via CapRover API, sets MariaDB 11.4 / volumes / `EFACLOUD_*` env, writes secrets once to gitignored `var/caprover-staging-secrets.env` (mode 600), enables forceSsl / base-domain SSL when API allows |
| Deploy (default) | **Local-dir**: tarball of the current working tree (includes uncommitted changes) → `caprover deploy -n … -a … -t <tar>`. Does **not** use `-b` (git archive of commits would ship stale origin without portal/PWA fixes). |
| Deploy (`--branch NAME`) | Optional remote-git style: `caprover deploy -n … -a … -b NAME` (committed tree only) |
| Health | HTTPS curls: `/portal/`, `/api/portal/v1/session`, `/forms/login.php` → 200; `/install/` → **403** when installed |

Options: `--auto-create`, `--skip-deploy`, `--skip-health`, `--base-url URL`, `--machine`, `--app`, `--branch`.

Default machine: `captain-project-park`. Production app is never created by this script.
`wait-caprover-then-bootstrap.sh` calls bootstrap with `--auto-create` after login.

## App layout

Provision **separate** CapRover apps and databases:

| Role | Web app (example name) | Database app (example name) |
|------|------------------------|-----------------------------|
| Staging | `efacloud-staging` | `efacloud-staging-db` |
| Production | `efacloud` | `efacloud-db` |

Do not share database credentials, persistent volumes, or `EFACLOUD_ADMIN_*`
passwords between staging and production.

## Staging / production isolation checklist

Complete for **each** environment before cutover rehearsal.

| # | Check | Staging | Production |
|---|--------|---------|------------|
| 1 | HTTPS enabled on the web app (CapRover Force HTTPS / Let's Encrypt) | ☐ | ☐ |
| 2 | Database app has **no** public HTTP route (CapRover-internal only) | ☐ | ☐ |
| 3 | Web talks to DB via CapRover service hostname (`srv-captain--…`), not a public IP | ☐ | ☐ |
| 4 | Distinct `EFACLOUD_DB_*` and admin credentials (never copy prod → staging) | ☐ | ☐ |
| 5 | Persistent volumes mounted (see below); volumes are **not** shared across envs | ☐ | ☐ |
| 6 | **Single web instance** initially (CapRover instance count = 1) | ☐ | ☐ |
| 7 | Image built from this fork only; no fixtures / `test_backup` / PII in image | ☐ | ☐ |
| 8 | Backup destination configured **outside** the repo (object storage / off-host) | ☐ | ☐ |
| 9 | Backup-failure alert wired (email / chat / CapRover notify) — see Health | ☐ | ☐ |
| 10 | Error monitoring destination set (logs / external APM — operator choice) | ☐ | ☐ |

## Environment variables

Copy from `.env.example`. On CapRover set them under **App Config → Environment
Variables** (never commit real values).

Staging checklist:

- Distinct `EFACLOUD_DB_HOST` pointing at the staging DB service
  (`srv-captain--efacloud-staging-db` style hostname).
- Distinct `EFACLOUD_DB_NAME` / `EFACLOUD_DB_USER` / `EFACLOUD_DB_PASSWORD`.
- Distinct admin password; do not reuse production credentials.
- `EFACLOUD_AUTO_INSTALL=1` for first bring-up. Bootstrap skips when
  installation is **complete** (`install/.locked` or `config/.install_complete`).
  Incomplete installs (settings without a complete marker) automatically resume.
- HTTPS enabled on the CapRover app; keep the database app off public HTTP.
- CapRover's edge nginx sets `X-Forwarded-Proto: https` after TLS termination;
  portal session cookies rely on that header for the `Secure` flag (do not strip it).
- Never set `EFACLOUD_PORTAL_FIXTURES=1` on CapRover (fixtures are local-only).

Production checklist:

- Same image digest/tag previously verified on staging.
- Separate DB and volumes.
- Fresh admin password and rotated DB password.
- Backup destinations configured outside the repo (object storage / off-host).
- Instance count remains 1 until multi-instance is explicitly validated.

## Persistent directories

Mount the same paths on staging and production, but on **separate** volumes:

- `/var/www/html/config` (includes `settings_db` and `.install_complete`)
- `/var/www/html/log`
- `/var/www/html/uploads`
- `/var/www/html/attachements`
- `/var/www/html/pdfs`
- `/var/www/html/resources`

Completion is persisted as `config/.install_complete` so CapRover image
redeploys do not reopen the installer when only `config` is a volume. The
entrypoint mirrors that marker to `install/.locked` on startup. Optionally also
persist `/var/www/html/install`.

## Versioned image promote (staging → production)

**Preferred path:** pull a GHCR immutable tag built by
`.github/workflows/docker-image.yml`:

`ghcr.io/thiesmoeller/efacloud:sha-<short>` (or `@sha256:…` from the Actions
job summary). Full procedure: **`docs/deploy/image-promote.md`**.

1. Publish via Actions (push to `main` / `feature/caprover` or
   `workflow_dispatch`) — or build locally with
   `./scripts/print-local-image-digest.sh` for offline evidence only.
2. Deploy that **same** `sha-…` / digest to **staging** (CapRover Deploy via
   ImageName). Do not promote a moving branch tag alone.
3. Run installer (or confirm `.install_complete` survives redeploy) and the
   staging gates in `acceptance-checklist.md`.
4. Promote the **identical** digest/tag to production — do not rebuild from a
   moving branch tip between staging sign-off and prod deploy.
5. After production deploy: confirm HTTPS, single instance, DB connectivity,
   persistent volumes still present, and health probes green.
6. Only then proceed with cutover (`docs/deploy/cutover-runbook.md`).

Alternative: CapRover Dockerfile deploy via `captain-definition.json` still
works for staging bring-up, but promote must still pin the resulting digest.

Rollback of an image promote (pre-cutover) is redeploying the previous known-good
tag. Post-cutover rollback requires trip reconciliation — see cutover runbook.

## Health checks, DB readiness, backup-failure alerts

### Database readiness

- CapRover MariaDB one-click / compose: wait until InnoDB is initialized before
  pointing the web app at it.
- Local compose already uses `depends_on: condition: service_healthy` on `db`
  (`healthcheck.sh --connect --innodb_initialized`). Mirror that intent on
  CapRover (order apps so DB is up; web bootstrap retries HTTP to installer).
- Web bootstrap (`EFACLOUD_AUTO_INSTALL=1`) waits for installer HTTP and fails
  the container if DB setup does not complete — treat crash-loop as a readiness
  signal, not a silent partial install.

### Application HTTP probes (operator-configured)

Until install is complete, Apache serves **only** `/install/` (other paths 403).
After complete:

| Probe | Expect | Notes |
|-------|--------|-------|
| `GET /portal/` | 200, HTML shell | Confirms PWA assets + Apache Alias |
| `GET /forms/login.php` | 200 | Confirms PHP app reachable |
| `GET /api/portal/v1/session` | 200 JSON (`authenticated: false` OK) | Confirms portal API front controller |

Configure CapRover / load-balancer health to one of the post-install probes.
Do not point production health at fixture mode or at `/install/`.

### Backup-failure alerts

CapRover's own backup **excludes** application volumes and images (see
[CapRover backup and restore](https://caprover.com/docs/backup-and-restore)).
Operators must:

1. Schedule separate DB dumps and volume/file backups (see
   `docs/deploy/backup-restore.md`).
2. Alert when the scheduled job fails, skips, or produces empty/zero-byte
   artifacts (mail, chat webhook, or monitoring — destination stays out of repo).
3. Practice restore on **staging** before cutover; a green CapRover config backup
   alone is not a restore proof.

### Error monitoring

Retain `log/` on the persistent volume. Optionally forward Apache/PHP error logs
to an external sink. Thresholds and destinations are ops inputs (not in-repo).

## Installation states

| State | Markers | Public HTTP |
|-------|---------|-------------|
| Fresh | no `settings_db`, no complete marker | only `/install/` |
| Incomplete | `settings_db` without complete marker | only `/install/` (recoverable) |
| Complete | `install/.locked` and/or `config/.install_complete` | app served; `/install/` denied |

## Backups (summary)

CapRover configuration backup does **not** include application volumes or
images. Back up the MariaDB data and the persistent directories above
separately, and practice restore on staging before production cutover.
Full procedure: `docs/deploy/backup-restore.md`.

## Local rehearsal (no CapRover credentials)

Use this fork's `docker-compose.yml` and scripts under `scripts/` — see
`docs/deploy/local-compose-smoke.md`. Do **not** follow parent-workspace
`TEST_ENV.md` / `docker-compose.test.yml` ROW R/8 migration paths for this
release; that harness targets a different product.
