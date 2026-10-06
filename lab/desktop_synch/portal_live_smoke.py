#!/usr/bin/env python3
"""Portal live smoke against SynchControl-synced MariaDB (no fixtures).

Driven by scripts/portal-live-synch-smoke.sh. Uses lab/backup_rehearsal PortalClient
+ ComposeDb. Idempotent where practical: finishes any open trip it starts; uses a
clean AVAILABLE single for lifecycle; uses a NOTAVAILABLE boat only for ACK probe
(does not leave it on water).
"""

from __future__ import annotations

import json
import os
import sys
import uuid
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "lab" / "backup_rehearsal"))

from db import ComposeDb  # noqa: E402
from portal_client import PortalClient  # noqa: E402

BASE_URL = os.environ.get("PORTAL_LIVE_BASE_URL", "http://127.0.0.1:18083")
PORTAL_ID = os.environ.get("PORTAL_LIVE_USER_ID", "902")
PASSWORD = os.environ.get("PORTAL_LIVE_PASSWORD", "RehearsePortal1!")
PROJECT = os.environ.get("COMPOSE_PROJECT_NAME", "efacloud-desktop-synch")


class Check:
    def __init__(self) -> None:
        self.pass_n = 0
        self.fail_n = 0

    def ok(self, name: str, detail: str = "") -> None:
        self.pass_n += 1
        print(f"  PASS  {name}" + (f" — {detail}" if detail else ""))

    def fail(self, name: str, detail: str = "") -> None:
        self.fail_n += 1
        print(f"  FAIL  {name}" + (f" — {detail}" if detail else ""))

    def info(self, name: str, detail: str = "") -> None:
        print(f"  INFO  {name}" + (f" — {detail}" if detail else ""))


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
SELECT bs.BoatId, b.Name, IFNULL(b.TypeVariant, '1')
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
    return {"boatId": rows[0][0], "boatName": rows[0][1], "boatVariant": rows[0][2] or "1"}


def pick_notavailable(db: ComposeDb) -> dict[str, str]:
    rows = db.query_rows(
        """
SELECT bs.BoatId, b.Name, IFNULL(NULLIF(b.TypeVariant,''), '1'), IFNULL(b.TypeSeats,'')
FROM efa2boatstatus bs
JOIN efa2boats b ON b.Id = bs.BoatId
  AND CAST(b.ValidFrom AS UNSIGNED) <= UNIX_TIMESTAMP()*1000
  AND CAST(b.InvalidFrom AS UNSIGNED) > UNIX_TIMESTAMP()*1000
WHERE bs.CurrentStatus = 'NOTAVAILABLE'
  AND b.TypeSeats = '1'
ORDER BY b.Name
LIMIT 1
"""
    )
    if not rows:
        # Fall back to any NOTAVAILABLE (ACK still fires; start may need more crew).
        rows = db.query_rows(
            """
SELECT bs.BoatId, b.Name, IFNULL(NULLIF(b.TypeVariant,''), '1'), IFNULL(b.TypeSeats,'')
FROM efa2boatstatus bs
JOIN efa2boats b ON b.Id = bs.BoatId
  AND CAST(b.ValidFrom AS UNSIGNED) <= UNIX_TIMESTAMP()*1000
  AND CAST(b.InvalidFrom AS UNSIGNED) > UNIX_TIMESTAMP()*1000
WHERE bs.CurrentStatus = 'NOTAVAILABLE'
ORDER BY b.Name
LIMIT 1
"""
        )
    if not rows:
        raise RuntimeError("no NOTAVAILABLE boat for ACK probe")
    return {
        "boatId": rows[0][0],
        "boatName": rows[0][1],
        "boatVariant": rows[0][2] or "1",
        "typeSeats": rows[0][3] or "",
    }


def pick_destination(db: ComposeDb) -> str:
    dest = db.query_scalar(
        """
SELECT Id FROM efa2destinations
WHERE CAST(ValidFrom AS UNSIGNED) <= UNIX_TIMESTAMP()*1000
  AND CAST(InvalidFrom AS UNSIGNED) > UNIX_TIMESTAMP()*1000
ORDER BY Name
LIMIT 1
"""
    )
    if not dest:
        raise RuntimeError("no current destination")
    return dest


def portal_person_id(db: ComposeDb, user_id: str) -> str:
    pid = db.query_scalar(
        f"SELECT PersonId FROM efaCloudUsers WHERE efaCloudUserID={ComposeDb.sql_literal(user_id)}"
    )
    if not pid:
        raise RuntimeError(f"portal user {user_id} has empty PersonId")
    return pid


def boat_id_of(row: dict[str, Any]) -> str:
    return str(row.get("boatId") or row.get("id") or "")


def main() -> int:
    checks = Check()
    db = ComposeDb(ROOT, project=PROJECT)
    person_id = portal_person_id(db, PORTAL_ID)
    dest_id = pick_destination(db)
    available = pick_available_single(db)
    notavail = pick_notavailable(db)
    checks.info(
        "fixture picks",
        f"available={available['boatName']} notavail={notavail['boatName']} "
        f"person={person_id} dest={dest_id}",
    )

    portal = PortalClient(BASE_URL)
    login = portal.login(str(PORTAL_ID), PASSWORD)
    priv = login.get("privileges") or {}
    trainer = bool(priv.get("trainer") or priv.get("portalTrainer") or priv.get("isTrainer"))
    # Also accept concessions echoed on user
    user = login.get("user") or {}
    concessions = int(user.get("concessions") or user.get("Concessions") or 0)
    if concessions & 131072:
        trainer = True
    checks.ok("login", f"user={PORTAL_ID} trainer={trainer} personId={user.get('personId') or person_id}")

    # --- boat lists ---
    for view in ("available", "onwater", "unavailable"):
        data = portal.boats(view=view)
        boats = data.get("boats") or []
        checks.ok(f"GET /boats?view={view}", f"count={len(boats)}")

    seat = portal.boats(view="available", seatCategory="1")
    seat_boats = seat.get("boats") or []
    if seat_boats:
        checks.ok("GET /boats seatCategory=1", f"count={len(seat_boats)}")
    else:
        checks.fail("GET /boats seatCategory=1", "empty")

    cats = seat.get("seatCategories") or (portal.boats().get("seatCategories") if False else None)
    # seatCategories may only appear on unfiltered list
    all_boats = portal.boats()
    cats = all_boats.get("seatCategories")
    if cats:
        checks.ok("seatCategories present", json.dumps(cats)[:120])
    else:
        checks.info("seatCategories", "absent on list response (ok if client-local)")

    # --- boat detail + damages ---
    detail = portal.boat(available["boatId"])
    status_obj = detail.get("status") or {}
    cur = ""
    if isinstance(status_obj, dict):
        cur = str(status_obj.get("CurrentStatus") or status_obj.get("currentStatus") or "")
    else:
        cur = str(detail.get("currentStatus") or "")
    checks.ok(
        "boat detail",
        f"{available['boatName']} CurrentStatus={cur or '?'} "
        f"damages_key={'damages' in detail or 'openDamages' in detail}",
    )
    # Also hit damages sub-resource if exposed
    code_dmg_list, dmg_list = portal._request(  # noqa: SLF001 — smoke only
        "GET", f"/boats/{available['boatId']}/damages"
    )
    if code_dmg_list == 200:
        n = len((dmg_list or {}).get("damages") or (dmg_list if isinstance(dmg_list, list) else []) or [])
        checks.ok("GET boat damages", f"http=200 n={n}")
    else:
        checks.info("GET boat damages", f"http={code_dmg_list} (detail may embed only)")

    # Prefer a boat that has open damages for detail evidence
    dmg_boat = db.query_scalar(
        """
SELECT BoatId FROM efa2boatdamages
WHERE Fixed IS NULL OR Fixed = '' OR Fixed = 'false'
LIMIT 1
"""
    )
    if dmg_boat:
        d2 = portal.boat(dmg_boat)
        open_d = d2.get("damages") or d2.get("openDamages") or []
        checks.ok("boat detail with damages", f"boatId={dmg_boat} open={len(open_d) if isinstance(open_d, list) else open_d}")

    # --- start / finish lifecycle ---
    start_payload = {
        "boatId": available["boatId"],
        "boatVariant": available["boatVariant"],
        "crew": [{"id": person_id}],
        "destinationId": dest_id,
        "idempotencyKey": str(uuid.uuid4()),
        "acknowledgmentTokens": [],
    }
    code, trip = start_with_acks(portal, start_payload)
    err = (trip or {}).get("error") or {}
    if code not in (200, 201) or err:
        checks.fail("start trip", f"{code} {trip}")
        print(json.dumps({"PASS": checks.pass_n, "FAIL": checks.fail_n}))
        return 1

    trip_obj = trip.get("trip") if isinstance(trip.get("trip"), dict) else trip
    entry_id = str(trip_obj.get("entryId") or trip_obj.get("EntryId") or "")
    bs = trip.get("boatStatus") or {}
    status = bs.get("CurrentStatus") or bs.get("currentStatus") or ""
    entry_no = str(bs.get("EntryNo") or bs.get("entryNo") or "")
    if not entry_id:
        entry_id = db.query_scalar(
            "SELECT EntryId FROM efa2logbook WHERE Open='true' "
            f"AND BoatId={ComposeDb.sql_literal(available['boatId'])} "
            "ORDER BY CAST(EntryId AS UNSIGNED) DESC LIMIT 1"
        )
    db_st = db.query_rows(
        "SELECT CurrentStatus, EntryNo FROM efa2boatstatus "
        f"WHERE BoatId={ComposeDb.sql_literal(available['boatId'])}"
    )
    if db_st and db_st[0][0] == "ONTHEWATER" and db_st[0][1] == str(entry_id):
        checks.ok(
            "start → ONTHEWATER + EntryNo",
            f"EntryId={entry_id} apiStatus={status or db_st[0][0]} EntryNo={entry_no or db_st[0][1]}",
        )
    else:
        checks.fail("start → ONTHEWATER + EntryNo", f"db={db_st} entry={entry_id} api={status}/{entry_no}")

    trip_cc = int(
        db.query_scalar(
            f"SELECT ChangeCount FROM efa2logbook WHERE EntryId={ComposeDb.sql_literal(entry_id)} "
            "AND Open='true' LIMIT 1"
        )
        or trip_obj.get("changeCount")
        or "0"
    )
    code_fin, data_fin = portal.finish_trip(
        entry_id,
        {"expectedChangeCount": trip_cc, "distance": "5 km"},
    )
    if code_fin in (200, 201):
        st_after = db.query_scalar(
            "SELECT CurrentStatus FROM efa2boatstatus "
            f"WHERE BoatId={ComposeDb.sql_literal(available['boatId'])}"
        )
        if st_after == "AVAILABLE":
            checks.ok("finish → AVAILABLE", f"EntryId={entry_id} status={st_after}")
        else:
            checks.fail("finish → AVAILABLE", f"status={st_after} resp={data_fin}")
    else:
        checks.fail("finish trip", f"{code_fin} {data_fin}")

    # --- damage without abort ---
    code_dmg, data_dmg = portal.report_damage(
        available["boatId"],
        {
            "severity": "FULLYUSEABLE",
            "description": "portal-live-synch-smoke damage (safe to ignore)",
        },
    )
    if code_dmg in (200, 201):
        aborted = (data_dmg or {}).get("tripAborted")
        if aborted is False or aborted == "false":
            checks.ok("report damage tripAborted=false", f"http={code_dmg}")
        else:
            checks.fail("report damage tripAborted=false", f"tripAborted={aborted}")
    else:
        checks.fail("report damage", f"{code_dmg} {data_dmg}")

    # --- ACK_REQUIRED on NOTAVAILABLE (probe only; abort cleanup if started) ---
    ack_payload = {
        "boatId": notavail["boatId"],
        "boatVariant": notavail["boatVariant"],
        "crew": [{"id": person_id}],
        "destinationId": dest_id,
        "idempotencyKey": str(uuid.uuid4()),
        "acknowledgmentTokens": [],
    }
    code_ack, data_ack = portal.start_trip(ack_payload)
    err_ack = (data_ack or {}).get("error") or {}
    if code_ack == 409 and err_ack.get("code") == "ACK_REQUIRED":
        kinds = [
            c.get("kind")
            for c in ((err_ack.get("details") or {}).get("checks") or [])
            if c.get("kind")
        ]
        checks.ok("ACK_REQUIRED on NOTAVAILABLE", f"kinds={kinds}")
        # Complete with acks then abort to leave boat not on water
        code2, data2 = start_with_acks(portal, ack_payload)
        if code2 in (200, 201):
            t2 = data2.get("trip") if isinstance(data2.get("trip"), dict) else data2
            eid2 = str(t2.get("entryId") or t2.get("EntryId") or "")
            if not eid2:
                eid2 = db.query_scalar(
                    "SELECT EntryId FROM efa2logbook WHERE Open='true' "
                    f"AND BoatId={ComposeDb.sql_literal(notavail['boatId'])} "
                    "ORDER BY CAST(EntryId AS UNSIGNED) DESC LIMIT 1"
                )
            cc2 = int(
                db.query_scalar(
                    f"SELECT ChangeCount FROM efa2logbook WHERE EntryId={ComposeDb.sql_literal(eid2)} "
                    "AND Open='true' LIMIT 1"
                )
                or "1"
            )
            code_ab, data_ab = portal.abort_trip(eid2, {"expectedChangeCount": cc2})
            st_na = db.query_scalar(
                "SELECT CurrentStatus FROM efa2boatstatus "
                f"WHERE BoatId={ComposeDb.sql_literal(notavail['boatId'])}"
            )
            if code_ab in (200, 201) and st_na != "ONTHEWATER":
                checks.ok(
                    "ACK path start+abort cleanup",
                    f"EntryId={eid2} status_after={st_na}",
                )
            else:
                checks.fail("ACK path cleanup", f"abort={code_ab} {data_ab} status={st_na}")
        else:
            checks.fail("ACK path start after tokens", f"{code2} {data2}")
    else:
        checks.fail("ACK_REQUIRED on NOTAVAILABLE", f"{code_ack} {data_ack}")

    print()
    print(f"portal_live_smoke PASS={checks.pass_n} FAIL={checks.fail_n}")
    return 1 if checks.fail_n else 0


if __name__ == "__main__":
    raise SystemExit(main())
