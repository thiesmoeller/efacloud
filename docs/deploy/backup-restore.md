# Backup and restore (efaCloud on CapRover)

Reference: [CapRover backup and restore](https://caprover.com/docs/backup-and-restore).

**Critical CapRover limitation:** CapRover's built-in backup covers CapRover
configuration (apps, nginx, etc.). It does **not** include application Docker
volumes or images. Club data lives in MariaDB and the web app persistent
directories — those need a **separate** backup pipeline.

Keep backup destinations, credentials, and bucket names **out of this
repository**.

## What to back up

| Component | Contents | CapRover config backup? |
|-----------|----------|-------------------------|
| MariaDB / MySQL data | All efaCloud tables (boats, persons, logbook, damages, users, …) | No |
| Persistent web volumes | `config`, `log`, `uploads`, `attachements`, `pdfs`, `resources` | No |
| CapRover app definitions | Env vars, nginx, HTTPS certs metadata | Yes (CapRover) |
| Container image | Built image digest/tag | No (rebuild from git tag or keep registry copy) |

Never rely on CapRover config backup alone before cutover.

## Database backup (application)

Preferred: logical dump from the DB container / host (operator-chosen tooling).

Example pattern (placeholders only — adjust host/auth outside the repo):

```bash
# Run against the CapRover DB service or a tunnel — credentials from secrets store
# On MariaDB 11 containers prefer mariadb-dump (same --single-transaction semantics).
mysqldump --single-transaction --routines --triggers \
  -h "$EFACLOUD_DB_HOST" -u "$EFACLOUD_DB_USER" -p \
  "$EFACLOUD_DB_NAME" > "efacloud-db-$(date -u +%Y%m%dT%H%M%SZ).sql"
```

Store the dump off-host. Encrypt at rest if the destination is shared.

Verify periodically:

- Non-empty file / expected size band
- `HEAD`/`grep` for key table names (e.g. logbook / boat tables)
- Restore into a disposable staging DB (see below)

## Persistent files backup

Archive the mounted paths (same list as CapRover persistent directories):

- `/var/www/html/config` — **includes** `settings_db`, `.install_complete`
- `/var/www/html/log`
- `/var/www/html/uploads`
- `/var/www/html/attachements`
- `/var/www/html/pdfs`
- `/var/www/html/resources`

Example pattern (run on the CapRover host or via a backup sidecar; paths are
illustrative):

```bash
tar -C /var/lib/docker/volumes -czf "efacloud-files-$(date -u +%Y%m%dT%H%M%SZ).tgz" \
  <staging-or-prod-volume-names>
```

Prefer CapRover/volume-aware tooling your host already uses; do not commit volume
IDs or host paths into git.

## Desktop EFA backup (cloud activation input)

Initial cloud activation and cutover use a **desktop** `efaBackup_*.zip` produced
by EFA. That zip is club PII:

- Keep it under the parent workspace `test_backup/` or another local path
- Ensure `.gitignore` / `.dockerignore` exclude it (already true for this fork)
- **Never** `COPY` it into the Docker image or CapRover build context

Sanitized structural fixtures for API smoke live in `fixtures/sanitized/` only.

## Local compose restore drill (application data)

Prove DB + persistent-files restore **without** CapRover credentials:

```bash
./scripts/compose-backup-restore-drill.sh
# defaults: project efacloud-restore-drill, port 18082, cleanup --down-volumes
```

What it does:

1. Compose up + auto-install on a free host port
2. Seeds a SQL marker table + file markers under `config` / `log` / `uploads` /
   `attachements` / `pdfs` / `resources`
3. `mariadb-dump --single-transaction` from the db container (MariaDB 11;
   equivalent to classic `mysqldump --single-transaction`)
4. `tar` archive of the persistent web paths (same list as CapRover volumes)
5. `docker compose down -v` (destroy data)
6. Recreate DB → import dump → extract files into named volumes → start web
7. Verifies `.install_complete` / `settings_db`, seeded token, app HTTP 200,
   `/install/` 403

Artifacts land under gitignored `var/restore-drill/`. This does **not** prove
CapRover host volumes, registry image pull, HTTPS, or off-host backup jobs.

## Restore practice (staging CapRover, before production cutover)

Goal: prove you can rebuild a working **CapRover staging** app from backups alone.
Local compose PASS above is necessary but not sufficient.

1. Note current staging image tag/digest.
2. Take a fresh DB dump + files archive of staging (or use a known rehearsal set).
3. Destroy or rename staging web/DB apps **or** use a throwaway CapRover app pair
   (preferred) so production is untouched.
4. Recreate DB app; restore dump into an empty database.
5. Recreate web app with the **same** image tag; attach empty persistent volumes;
   restore file archive into those paths (especially `config/` with
   `.install_complete` / `settings_db`).
6. Set env vars from the secrets store (not from git).
7. Start web; confirm:
   - Installer stays locked (`/install/` denied)
   - `/portal/` and `/forms/login.php` return 200
   - Spot-check boats / persons / open trips / damages via admin or portal
8. Document time-to-restore and any missing pieces (image pull, DNS, certs).

**Still CapRover-only (local drill does not cover):**

- CapRover persistent-directory restore on the host / volume IDs
- Off-host backup destination + encryption + failure alerts
- Staging HTTPS / DNS / certs after recreate
- Image pull by digest from the registry (not only local `docker compose build`)
- Env vars from CapRover secrets store (not repo `.env`)

If restore requires re-running the web installer, treat that as a **failed**
files backup (`.install_complete` / `settings_db` not restored). Fix the file
backup before cutover.

## Production restore notes

- Restoring an **older** snapshot after cutover can drop trips entered since the
  snapshot. Follow `docs/deploy/cutover-runbook.md` reconciliation before any
  destructive restore.
- After restore, re-verify desktop ↔ cloud sync and pause digital writes until
  consistency checks pass (outage fallback).

## Backup-failure alerts

Schedule DB + files jobs independently of CapRover config backup. Alert on:

- Job non-zero exit
- Missing artifact / size below threshold
- Age of newest successful backup exceeding the agreed RPO

Alert destinations stay outside the repository.
