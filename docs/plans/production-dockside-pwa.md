# Production efaCloud with an EFA-compatible dockside PWA

Status: agreed implementation plan; implementation has not started under this plan.
Recorded: 2026-10-02.

## Goal and agreed decisions

Deploy the hardened `thiesmoeller/efacloud` fork on the club's existing CapRover instance, together with a React/TypeScript PWA. Desktop and PWA operate on the same efaCloud data. The club currently uses EFA desktop only; cloud activation and initial data transfer are part of this release.

The first production release includes both the secure backend and the dockside PWA. It includes fast boat selection and the full individual trip workflow: start, correct, finish, abort, inspect damage, and report damage. Preserve EFA's configured defaults, validation, warnings, and explicit overrides while adapting presentation for phones.

- Existing CapRover infrastructure; efaCloud staging/production apps and databases still need provisioning.
- Individual accounts provisioned by administrators and linked to EFA persons.
- Members manage trips they participate in. A separate trainer privilege permits managing any crew's trips without administrator rights.
- Trainers check in one boat at a time, with that trip's own time and distance.
- Online PWA writes only. During outages, use a manual fallback and pause digital trip writes operationally until synchronization and consistency checks complete.
- One club, German UI, Europe/Berlin time.
- Reservation creation, repair administration, bulk operations, and unrelated EFA features remain outside v1. Existing reservations and repaired damage still affect/display in the trip workflow as appropriate.
- No replacement backend or ROW R/8 migration. This fork is the production target.

## 1. Fast boat selection and workflow parity

- Derive selection from EFA's actual boat attributes: `TypeSeats`, `TypeRigging`, `TypeCoxing`, `TypeType`, variant descriptions, and validity periods. Interpret parallel variant lists together; preserve EFA's variant numbering.
- Offer large seat-count filters: **Alle, Einer, Zweier, Dreier, Vierer, …**, showing categories present in the current fleet. Seat counts exclude the cox. Normalize `4X` and `4` into Vierer while retaining their rigging distinction.
- Within each category, sort boats alphabetically using German collation. Display rigging, coxing, and hull type as badges; offer these as optional secondary filters.
- Keep direct name search available from the initial screen. Searching spans all categories and matches boat names and variant descriptions.
- Selecting a boat with one matching variant opens the trip form directly. Multiple matching variants require a compact configuration choice. A physical boat can appear in multiple categories but always shares one availability state and damage history.
- Provide **Verfügbar**, **Auf Fahrt**, and **Nicht verfügbar** views, with damage indicators and reasons for unavailability. Unavailable boats remain accessible through their view/search because EFA permits explicit overrides.
- Match desktop trip fields, crew/cox layout, captain selection, destination/distance defaults, required fields, and configured restrictions. Preserve corrections, abort behavior, and the distinction between a trip that never happened and a completed trip.
- Use the checked-out Java EFA implementation and club configuration as the behavioral reference; efaWeb is a secondary reference. Document parity scenarios before implementation.

## 2. Damage handling and privileges

- Show unresolved damage on boat selection and in the trip form, most severe first. Expose report details and resolved history separately.
- Preserve EFA's three severities: **Boot nicht benutzbar**, **Boot eingeschränkt benutzbar**, and **Boot voll benutzbar**.
- Reproduce departure checks in EFA's order: boat status, relevant reservations, then damage warnings. Honor configured reservation lookahead and whether minor damage triggers a warning.
- Preserve EFA's explicit confirmation overrides for unavailable, reserved, and damaged boats. The user explicitly chose EFA parity rather than trainer-only overrides or a new unconditional block for unusable boats. Record acknowledgments; revalidate before saving and require renewed confirmation if relevant conditions changed.
- Support damage reporting from the boat view and trip form, plus EFA's “abort with damage report” action. Preserve description, severity, reporter, report time, and trip context/defaults.
- Store reports in existing EFA damage records, preserving synchronization, resulting availability behavior, and configured notifications. Reporting damage must not silently finish or abort a trip.
- Authenticated members may inspect and report boat damage. Repair administration remains in existing tools; trainer privileges do not confer administrator rights.
- Enforce trip permissions on the server, including alternative legacy endpoints accessible to these accounts.

## 3. Backend, security, and PWA architecture

- Add a same-origin `/api/portal/v1/` JSON API for sessions, boat variants/status, lookup data, trips, damage reports, and warning acknowledgments. Serve the frontend at `/portal/`; preserve desktop `/api/posttx.php`.
- Implement shared PHP domain services with server-side permissions and EFA-compatible validation. Commit related trip/status changes, sequence allocation, and audit changes atomically; preserve synchronization metadata.
- Add request idempotency and stale-state checks. Exercise legacy desktop writes against the new operations; locking the new API alone does not establish cross-client correctness.
- Use secure cookie sessions, CSRF protection, login throttling, logout, account revocation, and an administrator-assisted password reset workflow.
- Review all exposed legacy routes for authorization bypasses, SQL injection, XSS, CSRF, upload handling, and session weaknesses. Verify existing hardening and fix identified release-blocking vulnerabilities with regression coverage.
- Protect installation before public serving begins and make interrupted bootstrap recoverable. Distinguish incomplete installation from successful setup.
- Build images from explicit application inputs and committed frontend source, excluding secrets, local data, fixtures, demo credentials, and untracked build artifacts.
- Build a reproducible React/TypeScript application with install manifest, icons, and a service worker caching application assets only. Authenticated data and mutations remain network-only. Show success only after server confirmation, and provide clear offline messaging.

## 4. Deployment and migration

- Create isolated staging and production web/database apps with HTTPS, private database networking, persistent application storage, and one web instance initially.
- Build and promote the same versioned image through staging to production. Include database readiness, application health checks, error monitoring, and backup-failure alerts.
- Back up database and persistent files separately from CapRover configuration; CapRover's own backup excludes application volumes and images. Practice restoration before launch. Reference: <https://caprover.com/docs/backup-and-restore>.
- Rehearse initial cloud activation using a copy of the desktop backup. Verify boats, persons, destinations, historical trips, ongoing trips, damage, and repeated synchronization.
- At cutover, pause trip entry, take a fresh desktop backup, activate production cloud synchronization, verify data, and enable desk/PWA access.
- Document rollback with reconciliation of any trips entered after cutover; restoring an older snapshot must not silently discard new activity.
- During connection failures, use the agreed manual fallback and pause digital trip writes operationally. Resume after synchronization and consistency checks. Automated desktop enforcement requires separately validated desktop support; do not imply it already exists.
- EFA contains synchronization and recovery logic. Test actual recovery behavior: record synchronization alone does not prove that conflicting checkouts preserve consistent boat status and logbook records.
- Domain names, deployment credentials, and backup destination are deployment inputs to supply outside the repository. They do not block local implementation or staging preparation.

## 5. Acceptance and release gates

- Test the actual fleet's singles, triples, quads, coxed/coxless and scull/sweep variants, historical records, and unusual boat types. Selection by category requires no typing; name search reaches the same variants. Avoid counting historical boat versions as separate active boats.
- Compare desktop and PWA outcomes for start, correction, finish, abort, and abort-with-damage, including configured defaults and validation.
- Test all damage severities, multiple reports, repaired damage, reservation warnings, confirmation cancellation, overrides, and damage changes while a form is open.
- Verify desktop-created trips and damage appear in the PWA and vice versa; finish trips through either interface.
- Exercise simultaneous desktop/PWA changes, repeated submissions, interrupted requests, stale checkins, and reconnection. Release requires no silent loss or inconsistent boat/logbook state.
- Test midnight trips, crew selection, invalid distances, boat eligibility, and the currently selected logbook.
- Verify members cannot modify unrelated trips, trainers can, and unauthenticated/revoked accounts cannot write. Test legacy endpoints for permission bypasses.
- Validate installation and use on iOS Safari and Android Chrome, offline messaging, logout, and service-worker updates.
- Verify clean installation, interrupted bootstrap recovery, redeployment with persistent data, and restoration onto staging.
- Production release requires passing security, synchronization, workflow-parity, PWA, and restore checks.

## Implementation order and agent starting points

1. **Characterize behavior and establish fixtures.** Build a parity matrix from Java EFA and club configuration. Trace trip writes, damage/status effects, identifiers, synchronization metadata, and configuration transport. Establish reproducible desktop/cloud integration checks before changing behavior.
2. **Harden packaging and bootstrap.** Address the existing image and installer exposure, establish isolated staging configuration, and add focused security regressions. This can proceed independently of frontend presentation work.
3. **Implement shared domain services and the portal API.** Cover identity/privileges, boat variants, trip operations, damage, acknowledgments, and synchronization. Prove atomicity, retries, and cross-client behavior before exposing writes in the PWA.
4. **Implement the mobile workflow.** Build selection, trip forms, damage flows, and PWA installation against the agreed API, preserving the parity matrix. Validate on phones with actual fleet variants.
5. **Rehearse and release.** Complete security review, backup/restore, initial synchronization, cutover, and rollback rehearsals. Promote the tested image only after release gates pass.

Agents should record completed work, validation evidence, remaining gaps, and discovered deviations alongside this plan. Do not silently substitute efaWeb behavior where it differs from the desktop or change agreed permissions/override semantics.

### Repository observations at planning time

- Fork HEAD inspected: `939a7ed` (`Support CapRover-first setup via EFACLOUD_* environment variables.`).
- Existing security hardening commit: `ab35fcd` (`Harden installer access and validate untrusted SQL identifiers.`). Its presence is not a completed security review.
- `Dockerfile` currently uses `COPY . /var/www/html/`; `.dockerignore` does not exclude all local artifacts.
- Bootstrap starts Apache before installation completes. Installation detection currently relies on database-settings file existence, which needs interrupted-install testing.
- `demo/`, `fixtures/`, `lab/`, and `portal/` are pre-existing untracked directories. Preserve them. Inspection found frontend build artifacts and dependencies under `portal/`, but no frontend source/package manifest at its root. Do not assume those artifacts are a maintainable implementation.
- The workspace also contains an older `rowr8/` project and test harness aimed at a different migration. Reuse infrastructure only after checking its target paths and behavior; do not follow its ROW R/8 migration objective.

### Source references

Paths below are relative to this fork's root unless noted otherwise.

| Area | Starting points |
| --- | --- |
| Container and installation | `Dockerfile`, `.dockerignore`, `docker/apache-efacloud.conf`, `docker/docker-entrypoint.sh`, `docker/bootstrap-install.sh`, `install/install_guard.php` |
| PHP persistence/protocol | `api/posttx.php`, `classes/efa_api.php`, `classes/efa_record.php`, `classes/efa_tables.php`, `classes/tfyh_socket.php` |
| Sessions and identity | `classes/tfyh_app_sessions.php`, `forms/login.php`, `config/db_layout/` (`efaCloudUsers.PersonId` and `Rolle`) |
| Existing web behavior | `js_23/bPanel.js`, `js_23/bFormHandler.js`, `js_23/efaInputValidator.js`, `js_23/oBoat.js`, `js_23/oDamage.js` |
| Desktop boat variants | `../efa/de/nmichael/efa/data/BoatRecord.java`, `../efa/de/nmichael/efa/core/items/ItemTypeBoatstatusList.java` |
| Desktop trip checks and abort | `../efa/de/nmichael/efa/gui/EfaBoathouseFrame.java` (`checkStartSessionForBoat`, `checkBoatDamage`, start/correct/finish/abort actions) |
| Desktop trip form | `../efa/de/nmichael/efa/gui/EfaBaseFrame.java` |
| Desktop damage behavior | `../efa/de/nmichael/efa/data/BoatDamageRecord.java`, `../efa/de/nmichael/efa/data/BoatDamages.java`, `../efa/de/nmichael/efa/gui/dataedit/BoatDamageEditDialog.java` |
| Desktop synchronization | `../efa/de/nmichael/efa/data/efacloud/SynchControl.java`, `../efa/de/nmichael/efa/data/storage/EfaCloudStorage.java` |
| Existing local integration infrastructure | `../docker-compose.test.yml`, `../TEST_ENV.md` |
| Local backup for rehearsal | `../test_backup/efaBackup_20260607_095337.zip` |

The inspected backup contains multiple historical versions and boats with parallel variants such as three rowers with a cox versus four rowers without a cox, and scull/sweep alternatives. Treat the backup as local club data; derive sanitized fixtures for committed tests and never bake it into a production image.
