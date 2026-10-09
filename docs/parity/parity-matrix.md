# Parity matrix — EFA desktop ↔ dockside PWA

Status: characterization complete for step 1 (no product behavior changed).  
Reference: Java EFA under `../efa/`; club config sampled from local backup (not committed).  
Convention: German UI terms match the club. Desktop is authoritative; efaWeb notes are secondary and called out when they diverge.

Legend for **PWA expected**: `match-desktop` | `adapt-UI-only` | `cloud-extension` | `gap` (not yet specified in code).

---

## 1. Boat selection

| ID | Scenario | Desktop evidence | Club config / fleet note | PWA expected |
| --- | --- | --- | --- | --- |
| B1 | Attributes per variant: `TypeType`, `TypeSeats`, `TypeRigging`, `TypeCoxing`, `TypeDescription`, `TypeVariant` | `BoatRecord` fields; parallel lists read by index (`getTypeSeats(idx)` …) | Current valid fleet ≈ 57 boats; historical versions often had `4;4X` / `2;2X` / `3;4X` parallel variants | match-desktop |
| B2 | Seat count excludes cox | `BoatRecord.getNumberOfSeats` parses `TypeSeats` only; cox is `TypeCoxing` | — | match-desktop |
| B3 | Normalize `4X`/`2X`/… into Vierer/Zweier category while keeping rigging | `getGeneralNumberOfSeatsType` maps `4X→4`, `2X→2`, … | Current seat categories present: 1, 2, 3, 4, 8 (+ OTHER) | match-desktop |
| B4 | Seat-count filters **Alle / Einer / Zweier / Dreier / Vierer / …** | Desktop: **no chip filters**. With `BoatListSortBySeats=true`, `ItemTypeBoatstatusList.sortBootsList` inserts section headers from `EfaTypes` (`Einer`, `Zweier`, …). Digit keys jump to section (`ItemTypeList.scrollToEntry`). Empty text filter = Alle | Club: `BoatListSortBySeats = true` | adapt-UI-only (plan adds explicit filters; categories must mirror `getGeneralNumberOfSeatsType`) |
| B5 | Sort within category: German-friendly name order | `BoatString` + `EfaUtil.replaceAllUmlautsLowerCaseFast` (lowercase, strip diacritics, ß→ss) — **not** `Collator(GERMANY)` | — | match-desktop (same normalizer; do not invent ICU collation unless desktop changes) |
| B6 | Secondary badges / optional filters: rigging, coxing, hull type | Desktop sorts optionally by rigger/type (`BoatListSortByRigger/Type`, club both false); display via short type `getShortBoatType` | — | adapt-UI-only |
| B7 | Name search across categories (name + variant description) | Desktop filter substring on boat text / tooltip; umlaut folding if easy-find config | — | match-desktop intent |
| B8 | One matching variant → trip form; several → configuration choice | AVAILABLE multi-seat-count variants → one list row per distinct seat count (`sortBootsList` Hashtable); same seat count prefers `DefaultVariant` | Current club versions mostly single-variant; historical patterns remain in fixture set | match-desktop |
| B9 | Same physical boat, one availability + damage history across categories | Status keyed by `BoatId`; damages keyed by `BoatId`+`Damage` | — | match-desktop |
| B10 | Validity: only boats valid at “now” in lists | `boats.getBoat(id, now)`; invalid omitted (except unknown boats) | Many historical versions per boat Id | match-desktop |
| B11 | Views: **Verfügbar** / **Auf Fahrt** / **Nicht verfügbar** | Labels `"verfügbare Boote"`, `"Boote auf Fahrt"`, `"nicht verfügbare Boote"`; statuses `AVAILABLE` / `ONTHEWATER` / `NOTAVAILABLE` | Club: `BoatListShowOnMultiDayOrRegattaBoatsAsNotAvailable=true`; `BoatListShowReservedBoatsAsNotAvailable=false` | match-desktop |
| B12 | Unavailable still selectable with override | `checkStartSessionForBoat` yes/no/cancel for `STATUS_NOTAVAILABLE` | — | match-desktop |
| B13 | Damage indicator on lists | Background task can set `ShowInList=NOTAVAILABLE` + comment for open `NOTUSEABLE`; tooltips | Several boats show `Bootsschaden: … (Boot nicht benutzbar)` | match-desktop |

---

## 2. Departure checks (order and overrides)

Authoritative order in `EfaBoathouseFrame.checkStartSessionForBoat` (mode 1 = start from list):

| Step | Check | Dialog | Override | Hard fail |
| --- | --- | --- | --- | --- |
| 1 | Already `ONTHEWATER` with **different** `EntryNo` | `"Das Boot {boat} ist bereits unterwegs."` | — | yes |
| 2 | Already `ONTHEWATER` (same session, mode 1) | opens correct flow (`actionStartSessionCorrect`) | — | returns false (no start) |
| 3 | `STATUS_NOTAVAILABLE` | Title `"Boot gesperrt"`; ask `"Möchtest Du trotzdem das Boot benutzen?"` | **yes / no / cancel** — only YES continues | no |
| 4 | Reservation in look-ahead window | Title `"Boot reserviert"`; same override question | **yes / no / cancel** | no |
| 5 | Open damage via `checkBoatDamage` | Title `"Bootsschaden gemeldet"`; severity description; caller question | **yes / no** (no cancel variant) | no |

| ID | Scenario | Evidence | Club | PWA expected |
| --- | --- | --- | --- | --- |
| C1 | Check order status → reservations → damage | `checkStartSessionForBoat` sequence above | `ReservationLookAheadTime = 120` | match-desktop |
| C2 | Cancel on status/reservation dialog aborts start | `yesNoCancelDialog` ≠ YES | — | match-desktop |
| C3 | Damage warning set | `BoatDamages.warnDamage`: warn if severity `NOTUSEABLE` or `LIMITEDUSEABLE`, **or** if `InputWarnOnlyCriticalBoatDamages` true (misnamed key; UI = also warn non-critical) | Club value **false** → do **not** warn for `FULLYUSEABLE` | match-desktop |
| C4 | Most severe open damage shown first | `getBoatDamages(id, onlyOpen=true, mostSevereFirst=true)`; priority NOTUSEABLE &lt; LIMITED &lt; FULLY | — | match-desktop |
| C5 | Revalidate before save; renew confirmation if conditions changed | Plan requirement; desktop re-runs checks when entering start from list / boat change (`mode==2` from `EfaBaseFrame`) | — | match-desktop + record acknowledgments (portal) |
| C6 | Overrides not trainer-gated | Any boathouse user can confirm; no Trainer role in Java | — | match-desktop (plan: explicit confirmations for all authenticated trip actors) |

**Deviation from plan wording:** Plan §1 offers Alle/Einer/… as first-class filters. Desktop implements seat **sections + digit jump**, not filter chips. PWA may add chips but categories must equal desktop seat normalization.

---

## 3. Damage

| ID | Scenario | Desktop | efaWeb note (secondary) | PWA expected |
| --- | --- | --- | --- | --- |
| D1 | Severities | `FULLYUSEABLE` → „Boot voll benutzbar“; `LIMITEDUSEABLE` → „Boot eingeschränkt benutzbar“; `NOTUSEABLE` → „Boot nicht benutzbar“ (`BoatDamageRecord`) | Same constants in `bConfig.js` | match-desktop |
| D2 | Unresolved shown most severe first | `getPriority` / `getBoatDamages(..., true, true)` | `oDamage` hides `FULLYUSEABLE` from “open” display | Prefer desktop (show all open; warn policy C3) |
| D3 | Report from boat view / trip form | `BoatDamageEditDialog.newBoatDamage`; fields description, severity, reporter, report time, optional logbook text | `bFormHandler.postDamage_done` | match-desktop |
| D4 | Abort with damage | `actionAbortSession` choice 1 → damage dialog **then** abort (`MODE_BOATHOUSE_ABORT`) | Separate flows | match-desktop |
| D5 | Reporting must not finish/abort trip | Damage dialog alone does not mutate logbook; abort path deletes open entry only after abort mode save | Web: damage insert + optional status | match-desktop |
| D6 | `NOTUSEABLE` affects availability | Desktop background: unfixed NOTUSEABLE → `ShowInList=NOTAVAILABLE` + comment | `oBoat.updateBoatStatusOnDamage` only for NOTUSEABLE | match-desktop |
| D7 | Members may inspect/report; repair admin stays elsewhere | Desktop: `BoatDamageEnableReporting` (club true); repair via admin tools | Concessions `ewDamageEntry` etc. | cloud-extension for account ACL; store in `efa2boatdamages` |
| D8 | Resolved history separate from open | `Fixed` flag; fixed priority 9 | — | match-desktop |

---

## 4. Trip lifecycle

| ID | Action | Logbook | BoatStatus | Desktop entry |
| --- | --- | --- | --- | --- |
| T1 | **Start** | insert; `SessionIsOpen`/`Open=true`; date today; PWA start defaults to current server time | `CurrentStatus=ONTHEWATER`; set `EntryNo`, `Logbook`, comment | PWA intentionally differs from `MODE_BOATHOUSE_START`, whose suggestion adds `StartSessionTimeAdd` |
| T2 | **Correct** | update open entry | refresh OTW; if boat changed, old boat → available | `MODE_BOATHOUSE_START_CORRECT` |
| T3 | **Finish** | update; close session; end ≈ now−`FinishSessionTimeSubstract`; distance enabled | back to `BaseStatus` (usually AVAILABLE); clear entry/logbook/comment | `MODE_BOATHOUSE_FINISH` |
| T4 | **Abort** | **delete** open entry (trip never happened) | same as finish (available) | `MODE_BOATHOUSE_ABORT` after confirm |
| T5 | **Abort + damage** | damage report then abort delete | same | `actionAbortSession` case 1 |
| T6 | Late entry | insert **closed** session; no status change | — | `actionLateEntry` / efaWeb `LATEENTRY` |

| ID | Scenario | Club / evidence | PWA expected |
| --- | --- | --- | --- |
| T7 | Destination required at start | `StartSessionMustSelectDestination = true` | match-desktop |
| T8 | Distance required on finish (unless allow-without) | Config key `MustEnterDistance` = **false** means **do not** allow empty distance (`getValueAllowSessionsWithoutDistance`) | match-desktop |
| T9 | Default destination / distance from boat & destination records | `DefaultDestinationId`; known dest fills distance | match-desktop |
| T10 | Default times | PWA start uses current time; finish uses `FinishSessionTimeSubstract=5` | intentional start-time divergence; return matches desktop |
| T11 | Session type default | `SessionTypeDefault = NORMAL` | match-desktop |

Abort dialog strings (`actionAbortSession`): title „Fahrt abbrechen“; body that abort only if trip never happened; buttons „Fahrt abbrechen“ | „Fahrt abbrechen (Bootsschaden)“ | „Nichts“.

---

## 5. Crew / cox / captain

| ID | Scenario | Evidence | Club | PWA expected |
| --- | --- | --- | --- | --- |
| P1 | Cox field „Steuermann“; crew 1…n; 1× coxless labels crew as „Name“ | `EfaBaseFrame` | — | match-desktop |
| P2 | Coxless → cox disabled/cleared | `currentBoatUpdateGuiBoathouse` | — | match-desktop |
| P3 | Max crew = seat count | `InputAllowOnlyMaxCrewNumber = true` | match-desktop |
| P4 | Obmann visible; auto-select; not forced | `BoatCaptainShow=true`, `BoatCaptainAutoSelect=true`, `InputMustSelectBoatCaptain=false`, default `BOW` | match-desktop |
| P5 | Captain must be seated person when set | `checkBoatCaptain` | — | match-desktop |
| P6 | Required group in boat | `InputCheckMinGroupPersonsInBoat = true` + boat `RequiredGroupId` | match-desktop |
| P7 | efaWeb hardcodes `BoatCaptain=1` | `bFormHandler.js` | — | **Do not follow efaWeb** — match desktop |

---

## 6. Midnight / multi-day

| ID | Scenario | Evidence | Club | PWA expected |
| --- | --- | --- | --- | --- |
| M1 | Start &gt; end without EndDate → offer Mehrtagsfahrt | `EfaBaseFrame.checkTime` dialog „Ist dieser Eintrag eine Mehrtagsfahrt?“ | `AllowEnterEndDate = true` | match-desktop |
| M2 | EndDate must be after Date | `checkDate` | — | match-desktop |
| M3 | Multi-day / tour / regatta on water may list under Nicht verfügbar | `BoatListShowOnMultiDayOrRegattaBoatsAsNotAvailable = true` | match-desktop |
| M4 | No separate “midnight flag” | Crossing midnight modeled as EndDate / Mehrtagsfahrt | — | match-desktop |

---

## 7. Permissions (trainer vs member)

| ID | Scenario | Desktop | efaCloud today | PWA / portal expected |
| --- | --- | --- | --- | --- |
| R1 | Desktop has no Trainer role | Groups (`RequiredGroupId` / allowed groups) + admin mode | Roles: `anonymous < guest < member < bths < board < admin`; **no trainer** | **cloud-extension**: plan’s trainer privilege is new portal semantics, not a Java role |
| R2 | Members manage trips they participate in | N/A (shared kiosk) | `efaCloudUsers.PersonId` + own-session lists | Implement in portal API |
| R3 | Trainer may manage any crew’s trips without admin | N/A | Closest today: `bths` or broad concessions — **not equivalent** | New privilege; must not grant admin/repair admin |
| R4 | Legacy `/api/posttx.php` must enforce same rules | Desktop API user is typically bths-level | `config/access/api` | Harden in later steps |

---

## 8. Sync / identifiers (acceptance hooks)

| ID | Topic | Must preserve |
| --- | --- | --- |
| S1 | Record identity | `ecrid` (12-char); versionized `ValidFrom`/`InvalidFrom` on boats/persons |
| S2 | Optimistic concurrency | `LastModified`, `ChangeCount`, `LastModification` |
| S3 | Logbook key | `EntryId` + `Logbookname` (server); desktop per-file `EntryId` |
| S4 | Damage key | `BoatId` + `Damage` |
| S5 | Status linkage | `BoatStatus.EntryNo` + `Logbook` while OTW |

---

## 9. Explicit efaWeb deviations (do not copy blindly)

| Topic | Desktop | efaWeb |
| --- | --- | --- |
| BoatCaptain | Full Obmann UI | Forced `1` |
| Open damage list | All open severities for warning policy | Hides `FULLYUSEABLE` from open list |
| API keys | Client keys + keyfixing (API &lt;3) | Server autoincrement (API 3) |
| Seat filters | Sections / digit jump | Grouped HTML lists in `bPanel.js` |
| Departure check order | Status → reservation → damage with confirms | Client panel does not mirror this dialog chain |

---

## Coverage checklist (for later acceptance)

- [ ] Singles, triples, quads, 8+, coxed/coxless, scull/sweep, OTHER
- [ ] Historical version not shown as separate active boat
- [ ] Start / correct / finish / abort / abort-with-damage
- [ ] All three damage severities + repaired + multi-open
- [ ] Reservation warning + cancel override + renew on change
- [ ] Midnight / EndDate path
- [ ] Member vs trainer trip ACL (portal)
- [ ] Cross-client desktop ↔ PWA consistency
