# Trace notes — trip writes, damage/status, identifiers, sync, config

Characterization only (plan step 1). Paths relative to workspace unless noted.

---

## 1. Trip write path

### Desktop (Java)

| Step | Location |
| --- | --- |
| Start / correct / finish / abort actions | `efa/de/nmichael/efa/gui/EfaBoathouseFrame.java` — `actionStartSession`, `actionStartSessionCorrect`, `actionFinishSession`, `actionAbortSession` |
| Pre-start checks | `checkStartSessionForBoat`, `checkBoatDamage`, `checkBoatStatusOnTheWater` |
| Form + validation | `efa/de/nmichael/efa/gui/EfaBaseFrame.java` — modes `MODE_BOATHOUSE_START`, `_START_CORRECT`, `_FINISH`, `_ABORT` |
| Persist logbook | project `Logbook` storage (XML locally; `EfaCloudStorage` when cloud-enabled) |
| Update boat status after save | `EfaBoathouseFrame.finishBoathouseAction` → boat status update (`ONTHEWATER` / back to `BaseStatus`) |

**Abort semantics:** open logbook record is **deleted** (trip never happened), not closed. Finish **closes** the record and keeps history.

**Abort + damage:** `actionAbortSession` choice index 1 calls `BoatDamageEditDialog.newBoatDamage` **before** `showEfaBaseFrame(MODE_BOATHOUSE_ABORT, …)`.

### efaCloud (PHP / protocol)

| Step | Location |
| --- | --- |
| HTTP entry | `api/posttx.php` |
| Tx dispatch | `classes/tx_handler.php` — `execute_transaction` (`insert` / `update` / `delete` / `synch` / …) |
| Record validate + write | `classes/efa_api.php` → `api_modify`; `classes/efa_record.php` — `validate_record_APIv{1v2|3}`, `modify_record` |
| Table keys / autoincrement | `classes/efa_tables.php` — logbook key `EntryId`+`Logbookname`; autoincrement `EntryId` per logbook (API ≥3) |
| Schema | `config/db_layout/V9` table `efa2logbook` |

**appType:** numeric API user → desktop (`appType=0`); `@`-prefixed user id → efaWeb (`appType=1`) in `Tx_handler::parse_request_container`.

### efaWeb (secondary)

`js_23/bFormHandler.js`: `startSession_done` (insert Open), `endSession_done` (update closed), `updateSession_done`, `cancelSession_done` (delete) then `oBoat.updateBoatStatusOnSession` / `OnCancel`. Forces `BoatCaptain = 1` — **desktop divergence**.

---

## 2. Departure check order and overrides

Source: `EfaBoathouseFrame.checkStartSessionForBoat` (mode 1).

1. **On water, wrong EntryNo** → hard error „Das Boot … ist bereits unterwegs.“
2. **On water, mode 1** → redirect to correct; no start.
3. **`STATUS_NOTAVAILABLE`** → yes/no/cancel „Boot gesperrt“ / „Möchtest Du trotzdem das Boot benutzen?“
4. **Reservations** within `ReservationLookAheadTime` (club: 120 min) → yes/no/cancel „Boot reserviert“ / same question.
5. **`checkBoatDamage`** → yes/no „Bootsschaden gemeldet“ / caller question.

Damage warn gate: `BoatDamages.warnDamage` + config parameter key `InputWarnOnlyCriticalBoatDamages` (getter `getValueWarnEvenNonCriticalBoatDamages`). Club **false** → warn only `NOTUSEABLE` and `LIMITEDUSEABLE`.

---

## 3. Damage and status effects

| Concern | Java | PHP / JS |
| --- | --- | --- |
| Model | `BoatDamageRecord.java`, `BoatDamages.java` | `efa2boatdamages` in V9; web `js_23/oDamage.js` |
| Edit UI | `BoatDamageEditDialog.java` | `bFormHandler.postDamage_done` |
| Severities | `FULLYUSEABLE` / `LIMITEDUSEABLE` / `NOTUSEABLE` | same string constants |
| Auto list hide | Boathouse background task: open `NOTUSEABLE` → `ShowInList=NOTAVAILABLE` + short comment | `oBoat.updateBoatStatusOnDamage`: only `NOTUSEABLE` sets `ShowInList=NOTAVAILABLE` |
| Soft-delete sync hack | — | `Efa_record::clear_record_for_delete` forces `Severity=FULLYUSEABLE` on delete stub |

Reporting damage alone must not finish or abort a trip (desktop: separate dialogs; abort path is explicit).

---

## 4. Identifiers

| Id | Format | Used for |
| --- | --- | --- |
| Boat / Person / Destination Ids | UUID strings | FK fields (`BoatId`, `CoxId`, `Crew1Id`, …) |
| Logbook `EntryId` | int, scoped by logbook year/name | Trip number; mirrored on `BoatStatus.EntryNo` while open |
| Damage number | int `Damage` per boat | Composite key with `BoatId` |
| `ecrid` | 12-char BASE62-ish | Cloud primary match (`Ecrid.generate` / PHP generator) |
| `ClientSideKey` | `{efaCloudUserID}:{clientKey}` | Desktop keyfix trail |
| Version window | `ValidFrom` / `InvalidFrom` (Java millis; forever ≈ `9223372036854775807`) | Boats, persons, destinations — **not** damages/logbook |

---

## 5. Synchronization metadata

| Field | Role |
| --- | --- |
| `LastModified` | millis; synch window filter |
| `ChangeCount` | optimistic concurrency |
| `LastModification` | `insert` / `update` / `delete` |
| `ecrid` / `ecrown` / `ecrhis` | identity / owner / history (server-side API ≥3) |

Java: `efa/de/nmichael/efa/data/efacloud/SynchControl.java`, `…/storage/EfaCloudStorage.java` (`modifyServerRecord`, ecrid ensure on add, conflict by `LastModified`).

PHP: API ≥3 `Efa_tables::register_modification` / `add_system_fields_APIv3`; API &lt;3 desktop owns ChangeCount/LastModified (`add_system_fields_APIv1v2`). Upload look-back ~15 days mirrors Java.

Special: boat status EntryNo remapping during synch (`SynchControl.adjustBoatStatus`).

---

## 6. Configuration transport

1. Desktop `upload` tx → `uploads/{efaCloudUserID}/` (`Tx_handler::api_upload`).
2. `Efa_config::parse_client_config` maps `efa2project` / `efa2types` / `efa2config` → JSON.
3. `api_nop` handshake returns API version, session, synch periods, concessions, db layout.
4. efaWeb: `pages/efaWeb.php` + `Efa_config::pass_on_config` injects `efaTypes`, `efaProjectCfg`, `efaConfig`.

Club keys relevant to PWA (sampled from local backup config, not committed raw):

| Key | Club value | Meaning for parity |
| --- | --- | --- |
| `BoatListSortBySeats` | true | Seat section headers |
| `ReservationLookAheadTime` | 120 | Minutes |
| `InputWarnOnlyCriticalBoatDamages` | false | Do not warn FULLYUSEABLE |
| `StartSessionMustSelectDestination` | true | Dest required at start |
| `MustEnterDistance` | false | Do **not** allow empty distance (`getValueAllowSessionsWithoutDistance`) |
| `StartSessionTimeAdd` / `FinishSessionTimeSubstract` | 5 / 5 | Desktop default-time skew; PWA intentionally ignores the start offset because checkout occurs at the dock |
| `BoatCaptainShow` / `BoatCaptainAutoSelect` / `InputMustSelectBoatCaptain` | true / true / false | Obmann UX |
| `BoatCaptainDefault` | BOW | Stroke default |
| `InputAllowOnlyMaxCrewNumber` | true | Clamp crew to seats |
| `InputCheckMinGroupPersonsInBoat` | true | Required group warning |
| `AllowEnterEndDate` | true | Mehrtagsfahrt |
| `BoatListShowOnMultiDayOrRegattaBoatsAsNotAvailable` | true | Multi-day → Nicht verfügbar |
| `BoatListShowReservedBoatsAsNotAvailable` | false | Reserved stay selectable in Verfügbar (with warning) |
| `BoatDamageEnableReporting` | true | Allow report UI |
| `NameFormat` | FIRSTLAST | Person display |
| `LocaleDefaultDistanceUnit` | km | Distance unit |

---

## 7. Boat list / variants (selection)

| Concern | Source |
| --- | --- |
| List build / sort / sections | `ItemTypeBoatstatusList.sortBootsList` |
| Name sort normalizer | `EfaUtil.replaceAllUmlautsLowerCaseFast` via `BoatString` |
| Multi-variant AVAILABLE rows | one row per distinct seat count; `DefaultVariant` tie-break |
| Seat labels | `EfaTypes` CATEGORY_NUMSEATS → Einer, Zweier, … |
| `4X`→Vierer mapping | `BoatRecord.getGeneralNumberOfSeatsType` |
| Status lists | `BoatStatus` AVAILABLE / ONTHEWATER / NOTAVAILABLE |

**Plan deviation:** Alle/Einer/Zweier as explicit filters are a PWA UI adaptation; desktop uses seat-sorted sections + digit jump + text filter.

---

## 8. Permissions map

| Concept | Desktop | efaCloud today | Portal plan |
| --- | --- | --- | --- |
| Kiosk user | Shared local UI | Role `bths` + concessions | — |
| Member | Config flags (reserve, etc.) | `Rolle=member` + PersonId | Own trips |
| Trainer | Club **group** only (`RequiredGroupId` examples), not a system role | **No trainer role** | New privilege: any crew’s trips, not admin |
| Admin | `AdminRecord` / workflows | `admin` / `board` + Workflows bits | Repair admin stays here |

Files: `config/access/role_hierarchy`, `config/access/api`, `config/access/concessions`, `config/access/workflows`; user fields `efaCloudUsers.PersonId`, `Rolle`, `Workflows`, `Concessions`.

---

## 9. Local integration references

- Workspace harness: `../docker-compose.test.yml`, `../TEST_ENV.md` (also targets older ROW R/8 paths — reuse carefully).
- Raw club backup (local only): `../test_backup/efaBackup_20260607_095337.zip` and untracked `fixtures/efa-backup/` extract.
- Sanitized committed fixtures: `fixtures/sanitized/` (see README there).
