# Desktop SynchControl lab (local compose)

Local rehearsal of **EFA desktop → thiesmoeller-efacloud** cloud activation /
first upload (plan §4–5). Uses this fork’s compose + an overlay; does **not**
retarget parent `docker-compose.test.yml` (that harness still points at old
`efacloud/` for ROW R/8 work).

This is complementary to `backup-activation-rehearsal.md` (posttx-primary load
without the Java GUI). Here the goal is the real `SynchControl` /
`EfaCloudStorage` path.

## What is automated

| Step | Automated? |
|------|------------|
| Start fork `web`+`db` with `EFACLOUD_AUTO_INSTALL=1` | Yes |
| Create sync-capable **bths** account (numeric UserID, default **901**) | Yes |
| Build/run `efa-desktop` with club backup prepared (not baked into image) | Yes |
| Rewrite project `EfaCloudURL` / `StorageUsername` / `StoragePassword` / `StorageType=file/efaCloud` after `efa-prepare-backup` | Yes |
| `posttx` auth probe **from the desktop container** → `http://web/api/posttx.php` | Yes |
| Cloud **activation dialog** | Usually skipped — club backup already has `StorageType=file/efaCloud` |
| **SYNCH_UPLOAD_ALL** (`EfaCloudConfigDialog` → Upload Synchronisation) | **Yes** — `xdotool` in container (`--upload` / `--upload-only`) |
| MariaDB boat/person count verification after upload | Yes (`--verify-only`) |

EFA’s CLI (`MenuEfaCloud`) refuses efaCloud commands unless `TxRequestQueue` is
already live inside **efaBootshaus**. Standalone `efaCLI` cannot drive
`RQ_QUEUE_START_SYNCH_UPLOAD_ALL` (CLI `upload` only requests the last-30-days
upload, and only when TxRequestQueue is in-process). There is **no** file flag /
socket trigger for full first upload — shipping EFA exposes it only via
`EfaCloudConfigDialog` / Admin Mode → efaCloud. The lab drives that GUI on
`DISPLAY=:99` with `lab/desktop_synch/gui-synch-upload-all.sh`.

## Prerequisites

- Docker + compose plugin
- Fork `.env` (from `.env.example`); local passwords only
- Parent backup: `../test_backup/efaBackup_*.zip` + `.access` (not committed;
  first line `admin//…` is the local efa Admin-Mode password)
- Free host ports (defaults): **18083** (web; avoid 18081 rehearsal / 18082
  restore-drill), **6081** (noVNC), **5901** (VNC)

Do **not** set `EFACLOUD_PORTAL_FIXTURES`. Do **not** bake the backup into images.

## Start the lab

```bash
cd thiesmoeller-efacloud
./scripts/desktop-synch-lab.sh
# optional:
#   --port 18083 --novnc-port 6081 --bths-id 901
#   --no-build-desktop   # reuse efa-desktop:synch-lab
#   --skip-desktop       # cloud + bths + host probe only
#   --upload             # after start: drive SYNCH_UPLOAD_ALL via xdotool
```

Compose files used:

- `docker-compose.yml` — production fork web + MariaDB
- `docker-compose.desktop-synch.yml` — desktop service + lab ports/volumes

Open:

```text
noVNC:    http://127.0.0.1:6081/vnc.html
efaCloud: http://127.0.0.1:18083/forms/login.php
```

Re-run only the in-container posttx probe:

```bash
./scripts/desktop-synch-lab.sh --probe-only
```

## Credentials (desktop → posttx)

| Field | Lab default | Notes |
|-------|-------------|--------|
| URL | `http://web/` | Docker DNS name of compose service `web` |
| Username | `901` | **Numeric** efaCloud UserID (not admin name) |
| Password | `RehearseSync1!` | Lab-only; hashed into `efaCloudUsers` |
| Rolle | `bths` | Required for desktop sync write scope |

Admin `1142` from auto-install also works for posttx, but keep desktop clients on
**bths** (see `docs/security/release-review.md`).

Local **efa Admin Mode** login uses the club backup password from
`../test_backup/.access` (`admin//…`), not the efaCloud bths password.

## Automated SYNCH_UPLOAD_ALL

After the stack is up and posttx auth is WORKING:

```bash
./scripts/desktop-synch-lab.sh --upload-only
# or combined: ./scripts/desktop-synch-lab.sh --upload
./scripts/desktop-synch-lab.sh --verify-only
```

What `--upload-only` does inside `efa-desktop`:

1. Installs `xdotool` / ImageMagick / tesseract if missing (ephemeral apt).
2. Dismisses first-boot **Select Language** if present (`Alt+O`).
3. Waits for efaBootshaus title idle/WORKING (`✔` / queue glyphs).
4. **Admin Mode** → login from `.access` → **efaCloud** →
   **Upload Synchronisation starten**
   (`BUTTON_EFACLOUD_SYNCH_UPLOAD` → `RQ_QUEUE_START_SYNCH_UPLOAD_ALL`).
5. Polls until title returns to `✔` / log shows upload sync completed.

Requires `ExperimentalFunctions=true` in efa config (club backup already has it;
otherwise the efaCloud admin button is hidden).

## Manual noVNC steps (fallback)

1. Open noVNC → confirm boathouse UI with club boats/persons visible locally.
2. Watch the efaCloud status glyph (title bar). Wait until **WORKING** / **IDLE**
   (`✔`), not AUTHENTICATING / DISCONNECTED.
3. Admin login (`admin` + password from `.access`).
4. Admin Mode → **efaCloud**.
5. Click **Upload Synchronisation starten**.
6. Wait until status returns to IDLE/WORKING (~1–2 min for this backup).
7. If the menu only offers **Activate**: enter URL `http://web/`, UserID `901`,
   password `RehearseSync1!`, activate, **restart efa**, then repeat from step 4.

If activation fails with authorization errors, re-check:

```bash
./scripts/desktop-synch-lab.sh --probe-only
docker compose -p efacloud-desktop-synch logs --tail 100 web
```

## Verify MariaDB after upload

Expected ballpark from the club backup (same as posttx rehearsal): current
boats **58**, current persons **241**.

**Evidence (2026-10-02, project `efacloud-desktop-synch`, PASS):**

| Metric | Count |
|--------|------:|
| `efa2boats` rows | 208 |
| current boats | **58** |
| `efa2persons` rows | 922 |
| current persons | **241** |
| waters / destinations / boatstatus / messages / logbook 2026 | 215 / 25 / 155 / 210 / 200 |

Trigger: `xdotool` Admin Mode → efaCloud → Upload Synchronisation (not CapRover).
Log (desktop `efacloud.log`):

```text
09:07:52 STATECHANGE Synchronisation client to server (upload) starting
09:07:53 SYNCH Start upload synchronization for tables.
         [efa2autoincrement, efa2status, efa2waters, … efa2boats, efa2persons, …]
09:09:01 STATECHANGE … WORKING
09:09:01 SYNCH Synchronization transactions completed.
```

Re-check:

```bash
./scripts/desktop-synch-lab.sh --verify-only
# or:
source .env
docker compose -p efacloud-desktop-synch exec -T db \
  mariadb -u"$EFACLOUD_DB_USER" -p"$EFACLOUD_DB_PASSWORD" "$EFACLOUD_DB_NAME" -e "
SELECT COUNT(*) AS boat_rows FROM efa2boats;
SELECT COUNT(DISTINCT Id) AS current_boats FROM efa2boats
  WHERE CAST(ValidFrom AS UNSIGNED) <= UNIX_TIMESTAMP()*1000
    AND CAST(InvalidFrom AS UNSIGNED) > UNIX_TIMESTAMP()*1000;
SELECT COUNT(*) AS person_rows FROM efa2persons;
SELECT COUNT(DISTINCT Id) AS current_persons FROM efa2persons
  WHERE CAST(ValidFrom AS UNSIGNED) <= UNIX_TIMESTAMP()*1000
    AND CAST(InvalidFrom AS UNSIGNED) > UNIX_TIMESTAMP()*1000;
"
```

## Portal API on this stack

After upload, exercise the portal JSON API against the same MariaDB (no fixtures):

```bash
./scripts/portal-live-synch-smoke.sh
# optional second SynchControl pass + assert current boats still 58:
./scripts/portal-live-synch-smoke.sh --second-upload
```

Creates local-only portalTrainer user **902** (Concession 131072) linked to a real
PersonId. See progress note in `docs/plans/production-dockside-pwa-progress.md`.

## Tear down

Stack left **up** after the PASS run (ports 18083 / 6081) for inspection.

```bash
docker compose -p efacloud-desktop-synch -f docker-compose.yml \
  -f docker-compose.desktop-synch.yml down
# wipe DB + desktop home:
docker compose -p efacloud-desktop-synch -f docker-compose.yml \
  -f docker-compose.desktop-synch.yml down -v
```

## Explicitly not covered

- CapRover HTTPS SynchControl (still open / ops-blocked)
- Parent `docker-compose.test.yml` / ROW R/8
- Headless in-process `RQ_QUEUE_START_SYNCH_UPLOAD_ALL` without the boathouse GUI
  (shipping EFA has no such hook; lab uses GUI automation instead)
