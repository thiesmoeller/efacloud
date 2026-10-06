# Backup → cloud activation rehearsal (local compose)

**Scope:** local docker compose MariaDB only. This is a staging-equivalent
**rehearsal** of desktop-backup activation outcomes and portal/cross-client
checks. It does **not** complete CapRover production, real EFA desktop
`SynchControl` GUI sync, device QA, or cutover.

## Prerequisites

- Fork checkout with `.env` (copy from `.env.example`; local passwords only)
- Docker + Python 3
- Desktop backup zip **outside** the repo, e.g.
  `/home/thies/Projects/efa_new/test_backup/efaBackup_20260607_095337.zip`
- Do **not** commit the zip or extracted club PII
- Do **not** set `EFACLOUD_PORTAL_FIXTURES` (the script refuses if set)

## How to run

```bash
# Default: port 18081, project efacloud-rehearsal, tear-down optional
./scripts/backup-activation-rehearsal.sh --down-volumes

# Custom zip / port / keep stack up for debugging
./scripts/backup-activation-rehearsal.sh \
  --backup /path/to/efaBackup_*.zip \
  --port 18081

# Already extracted (e.g. /tmp/efa_bk_inspect)
./scripts/backup-activation-rehearsal.sh --extract-dir /tmp/efa_bk_inspect
```

`lab/backup_rehearsal/` is local-only tooling (mounted read-only for optional
in-container use; **not** `COPY`'d into the production image).

## What the harness does

1. Starts compose with auto-install (same pattern as
   `scripts/compose-local-smoke.sh`), free host port (default **18081**).
2. Parses EFA native backup (`*.efa2boats`, boatstatus, damages, reservations,
   persons, destinations, current-year logbook).
3. **SQL bootstrap only:** creates rehearsal `bths` (901) + portalTrainer (902),
   clears portal-relevant club tables.
4. **Primary club-data load via `/api/posttx.php`** (API v3 `insert` →
   `efa_api` / `efa_record`), in order: persons → boats → destinations →
   boatstatus → damages → reservations → logbook. Client mints `ecrid`;
   autoincrement keys (`EntryId` / `Damage` / `Reservation`) omitted so the
   server assigns them (same as `EfaCloudStorage.modifyServerRecord`).
5. Verifies counts vs backup XML: current boats (not historical doubles),
   persons, destinations, open/repaired damages, on-water status, open trips.
6. **Second synch:** re-upload via posttx `update` by `ecrid`; assert no new
   boat rows / no duplicate active boats.
7. Portal API against that DB (**not** fixtures): list boats, start / finish /
   abort trip, report damage; confirm boatstatus ↔ logbook linkage.
8. **Cross-client:** portal start → desktop-style `posttx` update of boatstatus
   while trip open → assert still `ONTHEWATER` + `EntryNo`; portal finish with
   stale `ChangeCount` → `STALE_STATE`; finish with correct count → consistent
   status+logbook.

## SynchControl / API v3 semantics (and documented deviations)

| Behaviour | Harness |
|-----------|---------|
| Client-minted `ecrid` before insert | Yes (`generate_ecrid`, matching desktop) |
| Preserve XML `ChangeCount` / `LastModified` on wire | Yes; server `register_modification` bumps CC and refreshes LM (API ≥ 3) |
| Strip autoincrement on insert | Yes (`EntryId` / `Damage` / `Reservation`) |
| German dates from XML | Converted to ISO `Y-m-d` before posttx — MariaDB `DATE` columns reject `DD.MM.YYYY` under STRICT. Real SynchControl sends DE via `getAsText`; this is a practical deviation. |
| `LastModification` | Set `insert` when missing; server overwrites on write |
| Logbook `Open` | Derived from `EndTime` when XML omits it |
| `boatstatus.EntryNo` vs remapped logbook `EntryId` | May disagree after server autoincrement (desktop also remaps). Portal trip fixture uses `AVAILABLE` boats. |
| Second synch | `update` with `ecrid` (+ `Id`/`ValidFrom` for versionized keys so the server does not mint a new UUID). No full content rewind — historical versions reject content edits; autoincrement keys were remapped on insert. Proves no duplicate current boats. |
| Historical logbook years / clubwork / waters / groups / status / config upload | Not loaded (still gaps) |
| SQL fallback for club data | **None** for portal-critical tables. SQL only for users + table clear. |

## What is proven

| Area | Local evidence |
|------|----------------|
| Fleet load from real backup through posttx/`efa_record` | Count checks vs XML |
| Historical boat versions not counted as active | Distinct `Id` with validity window |
| Portal reads/writes on real DB | Trip + damage + boatstatus |
| Repeated synch without silent duplicate actives | Second posttx update pass |
| Cross-client ChangeCount / status consistency | Portal + posttx on shared DB |

## What still needs real EFA desktop + CapRover

- Full desktop **cloud activation** UI and `SynchControl` / `EfaCloudStorage`
  first-upload (this harness drives the same HTTP write path; it is not the Java
  client).
- Historical logbook years beyond the current year (loader is minimal: current
  year only).
- Clubwork, waters, groups, messages, statistics, project/types/config binary
  upload handshake (`upload` + `Efa_config::parse_client_config`).
- CapRover staging/production apps, HTTPS, private DB networking, backup
  destinations, restore drill, cutover freeze window.
- Device QA (iOS Safari / Android Chrome PWA).
- Conflicting **simultaneous** desktop checkout races beyond the posttx overlap
  exercised here (release still wants staging desktop↔PWA).

See also: `cutover-runbook.md`, `acceptance-checklist.md`,
`local-compose-smoke.md`.
