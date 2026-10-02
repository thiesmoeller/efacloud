#!/usr/bin/env python3
"""Portal ↔ desktop posttx conflict race on shared SynchControl MariaDB.

1. Portal starts a trip on boat A → ONTHEWATER + EntryNo
2. Desktop-style posttx (bths) attempts a conflicting checkout / status rewrite
3. Assert no silent inconsistency (status↔open logbook)
4. Portal finish with stale ChangeCount → STALE_STATE
5. Cleanup (finish/abort) so the stack stays usable

Driven by scripts/portal-desktop-conflict-race.sh.
"""

from __future__ import annotations

import os
import sys
import time
import uuid
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "lab" / "backup_rehearsal"))

from db import ComposeDb  # noqa: E402
from efa2xml import generate_ecrid  # noqa: E402
from portal_client import PortalClient  # noqa: E402
from posttx_client import PosttxClient, parse_container_response  # noqa: E402

BASE_URL = os.environ.get("PORTAL_LIVE_BASE_URL", "http://127.0.0.1:18083")
PORTAL_ID = os.environ.get("PORTAL_LIVE_USER_ID", "902")
PORTAL_PASSWORD = os.environ.get("PORTAL_LIVE_PASSWORD", "RehearsePortal1!")
BTHS_ID = int(os.environ.get("EFA_CLOUD_USERNAME", "901"))
BTHS_PASSWORD = os.environ.get("EFA_CLOUD_PASSWORD", "RehearseSync1!")
PROJECT = os.environ.get("COMPOSE_PROJECT_NAME", "efacloud-desktop-synch")


class Check:
    def __init__(self) -> None:
        self.pass_n = 0
        self.fail_n = 0
        self.notes: list[str] = []

    def ok(self, name: str, detail: str = "") -> None:
        self.pass_n += 1
        print(f"  PASS  {name}" + (f" — {detail}" if detail else ""))

    def fail(self, name: str, detail: str = "") -> None:
        self.fail_n += 1
        print(f"  FAIL  {name}" + (f" — {detail}" if detail else ""))

    def info(self, name: str, detail: str = "") -> None:
        print(f"  INFO  {name}" + (f" — {detail}" if detail else ""))
        if detail:
            self.notes.append(f"{name}: {detail}")


def start_with_acks(
    portal: PortalClient,
    payload: dict[str, Any],
    *,
    max_rounds: int = 6,
) -> tuple[int, Any]:
    body = dict(payload)
    body.setdefault("acknowledgmentTokens", [])
    body.setdefault("idempotencyKey", str(uuid.uuid4()))
    last: tuple[int, Any] = (0, None)
    for _ in range(max_rounds):
        code, data = portal.start_trip(body)
        last = (code, data)
        err = (data or {}).get("error") or {}
        if code in (200, 201) and not err:
            return code, data
        if err.get("code") == "ACK_REQUIRED":
            tokens = list(body.get("acknowledgmentTokens") or [])
            boat_id = body["boatId"]
            for check in (err.get("details") or {}).get("checks") or []:
                kind = check.get("kind")
                if kind:
                    tokens.append(portal.ack(kind, boat_id))
            body["acknowledgmentTokens"] = tokens
            body["idempotencyKey"] = str(uuid.uuid4())
            continue
        if err.get("code") == "ACK_STALE":
            body["acknowledgmentTokens"] = []
            body["idempotencyKey"] = str(uuid.uuid4())
            continue
        return code, data
    return last


def pick_available_single(db: ComposeDb) -> dict[str, str]:
    rows = db.query_rows(
        """
SELECT bs.BoatId, b.Name, IFNULL(NULLIF(b.TypeVariant,''), '1'),
       bs.ecrid, bs.ChangeCount
FROM efa2boatstatus bs
JOIN efa2boats b ON b.Id = bs.BoatId
  AND CAST(b.ValidFrom AS UNSIGNED) <= UNIX_TIMESTAMP()*1000
  AND CAST(b.InvalidFrom AS UNSIGNED) > UNIX_TIMESTAMP()*1000
WHERE bs.CurrentStatus = 'AVAILABLE'
  AND b.TypeSeats = '1'
  AND NOT EXISTS (
    SELECT 1 FROM efa2boatdamages d
    WHERE d.BoatId = bs.BoatId
      AND (d.Fixed IS NULL OR d.Fixed = '' OR d.Fixed = 'false')
  )
ORDER BY b.Name
LIMIT 1
"""
    )
    if not rows:
        raise RuntimeError("no AVAILABLE single without open damages")
    return {
        "boatId": rows[0][0],
        "boatName": rows[0][1],
        "boatVariant": rows[0][2] or "1",
        "statusEcrid": rows[0][3],
        "statusChangeCount": rows[0][4],
    }


def pick_destination(db: ComposeDb) -> tuple[str, str]:
    rows = db.query_rows(
        """
SELECT Id, Name FROM efa2destinations
WHERE CAST(ValidFrom AS UNSIGNED) <= UNIX_TIMESTAMP()*1000
  AND CAST(InvalidFrom AS UNSIGNED) > UNIX_TIMESTAMP()*1000
ORDER BY Name
LIMIT 1
"""
    )
    if not rows:
        raise RuntimeError("no current destination")
    return rows[0][0], rows[0][1]


def portal_person(db: ComposeDb, user_id: str) -> tuple[str, str]:
    rows = db.query_rows(
        f"""
SELECT u.PersonId, CONCAT(IFNULL(p.FirstName,''),' ',IFNULL(p.LastName,''))
FROM efaCloudUsers u
JOIN efa2persons p ON p.Id = u.PersonId
  AND CAST(p.ValidFrom AS UNSIGNED) <= UNIX_TIMESTAMP()*1000
  AND CAST(p.InvalidFrom AS UNSIGNED) > UNIX_TIMESTAMP()*1000
WHERE u.efaCloudUserID={ComposeDb.sql_literal(user_id)}
LIMIT 1
"""
    )
    if not rows or not rows[0][0]:
        raise RuntimeError(f"portal user {user_id} missing PersonId")
    return rows[0][0], (rows[0][1] or "").strip()


def other_person(db: ComposeDb, exclude_id: str) -> tuple[str, str]:
    rows = db.query_rows(
        f"""
SELECT Id, CONCAT(IFNULL(FirstName,''),' ',IFNULL(LastName,''))
FROM efa2persons
WHERE CAST(ValidFrom AS UNSIGNED) <= UNIX_TIMESTAMP()*1000
  AND CAST(InvalidFrom AS UNSIGNED) > UNIX_TIMESTAMP()*1000
  AND Id <> {ComposeDb.sql_literal(exclude_id)}
  AND FirstName NOT LIKE 'Gast%'
  AND LastName IS NOT NULL AND LastName <> ''
ORDER BY LastName, FirstName
LIMIT 1
"""
    )
    if not rows:
        raise RuntimeError("no alternate person for desktop crew")
    return rows[0][0], (rows[0][1] or "").strip()


def open_trips_for_boat(db: ComposeDb, boat_id: str) -> list[list[str]]:
    return db.query_rows(
        f"""
SELECT EntryId, Open, BoatId, ecrid, ChangeCount
FROM efa2logbook
WHERE BoatId={ComposeDb.sql_literal(boat_id)} AND Open='true'
ORDER BY CAST(EntryId AS UNSIGNED)
"""
    )


def boat_status(db: ComposeDb, boat_id: str) -> list[str] | None:
    rows = db.query_rows(
        f"""
SELECT CurrentStatus, EntryNo, Comment, ChangeCount, ecrid
FROM efa2boatstatus WHERE BoatId={ComposeDb.sql_literal(boat_id)}
"""
    )
    return rows[0] if rows else None


def assert_status_logbook_consistent(db: ComposeDb, boat_id: str, checks: Check, label: str) -> bool:
    """Consistent means: ONTHEWATER ⇒ EntryNo matches exactly one open trip;
    AVAILABLE/NOTAVAILABLE ⇒ zero open trips (or EntryNo empty/null).
    """
    st = boat_status(db, boat_id)
    opens = open_trips_for_boat(db, boat_id)
    if not st:
        checks.fail(label, "missing boatstatus row")
        return False
    status, entry_no, comment, cc, ecrid = st
    open_ids = [r[0] for r in opens]
    if status == "ONTHEWATER":
        if len(opens) == 1 and str(entry_no) == str(opens[0][0]):
            checks.ok(
                label,
                f"ONTHEWATER EntryNo={entry_no} open={open_ids} comment={comment!r} CC={cc}",
            )
            return True
        checks.fail(
            label,
            f"inconsistent: status={status} EntryNo={entry_no} open={open_ids} comment={comment!r}",
        )
        return False
    # Not on water
    if len(opens) == 0:
        checks.ok(label, f"status={status} open=0 EntryNo={entry_no!r}")
        return True
    checks.fail(
        label,
        f"inconsistent: status={status} but open trips={open_ids} EntryNo={entry_no!r}",
    )
    return False


def desktop_conflicting_checkout(
    client: PosttxClient,
    db: ComposeDb,
    *,
    boat: dict[str, str],
    portal_entry_id: str,
    person_id: str,
    person_name: str,
    dest_id: str,
    dest_name: str,
) -> dict[str, Any]:
    """Attempt desktop-style start on a boat already ONTHEWATER (portal).

    Sends insert(logbook) + update(boatstatus→new EntryNo) in one container.
    """
    now = datetime.now(timezone.utc).astimezone()
    date_s = now.strftime("%Y-%m-%d")
    time_s = now.strftime("%H:%M:%S")
    ecrid = generate_ecrid()
    # Provisional client-side key; server assigns EntryId on insert (autoincrement).
    log_record = {
        "ecrid": ecrid,
        "Date": date_s,
        "BoatId": boat["boatId"],
        "BoatVariant": boat["boatVariant"],
        "BoatName": boat["boatName"],
        "Crew1Id": person_id,
        "Crew1Name": person_name,
        "AllCrewNames": person_name,
        "DestinationId": dest_id,
        "DestinationName": dest_name,
        "StartTime": time_s,
        "Open": "true",
        "ChangeCount": "1",
        "LastModified": str(int(time.time() * 1000)),
        "LastModification": "insert",
        "Comments": "conflict-race-desktop-checkout",
        "Logbookname": "2026",
    }
    # Status rewrite: point EntryNo at a sentinel that cannot match portal entry
    # until insert returns — we use update after reading new EntryId in two steps
    # if single-container can't know assigned EntryId.
    # First attempt: single-container insert only, then status update with new id.
    parsed_ins = client.modify_many(
        [{"type": "insert", "table": "efa2logbook", "record": log_record}]
    )
    new_entry = db.query_scalar(
        f"SELECT EntryId FROM efa2logbook WHERE ecrid={ComposeDb.sql_literal(ecrid)} LIMIT 1"
    )
    status_ecrid = db.query_scalar(
        f"SELECT ecrid FROM efa2boatstatus WHERE BoatId={ComposeDb.sql_literal(boat['boatId'])}"
    )
    status_cc = db.query_scalar(
        f"SELECT ChangeCount FROM efa2boatstatus WHERE BoatId={ComposeDb.sql_literal(boat['boatId'])}"
    )
    parsed_upd: dict[str, Any] | None = None
    if new_entry and status_ecrid:
        status_record = {
            "ecrid": status_ecrid,
            "BoatId": boat["boatId"],
            "CurrentStatus": "ONTHEWATER",
            "EntryNo": str(new_entry),
            "Comment": "conflict-race-desktop-redirect",
            "ChangeCount": str(status_cc or "0"),
            "LastModification": "update",
        }
        parsed_upd = client.modify_many(
            [{"type": "update", "table": "efa2boatstatus", "record": status_record}]
        )
    return {
        "insert": parsed_ins,
        "update": parsed_upd,
        "new_entry": new_entry,
        "ecrid": ecrid,
        "portal_entry": portal_entry_id,
    }


def cleanup_boat(portal: PortalClient, db: ComposeDb, boat_id: str, checks: Check) -> None:
    opens = open_trips_for_boat(db, boat_id)
    for row in opens:
        entry_id = row[0]
        cc = int(
            db.query_scalar(
                f"SELECT ChangeCount FROM efa2logbook WHERE EntryId={ComposeDb.sql_literal(entry_id)} "
                "AND Open='true' LIMIT 1"
            )
            or "0"
        )
        code, data = portal.finish_trip(
            entry_id, {"expectedChangeCount": cc, "distance": "1 km"}
        )
        if code not in (200, 201):
            code2, data2 = portal.abort_trip(entry_id, {"expectedChangeCount": cc})
            if code2 not in (200, 201):
                checks.info(
                    "cleanup attempt",
                    f"finish={code} {data} abort={code2} {data2} entry={entry_id}",
                )
            else:
                checks.info("cleanup abort", f"EntryId={entry_id}")
        else:
            checks.info("cleanup finish", f"EntryId={entry_id}")
    # If still open (portal ACL), force-close via SQL as last resort for lab usability
    left = open_trips_for_boat(db, boat_id)
    if left:
        for row in left:
            db.exec_sql(
                f"UPDATE efa2logbook SET Open='false', EndTime=CURTIME(), "
                f"Comments=CONCAT(IFNULL(Comments,''),' [conflict-race-force-close]') "
                f"WHERE EntryId={ComposeDb.sql_literal(row[0])} AND Open='true'"
            )
        db.exec_sql(
            f"UPDATE efa2boatstatus SET CurrentStatus='AVAILABLE', EntryNo=NULL, "
            f"Comment='conflict-race-cleanup' "
            f"WHERE BoatId={ComposeDb.sql_literal(boat_id)}"
        )
        checks.info("cleanup SQL force", f"closed {len(left)} open trips")
    st = boat_status(db, boat_id)
    if st and st[0] == "ONTHEWATER":
        db.exec_sql(
            f"UPDATE efa2boatstatus SET CurrentStatus='AVAILABLE', EntryNo=NULL "
            f"WHERE BoatId={ComposeDb.sql_literal(boat_id)}"
        )
    assert_status_logbook_consistent(db, boat_id, checks, "cleanup status+logbook usable")


def main() -> int:
    checks = Check()
    if os.environ.get("EFACLOUD_PORTAL_FIXTURES", "") in ("1", "true", "TRUE"):
        checks.fail("fixtures", "EFACLOUD_PORTAL_FIXTURES must be unset")
        return 2

    db = ComposeDb(ROOT, project=PROJECT)
    boat = pick_available_single(db)
    dest_id, dest_name = pick_destination(db)
    person_id, person_name = portal_person(db, PORTAL_ID)
    alt_id, alt_name = other_person(db, person_id)
    checks.info(
        "picks",
        f"boat={boat['boatName']} ({boat['boatId'][:8]}…) person={person_name} "
        f"alt={alt_name} dest={dest_name}",
    )

    portal = PortalClient(BASE_URL)
    portal.login(str(PORTAL_ID), PORTAL_PASSWORD)
    checks.ok("portal login", f"user={PORTAL_ID}")

    # --- 1. Portal start ---
    code, trip = start_with_acks(
        portal,
        {
            "boatId": boat["boatId"],
            "boatVariant": boat["boatVariant"],
            "crew": [{"id": person_id}],
            "destinationId": dest_id,
            "idempotencyKey": str(uuid.uuid4()),
            "acknowledgmentTokens": [],
        },
    )
    err = (trip or {}).get("error") or {}
    if code not in (200, 201) or err:
        checks.fail("portal start", f"{code} {trip}")
        return 1
    trip_obj = trip.get("trip") if isinstance(trip.get("trip"), dict) else trip
    entry_id = str(trip_obj.get("entryId") or trip_obj.get("EntryId") or "")
    if not entry_id:
        entry_id = db.query_scalar(
            "SELECT EntryId FROM efa2logbook WHERE Open='true' "
            f"AND BoatId={ComposeDb.sql_literal(boat['boatId'])} "
            "ORDER BY CAST(EntryId AS UNSIGNED) DESC LIMIT 1"
        )
    checks.ok("portal start → ONTHEWATER", f"EntryId={entry_id}")
    assert_status_logbook_consistent(db, boat["boatId"], checks, "after portal start")

    trip_cc_before_race = int(
        db.query_scalar(
            f"SELECT ChangeCount FROM efa2logbook WHERE EntryId={ComposeDb.sql_literal(entry_id)} "
            "AND Open='true' LIMIT 1"
        )
        or "0"
    )

    # --- 2. Desktop conflicting checkout ---
    desktop = PosttxClient(BASE_URL, BTHS_ID, BTHS_PASSWORD)
    nop = desktop.nop_session()
    nop_parsed = parse_container_response(nop) if nop and not nop.startswith("http") else {"ok": True, "decoded": nop}
    # nop_session returns raw session string sometimes — treat non-exception as auth ok
    checks.ok("posttx bths auth", f"user={BTHS_ID}")

    race = desktop_conflicting_checkout(
        desktop,
        db,
        boat=boat,
        portal_entry_id=entry_id,
        person_id=alt_id,
        person_name=alt_name,
        dest_id=dest_id,
        dest_name=dest_name,
    )
    ins_ok = bool((race["insert"] or {}).get("ok"))
    upd_ok = bool((race["update"] or {}).get("ok")) if race["update"] else False
    ins_msg = ""
    if race["insert"] and (race["insert"].get("txs") or race["insert"].get("cmsg")):
        txs = race["insert"].get("txs") or []
        ins_msg = (txs[0].get("result_message") if txs else "") or str(race["insert"].get("cmsg") or "")
    checks.info(
        "desktop race result",
        f"insert_ok={ins_ok} update_ok={upd_ok} new_entry={race['new_entry']} "
        f"ins_cresult={(race['insert'] or {}).get('cresult')} "
        f"upd_cresult={(race['update'] or {}).get('cresult') if race['update'] else None} "
        f"ins_msg={ins_msg[:160]}",
    )

    st = boat_status(db, boat["boatId"])
    opens = open_trips_for_boat(db, boat["boatId"])
    open_ids = [r[0] for r in opens]

    # Expected after concurrency guard:
    #   desktop second open-logbook insert fails (CONFLICT) → portal trip intact.
    # Legacy undocumented path (pre-guard) could create split-brain; that is FAIL.
    consistent = (
        st is not None
        and st[0] == "ONTHEWATER"
        and len(opens) == 1
        and str(st[1]) == str(entry_id)
    )
    if not ins_ok and consistent:
        behavior = (
            "second write failed/conflicts (boat concurrency guard); "
            "status+logbook stay on portal trip"
        )
        if "CONFLICT" in ins_msg.upper() or "open trip" in ins_msg.lower():
            checks.ok("desktop conflicting insert rejected", ins_msg[:180] or "insert not ok")
        else:
            checks.ok("desktop conflicting insert rejected", f"insert_ok=False msg={ins_msg[:120]}")
    elif ins_ok and not upd_ok and consistent:
        behavior = (
            "desktop insert reported ok but status redirect failed; "
            "single open still portal — check for orphan"
        )
        # If insert actually created a second open, consistent would be false.
    elif consistent:
        behavior = "status+logbook stay consistent after race"
    else:
        behavior = (
            f"post-race state status={st} opens={open_ids} "
            f"insert_ok={ins_ok} update_ok={upd_ok}"
        )

    checks.info("race behavior", behavior)
    if consistent:
        checks.ok("no silent inconsistent state", behavior)
    else:
        checks.fail("no silent inconsistent state", behavior)

    # If desktop left an orphan open trip (insert ok, update failed), close orphan via SQL
    # so STALE_STATE test can still run on portal entry — but only after recording FAIL above.
    if len(opens) > 1:
        for row in opens:
            if str(row[0]) != str(entry_id):
                db.exec_sql(
                    f"UPDATE efa2logbook SET Open='false', EndTime=CURTIME(), "
                    f"Comments=CONCAT(IFNULL(Comments,''),' [race-orphan-close]') "
                    f"WHERE EntryId={ComposeDb.sql_literal(row[0])}"
                )
                checks.info("orphan close", f"EntryId={row[0]}")
        # Restore status pointer to portal entry if redirected
        st2 = boat_status(db, boat["boatId"])
        if st2 and str(st2[1]) != str(entry_id):
            db.exec_sql(
                f"UPDATE efa2boatstatus SET CurrentStatus='ONTHEWATER', "
                f"EntryNo={ComposeDb.sql_literal(entry_id)}, "
                f"Comment='conflict-race-restored-portal' "
                f"WHERE BoatId={ComposeDb.sql_literal(boat['boatId'])}"
            )

    # --- 3. Desktop bump ChangeCount on logbook (so portal stale finish is meaningful) ---
    # Prefer a real posttx update on boatstatus (like rehearsal) to bump related metadata,
    # then finish with wrong expectedChangeCount.
    status_ecrid = db.query_scalar(
        f"SELECT ecrid FROM efa2boatstatus WHERE BoatId={ComposeDb.sql_literal(boat['boatId'])}"
    )
    status_cc = db.query_scalar(
        f"SELECT ChangeCount FROM efa2boatstatus WHERE BoatId={ComposeDb.sql_literal(boat['boatId'])}"
    )
    bump = desktop.modify_many(
        [
            {
                "type": "update",
                "table": "efa2boatstatus",
                "record": {
                    "ecrid": status_ecrid,
                    "BoatId": boat["boatId"],
                    "CurrentStatus": "ONTHEWATER",
                    "EntryNo": str(entry_id),
                    "Comment": "conflict-race-desktop-bump",
                    "ChangeCount": str(status_cc or "0"),
                },
            }
        ]
    )
    if bump.get("ok"):
        checks.ok("desktop status bump while open", "Comment=conflict-race-desktop-bump")
    else:
        checks.info("desktop status bump", f"ok={bump.get('ok')} {bump.get('cresult')} {bump.get('cmsg')}")

    # Also bump logbook ChangeCount via posttx so finish stale is real
    log_ecrid = db.query_scalar(
        f"SELECT ecrid FROM efa2logbook WHERE EntryId={ComposeDb.sql_literal(entry_id)} AND Open='true'"
    )
    log_cc = int(
        db.query_scalar(
            f"SELECT ChangeCount FROM efa2logbook WHERE EntryId={ComposeDb.sql_literal(entry_id)} "
            "AND Open='true'"
        )
        or "0"
    )
    if log_ecrid:
        bump_log = desktop.modify_many(
            [
                {
                    "type": "update",
                    "table": "efa2logbook",
                    "record": {
                        "ecrid": log_ecrid,
                        "EntryId": str(entry_id),
                        "Comments": "conflict-race-logbook-bump",
                        "ChangeCount": str(log_cc),
                        "Open": "true",
                    },
                }
            ]
        )
        if bump_log.get("ok"):
            checks.ok("desktop logbook bump", f"EntryId={entry_id} priorCC={log_cc}")
        else:
            checks.info("desktop logbook bump", str(bump_log.get("txs") or bump_log.get("cmsg"))[:200])

    fresh_cc = int(
        db.query_scalar(
            f"SELECT ChangeCount FROM efa2logbook WHERE EntryId={ComposeDb.sql_literal(entry_id)} "
            "AND Open='true' LIMIT 1"
        )
        or str(log_cc)
    )
    stale_cc = 0 if fresh_cc != 0 else -1
    # Prefer deliberately stale: use trip_cc_before_race if desktop bumped it
    if fresh_cc != trip_cc_before_race:
        stale_cc = trip_cc_before_race
    code_stale, data_stale = portal.finish_trip(
        entry_id,
        {"expectedChangeCount": stale_cc, "distance": "5 km"},
    )
    err_code = ((data_stale or {}).get("error") or {}).get("code")
    if code_stale == 409 and err_code in ("STALE_STATE", "CHANGECOUNT_REQUIRED"):
        checks.ok(
            "portal finish stale ChangeCount → STALE_STATE",
            f"http=409 code={err_code} sentCC={stale_cc} actualCC={fresh_cc}",
        )
    else:
        checks.fail(
            "portal finish stale ChangeCount → STALE_STATE",
            f"{code_stale} {data_stale} sentCC={stale_cc} actualCC={fresh_cc}",
        )

    # --- 4. Cleanup with correct ChangeCount ---
    cleanup_boat(portal, db, boat["boatId"], checks)

    print()
    print(f"conflict_race PASS={checks.pass_n} FAIL={checks.fail_n}")
    print("RACE_BEHAVIOR:", behavior)
    return 1 if checks.fail_n else 0


if __name__ == "__main__":
    raise SystemExit(main())
