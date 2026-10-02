"""Parse EFA native *.efa2* XML backup objects into record dicts."""

from __future__ import annotations

import os
import re
import secrets
import xml.etree.ElementTree as ET
from pathlib import Path
from typing import Any

FOREVER = "9223372036854775807"
ECMAP = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789*-"
DATE_DE = re.compile(r"^(\d{1,2})\.(\d{1,2})\.(\d{4})$")

# Tables the portal / dockside workflow needs for rehearsal.
# Posttx load order: persons → boats → destinations → boatstatus → damages →
# reservations → logbook (references before dependents).
LOAD_TABLES = (
    "efa2persons",
    "efa2boats",
    "efa2destinations",
    "efa2boatstatus",
    "efa2boatdamages",
    "efa2boatreservations",
    "efa2logbook",
)

# Clear order (dependents first) for idempotent rehearsal resets.
CLEAR_TABLES = (
    "efa2logbook",
    "efa2boatdamages",
    "efa2boatreservations",
    "efa2boatstatus",
    "efa2boats",
    "efa2persons",
    "efa2destinations",
)

# Map backup filename stem → DB table (logbooks are year-named).
STATIC_FILES = {
    "boats.efa2boats": "efa2boats",
    "boatstatus.efa2boatstatus": "efa2boatstatus",
    "boatdamages.efa2boatdamages": "efa2boatdamages",
    "boatreservations.efa2boatreservations": "efa2boatreservations",
    "persons.efa2persons": "efa2persons",
    "destinations.efa2destinations": "efa2destinations",
}


def generate_ecrid() -> str:
    """Match Efa_tables::generate_ecrids (12-char BASE64-ish)."""
    raw = secrets.token_bytes(9)
    hex_s = raw.hex()
    ecrid = []
    for b in range(3):
        n = int(hex_s[b * 6 : b * 6 + 6], 16)
        for _ in range(4):
            ecrid.append(ECMAP[n % 64])
            n //= 64
    return "".join(ecrid)


def german_date_to_sql(value: str | None) -> str | None:
    if value is None or value == "":
        return None
    m = DATE_DE.match(value.strip())
    if not m:
        return value
    d, mo, y = m.groups()
    return f"{int(y):04d}-{int(mo):02d}-{int(d):02d}"


def parse_records(path: Path) -> list[dict[str, str]]:
    tree = ET.parse(path)
    root = tree.getroot()
    out: list[dict[str, str]] = []
    for rec in root.findall("./data/record"):
        row: dict[str, str] = {}
        for child in rec:
            row[child.tag] = child.text if child.text is not None else ""
        out.append(row)
    return out


def find_project_data_dir(extract_root: Path) -> Path:
    data = extract_root / "data"
    if not data.is_dir():
        raise FileNotFoundError(f"No data/ under {extract_root}")
    # Prefer nested club folder with boats.efa2boats
    for child in sorted(data.iterdir()):
        if child.is_dir() and (child / "boats.efa2boats").is_file():
            return child
    if (data / "boats.efa2boats").is_file():
        return data
    raise FileNotFoundError(f"Could not locate boats.efa2boats under {data}")


def backup_timestamp_ms(extract_root: Path) -> int:
    meta = extract_root / "backup.meta"
    if meta.is_file():
        text = meta.read_text(encoding="utf-8", errors="replace")
        m = re.search(r"<TimeStamp>(\d+)</TimeStamp>", text)
        if m:
            return int(m.group(1))
    return int(__import__("time").time() * 1000)


def is_valid_at(record: dict[str, str], at_ms: int) -> bool:
    vf = int(record.get("ValidFrom") or "0")
    inv_s = record.get("InvalidFrom") or FOREVER
    try:
        inv = int(inv_s)
    except ValueError:
        inv = int(FOREVER)
    return vf <= at_ms < inv


def prepare_record(table: str, raw: dict[str, str], *, logbook_name: str | None = None) -> dict[str, Any]:
    """
    Normalize a backup record for first-upload shaped posttx / DB write.

    Matches EfaCloudStorage + API v3 as closely as practical:
    - Client mints ecrid when missing (desktop does this before INSERT).
    - Preserves XML ChangeCount / LastModified; server register_modification
      will bump ChangeCount and refresh LastModified on write.
    - German dates → ISO (MariaDB DATE columns reject DD.MM.YYYY under STRICT;
      SynchControl sends DE via getAsText — documented deviation).
    - Logbook Open derived from EndTime when XML omits it.
    """
    row: dict[str, Any] = dict(raw)
    # System fields desktop first-upload typically lacks; client mints ecrid (API v3).
    if not row.get("ecrid") or len(str(row.get("ecrid", ""))) < 10:
        row["ecrid"] = generate_ecrid()
    # LastModification: desktop may omit on virgin backup rows; server fills on write.
    if not row.get("LastModification"):
        row["LastModification"] = "insert"
    if "ecrown" not in row or row["ecrown"] == "":
        row["ecrown"] = 0
    if not row.get("ChangeCount"):
        row["ChangeCount"] = 1
    if not row.get("LastModified"):
        row["LastModified"] = 0

    # Date fields (German → SQL / ISO). Required for MariaDB DATE columns.
    for key in (
        "Date",
        "EndDate",
        "ReportDate",
        "FixDate",
        "DateFrom",
        "DateTo",
        "Birthday",
        "StatusFrom",
        "StatusTo",
        "ManufactionDate",
        "PurchaseDate",
        "SellingDate",
    ):
        if key in row:
            converted = german_date_to_sql(str(row[key]))
            if converted is None:
                row.pop(key, None)
            else:
                row[key] = converted

    if table == "efa2logbook":
        row["Logbookname"] = logbook_name or row.get("Logbookname") or ""
        # Desktop XML omits Open; closed sessions have EndTime.
        if not row.get("Open"):
            row["Open"] = "false" if row.get("EndTime") else "true"

    if table == "efa2boatdamages":
        if "Fixed" not in row or row["Fixed"] == "":
            row["Fixed"] = "false"

    return row


def collect_load_plan(data_dir: Path, *, logbook_year: str | None = None) -> dict[str, list[dict[str, Any]]]:
    """Build table → prepared records for portal-relevant tables."""
    plan: dict[str, list[dict[str, Any]]] = {t: [] for t in LOAD_TABLES}

    for fname, table in STATIC_FILES.items():
        path = data_dir / fname
        if not path.is_file():
            continue
        for raw in parse_records(path):
            plan[table].append(prepare_record(table, raw))

    year = logbook_year or str(__import__("datetime").date.today().year)
    log_path = data_dir / f"{year}.efa2logbook"
    if log_path.is_file():
        for raw in parse_records(log_path):
            plan["efa2logbook"].append(prepare_record("efa2logbook", raw, logbook_name=year))

    return plan


def fleet_expected_counts(data_dir: Path, at_ms: int) -> dict[str, Any]:
    """Counts from backup XML (source of truth for verification)."""
    boats = parse_records(data_dir / "boats.efa2boats")
    persons = parse_records(data_dir / "persons.efa2persons")
    dests = parse_records(data_dir / "destinations.efa2destinations")
    status = parse_records(data_dir / "boatstatus.efa2boatstatus")
    damages = parse_records(data_dir / "boatdamages.efa2boatdamages")

    current_boat_ids: set[str] = set()
    seat_mix: dict[str, int] = {}
    for b in boats:
        if not is_valid_at(b, at_ms):
            continue
        bid = b["Id"]
        if bid in current_boat_ids:
            continue
        current_boat_ids.add(bid)
        seats = (b.get("TypeSeats") or "1").split(";")[0]
        if seats.endswith("X"):
            seats = seats[:-1]
        # Mirror getGeneralNumberOfSeatsType numeric bucket for OTHER/8/…
        seat_mix[seats] = seat_mix.get(seats, 0) + 1

    current_person_ids = {p["Id"] for p in persons if is_valid_at(p, at_ms)}
    current_dest_ids = {d["Id"] for d in dests if is_valid_at(d, at_ms)}

    onwater = [s for s in status if (s.get("CurrentStatus") or "") == "ONTHEWATER"]
    open_damages = [d for d in damages if (d.get("Fixed") or "").lower() != "true"]
    repaired = [d for d in damages if (d.get("Fixed") or "").lower() == "true"]
    sev: dict[str, int] = {}
    for d in open_damages:
        s = d.get("Severity") or ""
        sev[s] = sev.get(s, 0) + 1

    year = str(__import__("datetime").date.today().year)
    log_path = data_dir / f"{year}.efa2logbook"
    open_trips = 0
    log_rows = 0
    if log_path.is_file():
        logs = parse_records(log_path)
        log_rows = len(logs)
        for t in logs:
            # Open if no EndTime (desktop open session).
            if not t.get("EndTime"):
                open_trips += 1

    return {
        "at_ms": at_ms,
        "boat_versions": len(boats),
        "current_boats": len(current_boat_ids),
        "seat_mix": seat_mix,
        "current_persons": len(current_person_ids),
        "person_versions": len(persons),
        "current_destinations": len(current_dest_ids),
        "destination_versions": len(dests),
        "boatstatus_rows": len(status),
        "onwater": len(onwater),
        "open_damages": len(open_damages),
        "repaired_damages": len(repaired),
        "damage_severity_open": sev,
        "logbook_year": year,
        "logbook_rows": log_rows,
        "open_trips": open_trips,
    }


def pick_trip_fixture(data_dir: Path, at_ms: int) -> dict[str, str]:
    """Choose an available single without NOTUSEABLE damage + a person + destination."""
    damages_by_boat: dict[str, list[str]] = {}
    for d in parse_records(data_dir / "boatdamages.efa2boatdamages"):
        if (d.get("Fixed") or "").lower() == "true":
            continue
        damages_by_boat.setdefault(d["BoatId"], []).append(d.get("Severity") or "")

    status_by_id = {
        s["BoatId"]: s for s in parse_records(data_dir / "boatstatus.efa2boatstatus")
    }

    boat = None
    for b in parse_records(data_dir / "boats.efa2boats"):
        if not is_valid_at(b, at_ms):
            continue
        st = status_by_id.get(b["Id"], {})
        if (st.get("CurrentStatus") or "") != "AVAILABLE":
            continue
        if (st.get("ShowInList") or "") == "NOTAVAILABLE":
            continue
        sevs = damages_by_boat.get(b["Id"], [])
        if "NOTUSEABLE" in sevs:
            continue
        seats = (b.get("TypeSeats") or "1").split(";")[0]
        if seats.endswith("X"):
            seats = seats[:-1]
        if seats != "1":
            continue
        boat = b
        break
    if boat is None:
        raise RuntimeError("No available single boat without NOTUSEABLE damage for trip fixture")

    person = None
    for p in parse_records(data_dir / "persons.efa2persons"):
        if is_valid_at(p, at_ms) and p.get("FirstName") and p.get("LastName"):
            person = p
            break
    if person is None:
        raise RuntimeError("No current person for trip fixture")

    dest = None
    for d in parse_records(data_dir / "destinations.efa2destinations"):
        if is_valid_at(d, at_ms) and d.get("Id"):
            # Prefer one with distance
            if d.get("Distance"):
                dest = d
                break
            if dest is None:
                dest = d
    if dest is None:
        raise RuntimeError("No current destination for trip fixture")

    return {
        "boatId": boat["Id"],
        "boatName": boat.get("Name") or "",
        "boatVariant": (boat.get("TypeVariant") or "1").split(";")[0],
        "personId": person["Id"],
        "personName": f"{person.get('FirstName','')} {person.get('LastName','')}".strip(),
        "destinationId": dest["Id"],
        "destinationName": dest.get("Name") or "",
        "distance": dest.get("Distance") or "5 km",
    }


def locate_extract_or_unzip(backup_zip: Path, extract_dir: Path | None) -> Path:
    if extract_dir and extract_dir.is_dir() and (extract_dir / "data").is_dir():
        return extract_dir
    import zipfile
    import tempfile

    target = extract_dir or Path(tempfile.mkdtemp(prefix="efa_bk_rehearsal_"))
    target.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(backup_zip, "r") as zf:
        # Desktop zips often use Windows backslashes in member names.
        for info in zf.infolist():
            name = info.filename.replace("\\", "/")
            if not name or name.endswith("/"):
                (target / name).mkdir(parents=True, exist_ok=True)
                continue
            dest = target / name
            dest.parent.mkdir(parents=True, exist_ok=True)
            with zf.open(info) as src, open(dest, "wb") as out:
                out.write(src.read())
    if not (target / "data").is_dir():
        raise FileNotFoundError(f"Extracted zip but no data/ under {target}")
    return target
