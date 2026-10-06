# Acceptance checklist — dockside PWA release gates

Mapped to `docs/parity/parity-matrix.md` IDs.  
**Production is not released** until all **Release-blocking** rows are Pass.

Legend for **How verified**:

| Tag | Meaning |
|-----|---------|
| `auto-unit` | Covered by `./tests/portal/run.sh` and/or `portal` unit tests / security suite |
| `auto-local` | Runnable today via `scripts/portal-fixture-smoke.sh` or `scripts/compose-local-smoke.sh` |
| `device` | Needs real iOS Safari / Android Chrome (or equivalent device lab) |
| `staging` | Needs CapRover staging (or equivalent HTTPS + MariaDB + club backup copy) |
| `ops` | Needs external inputs: domain, CapRover creds, backup destination, cutover window |

Status column is for sign-off on the release candidate image (not filled = open).

---

## A. Packaging, install, restore

| Gate | Parity / plan | How verified | Status |
|------|---------------|--------------|--------|
| Clean install + auto-bootstrap | Plan §5 | `auto-local` compose smoke; `staging` CapRover first bring-up | Partial (auto only) |
| Interrupted bootstrap resumes | Plan §5 / step 2 | `auto-unit` security suite; `staging` kill mid-install | Partial (auto only) |
| Redeploy keeps data (`.install_complete`) | Plan §5 | `auto-local` compose recreate; `staging` CapRover redeploy | Partial (auto only) |
| Image excludes fixtures/secrets/backups | Plan §3 | `auto-local` `docker build` + `docs/deploy/image-contents.md` | Pass (auto) |
| DB + files backup separate from CapRover config | Plan §4 | `auto-local` `compose-backup-restore-drill.sh`; `ops` + CapRover staging still required | Partial (local Pass) |
| Restore onto staging practiced | Plan §5 | `auto-local` compose drill PASS; CapRover staging restore still open (`backup-restore.md`) | Partial (local Pass) |

---

## B. Boat selection (parity B*)

| Gate | IDs | How verified | Status |
|------|-----|--------------|--------|
| Variant attributes / seat exclude cox / 4X→Vierer | B1–B3 | `auto-unit` portal boats; `staging` real fleet | Partial (auto only) |
| Seat category filters (Alle/Einer/…) | B4 | `auto-unit` + `portal` chip tests; `auto-local` live synch smoke seatCategory=1; `device` UX | Partial (auto + local live) |
| Umlaut-fold name sort | B5 | `auto-unit` | Pass (auto) |
| Secondary badges / filters | B6 | `auto-unit` / PWA; `device` | Partial (auto only) |
| Name search | B7 | `auto-unit`; `staging` fleet | Partial (auto only) |
| Single vs multi-variant choice | B8 | `auto-unit` fixtures (dual variants); `staging` | Partial (auto only) |
| Shared status/damage across categories | B9 | `auto-unit`; `staging` | Partial (auto only) |
| Validity: no historical-as-active | B10 | `auto-unit` fixtures hist boat; `auto-local` backup rehearsal; `staging` | Partial (auto + local rehearsal) |
| Verfügbar / Auf Fahrt / Nicht verfügbar | B11 | `auto-unit`; `auto-local` fixture smoke + backup rehearsal + portal-live-synch-smoke (33/0/25); `staging` | Partial (auto + local live) |
| Unavailable selectable with override | B12 | `auto-unit` ACK flow; `auto-local` live ACK_REQUIRED; **portal-browser-e2e --with-ack** (Chrome ACK dialog → start → abort); `device` touch still open | Partial↑ (auto + local live + host Chrome ACK) |
| Damage indicator on lists | B13 | `auto-unit`; `auto-local` live boat detail open damages; `staging` | Partial (auto + local live) |
| Actual club fleet mix (1/2/3/4/8, coxed/coxless, scull/sweep) | Plan §5 | `auto-local` backup rehearsal + SynchControl DB (58 currents); `staging` CapRover still required | Partial (local rehearsal) |

---

## C. Departure checks (parity C*)

| Gate | IDs | How verified | Status |
|------|-----|--------------|--------|
| Order status → reservation → damage | C1 | `auto-unit` | Pass (auto) |
| Cancel aborts start | C2 | `auto-unit` + PWA ack tests; `device` | Partial (auto only) |
| Damage warn policy (incl. misnamed config key) | C3 | `auto-unit` | Pass (auto) |
| Most severe open first | C4 | `auto-unit` | Pass (auto) |
| Revalidate / ACK_STALE renew | C5 | `auto-unit`; **portal-browser-e2e --with-ack** (SQL Comment mutate between ack/start → renew dialog); `device` touch still open | Partial↑ (auto + host Chrome ACK_STALE) |
| Overrides not trainer-gated | C6 | `auto-unit` | Pass (auto) |

---

## D. Damage (parity D*)

| Gate | IDs | How verified | Status |
|------|-----|--------------|--------|
| Three severities + copy | D1 | `auto-unit`; **portal-browser-e2e --with-damage** (Chrome Schwere labels: nicht / eingeschränkt / voll benutzbar); `device` still open | Partial↑ (auto + host Chrome E2E) |
| Unresolved ordering; show FULLYUSEABLE when open | D2 | `auto-unit` | Pass (auto) |
| Report from boat/trip; fields preserved | D3 | `auto-unit`; `auto-local` live synch smoke damage; **portal-browser-e2e --with-damage** (Schaden melden from boat start UI); `staging` | Partial↑ (auto + local live + host Chrome) |
| Abort-with-damage order | D4 / T5 | `auto-unit` | Pass (auto) |
| Report does not finish/abort trip | D5 | `auto-unit`; `auto-local` live `tripAborted=false`; **portal-browser-e2e --with-damage** (success copy + stay on boat/damage path) | Pass (auto + local live + host Chrome) |
| NOTUSEABLE availability effect | D6 | `auto-unit`; `staging` sync | Partial (auto only) |
| Members report; repair admin elsewhere | D7 | `auto-unit` ACL; `staging` accounts | Partial (auto only) |
| Resolved history separate | D8 | `auto-unit` | Pass (auto) |

---

## E. Trip lifecycle (parity T*)

| Gate | IDs | How verified | Status |
|------|-----|--------------|--------|
| Start / correct / finish / abort / abort+damage | T1–T5 | `auto-unit`; `auto-local` backup rehearsal + **portal-live-synch-smoke** + **portal-browser-e2e** (Chrome start/finish on SynchControl DB); `staging` cross-client desktop | Partial↑ (auto + local live + host Chrome E2E) |
| Late entry (if exposed) | T6 | Out of PWA v1 scope unless added — N/A or `staging` desk | N/A (out of PWA v1) |
| Destination required / distance / defaults / ±5 min / session type | T7–T11 | `auto-unit` + club-config fixtures; `staging` | Partial (auto only) |
| Desktop ↔ PWA same outcomes | Plan §5 | `staging` | |
| Cross-client races / idempotency / stale ChangeCount | S2 + plan | `auto-unit` partial; `auto-local` **portal-desktop-conflict-race.sh** (portal start + bths posttx conflict → guard reject; STALE_STATE); `staging` desktop mandatory | Partial↑ (auto + local live race) |

---

## F. Crew / midnight / permissions

| Gate | IDs | How verified | Status |
|------|-----|--------------|--------|
| Cox/crew/Obmann rules | P1–P6 | `auto-unit` partial; `device` form UX; **do not** force BoatCaptain=1 (P7) | Partial (auto only) |
| Midnight / EndDate / Mehrtagsfahrt | M1–M4 | `auto-unit` fixture trip; `device` | Partial (auto only) |
| Member vs trainer ACL; revoked/unauth | R1–R4 | `auto-unit`; `staging` legacy `/api/posttx.php` review | Partial (auto only) |

---

## G. Sync identifiers (parity S*)

| Gate | IDs | How verified | Status |
|------|-----|--------------|--------|
| ecrid / validity / ChangeCount / logbook keys / damage keys / status linkage | S1–S5 | `auto-unit` metadata; `auto-local` backup rehearsal status↔logbook; `staging` desktop sync | Partial (auto + local rehearsal) |
| Repeated sync after backup activation | Plan §4 | `auto-local` second-synch upsert on compose; local desktop SynchControl first-upload **PASS** + **second upload still 58 boats** (`portal-live-synch-smoke.sh --second-upload`); `staging` CapRover SynchControl still required | Partial (local desktop PASS; CapRover open) |
| Conflicting checkouts leave consistent status+logbook | Plan §4 | `auto-local` **portal-desktop-conflict-race.sh** PASS (second open insert → CONFLICT; status+logbook stay on portal trip); `staging` desktop↔PWA still required | Partial↑ (local live race) |

---

## H. PWA / devices

| Gate | How verified | Status |
|------|--------------|--------|
| Install on iOS Safari | `device` | |
| Install on Android Chrome | `device` | |
| Offline messaging; no offline mutation success | `device` (+ unit offline banner); **Partial↑** host Chrome CDP E2E (`portal-browser-e2e.sh`) | Partial↑ (host Chrome; iOS/Android still open) |
| Logout; service-worker update banner | `device`; SW registration asserted in host Chrome E2E (update banner still device) | Partial↑ (SW reg host; update banner / logout device) |
| German UI; success only on 2xx | `auto-unit` / build; host Chrome E2E German login + server-confirmed start/finish; `device` | Partial↑ (host Chrome; physical device still open) |

---

## I. Security / release

| Gate | How verified | Status |
|------|--------------|--------|
| Focused security regressions green | `auto-unit` `./tests/security/run.sh` | Pass (auto) |
| Legacy route security review (release-blocking vulns) | Manual review + suite — `docs/security/release-review.md`; R11 Secure cookie **Partial↑** (local proxy drill); CapRover staging still ops | Pass (local review) / Partial↑ (R11) |
| Staging isolation + HTTPS + private DB + single instance | `ops` / `staging` — apps partially registered then **ops pause**; finish or delete only with operator OK (`GO_LIVE.md`) | Partial (paused) |
| Image promote staging→prod same digest | `ops` | |
| Cutover + rollback reconciliation documented & rehearsed | Docs + local rehearsal (`cutover-runbook.md`, compose / SynchControl / restore drills); CapRover cutover still open | Partial (docs + local rehearsal) |
| Backup-failure alerts configured | `ops` | |

---

## What is automated today vs blocked

### Automated / local today (no CapRover)

- **Release-candidate local harness:** `./scripts/release-candidate-local.sh`
  (default: security + portal unit + portal npm test/build + compose smoke on
  :18084; live synch/conflict/browser E2E if `efacloud-desktop-synch` is up;
  `--full` adds backup/restore drill). CapRover still out of scope.
- Portal domain + API fixture tests: `./tests/portal/run.sh`
- Security regressions: `./tests/security/run.sh`
- PWA unit + build: `cd portal && npm run test && npm run build`
- Portal HTTP smoke on sanitized fixtures: `./scripts/portal-fixture-smoke.sh`
- Compose install / health smoke (dev secrets in `.env` only):
  `./scripts/compose-local-smoke.sh`
- Backup → MariaDB activation rehearsal (real zip, no fixtures):
  `./scripts/backup-activation-rehearsal.sh` — see
  `docs/deploy/backup-activation-rehearsal.md`
- Compose DB + persistent-files backup/restore drill:
  `./scripts/compose-backup-restore-drill.sh` — see
  `docs/deploy/backup-restore.md` (local section); CapRover staging restore still open
- Desktop SynchControl first-upload lab (real efaBootshaus → posttx):
  `./scripts/desktop-synch-lab.sh` + `--upload-only` — see
  `docs/deploy/desktop-synch-lab.md` (local PASS 2026-10-02; CapRover still open)
- Portal API against SynchControl-synced MariaDB (no fixtures):
  `./scripts/portal-live-synch-smoke.sh` (+ optional `--second-upload`)
- Host Chrome portal E2E (German login → start/finish → offline CDP → SW):
  `./scripts/portal-browser-e2e.sh` — Partial↑ device-adjacent; not CapRover/iOS.
  Optional (off by default): `--with-damage` (D1/D3/D5 UI), `--with-ack`
  (B12 ACK dialog + C5 ACK_STALE renew via SQL mutate).
- Portal ↔ desktop posttx conflict race on shared MariaDB:
  `./scripts/portal-desktop-conflict-race.sh`

### Needs device

- Real phone install, SW update banner, offline UX on iOS Safari / Android Chrome,
  touch workflows for ACK dialogs (host Chrome `--with-ack` covers dialog path;
  physical-device touch/install still required)

### Needs staging + external ops inputs

- CapRover app provisioning, real HTTPS domains, DB passwords, backup destinations
- Real EFA desktop SynchControl on CapRover staging HTTPS (local compose lab
  PASS: `docs/deploy/desktop-synch-lab.md` — SYNCH_UPLOAD_ALL via xdotool,
  boats≈58 / persons≈241; does **not** replace CapRover)
- Device QA and production cutover window / rollback drill
- Backup-failure alert wiring

Do not treat local fixture smoke as fleet acceptance. Local backup rehearsal /
portal-live-synch smoke / host Chrome E2E / conflict race strengthen MariaDB
evidence but do **not** pass CapRover / physical-device / ops rows.
Production is not released.

---

## Sign-off snapshot — 2026-10-02

**Production is not released.** Status below reflects automated/local evidence only.

### Automated suites green (this snapshot)

| Suite | Result |
|-------|--------|
| `./tests/security/run.sh` | 83 passed |
| `./tests/portal/run.sh` | 50 passed |
| `cd portal && npm run test` | 8 passed |
| `./scripts/portal-fixture-smoke.sh` | passed |
| `EFACLOUD_HTTP_PORT=18080 ./scripts/compose-local-smoke.sh --down` | passed |
| `./scripts/backup-activation-rehearsal.sh --down-volumes` (port 18081) | **PASS** (35/0; posttx-primary load; local MariaDB, not CapRover) |
| `./scripts/compose-backup-restore-drill.sh` (port 18082) | **PASS** (local DB+files destroy/restore; CapRover staging restore still open) |
| `./scripts/desktop-synch-lab.sh` (port 18083) | **PASS** (SYNCH_UPLOAD_ALL; boats 58 / persons 241) |
| `./scripts/portal-live-synch-smoke.sh --second-upload` (port 18083) | **PASS** (portal on SynchControl DB; second upload still 58 boats) |
| `./scripts/portal-browser-e2e.sh` (port 18083, system Chrome) | **PASS** (default: German login→start/finish→offline→SW) |
| `./scripts/portal-browser-e2e.sh --with-damage --with-ack` | **PASS** (19 checks: +D1 severities, D5 report UI, B12 ACK dialog, C5 ACK_STALE renew; Partial↑ device) |
| `./scripts/portal-desktop-conflict-race.sh` (port 18083) | **PASS** (bths conflict → CONFLICT; STALE_STATE; cleanup) |
| `cd portal && npm run build` + docker multi-stage image build (step 4) | passed |
| `./scripts/release-candidate-local.sh` (default; no `--full`) | **PASS** 2026-10-02 (security 83; portal 50; npm 8+build; compose smoke :18084; live synch/conflict/E2E on :18083; restore drill SKIP without `--full`) |

### Remaining release blockers (must clear before full Pass)

- CapRover staging + production apps (isolation, HTTPS, private DB, single instance)
  — machine `captain-project-park` listed; **re-login required** before deploy
- Image promote staging→prod (same digest) — still ops
- Device QA (iOS Safari / Android Chrome install, SW update banner, offline UX, ACK touch flows)
- CapRover desktop SynchControl + desktop↔PWA on staging HTTPS (local compose PASS only)
- CapRover staging DB + files restore drill (local compose restore PASS); backup-failure alerts
- CapRover cutover + rollback reconciliation rehearsed; production cutover window
  (docs + local rehearsal Partial; CapRover still open)
- CapRover HTTPS cookie `Secure` / proxy headers — **Partial↑**: local
  `Portal_session::request_is_https` + `./scripts/https-cookie-drill.sh` PASS;
  CapRover staging Force HTTPS still ops
