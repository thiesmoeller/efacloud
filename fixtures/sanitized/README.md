# Sanitized fixtures (dockside PWA)

Committed, synthetic club data for parity and integration tests.

## What this is

JSON records shaped like EFA / efaCloud fields (`TypeSeats`, `CurrentStatus`,
`Severity`, `Open`, …) covering the scenarios in `docs/parity/parity-matrix.md`.

Stable UUIDs live in `fleet-summary.json` → `ids`.

## What was sanitized / excluded

Derived from **structure** of the local club backup only:

| Kept as patterns | Removed / replaced |
| --- | --- |
| Seat-category mix (1, 2, 3, 4, 8, `2X`/`4X`) | Real boat names and UUIDs |
| Historical parallel-variant shapes (`2;2X`, `3;4X`, `4;4X`) | Real person names, emails, phones |
| Three damage severities + fixed damage | Real damage free-text tied to members |
| Status views Verfügbar / Auf Fahrt / Nicht verfügbar | Full multi-year logbooks |
| Club **config key values** (no PII) | Admin credentials, `.access`, zip |
| Midnight trip via `EndDate` | Reservation contact phone numbers |

**Do not commit** raw extracts under `fixtures/efa-backup/` or zips under
`test_backup/`. Those stay local for rehearsal only.

## Files

| File | Contents |
| --- | --- |
| `boats.json` | Active + expired boat versions / multi-variant |
| `boatstatus.json` | AVAILABLE / ONTHEWATER / NOTAVAILABLE samples |
| `boatdamages.json` | NOTUSEABLE, LIMITEDUSEABLE, FULLYUSEABLE, fixed |
| `persons.json` | Synthetic members (`* Beispiel`) |
| `destinations.json` | Named destinations with/without distance |
| `groups.json` | Synthetic „Trainer“ group for RequiredGroup checks |
| `boatreservations.json` | One upcoming reservation |
| `logbook-2026.json` | Open trip, closed trip, midnight/`EndDate` trip |
| `club-config.json` | Behavioral config snapshot |
| `portal-users.json` | member / trainer concession (131072) / bths / admin personas |
| `fleet-summary.json` | Index + stable ids |

## Not full EFA XML

These are **characterization fixtures**, not a drop-in `efaBackup_*.zip`.
Import into MariaDB / desktop will need a converter in a later step if required.

## Local portal API smoke (no CapRover, no club PII)

```bash
./scripts/portal-fixture-smoke.sh
```

Starts an ephemeral PHP server with `EFACLOUD_PORTAL_FIXTURES=1` (or use
`--base-url` against compose + `docker-compose.portal-fixtures.yml`). Logs in as
fixture user `101` / `fixture-pass` and exercises boats, persons, destinations,
open trip, damage report, and trip start response shape.

Multi-step mutation persistence and idempotent replay are covered by
`./tests/portal/run.sh` (in-process store), not by the HTTP smoke (fixture store
reloads per request).

Never bake these fixtures into CapRover/production images (already `.dockerignore`d).
Optional compose overlay mounts them read-only for local same-origin checks only —
see `docs/deploy/local-compose-smoke.md`.
