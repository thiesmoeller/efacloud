# Progress: production dockside PWA

Updated: 2026-10-02  
Plan: `docs/plans/production-dockside-pwa.md`

---

## Step 1 — Characterize behavior and establish fixtures

### Completed

- Parity matrix: `docs/parity/parity-matrix.md` (boat selection, departure checks,
  damage, trip lifecycle, crew/cox/Obmann, midnight, permissions, sync hooks).
- Trace notes: `docs/parity/trace-notes.md` (Java ↔ PHP write paths, identifiers,
  sync metadata, config transport, efaWeb divergences).
- Sanitized fixtures: `fixtures/sanitized/` (+ README documenting sanitization).
- `.gitignore` excludes `fixtures/efa-backup/` and `test_backup/` (raw PII / zips).
- Club behavioral config sampled from local backup into `fixtures/sanitized/club-config.json`
  (keys only; backup itself not committed).

### Validation evidence

- Desktop check order verified in `EfaBoathouseFrame.checkStartSessionForBoat`
  (status → reservations → damage) and `checkBoatDamage` / `BoatDamages.warnDamage`.
- Abort vs abort-with-damage verified in `actionAbortSession`.
- Seat normalization `4X→4` in `BoatRecord.getGeneralNumberOfSeatsType`; list sections
  in `ItemTypeBoatstatusList.sortBootsList`.
- Name sort is umlaut-folding string compare, not `Collator(GERMAN)`.
- PHP equivalents traced via `api/posttx.php`, `efa_api.php`, `efa_record.php`,
  `efa_tables.php`, and efaWeb JS (`bFormHandler.js`, `oBoat.js`, `oDamage.js`).
- Local backup structure inspected (≈57 current boats; seat mix 1/2/3/4/8; open
  damages of all three severities; config keys listed in trace notes). No automated
  desktop UI run in this step.

### Deviations discovered

1. **Alle/Einer/Zweier filters:** Plan describes first-class seat filters; desktop
   uses seat-sorted **section headers** + digit jump + text filter. PWA chips are
   UI adaptation; categories must still follow `getGeneralNumberOfSeatsType`.
2. **German collation:** Desktop does **not** use ICU/`Collator`; it uses
   `replaceAllUmlautsLowerCaseFast`. Matching that is the parity target.
3. **Trainer role:** Absent in Java and in efaCloud `Rolle` hierarchy. Plan trainer
   privilege is a **new portal** ACL; closest stock role is `bths`/concessions —
   not equivalent. Desktop “trainer” is only a possible club **group**.
4. **Current club fleet multi-variants:** Active versions are mostly single-variant;
   rich `2;2X` / `3;4X` / `4;4X` patterns appear in **historical** versions. Sanitized
   fixtures intentionally keep parallel variants for PWA coverage.
5. **efaWeb BoatCaptain=1** and open-damage list hiding `FULLYUSEABLE` diverge from
   desktop — must not be copied.
6. Config key names are misleading: `MustEnterDistance` / `InputWarnOnlyCriticalBoatDamages`
   (see `club-config.json` notes).
7. Parallel work: Step 2 packaging progress was already recorded in this file; left intact below.
8. Workspace `TEST_ENV.md` / `docker-compose.test.yml` still document ROW R/8 migration
   targets — reuse only after path/behavior check (also noted in the plan).

### Remaining gaps (later steps)

- No converter yet from `fixtures/sanitized/*.json` → importable efaCloud DB / EFA XML.
- No automated parity tests executing desktop vs portal outcomes.
- Acknowledgment persistence / revalidation protocol for overrides not designed (step 3).
- Portal trainer privilege schema not implemented.
- Live CapRover / compose rehearsal of backup → cloud synch not run under this step.
- Step 2 security packaging may proceed independently; steps 3–5 still open.

---

## Step 2 — Harden packaging and bootstrap

Updated: 2026-10-02

## Completed

### Image / packaging
- Replaced blanket `COPY .` with explicit application `COPY` paths in `Dockerfile`.
- Expanded `.dockerignore` to exclude secrets, local env, git/editor/agent scratch,
  lab/demo/fixtures, portal `node_modules`/`dist`, caches, tests, and backup zips.
- Documented rationale in `docs/deploy/image-contents.md`.
- Workspace `demo/`, `fixtures/`, `lab/`, `portal/` left intact; excluded from the image.

### Install lifecycle
- **Complete** = `install/.locked` and/or `config/.install_complete`
  (written by `setup_finish.php` via `efacloud_install_mark_complete()`).
- **Incomplete** = `config/settings_db` (or `dbSettings`) without a complete marker.
- **Fresh** = neither settings nor complete marker.
- Entrypoint no longer treats `settings_db` alone as installed; interrupted bootstrap
  resumes (`install/.db_ready` skips re-init when DB was already seeded).
- `config/.install_complete` survives CapRover redeploys on the existing config volume;
  entrypoint mirrors it to `install/.locked`.
- Apache denies non-`/install/` requests until complete; blocks `/install/` once complete.
- Root `index.php` routes incomplete setups back to the installer, not the public app.

### Staging docs
- `docs/deploy/caprover-staging-production.md` — separate apps/DBs/volumes, env
  isolation, promotion, install states. No real secrets or domains in-repo.
- `.env.example` and README updated for complete-vs-incomplete install behavior.

### Security regressions
- Suite: `tests/security/run.php` (+ `tests/security/run.sh` via host PHP or
  `php:8.2-cli` Docker).
- Covers install state/guard allow/deny and `Efa_tables::is_safe_table_name` /
  socket identifier regex alignment; asserts page gates and socket call sites.
- Extended `assert_safe_sql_identifier` to `insert_into` and `update_record_matched`
  (previously only delete/find matched paths from `ab35fcd`).
- **Evidence:** `./tests/security/run.sh` → **47 passed, 0 failed** (2026-10-02).
- **Evidence:** `docker build -t efacloud-packaging-test .` succeeded; image lacks
  `demo/`, `lab/`, `fixtures/`, `portal/`, `.env`, and `settings_db`.

## Remaining gaps (later security review / release)

- Full review of legacy routes (authz bypass, XSS, CSRF, uploads, sessions) still
  outstanding; `ab35fcd` is hardening, not a completed security review.
- SQL identifier checks do not yet cover all column-name interpolation
  (`clause_for_wherekeyis` and similar).
- Raw `query()` still accepts caller-built SQL.
- Portal API / PWA UI not started (steps 3–4).
- End-to-end interrupted-bootstrap test against a running CapRover/compose stack
  not executed in this step (logic + unit coverage only).
- No committed portal frontend source yet; image correctly omits portal artifacts.
- Staging/production CapRover apps and restore rehearsal still to provision (step 5).

---

## Step 3 — Shared domain services + portal JSON API

Updated: 2026-10-02

### Completed

#### Domain services (`classes/portal/`)
- Identity/session: cookie name `EFA_PORTAL`, CSRF, login throttle, logout,
  revoked-account rejection (`Portal_session`).
- Boats: variants, seat categories via `getGeneralNumberOfSeatsType` semantics,
  umlaut-fold sort, views available/onwater/unavailable, damage indicators
  including open `FULLYUSEABLE` (`Portal_boats`).
- Departure checks in EFA order + acknowledgment tokens with snapshot hashes
  and revalidation (`Portal_departure`).
- Trips: start / correct / finish / abort / abort-with-damage; atomic UoW;
  sync metadata `LastModified` / `ChangeCount` / `LastModification` / `ecrid`;
  idempotency keys; stale ChangeCount rejection (`Portal_trips`).
- Damage report without finishing/aborting trips (`Portal_damage`).
- Permissions: member = participant; **trainer = Concession bit 131072**
  `portalTrainer` (`Portal_permissions` + `config/access/concessions`).

#### API
- Front controller `api/portal/v1/index.php` + `.htaccess` rewrite.
- Contract: `docs/api/portal-v1.md`; Apache notes: `docs/api/portal-apache.md`.
- Fixture store for tests; DB store (`Portal_db_store`) when install complete.
- Desktop `/api/posttx.php` untouched.

#### Packaging
- `Dockerfile` already `COPY api` and `COPY classes` — portal API classes ship
  in the image. Fixtures/secrets remain excluded (`.dockerignore`).

### Validation evidence

- `./tests/portal/run.sh` → **50 passed, 0 failed** (2026-10-02).
- `./tests/security/run.sh` → **47 passed, 0 failed** (unchanged).

### Deviations / design choices

1. Trainer privilege implemented as Concession bit **131072** (not a Rolle, no
   DB schema migration). Fixtures user 102: `Concessions: 131072` /
   `TrainerPrivilege: true`.
2. Password reset is documented as admin-assisted only (no self-service endpoint).
3. DB repository uses `mysqli` transactions when available; fixture store uses
   snapshot rollback for atomicity in tests.
4. When DB is unavailable and fixtures are not present in the image → HTTP 503
   (production path requires completed install + DB).

### Remaining gaps (steps 4–5)

- ~~React/TypeScript PWA UI against this contract (step 4).~~ → see Step 4 below.
- Live integration against docker-compose / CapRover with real MariaDB.
- Cross-client desktop↔portal write races beyond ChangeCount unit coverage.
- Full legacy-route security review still open from step 2.
- Backup/restore and cutover rehearsal (step 5).

---

## Step 4 — Mobile workflow (dockside React/TypeScript PWA)

Updated: 2026-10-02

### Completed

#### Frontend (`portal/`)
- Replaced artifact-only `portal/` with a maintainable Vite + React 19 + TypeScript
  app (`package.json`, lockfile, `vite.config.ts`, tsconfigs, `index.html`, `src/**`).
- German UI; `credentials: 'include'`; CSRF via `X-CSRF-Token`; success only on 2xx.
- Screens: login/logout/session, boat list (seat chips from `seatCategories`, views
  Verfügbar/Auf Fahrt/Nicht verfügbar, search, secondary filters, badges, damage
  indicators), compact variant pick, boat detail, trip start/correct/finish/abort/
  abort-with-damage, ACK dialogs (`ACK_REQUIRED` / `ACK_STALE` in EFA order with
  API German copy), damage open/history/report.
- Online writes only; offline banner; no reservation creation / repair admin / bulk.
- Does not force `BoatCaptain=1`.
- PWA: `manifest.webmanifest`, icons, `sw.js` caches `/portal/` assets only;
  `/api/` network-only; SW update banner.

#### Packaging
- Multi-stage `Dockerfile`: Node builds portal → copy `dist` to `/var/www/html/portal`.
- `docker/apache-efacloud.conf` Alias + FallbackResource for `/portal/`.
- `.dockerignore` allows portal source for the build stage; excludes
  `portal/node_modules` and `portal/dist` from context junk; final image still
  has only built assets.
- Docs: `docs/api/portal-apache.md`, `docs/deploy/image-contents.md`, `portal/README.md`.

### Validation evidence

- `cd portal && npm run test` → **8 passed, 0 failed** (seat-filter chips + ack-flow
  client helpers).
- `cd portal && npm run build` → success; output under `portal/dist`
  (`index.html`, hashed assets, `sw.js`, manifest, icons, `.htaccess`).
- `docker build -t efacloud-portal-step4-test .` → success; image contains
  `/var/www/html/portal/index.html` (+ assets), no `portal/node_modules`, no
  fixtures; Apache conf includes `/portal` Alias.

### Deviations / design choices

1. Client-side secondary filters (rigging/coxing/hull) applied after API list;
   seat category / view / search still server-driven.
2. On `ACK_STALE`, client clears all acknowledgment tokens and re-walks dialogs
   (safe; avoids opaque token↔kind mapping).
3. Dev proxy `/api` → `http://127.0.0.1:8080` (override `VITE_API_PROXY`);
   documented in `portal/README.md` / `vite.config.ts`.

### Remaining gaps (step 5)

- Device QA (iOS Safari / Android Chrome install, offline messaging, SW updates).
- CapRover staging/production image promote with live MariaDB + club fleet.
- ~~Security review of legacy routes + portal session in production HTTPS.~~ → see Step 5 security below.
- Cross-client desktop↔PWA race / cutover / backup-restore rehearsal.
- End-to-end UI against running portal API not executed in this step (unit + build only).

---

## Step 5 — Security review (release gate portion)

Updated: 2026-10-02

### Completed

- Full pass over exposed routes: `api/`, `pages/`, `forms/`, `public/`, `install/` (locked),
  `portal/` static, `index.php`, plus portal `api/portal/v1/` and `classes/portal/`.
- Report: `docs/security/release-review.md` (findings table with severity + status).

#### Release-blocking fixes
1. **`public/phpinfo.php`** — require `init.php` (admin menu ACL); was unauthenticated.
2. **Member `/api/posttx.php` writes** — `Efa_member_write_guard` in `efa_api::api_modify`:
   members limited to logbook / boatstatus / damages / messages; trips need participant or
   `portalTrainer`; **bths/board/admin unchanged** (desktop sync).
3. **SQL WHERE clause / field keys** — `clause_for_wherekeyis` operator allowlist + IN sanitizer;
   insert/update/delete validate field keys as SQL identifiers.
4. **`pages/getrecord.php`** — `is_safe_table_name` + XSS escaping.
5. **Portal fixture fallback** — installed + DB down → **503** (no silent fixture passwords).
6. **Upload filenames** — `basename()` on import/storage forms.

### Validation evidence

- `./tests/security/run.sh` → **97 passed, 0 failed** (was 74; +R11 HTTPS detection).
- `./scripts/https-cookie-drill.sh --down` → **PASS** (Secure on :18443; absent on plain HTTP).
- `./tests/portal/run.sh` → **50 passed, 0 failed**.

### Deviations / residual (ops)

- Legacy form CSRF remains sequence-token based (accepted); portal uses `X-CSRF-Token`.
- CapRover TLS termination Secure cookies: **Partial↑** — app trusts
  `X-Forwarded-Proto: https` (`Portal_session::request_is_https`); local
  `./scripts/https-cookie-drill.sh` PASS. CapRover staging Force HTTPS still ops.
- Do not use `member` role for desktop synch accounts; do not enable
  `EFACLOUD_PORTAL_FIXTURES` in production.
- Cutover / backup-restore / device QA still open for the rest of step 5.

---

## Step 5 — Deploy / acceptance (docs + local rehearsal)

Updated: 2026-10-02

**Production is not released.** Documentation and local rehearsal prep only. Live
CapRover, domains, backup destinations, and cutover remain external ops gates.
Security fixes above are a separate release-gate portion of the same step.

### Completed locally (no CapRover credentials required)

#### Deploy documentation (`docs/deploy/`)
- Extended `caprover-staging-production.md`: isolation checklist (HTTPS, private
  DB, persistent volumes, single web instance), versioned image promote
  staging→prod, health / DB readiness / backup-failure alert expectations.
- `backup-restore.md` — DB + persistent files separate from CapRover config;
  restore practice; points at
  [CapRover backup and restore](https://caprover.com/docs/backup-and-restore).
- `cutover-runbook.md` — activation rehearsal, cutover day (pause → fresh
  desktop backup → activate → verify → enable desk/PWA), rollback + trip
  reconciliation, outage manual fallback / pause digital writes.
- `acceptance-checklist.md` — gates mapped to parity matrix IDs; tagged
  `auto-unit` / `auto-local` / `device` / `staging` / `ops`.
- `local-compose-smoke.md` — local procedures; explicitly **does not** follow
  parent `TEST_ENV.md` / ROW R/8 `docker-compose.test.yml`.

#### Local scripts / compose
- `scripts/compose-local-smoke.sh` — compose build/up + HTTP readiness probes
  (`/portal/`, `/forms/login.php`, `/api/portal/v1/session`) without CapRover.
  Supports `EFACLOUD_HTTP_PORT` when 8080 is occupied.
- `scripts/portal-fixture-smoke.sh` + `scripts/portal-fixture-router.php` —
  sanitized-fixture portal API smoke (login, boats/persons/destinations/trips/
  damage, trip start shape). Default password `fixture-pass` for users 101/102.
- `docker-compose.portal-fixtures.yml` — local-only overlay mounts
  `fixtures/sanitized` and sets `EFACLOUD_PORTAL_FIXTURES=1` (never for CapRover).
- `.env.example` documents `EFACLOUD_PORTAL_FIXTURES` as local-only.
- Fixtures README documents the smoke path; raw `test_backup/` / PII remain
  gitignored and out of images.

#### Validation evidence (2026-10-02)
- `./scripts/portal-fixture-smoke.sh` → **passed** (ephemeral PHP via Docker
  `php:8.2-cli`; sanitized fixtures only).
- `EFACLOUD_HTTP_PORT=18080 ./scripts/compose-local-smoke.sh --down` → **passed**
  (auto-install; portal/login/session 200; `/install/` 403).
- Prior suites remain the automation baseline (portal/security unit tests; see
  steps 2–4 and security portion above).

#### Packaging fix found during local smoke
- `docker/apache-efacloud.conf`: Apache 2.4.68 rejects `<LocationMatch>` nested in
  `<If>`. Replaced pre-install gate with `RewriteCond`/`RewriteRule` [F] while
  keeping the same lock-file semantics (`install/.locked` /
  `config/.install_complete`).

---

## Step 5 — Backup activation rehearsal (local compose MariaDB)

Updated: 2026-10-02 (posttx-primary load)

**CapRover production is not done.** This is a local staging-equivalent rehearsal
of desktop-backup → cloud DB outcomes + portal/cross-client checks.

### Completed

#### Harness
- `scripts/backup-activation-rehearsal.sh` — compose auto-install on free port
  (default **18081**), extract `efaBackup_*.zip` (Windows path normalize), run
  Python checks, optional `--down-volumes`.
- `lab/backup_rehearsal/` — local-only XML parser + **posttx** load client + portal
  checks (not copied into the Docker image). Docs:
  `docs/deploy/backup-activation-rehearsal.md`.
- **Primary load path:** parse EFA native `*.efa2*` → `/api/posttx.php` API v3
  `insert` through `efa_api` / `efa_record` (persons → boats → destinations →
  boatstatus → damages → reservations → logbook). Client-minted `ecrid`;
  autoincrement keys stripped (server assigns). SQL only for rehearsal users +
  table clear.
- Second synch: posttx `update` by `ecrid` (+ versionized `Id`/`ValidFrom`);
  assert no new boat rows / no duplicate actives.
- Never enables `EFACLOUD_PORTAL_FIXTURES`; zip/PII stay outside git.

#### Portal DB fixes found while rehearsing (shipped in fork)
- `api/portal/v1/index.php`: `chdir` to `api/` so `Tfyh_toolbox` relative includes
  work from `api/portal/v1/`; bind portal user onto toolbox session for writes.
- `Portal_db_store`: correct `find_records_matched` / `find_record_matched`
  signatures; cast user id to string; filter unknown columns (`SessionIsOpen`);
  omit empty optional numerics (`BoatCaptain=''` breaks MariaDB STRICT); fail
  loud on insert errors.

### Validation evidence (2026-10-02, posttx-primary)

```text
./scripts/backup-activation-rehearsal.sh \
  --backup …/test_backup/efaBackup_20260607_095337.zip \
  --port 18081 --down-volumes
→ OVERALL: PASS  (PASS=35 FAIL=0)
```

Counts verified vs backup XML after posttx insert:

| Metric | Value |
|--------|------:|
| Current boats (not historical doubles) | 58 |
| Boat version rows | 208 |
| Current persons | 241 |
| Current destinations | 17 |
| Open damages (L/F/N severities) | 20 (7/7/6) |
| Repaired damages | 4 |
| Logbook 2026 rows / open trips | 200 / 0 |
| Seat mix (current) | 1:32, 2:8, 3:1, 4:11, 8:1, OTHER:5 |
| Second synch (posttx update) | 0 new boat rows; current still 58 |

Portal on real MariaDB: list/detail, start→`ONTHEWATER`+`EntryNo`, finish,
abort, damage (`tripAborted=false`). Cross-client: posttx update (bths 901)
while trip open keeps status+EntryNo; portal finish with stale ChangeCount →
`409 STALE_STATE`; finish with correct count → `AVAILABLE`.

### Remaining gaps (still open)

- Real EFA desktop **SynchControl** `SYNCH_UPLOAD_ALL` against CapRover HTTPS
  (local compose lab **PASS** — boats 58 / persons 241 via xdotool; CapRover
  still ops-blocked).
- Historical logbook years beyond current; clubwork/waters/groups/messages;
  project/types/config `upload` handshake.
- CapRover staging/prod, device QA, backup-restore drill, cutover window.
- Acceptance rows for CapRover/device/ops remain unfinished (local rows only
  strengthened to Partial / auto-local).

### Explicitly BLOCKED on external / ops inputs

| Gate | Blocked on |
|------|------------|
| CapRover staging + production apps | CapRover access, app names, env secrets |
| HTTPS + real hostnames | Club domains / DNS / certs |
| Private DB wiring on CapRover | CapRover DB service hostnames + passwords |
| Backup destinations + failure alerts | Off-host storage + notify channel |
| Desktop SynchControl on CapRover | Staging URL + desktop client + fresh zip |
| Device QA (iOS/Android PWA install, SW, offline) | Physical devices / device lab |
| Production cutover + rollback drill | Club freeze window + ops decision |
| Mark production released | All release-blocking rows in `acceptance-checklist.md` |

### Deviations / notes

1. HTTP fixture smoke cannot prove multi-request idempotency: `Portal_fixture_store`
   reloads per request. In-process coverage remains `./tests/portal/run.sh`.
2. Parent-workspace `TEST_ENV.md` / `docker-compose.test.yml` stay unused for this
   release path (ROW R/8 migration target).
3. No production release tag or “go-live” claim in this update.
4. Apache pre-install deny rewritten to RewriteRule for 2.4.68 compatibility
   (discovered when compose smoke failed to start Apache).
5. Local backup rehearsal drives the real posttx/`efa_record` write path; it is
   still not a substitute for desktop SynchControl on CapRover.
6. German dates from XML are converted to ISO before posttx (MariaDB `DATE` +
   STRICT). Second synch uses identity fields only (avoids historical-version
   content rejection and autoincrement key rewind).

---

## Desktop SynchControl lab (local compose) — 2026-10-02

**PASS — real SYNCH_UPLOAD_ALL completed** against local fork compose (not
CapRover). Club backup landed in MariaDB via efaBootshaus `TxRequestQueue` /
`EfaCloudConfigDialog` (xdotool on `DISPLAY=:99`).

### Added (fork only — parent `docker-compose.test.yml` untouched)

| Artifact | Role |
|----------|------|
| `docker-compose.desktop-synch.yml` | Overlay: `efa-desktop` + lab env; builds from parent `docker/efa-desktop/` |
| `scripts/desktop-synch-lab.sh` | Auto-install web, create bths **901**, start desktop, probes; `--upload` / `--upload-only` / `--verify-only` |
| `lab/desktop_synch/efa-start-synch-lab.sh` | After `efa-prepare-backup`, rewrite project URL/UserID/password/`file/efaCloud` |
| `lab/desktop_synch/gui-synch-upload-all.sh` | xdotool: language dismiss → Admin Mode → efaCloud → Upload Synchronisation |
| `lab/desktop_synch/posttx-probe.sh` + `PosttxProbe.java` | API v3 NOP from desktop JRE → `http://web/api/posttx.php` |
| `docs/deploy/desktop-synch-lab.md` | Automation + noVNC fallback + MariaDB evidence |

### Evidence (project `efacloud-desktop-synch`, host port **18083**)

```text
./scripts/desktop-synch-lab.sh --port 18083
# posttx auth PASS (host + desktop); project retargeted to http://web/ + 901

Trigger: Admin Mode → efaCloud → Upload Synchronisation starten
  (RQ_QUEUE_START_SYNCH_UPLOAD_ALL via xdotool; ~70s wall)

efacloud.log:
  09:07:52 Synchronisation client to server (upload) starting
  09:07:53 Start upload synchronization for tables
           [… efa2boats, efa2persons, efa2logbook, …]
  09:09:01 Synchronization transactions completed → WORKING (title ✔)

MariaDB after upload:
  boat_rows=208  current_boats=58
  person_rows=922  current_persons=241
  waters=215 destinations=25 boatstatus=155 messages=210 logbook_2026=200
```

No file/socket/env trigger exists in shipping EFA for full first upload; CLI
`MenuEfaCloud upload` is last-30-days only and requires in-process
`TxRequestQueue`. Lab automation is re-runnable:

```bash
./scripts/desktop-synch-lab.sh --upload-only
./scripts/desktop-synch-lab.sh --verify-only
```

Stack left up for inspection (`down` / `down -v` in lab doc). **CapRover HTTPS
SynchControl still open** (ops-blocked).

### Remaining for CapRover gate

1. Staging URL + desktop client + fresh zip against CapRover HTTPS.
2. Acceptance row for CapRover SynchControl remains unfinished.

---

## Local compose DB + files restore drill — 2026-10-02

**Production is not released.** CapRover staging restore remains open.

### Added
- `scripts/compose-backup-restore-drill.sh` — compose project
  `efacloud-restore-drill` (default port **18082**): auto-install → seed SQL +
  file markers → `mariadb-dump --single-transaction` → tar of persistent paths
  (`config`, `log`, `uploads`, `attachements`, `pdfs`, `resources`) →
  `down -v` → restore DB + volume extract → verify
  `.install_complete` / `settings_db`, seed token, app 200, `/install/` 403 →
  default `--down-volumes` cleanup.
- Artifacts under gitignored `var/restore-drill/`.
- `docs/deploy/backup-restore.md` — local drill command + CapRover-only gaps.
- Acceptance rows for DB/files backup + restore practice marked
  **Partial (local Pass)**; CapRover staging restore still open.

### Command / evidence
```bash
./scripts/compose-backup-restore-drill.sh
```
**OVERALL PASS** (21 checks, 0 failures) on 2026-10-02 — project
`efacloud-restore-drill`, port 18082; DB dump ~37 KiB + files archive ~237 KiB
under `var/restore-drill/`; destroy `-v` then restore verified
`.install_complete` / `settings_db`, SQL+file seed tokens, app 200, `/install/` 403.
Production not released.

### CapRover-only restore gaps (still open)
- CapRover host persistent-directory / volume restore
- Off-host backup destination, encryption, failure alerts
- Staging HTTPS / DNS / certs after recreate
- Registry image pull by digest
- Env from CapRover secrets store (not repo `.env`)

---

## Portal live smoke on SynchControl-synced stack — 2026-10-02

**PASS** against project `efacloud-desktop-synch` (host **http://127.0.0.1:18083**),
MariaDB already loaded by real desktop `SYNCH_UPLOAD_ALL` (not fixtures, not
posttx-primary rehearsal). CapRover / device still open.

### Added
- `scripts/portal-live-synch-smoke.sh` — assumes desktop-synch stack is up;
  ensures portalTrainer user **902** (PersonId linked, Concession **131072**,
  local password only); exercises `/api/portal/v1/` on the DB store.
- `lab/desktop_synch/portal_live_smoke.py` — login, boat views + seat filter,
  boat detail/damages, start→`ONTHEWATER`+`EntryNo`, finish→`AVAILABLE`,
  damage `tripAborted=false`, `ACK_REQUIRED` on NOTAVAILABLE (+ abort cleanup).
- Optional `--second-upload` → `desktop-synch-lab.sh --upload-only` and assert
  current boats still **58**.

### Evidence (2026-10-02)

```text
./scripts/portal-live-synch-smoke.sh --second-upload
→ OVERALL: PASS
```

| Check | Result |
|-------|--------|
| current boats / persons | **58** / **241** |
| portal login 902 (trainer) | PASS |
| GET /boats available / onwater / unavailable | 33 / 0 / 25 |
| seatCategory=1 | 19 |
| boat detail + damages (incl. open=3 on Neuwerk) | PASS |
| start → ONTHEWATER + EntryNo (EntryId 202) | PASS |
| finish → AVAILABLE | PASS |
| report damage tripAborted=false | PASS |
| ACK_REQUIRED on NOTAVAILABLE (Baracuda) + cleanup | PASS |
| second SynchControl upload; boats still 58 | PASS |

Still **not** CapRover HTTPS SynchControl, device QA, or production release.

---

## Host Chrome browser E2E + portal↔desktop conflict race — 2026-10-02

**PASS** against `efacloud-desktop-synch` @ **http://127.0.0.1:18083**. Production
not released. CapRover / physical iOS/Android still open.

### Added
- `scripts/portal-browser-e2e.sh` + `portal/e2e/browser-e2e.mjs` (playwright-core +
  system `google-chrome`): German login → boats/views/seat chips → start → finish
  → CDP offline banner + no false mutation success → service worker registration.
- `scripts/portal-desktop-conflict-race.sh` +
  `lab/desktop_synch/portal_desktop_conflict_race.py`: portal start → bths posttx
  competing open logbook → assert consistency → stale ChangeCount finish →
  `STALE_STATE` → cleanup.

### Bugfixes found by E2E / race
1. **PHP warnings into JSON** — portal front controller restored CWD to
   `api/portal/v1/`, so `Tfyh_socket` relative `../log/lwa` missed and polluted
   trip-start responses; UI showed success+failure and never navigated. Fixed by
   keeping CWD at `api/`, `@mkdir` + silenced lwa writes, and casting nulls for
   `strcmp`. Client also strips accidental HTML before JSON.parse.
2. **Silent split-brain on concurrent checkout** — bths posttx could insert a
   second open logbook and redirect `EntryNo`. Added
   `Efa_boat_concurrency_guard` in `api_modify` (second open → `502 CONFLICT`).

### Evidence
```text
./scripts/portal-desktop-conflict-race.sh  → OVERALL: PASS
  RACE_BEHAVIOR: second write failed/conflicts (boat concurrency guard);
                 status+logbook stay on portal trip
  + portal finish stale ChangeCount → 409 STALE_STATE

./scripts/portal-browser-e2e.sh            → OVERALL: PASS
  (12 browser checks: login, chips, start/finish, offline, SW)
```

Acceptance: Partial↑ for device-adjacent host Chrome E2E and local conflict race.
iOS Safari / Android install / CapRover HTTPS still open.

---

## Step 5 — Portal static 500 after audit (fix)

Updated: 2026-10-02

### Bug
`Tfyh_audit::set_dirs_access_rights()` treats any top-level dir not in
`$tfyh_public_dirs` as forbidden (`chmod 0700` + `.htaccess` `deny for all`).
`portal/` was missing from the allowlist, so after cron/upgrade audit the
dockside PWA returned **HTTP 500** while `/api/portal/v1/` kept working.

### Fix
- Added `portal` to `$tfyh_public_dirs` in `classes/tfyh_audit.php`.
- Security regressions: apache `/portal` Alias + allowlist assertion.
- Live lab container restored; re-audit leaves `portal` mode **755** and
  `/portal/` **200** (SPA routing via Apache `FallbackResource` when audit
  removes `.htaccess`).

### Evidence
- `./tests/security/run.sh` → **76 passed, 0 failed**
- Chrome headless `http://127.0.0.1:18083/portal/` renders German login
  (`efaPortal`, Anmelden, manifest linked)
- `curl` `/portal/`, `/portal/manifest.webmanifest`, `/portal/sw.js` → **200**

---

## Completion audit — 2026-10-02

Software implementation steps **1–4** + security fixes + local smoke + **local
backup→MariaDB activation rehearsal (posttx-primary)** + **local compose
DB+files backup/restore drill** + **local desktop SynchControl first-upload
PASS** (SYNCH_UPLOAD_ALL via xdotool; current boats **58** / persons **241**)
+ **portal API live smoke on that SynchControl DB** (`portal-live-synch-smoke.sh`
PASS, incl. optional second upload with no duplicate currents)
+ **portal static serving fix (audit allowlist)** + Chrome headless login shell
+ **host Chrome browser E2E** (`portal-browser-e2e.sh` PASS — Partial↑ device)
+ **portal↔desktop conflict race** (`portal-desktop-conflict-race.sh` PASS;
concurrency guard rejects second open trip; STALE_STATE)
are **DONE**.
Production release gates remain **OPEN**. Goal is **not complete** until external
ops / device / CapRover rows in `docs/deploy/acceptance-checklist.md` pass
(CapRover staging/prod, physical device QA, real desktop SynchControl upload on
CapRover, CapRover staging restore, cutover). See the checklist Sign-off snapshot
for Pass (auto) vs Partial↑ vs open release-blocking counts.

---

## Release-candidate local harness + CapRover re-login — 2026-10-02

**Production is not released.** CapRover deploy not attempted (auth expired).

### CapRover CLI

| Item | Status |
|------|--------|
| Machine | `captain-project-park` @ https://captain.project-park.de |
| Auth | **Expired** — `Invalid credentials` on API call; requires `caprover login` |
| Deploy | **Not attempted** (no invented passwords; no destructive CapRover ops) |

Documented in `docs/deploy/caprover-staging-production.md` (§ CLI machine status).

### Added

- `scripts/release-candidate-local.sh` — single local RC gate runner:
  1. `./tests/security/run.sh`
  2. `./tests/portal/run.sh`
  3. `cd portal && npm run test && npm run build`
  4. `compose-local-smoke.sh --down` on free port (default **18084**, project
     `efacloud-rc-smoke`)
  5. `compose-backup-restore-drill.sh` only with **`--full`** (SKIP by default)
  6. If `efacloud-desktop-synch` is up on :18083: live synch smoke, conflict race,
     browser E2E; else SKIP with lab start hint
  - Fail-fast on FAIL; prints PASS/FAIL/SKIP summary table

### Acceptance honesty updates

| Gate | Status now |
|------|------------|
| Legacy route security review | **Pass (local review)** — `docs/security/release-review.md` + security suite; R11 Secure behind proxy **Partial↑** (local https-cookie-drill); CapRover staging still ops |
| Cutover documented & rehearsed | **Partial (docs + local rehearsal)** — CapRover cutover still open |
| Image promote staging→prod | still empty / ops |
| iOS / Android install | still empty / device |
| CapRover staging isolation | Partial — machine known; **re-auth required** |

### Packaging

- `captain-definition.json` remains valid CapRover Dockerfile deploy
  (`schemaVersion: 2`, `dockerfilePath: "./Dockerfile"`).

### Evidence

```text
./scripts/release-candidate-local.sh
# 2026-10-02T09:34:12Z  FULL=0  SMOKE_PORT=18084

GATE                                         STATUS DETAIL
tests/security/run.sh                        PASS   83 passed
tests/portal/run.sh                          PASS   50 passed
portal npm test                              PASS   8 passed
portal npm build                             PASS
compose-local-smoke (:18084)                 PASS   project efacloud-rc-smoke
compose-backup-restore-drill                 SKIP   pass --full
portal-live-synch-smoke                      PASS   :18083 desktop-synch
portal-desktop-conflict-race                 PASS
portal-browser-e2e                           PASS
OVERALL: PASS (local). CapRover / device / ops still open.
```

### Still blocked (hard)

1. CapRover re-login + run `./scripts/caprover-staging-bootstrap.sh` (one-click template ready; auth still expired)
2. Image promote same digest staging→prod
3. Physical iOS Safari / Android Chrome device QA
4. CapRover SynchControl + staging restore + cutover window + backup-failure alerts


---

## CapRover staging automation (one-click + bootstrap) — 2026-10-02

**Production is not released.** Auth on `captain-project-park` remains expired;
bootstrap is fail-closed until `caprover login` succeeds.

### Added

- `caprover-one-click.yml` — CapRover one-click for web + MariaDB 11.4:
  staging-oriented app pair, persistent volumes, generated passwords,
  `EFACLOUD_*` env (no `EFACLOUD_PORTAL_FIXTURES`), `containerHttpPort` 80,
  DB host `srv-captain--$$cap_appname-db`, web placeholder until Dockerfile deploy.
- `scripts/caprover-staging-bootstrap.sh` — probes auth via `caprover api` GET
  `/user/system/info` (non-interactive; exit **2** + exact re-login instructions
  on invalid/expired credentials); lists apps; prints one-click steps if
  `efacloud-staging` / db missing; deploys with
  `caprover deploy -n captain-project-park -a efacloud-staging`; HTTPS health
  probes. Does **not** create production.
- `docs/deploy/caprover-staging-production.md` — one-click + bootstrap usage.

### Evidence (auth still expired)

```text
./scripts/caprover-staging-bootstrap.sh
→ exit 2 — CapRover auth invalid / expired; prints `caprover login` instructions
```

### Next operator action

```bash
caprover login
# URL: https://captain.project-park.de
# Machine name: captain-project-park
# Use the real CapRover password (do not invent / commit)

./scripts/caprover-staging-bootstrap.sh
```

If apps are missing, paste `caprover-one-click.yml` in CapRover TEMPLATE with
app name `efacloud-staging`, enable HTTPS, then re-run the bootstrap script.

---

## Step 5 — R11 Secure cookie behind TLS proxy (Partial↑)

Updated: 2026-10-02

### Done locally
- `Portal_session::request_is_https()` trusts `HTTPS`, `REQUEST_SCHEME`,
  `X-Forwarded-Proto: https` (CapRover), and `X-Forwarded-Ssl`.
- Security unit matrix in `tests/security/run.php`.
- `docker-compose.https-proxy.yml` + `scripts/https-cookie-drill.sh` (self-signed
  nginx → `web:80`, host **18443**).
- Docs: release-review R11 Partial↑, acceptance, `.env.example`, apache note,
  CapRover staging doc.

### Still CapRover-blocked
- Force HTTPS + live cookie check on staging/prod (needs `caprover login`).

---

## GHCR versioned image + CapRover promote path — 2026-10-02

**Software complete** for image publish/promote docs. CapRover login still
blocks staging. GHCR workflow is prepared locally; **not run on remote** until
the branch is pushed (this turn did **not** `git push` / `git commit`).

### Added

| Artifact | Role |
|----------|------|
| `.github/workflows/docker-image.yml` | On push to `main` / `feature/caprover` + `workflow_dispatch`: multi-stage build, smoke (portal + `apache2 -t`), push `ghcr.io/thiesmoeller/efacloud` tags `sha-<short>`, branch name, `sha-<full>`; digest in job summary; `packages:write` + `contents:read` |
| `docs/deploy/image-promote.md` | Actions summary → CapRover staging → same digest to prod |
| `scripts/print-local-image-digest.sh` | Local build + smoke + print `image_id` for offline evidence |
| `caprover-one-click.yml` | Web image var → GHCR preferred (`sha-…` / digest); branch default only as tip |
| CapRover staging doc | Points promote at GHCR `sha-…` + `image-promote.md` |

### Local digest evidence (this turn)

```text
./scripts/print-local-image-digest.sh
→ Smoke OK (portal assets + apache2 -t)
tag:          efacloud:local-digest
image_id:     sha256:1183ba23d5d63abf854cd92cc91be6958595f3ae83588237b48d52ba941446b0
repo_digest:  (none — not pushed)
git_short:    939a7ed
→ CapRover target after GHCR publish: ghcr.io/thiesmoeller/efacloud:sha-939a7ed
```

### Still blocked

1. **`caprover login`** on `captain-project-park` (https://captain.project-park.de) —
   auth expired; no invented password; staging pull/promote waiting on operator.
2. Push branch so Actions can publish to GHCR (workflow file ready; remote run pending).
3. After login + GHCR: staging ImageName deploy of `sha-…`, then same digest to prod.

---

## Software completion audit — 2026-10-02 (post code-gap pass)

Plan: `docs/plans/production-dockside-pwa.md` §§1–3 + acceptance §5  
Tree: `/home/thies/Projects/efa_new/thiesmoeller-efacloud`  
Scope: **implementable product/code** only (not CapRover credentials / physical
phones / cutover window).

### Counts

| Status | Count |
|--------|------:|
| **DONE** | 38 |
| **PARTIAL** | 9 |
| **MISSING (ops)** | 8 |
| **MISSING (code)** | 0 |

### Requirement audit table

| # | Requirement (plan) | Status | Evidence |
|---|-------------------|--------|----------|
| 1.1 | Boat attributes / variants / validity | DONE | `Portal_boats`, `Portal_constants::general_number_of_seats_type`, fixtures + live synch |
| 1.2 | Seat filters Alle/Einer/… (cox excluded; 4X→Vierer) | DONE | `buildSeatFilterChips`, portal unit + browser E2E seat chips |
| 1.3 | Umlaut-fold alpha sort + badges + secondary filters | DONE | `Portal_constants::fold_umlauts_lower`; PWA secondary filters |
| 1.4 | Name search across categories | DONE | `GET /boats?search=`; BoatListScreen |
| 1.5 | Single vs multi-variant → form / config pick | DONE | BoatListScreen + VariantPickScreen |
| 1.6 | Verfügbar / Auf Fahrt / Nicht verfügbar + damage | DONE | boat views API + PWA tabs; live smoke + E2E |
| 1.7 | Trip fields / crew / cox / Obmann / defaults / correct / abort | DONE | `Portal_trips` + TripFormScreen; E2E correct + abort-with-damage |
| 1.8 | Parity matrix documented | DONE | `docs/parity/parity-matrix.md`, `trace-notes.md` |
| 2.1 | Unresolved damage most severe first; history separate | DONE | `Portal_damage` list + DamageListScreen |
| 2.2 | Three severities German copy | DONE | SeverityPicker; E2E `--with-damage` |
| 2.3 | Departure checks EFA order | DONE | `Portal_departure`; portal unit ACK order |
| 2.4 | Explicit overrides + ACK revalidate | DONE | ACK tokens; unit + E2E `--with-ack` |
| 2.5 | Damage from boat/trip + abort-with-damage | DONE | API + UI; unit + browser E2E abort-with-damage |
| 2.6 | Report does not finish/abort trip | DONE | `tripAborted=false`; live + E2E `--with-damage` |
| 2.7 | Members report; repair admin elsewhere | DONE | ACL; repair not in PWA |
| 2.8 | Server trip permissions + legacy member writes | DONE | `Portal_permissions`, `Efa_member_write_guard` |
| 3.1 | `/api/portal/v1/` + `/portal/` + preserve posttx | DONE | `api/portal/v1/index.php`, apache Alias |
| 3.2 | Shared PHP domain + atomic writes + sync metadata | DONE | `classes/portal/*`, ChangeCount/ecrid |
| 3.3 | Idempotency + stale-state; concurrency vs desktop | DONE | idempotency keys; `Efa_boat_concurrency_guard`; conflict race script |
| 3.4 | Secure cookie, CSRF, throttle, logout, revoke | DONE | `Portal_session`; security suite |
| 3.5 | **Admin-assisted password reset workflow** | DONE | `POST /admin/password-reset`, `docs/ops/admin-password-reset.md`, config `passwordReset.steps`, portal tests |
| 3.6 | Legacy route security review + regressions | DONE | `docs/security/release-review.md`; `./tests/security/run.sh` 97 pass |
| 3.7 | Install lifecycle complete vs incomplete | DONE | entrypoint + security suite |
| 3.8 | Explicit image inputs; no secrets/fixtures | DONE | Dockerfile + `.dockerignore`; `scripts/verify-image-contents.sh` |
| 3.9 | React/TS PWA, SW assets-only, online writes | DONE | `portal/`; browser E2E SW + offline |
| 5.1 | Fleet mix / category / search / no historical doubles | PARTIAL | Local backup rehearsal + SynchControl 58 boats; CapRover fleet still ops |
| 5.2 | Desktop↔PWA start/correct/finish/abort/abort+damage | PARTIAL | Portal unit + host Chrome correct/abort-with-damage; CapRover desktop still ops |
| 5.3 | Damage severities / multi / repaired / reservation / cancel / stale | PARTIAL | Unit + optional E2E flags; device touch still ops |
| 5.4 | Cross-client races / interrupted / stale | PARTIAL | `portal-desktop-conflict-race.sh` PASS local; CapRover still ops |
| 5.5 | Midnight / crew / distance / logbook | DONE | portal unit + club-config fixtures |
| 5.6 | Member vs trainer vs revoked; legacy bypass | DONE | portal + security suites |
| 5.7 | iOS Safari / Android Chrome install + SW updates | MISSING (ops) | Host Chrome **Pixel 7 / iPhone 13 emulation** PASS (login/list/start); physical devices still open |
| 5.8 | Offline messaging | PARTIAL | Host Chrome CDP E2E PASS; physical device still open |
| 5.9 | Clean install / interrupted bootstrap / redeploy | PARTIAL | Local compose smoke + unit; CapRover redeploy still ops |
| 5.10 | Staging restore practiced | PARTIAL | Local `compose-backup-restore-drill.sh` PASS; CapRover staging restore ops |
| 4.* | CapRover staging/prod apps, HTTPS domains, private DB | MISSING (ops) | Auth expired; bootstrap fail-closed |
| 4.* | Image promote staging→prod | MISSING (ops) | GHCR workflow + `image-promote.md` ready; CapRover login + remote Actions push still open |
| 4.* | SynchControl on CapRover HTTPS | MISSING (ops) | Local SynchControl PASS; CapRover open |
| 4.* | Backup destinations + failure alerts | MISSING (ops) | Docs only |
| 4.* | Cutover + rollback with live freeze | MISSING (ops) | Runbook docs; window not scheduled |
| 4.* | Production release sign-off | MISSING (ops) | Acceptance checklist open rows |

### Code gaps fixed this turn

1. **Admin password reset workflow** — `Portal_admin`, `POST /api/portal/v1/admin/password-reset`, workflow on `GET /config`, `docs/ops/admin-password-reset.md`, fixture+unit coverage.
2. **Browser E2E** — default path now **Auf Fahrt → correct → abort-with-damage**; **Pixel 7 + iPhone 13** login/list/start (+ abort cleanup).
3. **`scripts/verify-image-contents.sh`** — asserts concurrency guard, audit `portal` allowlist, `X-Forwarded-Proto` Secure cookies, packaging exclusions.
4. **Auf Fahrt correct UX** — trip screen title **„Fahrt korrigieren“** when correcting an open trip.
5. **`$sql_cmd` warning** on abort delete — `tfyh_socket::delete_record_matched` initializes `$sql_cmd` (stopped HTML pollution before JSON).

### Validation evidence (this turn)

```text
./tests/portal/run.sh                         → 57 passed, 0 failed
./tests/security/run.sh                       → 97 passed, 0 failed
cd portal && npm run test                     → 8 passed
./scripts/verify-image-contents.sh            → OVERALL PASS
./scripts/portal-browser-e2e.sh               → OVERALL PASS (23 browser checks)
  incl. Auf Fahrt correct, abort-with-damage, Pixel 7, iPhone 13
```

### Remaining

- **MISSING (code):** none identified in v1 product scope after this pass.
- **MISSING (ops) / PARTIAL:** CapRover auth + staging/prod, physical device QA,
  CapRover SynchControl / restore / cutover — unchanged; production **not** released.

---

## Ops block — CapRover auth (re-checked 2026-10-02 10:04 UTC)

Attempts that **did not** restore CapRover access:

- `caprover` CLI token expired (`Invalid credentials` / exit 2)
- SSH `project-park.de` — all local keys `Permission denied`
- Chrome Local Storage JWTs for `captain.project-park.de` — API returns `1106 Auth token corrupted`
- No `CAPROVER_PASSWORD` in environment; no Android device via `adb`

**Required:** interactive `caprover login` (or valid password/`CAPROVER_PASSWORD`) for
`https://captain.project-park.de` / machine `captain-project-park`, then:

```bash
./scripts/caprover-staging-bootstrap.sh
```

Software/local RC remains ready; goal **not** complete until CapRover staging + device/cutover gates pass.

### CapRover wait helper

While auth is expired, run a poller that auto-continues staging once login works:

```bash
./scripts/wait-caprover-then-bootstrap.sh
# other terminal: caprover login
```

### CapRover waiter timeout (2026-10-02 11:12 UTC)

`./scripts/wait-caprover-then-bootstrap.sh` ran **180** probe rounds (~1h) against
`captain-project-park` / https://captain.project-park.de — every probe **exit 2**
(expired token). No `caprover login`, no `/tmp/caprover-password`, no
`CAPROVER_PASSWORD`. Staging auto-create/deploy **did not run**.

Re-start after login:

```bash
caprover login
./scripts/caprover-staging-bootstrap.sh --auto-create
# or: ./scripts/wait-caprover-then-bootstrap.sh
```

### CapRover ops pause (2026-10-02 ~11:27 UTC) — operator confirmation required

Auth was later restored via **official CapRover SSH password-reset** on
`project-park.de` (root SSH available; CLI token had been expired). Staging apps
were **partially** provisioned then **stopped on operator request** (no further
live CapRover mutations without explicit OK).

| Item | State |
|------|--------|
| CapRover admin password | **Reset** — operator must change in CapRover Settings; clear `/tmp/caprover-password` |
| `efacloud-staging` / `efacloud-staging-db` | Registered; DB env partially applied; **full tarball deploy + health not done** |
| `var/caprover-staging-secrets.env` | Written locally (gitignored) |
| Bootstrap script | Fixed: curl API (no flaky `caprover api -d`), `@file` bodies, bash `${3:-{}}` bug, `captain-definition` name, reuse secrets |
| Live deploy / waiter | **Paused** — see `docs/deploy/GO_LIVE.md` ops pause |
| Device QA checklist | `docs/deploy/device-qa.md` (physical iOS/Android still open) |

**Do not** run `--auto-create` / login-and-bootstrap / wait-then-bootstrap against
production CapRover until the operator confirms (keep, finish, or delete the
staging apps).

Software/local RC still ready; goal **not** complete (staging HTTPS gates, device
QA, promote, cutover open).

