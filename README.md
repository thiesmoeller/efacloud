# efacloud

Club deployment fork of the [efaCloud](https://www.efacloud.org) server (GPL-2.0). It extends the upstream PHP application with Docker and CapRover packaging for self-hosting.

Upstream reference: [tfyh/efacloud](https://github.com/tfyh/efacloud)

Current upstream release: **2.4.0_13** (2026-05-20). See
[release provenance and upgrade notes](docs/deploy/upstream-2.4.0_13.md).

## Quick start (local)

Copy the example environment file and adjust values:

```bash
cp .env.example .env
docker compose up --build
```

Open `http://localhost:8080/`.

With `EFACLOUD_AUTO_INSTALL=1` in `.env`, the container runs the installer automatically on first start. With `EFACLOUD_AUTO_INSTALL=0`, complete the web installer manually. Form fields are prefilled from the same environment variables.

Default local database settings when using `.env.example`:

```text
EFACLOUD_DB_HOST=db
EFACLOUD_DB_NAME=efacloud
EFACLOUD_DB_USER=efacloud
EFACLOUD_DB_PASSWORD=<your-password>
```

Change the admin credentials from the example defaults before production use.

## Environment variables

Set these in CapRover under **App Config → Environment Variables** (or in `.env` for local compose).

| Variable | Required | Purpose |
|----------|----------|---------|
| `EFACLOUD_AUTO_INSTALL` | No | Set to `1` to auto-run the installer on first start |
| `EFACLOUD_BASE_URL` | No | Bootstrap target URL (default `http://127.0.0.1` inside the container) |
| `EFACLOUD_DB_HOST` | Yes\* | Database host, e.g. `srv-captain--efacloud-db` |
| `EFACLOUD_DB_NAME` | Yes\* | Database name |
| `EFACLOUD_DB_USER` | Yes\* | Database user |
| `EFACLOUD_DB_PASSWORD` | Yes\* | Database password |
| `EFACLOUD_ADMIN_FIRST` | Yes\* | Admin first name |
| `EFACLOUD_ADMIN_LAST` | Yes\* | Admin last name |
| `EFACLOUD_ADMIN_EMAIL` | Yes\* | Admin email |
| `EFACLOUD_ADMIN_ID` | Yes\* | Numeric efaCloud login ID |
| `EFACLOUD_ADMIN_NAME` | Yes\* | Admin username (must not be `admin`) |
| `EFACLOUD_ADMIN_PASSWORD` | Yes\* | Admin password (must pass efaCloud password rules) |
| `TZ` | No | Container timezone (default `Europe/Berlin`) |

\*Required when `EFACLOUD_AUTO_INSTALL=1`. When auto-install is off, the same variables prefill the web installer forms.

Example CapRover configuration:

```text
EFACLOUD_AUTO_INSTALL=1
EFACLOUD_DB_HOST=srv-captain--efacloud-db
EFACLOUD_DB_NAME=efacloud
EFACLOUD_DB_USER=efacloud
EFACLOUD_DB_PASSWORD=<from MariaDB app>
EFACLOUD_ADMIN_FIRST=Max
EFACLOUD_ADMIN_LAST=Mustermann
EFACLOUD_ADMIN_EMAIL=admin@your-club.example
EFACLOUD_ADMIN_ID=1142
EFACLOUD_ADMIN_NAME=clubadmin
EFACLOUD_ADMIN_PASSWORD=<strong-password>
TZ=Europe/Berlin
```

Auto-install runs only when installation is not yet **complete**
(`install/.locked` or `config/.install_complete`). Interrupted setups that have
`settings_db` but no complete marker are resumed. After a successful run, the
installer is locked and later redeploys reuse the persisted `/var/www/html/config`
volume (including `.install_complete`).

Admin environment variables are **installation inputs**, not ongoing account
management. Changing them after setup does not change an existing password or
username. Manage accounts in efaCloud; for an administrator lockout use the
one-time server command in [admin password recovery](docs/ops/admin-password-reset.md).
After initial setup, remove the bootstrap admin password from the deployment
environment once it is safely recorded in your password manager.

Logbook information requires authentication, including the historical
`/public/fahrtenbuch.php` and `/public/info.php` URLs. Persisted upstream
`public_*` flags cannot grant anonymous access in this fork.

## Deploy on CapRover

Create two apps:

- `efacloud` — this repository
- `efacloud-db` — MariaDB or MySQL one-click app

### Web app (`efacloud`)

1. In CapRover, create app `efacloud`.
2. Connect the GitHub repo `thiesmoeller/efacloud` (branch `main`) or deploy the directory manually.
3. CapRover detects `captain-definition.json` and builds `Dockerfile`.
4. Set **Container HTTP Port** to `80`.
5. Add persistent directories:
   - `/var/www/html/config`
   - `/var/www/html/log`
   - `/var/www/html/uploads`
   - `/var/www/html/attachements`
   - `/var/www/html/pdfs`
   - `/var/www/html/resources`
6. Set the environment variables from the table above. Use `EFACLOUD_AUTO_INSTALL=1` for unattended first-time setup.

### Database app (`efacloud-db`)

Use CapRover's MariaDB app and note database name, user, and password. Use the same values for `EFACLOUD_DB_*` on the web app.

If you prefer manual setup instead of auto-install, set `EFACLOUD_AUTO_INSTALL=0` and enter the same values in the web installer. The forms are prefilled from the environment variables.

Legacy manual-only mapping:

```text
db_host: srv-captain--efacloud-db
db_name: <your-db-name>
db_user: <your-db-user>
db_up: <your-db-password>
```

Then finish setup at `https://efacloud.<your-domain>/`.

For the efa desktop client:

```text
URL: https://efacloud.<your-domain>/
```

## Security notes for this fork

- `config/settings_db` must never be committed. Rotate credentials if they were ever pushed to git.
- The installer is blocked automatically after setup (`install/.locked` + `config/.install_complete` + Apache deny).
- Until installation completes, Apache serves only `/install/` (public app/API return 403).
- Security hardening in this fork includes SQL identifier validation, XSS fix in maintenance page, upgrade version sanitization, and HTTP security headers in the Docker image.
- Staging/production isolation notes: `docs/deploy/caprover-staging-production.md`.
- **Go-live path (login → staging → cutover):** `docs/deploy/GO_LIVE.md`.
- Cutover / backup / acceptance: `docs/deploy/cutover-runbook.md`,
  `docs/deploy/backup-restore.md`, `docs/deploy/acceptance-checklist.md`.
- Local smoke (no CapRover): `docs/deploy/local-compose-smoke.md`,
  `./scripts/compose-local-smoke.sh`, `./scripts/portal-fixture-smoke.sh`.
- Focused security regressions: `tests/security/run.sh`.
- Local release-candidate suite: `./scripts/release-candidate-local.sh`.
- After CapRover login: `./scripts/wait-caprover-then-bootstrap.sh` or
  `./scripts/caprover-staging-bootstrap.sh --auto-create`.

## Backups and upgrades

Back up:

- the MariaDB/MySQL database
- the CapRover persistent directories listed above

Before upgrading:

1. Back up database and persistent directories.
2. Merge or cherry-pick upstream efaCloud releases.
3. Test locally with `docker compose`.
4. Redeploy on CapRover.
5. Run the in-app upgrade page as admin if prompted.

## License

efaCloud server code is GPL-2.0. See `license/LICENSE`.
