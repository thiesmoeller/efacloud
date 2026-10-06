"""
Backup → cloud activation rehearsal against docker-compose MariaDB + portal API.

Loads EFA native backup tables via desktop `/api/posttx.php` (efa_api / efa_record
API v3 insert path), verifies fleet counts, exercises portal writes without
fixtures, simulates a second synch (posttx update), and runs one cross-client
(portal + posttx) consistency check.

Never enables EFACLOUD_PORTAL_FIXTURES. Does not claim CapRover / desktop GUI done.
"""

from __future__ import annotations

import argparse
import json
import os
import sys
import time
import uuid
from pathlib import Path
from typing import Any

from db import ComposeDb
from efa2xml import (
    CLEAR_TABLES,
    LOAD_TABLES,
    backup_timestamp_ms,
    collect_load_plan,
    find_project_data_dir,
    fleet_expected_counts,
    locate_extract_or_unzip,
    pick_trip_fixture,
)
from portal_client import PortalClient
from posttx_client import PosttxClient, parse_container_response, record_for_posttx

ROOT = Path(__file__).resolve().parents[2]

# Rehearsal-only accounts (local compose).
BTHS_ID = 901
PORTAL_ID = 902
REHEARSAL_PASSWORD = "RehearseSync1!"

# Batch size for posttx containers (multiple txs per HTTP round-trip).
POSTTX_BATCH = 25


class Check:
    def __init__(self):
        self.items: list[dict[str, Any]] = []

    def ok(self, name: str, detail: str = "") -> None:
        self.items.append({"name": name, "status": "PASS", "detail": detail})
        print(f"  PASS  {name}" + (f" — {detail}" if detail else ""))

    def fail(self, name: str, detail: str = "") -> None:
        self.items.append({"name": name, "status": "FAIL", "detail": detail})
        print(f"  FAIL  {name}" + (f" — {detail}" if detail else ""))

    def info(self, name: str, detail: str = "") -> None:
        self.items.append({"name": name, "status": "INFO", "detail": detail})
        print(f"  INFO  {name}" + (f" — {detail}" if detail else ""))

    @property
    def failed(self) -> bool:
        return any(i["status"] == "FAIL" for i in self.items)


def load_env_file(path: Path) -> dict[str, str]:
    out: dict[str, str] = {}
    if not path.is_file():
        return out
    for line in path.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        k, v = line.split("=", 1)
        out[k.strip()] = v.strip().strip('"').strip("'")
    return out


def ensure_users(db: ComposeDb, person_id: str, checks: Check) -> None:
    """SQL bootstrap only: rehearsal bths + portalTrainer accounts."""
    pw = db.php_password_hash(REHEARSAL_PASSWORD)
    # Remove prior rehearsal users if re-run on same volumes
    db.exec_sql(
        f"DELETE FROM efaCloudUsers WHERE efaCloudUserID IN ({BTHS_ID},{PORTAL_ID});"
    )
    now_ms = int(time.time() * 1000)
    rows = [
        {
            "EMail": "bths-rehearsal@example.test",
            "efaCloudUserID": BTHS_ID,
            "efaAdminName": "bths_rehearsal",
            "PersonId": "",
            "Vorname": "Bths",
            "Nachname": "Rehearsal",
            "Rolle": "bths",
            "Subskriptionen": 0,
            "Workflows": 0,
            "Concessions": 0,
            "Passwort_Hash": pw,
            "LastModified": now_ms,
        },
        {
            "EMail": "portal-rehearsal@example.test",
            "efaCloudUserID": PORTAL_ID,
            "efaAdminName": "portal_rehearsal",
            "PersonId": person_id,
            "Vorname": "Portal",
            "Nachname": "Trainer",
            "Rolle": "member",
            "Subskriptionen": 0,
            "Workflows": 0,
            "Concessions": 131072,  # portalTrainer
            "Passwort_Hash": pw,
            "LastModified": now_ms,
        },
    ]
    n = db.insert_rows("efaCloudUsers", rows)
    checks.ok("rehearsal users", f"inserted {n} (bths={BTHS_ID}, portalTrainer={PORTAL_ID})")


def clear_club_tables(db: ComposeDb) -> None:
    db.exec_sql(
        "SET FOREIGN_KEY_CHECKS=0;\n"
        + "\n".join(f"DELETE FROM `{t}`;" for t in CLEAR_TABLES)
        + "\nSET FOREIGN_KEY_CHECKS=1;"
    )


def _posttx_fail_detail(parsed: dict[str, Any]) -> str:
    txs = parsed.get("txs") or []
    bad = [t for t in txs if t.get("result_code") not in ("300", "303")]
    if bad:
        t = bad[0]
        return (
            f"cresult={parsed.get('cresult')} tx={t.get('id')} "
            f"code={t.get('result_code')} msg={t.get('result_message', '')[:300]}"
        )
    return (parsed.get("decoded") or parsed.get("raw") or "")[:400]


def load_backup_via_posttx(
    client: PosttxClient,
    plan: dict[str, list],
    checks: Check,
    *,
    batch_size: int = POSTTX_BATCH,
) -> dict[str, int]:
    """
    Primary load path: API v3 insert through /api/posttx.php (efa_record).

    Order: persons → boats → destinations → boatstatus → damages → reservations → logbook.
    Autoincrement fields (EntryId/Damage/Reservation) omitted — server assigns
    (same as EfaCloudStorage.modifyServerRecord).
    """
    counts: dict[str, int] = {}
    nop = client.nop_session()
    nop_parsed = parse_container_response(nop)
    if not nop_parsed.get("ok") and "Authentication failed" in (nop_parsed.get("decoded") or nop):
        checks.fail("posttx auth (bths)", (nop_parsed.get("decoded") or nop)[:300])
        return counts
    checks.ok("posttx auth (bths)", f"user={client.user_id}")

    for table in LOAD_TABLES:
        rows = plan.get(table) or []
        if not rows:
            counts[table] = 0
            checks.ok(f"posttx insert {table}", "0 rows")
            continue
        ok_n = 0
        for i in range(0, len(rows), batch_size):
            chunk = rows[i : i + batch_size]
            txs = [
                {
                    "type": "insert",
                    "table": table,
                    "record": record_for_posttx(table, r, for_insert=True),
                }
                for r in chunk
            ]
            parsed = client.modify_many(txs)
            if not parsed.get("ok"):
                checks.fail(
                    f"posttx insert {table}",
                    f"at row {i}: {_posttx_fail_detail(parsed)}",
                )
                counts[table] = ok_n
                return counts
            ok_n += len(chunk)
        counts[table] = ok_n
        checks.ok(f"posttx insert {table}", f"{ok_n} rows")
    checks.ok("load path", "posttx-primary (efa_record API v3); SQL only for users/clear")
    return counts


def _second_synch_record(table: str, row: dict[str, Any]) -> dict[str, Any]:
    """Re-upload payload for second synch.

    Must include versionized data-key fields (Id, ValidFrom) so
    add_system_fields_APIv3 does not mint a new UUID Id. Content otherwise
    stays untouched so historical (already-invalid) versions are accepted.
    """
    out: dict[str, Any] = {"ecrid": str(row.get("ecrid") or "")}
    if row.get("Id"):
        out["Id"] = str(row["Id"])
    if row.get("ValidFrom"):
        out["ValidFrom"] = str(row["ValidFrom"])
    return out


def second_synch_via_posttx(
    client: PosttxClient,
    db: ComposeDb,
    plan: dict[str, list],
    expected: dict[str, Any],
    checks: Check,
    *,
    batch_size: int = POSTTX_BATCH,
) -> None:
    """Re-upload via posttx update by ecrid — no duplicate current boats."""
    before_boats = db.query_scalar("SELECT COUNT(*) FROM efa2boats")
    before_cur = db.query_scalar(
        f"SELECT COUNT(DISTINCT Id) FROM efa2boats "
        f"WHERE CAST(ValidFrom AS UNSIGNED) <= {expected['at_ms']} "
        f"AND CAST(InvalidFrom AS UNSIGNED) > {expected['at_ms']}"
    )
    tables = ("efa2boats", "efa2boatstatus", "efa2persons", "efa2destinations", "efa2boatdamages")
    updated = 0
    for table in tables:
        rows = plan.get(table) or []
        for i in range(0, len(rows), batch_size):
            chunk = rows[i : i + batch_size]
            txs = [
                {
                    "type": "update",
                    "table": table,
                    "record": _second_synch_record(table, r),
                }
                for r in chunk
                if r.get("ecrid")
            ]
            if not txs:
                continue
            parsed = client.modify_many(txs)
            if not parsed.get("ok"):
                checks.fail(
                    "second synch posttx update",
                    f"{table} at {i}: {_posttx_fail_detail(parsed)}",
                )
                return
            updated += len(txs)

    after_boats = db.query_scalar("SELECT COUNT(*) FROM efa2boats")
    after_cur = db.query_scalar(
        f"SELECT COUNT(DISTINCT Id) FROM efa2boats "
        f"WHERE CAST(ValidFrom AS UNSIGNED) <= {expected['at_ms']} "
        f"AND CAST(InvalidFrom AS UNSIGNED) > {expected['at_ms']}"
    )
    if (
        before_boats == after_boats
        and before_cur == after_cur
        and int(after_cur or 0) == expected["current_boats"]
    ):
        checks.ok(
            "second synch upsert",
            f"boats={after_boats} current={after_cur} (posttx updates={updated})",
        )
        checks.ok("second synch no new rows", "0 inserts (update-only re-upload)")
    else:
        checks.fail(
            "second synch upsert",
            f"before={before_boats}/{before_cur} after={after_boats}/{after_cur}",
        )


def verify_db_counts(db: ComposeDb, expected: dict[str, Any], checks: Check) -> None:
    at = expected["at_ms"]

    cur_boats2 = db.query_scalar(
        f"SELECT COUNT(DISTINCT Id) FROM efa2boats "
        f"WHERE CAST(ValidFrom AS UNSIGNED) <= {at} "
        f"AND CAST(InvalidFrom AS UNSIGNED) > {at}"
    )
    cur = cur_boats2
    if int(cur or 0) == expected["current_boats"]:
        checks.ok("current boats", f"{cur} (not historical doubles)")
    else:
        checks.fail("current boats", f"db={cur} expected={expected['current_boats']}")

    versions = db.query_scalar("SELECT COUNT(*) FROM efa2boats")
    if int(versions or 0) == expected["boat_versions"]:
        checks.ok("boat version rows", versions)
    else:
        checks.fail("boat version rows", f"db={versions} expected={expected['boat_versions']}")

    cur_p = db.query_scalar(
        f"SELECT COUNT(DISTINCT Id) FROM efa2persons "
        f"WHERE CAST(ValidFrom AS UNSIGNED) <= {at} AND CAST(InvalidFrom AS UNSIGNED) > {at}"
    )
    if int(cur_p or 0) == expected["current_persons"]:
        checks.ok("current persons", cur_p)
    else:
        checks.fail("current persons", f"db={cur_p} expected={expected['current_persons']}")

    cur_d = db.query_scalar(
        f"SELECT COUNT(DISTINCT Id) FROM efa2destinations "
        f"WHERE CAST(ValidFrom AS UNSIGNED) <= {at} AND CAST(InvalidFrom AS UNSIGNED) > {at}"
    )
    if int(cur_d or 0) == expected["current_destinations"]:
        checks.ok("current destinations", cur_d)
    else:
        checks.fail("current destinations", f"db={cur_d} expected={expected['current_destinations']}")

    onw = db.query_scalar("SELECT COUNT(*) FROM efa2boatstatus WHERE CurrentStatus='ONTHEWATER'")
    if int(onw or 0) == expected["onwater"]:
        checks.ok("onwater boatstatus", onw)
    else:
        checks.fail("onwater boatstatus", f"db={onw} expected={expected['onwater']}")

    open_dmg = db.query_scalar(
        "SELECT COUNT(*) FROM efa2boatdamages WHERE Fixed IS NULL OR Fixed='' OR LOWER(Fixed)='false' OR Fixed='0'"
    )
    if int(open_dmg or 0) == expected["open_damages"]:
        checks.ok("open damages", f"{open_dmg} sev={expected['damage_severity_open']}")
    else:
        checks.fail("open damages", f"db={open_dmg} expected={expected['open_damages']}")

    fixed = db.query_scalar(
        "SELECT COUNT(*) FROM efa2boatdamages WHERE LOWER(Fixed)='true' OR Fixed='1'"
    )
    if int(fixed or 0) == expected["repaired_damages"]:
        checks.ok("repaired damages", fixed)
    else:
        checks.fail("repaired damages", f"db={fixed} expected={expected['repaired_damages']}")

    year = expected["logbook_year"]
    lb = db.query_scalar(
        f"SELECT COUNT(*) FROM efa2logbook WHERE Logbookname={ComposeDb.sql_literal(year)}"
    )
    if int(lb or 0) == expected["logbook_rows"]:
        checks.ok(f"logbook {year} rows", lb)
    else:
        checks.fail(f"logbook {year} rows", f"db={lb} expected={expected['logbook_rows']}")

    open_t = db.query_scalar(
        f"SELECT COUNT(*) FROM efa2logbook WHERE Logbookname={ComposeDb.sql_literal(year)} "
        f"AND (Open='true' OR Open='on')"
    )
    if int(open_t or 0) == expected["open_trips"]:
        checks.ok("open trips (backup)", open_t)
    else:
        checks.fail("open trips (backup)", f"db={open_t} expected={expected['open_trips']}")

    checks.info("seat mix (backup XML)", json.dumps(expected["seat_mix"], sort_keys=True))


def start_trip_with_acks(portal: PortalClient, fixture: dict[str, str]) -> dict[str, Any]:
    payload: dict[str, Any] = {
        "boatId": fixture["boatId"],
        "boatVariant": fixture["boatVariant"],
        "crew": [{"id": fixture["personId"]}],
        "destinationId": fixture["destinationId"],
        "idempotencyKey": str(uuid.uuid4()),
        "acknowledgmentTokens": [],
    }
    for _ in range(5):
        code, data = portal.start_trip(payload)
        err = (data or {}).get("error") or {}
        if code in (200, 201) and not err:
            return data
        if err.get("code") == "ACK_REQUIRED":
            tokens = list(payload.get("acknowledgmentTokens") or [])
            for check in (err.get("details") or {}).get("checks") or []:
                kind = check.get("kind")
                if kind:
                    tokens.append(portal.ack(kind, fixture["boatId"]))
            payload["acknowledgmentTokens"] = tokens
            continue
        if err.get("code") == "ACK_STALE":
            payload["acknowledgmentTokens"] = []
            continue
        raise RuntimeError(f"start trip failed {code}: {data}")
    raise RuntimeError("start trip exhausted ack retries")


def portal_and_cross_client(
    db: ComposeDb,
    base_url: str,
    fixture: dict[str, str],
    admin_id: int,
    admin_password: str,
    checks: Check,
) -> None:
    if os.environ.get("EFACLOUD_PORTAL_FIXTURES", "") in ("1", "true", "TRUE"):
        checks.fail("fixtures disabled", "EFACLOUD_PORTAL_FIXTURES is set — aborting")
        return
    checks.ok("fixtures disabled", "EFACLOUD_PORTAL_FIXTURES unset")

    portal = PortalClient(base_url)
    portal.login(str(PORTAL_ID), REHEARSAL_PASSWORD)
    checks.ok("portal login", f"user {PORTAL_ID} trainer")

    boats = portal.boats(view="available", seatCategory="1")
    boat_list = boats.get("boats") or []
    if not boat_list:
        checks.fail("portal list boats", "no available singles")
        return
    names = {b.get("boatId") or b.get("id") for b in boat_list}
    if fixture["boatId"] in names or any(
        (b.get("boatId") == fixture["boatId"] or b.get("id") == fixture["boatId"]) for b in boat_list
    ):
        checks.ok("portal list boats", f"available singles={len(boat_list)} includes fixture")
    else:
        checks.info("portal list boats", f"available singles={len(boat_list)}; fixture may be filtered")

    portal.boat(fixture["boatId"])
    checks.ok("portal boat detail", fixture["boatName"] or fixture["boatId"])

    trip = start_trip_with_acks(portal, fixture)
    trip_obj = trip.get("trip") if isinstance(trip.get("trip"), dict) else trip
    entry_id = str(trip_obj.get("entryId") or trip_obj.get("EntryId") or "")
    cc = trip_obj.get("changeCount")
    if not entry_id:
        entry_id = db.query_scalar(
            "SELECT EntryId FROM efa2logbook WHERE Open='true' ORDER BY CAST(EntryId AS UNSIGNED) DESC LIMIT 1"
        )
        cc = db.query_scalar(
            f"SELECT ChangeCount FROM efa2logbook WHERE EntryId={ComposeDb.sql_literal(entry_id)} "
            f"AND Open='true' LIMIT 1"
        )
    checks.ok("portal start trip", f"EntryId={entry_id} ChangeCount={cc}")

    st = db.query_rows(
        f"SELECT CurrentStatus, EntryNo, ChangeCount FROM efa2boatstatus "
        f"WHERE BoatId={ComposeDb.sql_literal(fixture['boatId'])}"
    )
    if not st or st[0][0] != "ONTHEWATER" or st[0][1] != str(entry_id):
        checks.fail("boatstatus linkage after start", str(st))
    else:
        checks.ok("boatstatus linkage after start", f"ONTHEWATER EntryNo={entry_id} CC={st[0][2]}")
    status_cc = int(st[0][2]) if st else 0

    posttx_users = [(BTHS_ID, REHEARSAL_PASSWORD), (admin_id, admin_password)]
    posttx_ok = False
    posttx_raw = ""
    status_ecrid = db.query_scalar(
        f"SELECT ecrid FROM efa2boatstatus WHERE BoatId={ComposeDb.sql_literal(fixture['boatId'])}"
    )
    for uid, pwd in posttx_users:
        client = PosttxClient(base_url, uid, pwd)
        try:
            nop = client.nop_session()
            if "Authentication failed" in nop or ";403;" in nop or nop.startswith("403"):
                continue
            record = {
                "ecrid": status_ecrid,
                "BoatId": fixture["boatId"],
                "Comment": "rehearsal-desktop-overlap",
                "CurrentStatus": "ONTHEWATER",
                "EntryNo": str(entry_id),
                "ChangeCount": str(status_cc),
            }
            posttx_raw_enc = client.modify(type_="update", table="efa2boatstatus", record=record)
            parsed = parse_container_response(posttx_raw_enc)
            if parsed.get("ok"):
                posttx_ok = True
                checks.ok("posttx bths/admin auth+update", f"user={uid} tx=300")
                break
            posttx_raw = parsed.get("decoded") or posttx_raw_enc
        except Exception as e:  # noqa: BLE001
            posttx_raw = str(e)
            continue
    if not posttx_ok:
        checks.fail("posttx update while trip open", posttx_raw[:400])

    st2 = db.query_rows(
        f"SELECT CurrentStatus, EntryNo, Comment, ChangeCount FROM efa2boatstatus "
        f"WHERE BoatId={ComposeDb.sql_literal(fixture['boatId'])}"
    )
    if (
        st2
        and st2[0][0] == "ONTHEWATER"
        and st2[0][1] == str(entry_id)
        and "rehearsal-desktop-overlap" in (st2[0][2] or "")
    ):
        checks.ok(
            "cross-client status+logbook consistent",
            f"still ONTHEWATER EntryNo={entry_id} comment={st2[0][2]!r}",
        )
    else:
        checks.fail("cross-client status+logbook consistent", str(st2))

    trip_cc = int(
        db.query_scalar(
            f"SELECT ChangeCount FROM efa2logbook WHERE EntryId={ComposeDb.sql_literal(entry_id)} "
            f"AND Open='true' LIMIT 1"
        )
        or "0"
    )
    code_stale, data_stale = portal.finish_trip(
        entry_id,
        {
            "expectedChangeCount": 0 if trip_cc != 0 else -1,
            "endTime": "12:00:00",
            "distance": fixture.get("distance") or "5 km",
        },
    )
    err_code = ((data_stale or {}).get("error") or {}).get("code")
    if code_stale == 409 and err_code in ("STALE_STATE", "CHANGECOUNT_REQUIRED"):
        checks.ok("portal stale ChangeCount rejected", f"{code_stale} {err_code}")
    else:
        checks.fail("portal stale ChangeCount rejected", f"{code_stale} {data_stale}")

    code_fin, data_fin = portal.finish_trip(
        entry_id,
        {
            "expectedChangeCount": trip_cc,
            "distance": fixture.get("distance") or "5 km",
        },
    )
    if code_fin in (200, 201):
        checks.ok("portal finish trip", f"EntryId={entry_id}")
    else:
        checks.fail("portal finish trip", f"{code_fin} {data_fin}")
        return

    st3 = db.query_scalar(
        f"SELECT CurrentStatus FROM efa2boatstatus WHERE BoatId={ComposeDb.sql_literal(fixture['boatId'])}"
    )
    open_left = db.query_scalar(
        f"SELECT COUNT(*) FROM efa2logbook WHERE EntryId={ComposeDb.sql_literal(entry_id)} AND Open='true'"
    )
    if st3 != "ONTHEWATER" and open_left in ("", "0"):
        checks.ok("after finish status+logbook", f"status={st3} open={open_left}")
    else:
        checks.fail("after finish status+logbook", f"status={st3} open={open_left}")

    trip2 = start_trip_with_acks(portal, fixture)
    trip2_obj = trip2.get("trip") if isinstance(trip2.get("trip"), dict) else trip2
    entry2 = str(trip2_obj.get("entryId") or trip2_obj.get("EntryId") or "")
    if not entry2:
        entry2 = db.query_scalar(
            "SELECT EntryId FROM efa2logbook WHERE Open='true' ORDER BY CAST(EntryId AS UNSIGNED) DESC LIMIT 1"
        )
    cc2 = int(
        db.query_scalar(
            f"SELECT ChangeCount FROM efa2logbook WHERE EntryId={ComposeDb.sql_literal(entry2)} AND Open='true'"
        )
        or "1"
    )
    code_ab, data_ab = portal.abort_trip(entry2, {"expectedChangeCount": cc2})
    if code_ab in (200, 201):
        checks.ok("portal abort trip", f"EntryId={entry2}")
    else:
        checks.fail("portal abort trip", f"{code_ab} {data_ab}")

    code_dmg, data_dmg = portal.report_damage(
        fixture["boatId"],
        {
            "severity": "FULLYUSEABLE",
            "description": "rehearsal-damage-report",
        },
    )
    if code_dmg in (200, 201):
        aborted = (data_dmg or {}).get("tripAborted")
        checks.ok("portal report damage", f"tripAborted={aborted}")
    else:
        checks.fail("portal report damage", f"{code_dmg} {data_dmg}")


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--backup-zip", required=True, type=Path)
    ap.add_argument("--extract-dir", type=Path, default=None)
    ap.add_argument("--base-url", default=os.environ.get("REHEARSAL_BASE_URL", "http://127.0.0.1:18081"))
    ap.add_argument("--compose-project", default=os.environ.get("COMPOSE_PROJECT_NAME", "efacloud-rehearsal"))
    ap.add_argument("--skip-load", action="store_true", help="Verify/exercise only (DB already loaded)")
    args = ap.parse_args()

    env = load_env_file(ROOT / ".env")
    for k, v in env.items():
        os.environ.setdefault(k, v)

    if os.environ.get("EFACLOUD_PORTAL_FIXTURES") in ("1", "true", "TRUE"):
        print("REFUSE: EFACLOUD_PORTAL_FIXTURES must not be enabled for this rehearsal.", file=sys.stderr)
        return 2

    checks = Check()
    print("== backup activation rehearsal ==")
    print(f"base_url={args.base_url} project={args.compose_project}")

    extract = locate_extract_or_unzip(args.backup_zip, args.extract_dir)
    data_dir = find_project_data_dir(extract)
    at_ms = backup_timestamp_ms(extract)
    expected = fleet_expected_counts(data_dir, at_ms)
    fixture = pick_trip_fixture(data_dir, at_ms)
    checks.info("backup extract", str(data_dir))
    checks.info(
        "expected fleet",
        json.dumps(
            {
                k: expected[k]
                for k in (
                    "current_boats",
                    "current_persons",
                    "current_destinations",
                    "open_trips",
                    "onwater",
                    "open_damages",
                    "repaired_damages",
                    "logbook_rows",
                )
            }
        ),
    )
    checks.info(
        "trip fixture",
        f"{fixture['boatName']} / {fixture['personName']} / {fixture['destinationName']}",
    )

    db = ComposeDb(
        ROOT,
        project=args.compose_project,
        db_name=env.get("EFACLOUD_DB_NAME", "efacloud"),
        db_user=env.get("EFACLOUD_DB_USER", "efacloud"),
        db_password=env.get("EFACLOUD_DB_PASSWORD", ""),
    )

    plan = collect_load_plan(data_dir, logbook_year=expected["logbook_year"])
    posttx = PosttxClient(args.base_url, BTHS_ID, REHEARSAL_PASSWORD)

    if not args.skip_load:
        print("\n-- bootstrap accounts (SQL) + clear club tables --")
        ensure_users(db, fixture["personId"], checks)
        clear_club_tables(db)
        checks.ok("clear club tables", ",".join(CLEAR_TABLES))

        print("\n-- load via posttx (efa_record API v3) --")
        load_backup_via_posttx(posttx, plan, checks)
    else:
        checks.info("load skipped", "--skip-load")

    if checks.failed:
        print("\n== summary ==")
        failed = [i for i in checks.items if i["status"] == "FAIL"]
        passed = [i for i in checks.items if i["status"] == "PASS"]
        print(f"PASS={len(passed)} FAIL={len(failed)}")
        for f in failed:
            print(f"  - {f['name']}: {f['detail']}")
        print("OVERALL: FAIL")
        return 1

    print("\n-- verify fleet counts --")
    verify_db_counts(db, expected, checks)

    print("\n-- second synch (posttx update re-upload) --")
    second_synch_via_posttx(posttx, db, plan, expected, checks)

    print("\n-- portal + cross-client --")
    admin_id = int(env.get("EFACLOUD_ADMIN_ID", "1142"))
    admin_password = env.get("EFACLOUD_ADMIN_PASSWORD", "")
    portal_and_cross_client(db, args.base_url, fixture, admin_id, admin_password, checks)

    print("\n== summary ==")
    failed = [i for i in checks.items if i["status"] == "FAIL"]
    passed = [i for i in checks.items if i["status"] == "PASS"]
    print(f"PASS={len(passed)} FAIL={len(failed)} INFO={len(checks.items) - len(passed) - len(failed)}")
    if failed:
        print("FAILED CHECKS:")
        for f in failed:
            print(f"  - {f['name']}: {f['detail']}")
        print("OVERALL: FAIL")
        print(
            "Gaps remain: real EFA desktop SynchControl GUI, CapRover staging/prod, "
            "device QA — see docs/deploy/backup-activation-rehearsal.md"
        )
        return 1
    print("OVERALL: PASS")
    print(
        "Proven locally on compose MariaDB via posttx/efa_record. "
        "Still needed: real desktop SynchControl + CapRover."
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
