# Cutover runbook — desktop → efaCloud + dockside PWA

Audience: club ops + deployer. Domains, CapRover credentials, and backup
destinations are supplied outside this repository.

**Do not mark production released** until `docs/deploy/acceptance-checklist.md`
gates pass on the promoted image.

## Preconditions

- [ ] Staging signed off on the **same** image digest that will go to production
- [ ] Production CapRover web + DB apps provisioned (isolation checklist in
      `caprover-staging-production.md`)
- [ ] DB + persistent-file backup jobs green; restore practiced on staging
      (`backup-restore.md`)
- [ ] Initial cloud activation **rehearsed** on staging with a **copy** of a
      desktop backup (see below)
- [ ] Desk/PWA accounts planned (admin-provisioned, linked to EFA persons)
- [ ] Manual paper/whiteboard fallback agreed for outages

## Initial cloud activation rehearsal (staging)

Use a **copy** of the desktop backup — never the only club copy, and never bake
the zip into an image.

1. On a staging efaCloud that is install-complete, import / activate cloud sync
   from the backup copy using EFA's supported cloud activation flow (desktop →
   this efaCloud URL). Exact desktop UI steps follow the club's EFA version;
   record the build/version used.
2. Verify after first sync:
   - Boats (current valid only; historical versions not double-counted as active)
   - Persons
   - Destinations
   - Historical trips present in server logbook
   - Ongoing / open trips and boat status (`ONTHEWATER` linkage)
   - Open and repaired damage records
3. Run **repeated** synchronization (second and third sync / idle cycles). Confirm
   no silent loss, no duplicate active boats, and boat status stays consistent
   with the logbook.
4. Spot-check portal/PWA read paths against the same fleet (staging HTTPS).
5. Capture counts (boats / persons / open trips / open damages) for cutover day
   comparison.

Parent-workspace `test_backup/` may hold the local zip for rehearsal. Do not
commit it. Do **not** use parent `TEST_ENV.md` ROW R/8 replay as this cutover path.

**Local compose rehearsal (not CapRover):** 
`./scripts/backup-activation-rehearsal.sh` — see
`docs/deploy/backup-activation-rehearsal.md`. Proves MariaDB load + portal +
posttx overlap locally; still run the desktop SynchControl steps above on
staging before cutover.

## Cutover day (production)

### 1. Pause trip entry

- Announce a freeze: no new desktop trips, no desk/PWA writes.
- Finish or explicitly leave open any in-progress sessions per club policy;
  record open boats/trips on paper if needed for reconciliation.

### 2. Fresh desktop backup

- Create a new `efaBackup_*.zip` on the authoritative desktop.
- Copy it to the secure ops location (not git). Verify zip integrity (size /
  open test).

### 3. Activate production cloud sync

- Ensure production web is on the signed-off image, HTTPS, single instance, DB
  healthy, volumes mounted.
- Point desktop cloud URL at production; activate / import from the **fresh**
  backup per EFA's flow.
- Wait for initial synchronization to complete; keep the freeze in place.

### 4. Verify data

Compare against rehearsal counts and spot-checks:

| Check | Pass criteria |
|-------|----------------|
| Boats | Current fleet count; seat/variant sample matches desktop |
| Persons | Expected membership sample |
| Destinations | Sample with/without distance |
| Trips | Recent history + any intentional open trips |
| Damage | Open severities + at least one repaired history sample |
| Sync | Second sync cycle clean |

### 5. Enable desk / PWA

- Provision or unlock portal accounts (member / trainer concession as needed).
- Enable dockside PWA URL for club devices (`/portal/`).
- Lift the freeze: allow desktop + PWA writes.
- Watch first live trips on both clients; confirm cross-visibility.

## Rollback + reconciliation

Rollback is **not** “restore last night's DB and forget recent activity.”

### If cutover must be aborted before enabling desk/PWA

1. Keep freeze in place.
2. Disable cloud sync / point desktop back to local-only per club procedure.
3. Production cloud may be left installed but unused; do not delete volumes until
   the abort decision is final and backups are secured.
4. Resume desktop-only operation from the fresh backup + any paper notes from the
   freeze window (should be empty if freeze held).

### If trips were entered after cutover and a restore is required

1. **Immediately** pause all digital writes (desktop cloud + PWA + desk).
2. Export / list every trip, damage report, and boat-status change **after** the
   snapshot you might restore (admin UI, SQL extract, or desktop reports —
   operator choice). Store that list off-box.
3. Restore DB + files only if necessary; choose the snapshot deliberately.
4. Re-apply or re-enter the post-snapshot activity from the reconciliation list
   (manual entry on desktop or portal). Prefer re-entry over silent discard.
5. Re-run sync and consistency checks (open trips ↔ `BoatStatus`, damage
   indicators).
6. Only then lift the write pause.

Restoring an older snapshot **without** reconciliation silently discards
post-cutover activity — that is a failed rollback.

## Outage manual fallback

Agreed product policy: online PWA writes only; during connection failures use
manual fallback and **pause digital trip writes** until synchronization and
consistency checks complete.

| Phase | Action |
|-------|--------|
| Detect outage | PWA shows offline; desktop sync errors; monitoring alert |
| Immediate | Pause PWA/desk digital writes; use paper / whiteboard for trips |
| During outage | Do not invent dual-write to a second system as source of truth |
| Recovery | Restore connectivity; sync; reconcile paper vs server (open boats, times) |
| Resume | Lift pause only after boat status and logbook agree |

Automated desktop enforcement of “no writes while cloud down” requires separately
validated desktop support — **do not assume it already exists**. Operational
pause is mandatory regardless.

## Related docs

- Isolation / promote / health: `caprover-staging-production.md`
- Backups: `backup-restore.md`
- Acceptance gates: `acceptance-checklist.md`
- Local smoke (no CapRover): `local-compose-smoke.md`
