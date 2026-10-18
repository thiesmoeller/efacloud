"""
Convert closed logbook years of an EFA desktop backup into CSV files for the
admin web form "Fahrten importieren" (forms/fahrten_importieren.php).

One file per year, ';'-separated, '"'-quoted, UTF-8. Keeps the desktop EntryId,
converts German dates to ISO, closes trips without EndTime, and omits efaCloud
system fields (the form forbids client ecrids and sets LastModification itself).
Years with more than --max-rows trips are split into parts.

Output contains club PII — write it under the gitignored var/ only.
"""

from __future__ import annotations

import argparse
import csv
import datetime
from pathlib import Path

from efa2xml import find_project_data_dir, locate_extract_or_unzip, parse_records, prepare_record

DROP = {"ecrid", "ecrown", "ecrhis", "LastModification", "LastModified", "ClientSideKey"}

# The form writes every header column for every row; '' is rejected by MariaDB for
# these column types, so rows are grouped by which of them they actually carry.
TYPED = ("Date", "EndDate", "BoatVariant", "BoatCaptain", "StartTime", "EndTime", "ChangeCount")


def year_rows(path: Path, year: str) -> list[dict[str, str]]:
    rows = []
    for raw in sorted(parse_records(path), key=lambda r: int(r.get("EntryId") or 0)):
        rec = prepare_record("efa2logbook", raw, logbook_name=year)
        rec["Open"] = "false"
        row = {k: str(v) for k, v in rec.items() if k not in DROP and v is not None}
        rows.append({k: v for k, v in row.items() if v != "" or k not in TYPED})
    return rows


def typed_groups(rows: list[dict[str, str]]) -> list[list[dict[str, str]]]:
    groups: dict[tuple[str, ...], list[dict[str, str]]] = {}
    for r in rows:
        groups.setdefault(tuple(k for k in TYPED if k in r), []).append(r)
    return sorted(groups.values(), key=len, reverse=True)


def write_csv(path: Path, rows: list[dict[str, str]]) -> None:
    fields: list[str] = ["Logbookname", "EntryId"]
    for r in rows:
        for k in r:
            if k not in fields:
                fields.append(k)
    with path.open("w", encoding="utf-8", newline="") as f:
        w = csv.DictWriter(f, fieldnames=fields, delimiter=";", quotechar='"', lineterminator="\n")
        w.writeheader()
        w.writerows(rows)


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--backup-zip", required=True, type=Path)
    ap.add_argument("--extract-dir", type=Path, default=None)
    ap.add_argument("--out-dir", type=Path, required=True)
    ap.add_argument("--max-rows", type=int, default=200)
    args = ap.parse_args()

    data_dir = find_project_data_dir(locate_extract_or_unzip(args.backup_zip, args.extract_dir))
    args.out_dir.mkdir(parents=True, exist_ok=True)
    current_year = datetime.date.today().year
    total = 0
    for path in sorted(data_dir.glob("*.efa2logbook")):
        if not path.stem.isdigit() or int(path.stem) >= current_year:
            continue
        rows = year_rows(path, path.stem)
        n = 0
        for group in typed_groups(rows):
            for i in range(0, len(group), args.max_rows):
                n += 1
                out = args.out_dir / f"fahrten_{path.stem}_{n:02d}.csv"
                write_csv(out, group[i : i + args.max_rows])
        print(f"{path.stem}: {len(rows)} trips in {n} files")
        total += len(rows)
    print(f"total: {total} trips")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
