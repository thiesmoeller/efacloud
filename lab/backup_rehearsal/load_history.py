"""
Load closed historical logbook years (and optionally clubwork books) from one
EFA desktop backup into an efaCloud instance via /api/posttx.php.

Intended for instances whose master data (persons, boats, destinations) already
came from the same EFA project, so trip references resolve. Years that already
hold trips on the server are skipped. The current year is never loaded — that
stays with desktop SynchControl.

Logbooks go through API v2 by default, which keeps the desktop EntryId (API v3
forbids client EntryIds and renumbers from 1, closing gaps). Clubwork always uses
API v3. The server only accepts books listed in an uploaded .efa2project, hence
--upload-project; that needs a writable config/client_cfg/ on the server.
"""

from __future__ import annotations

import argparse
import datetime
import json
import os
import sys
import time
from pathlib import Path
from typing import Any

from efa2xml import find_project_data_dir, locate_extract_or_unzip, parse_records, prepare_record
from posttx_client import build_container, parse_container_response, record_for_posttx, PosttxClient

BATCH = 25


def server_keys(client: PosttxClient, table: str, filter_: dict[str, str], key: str) -> set[str]:
    """Data keys already on the server (keys-only 'synch' select, so no quoted multi-line fields)."""
    txc = build_container(
        user_id=client.user_id,
        password=client.password,
        container_id=client._cid,
        transactions=[{"id": client._txid, "type": "synch", "table": table, "record": filter_}],
    )
    client._cid += 1
    client._txid += 1
    parsed = parse_container_response(client._post(txc))
    if not parsed["txs"] or parsed["txs"][0]["result_code"] != "300":
        raise RuntimeError(f"synch {table} {filter_} failed: {parsed['decoded'][:300]}")
    lines = [l for l in parsed["txs"][0]["result_message"].splitlines() if l.strip()]
    if not lines:
        return set()
    idx = lines[0].split(";").index(key)
    return {l.split(";")[idx] for l in lines[1:]}


def send_batch(client: PosttxClient, table: str, records: list[dict[str, Any]], api_version: int):
    txs = []
    for r in records:
        txs.append({"id": client._txid, "type": "insert", "table": table, "record": r})
        client._txid += 1
    txc = build_container(
        user_id=client.user_id,
        password=client.password,
        container_id=client._cid,
        transactions=txs,
        api_version=api_version,
    )
    client._cid += 1
    for attempt in range(5):
        try:
            return txs, parse_container_response(client._post(txc))
        except Exception as e:  # noqa: BLE001
            if attempt == 4:
                raise
            print(f"    retry after error: {e}", flush=True)
            time.sleep(10 * (attempt + 1))


def build_logbook_records(path: Path, year: str, api_version: int) -> list[dict[str, Any]]:
    rows = parse_records(path)
    rows.sort(key=lambda r: int(r.get("EntryId") or 0))
    out = []
    for raw in rows:
        rec = prepare_record("efa2logbook", raw, logbook_name=year)
        rec["Open"] = "false"
        if api_version >= 3:
            out.append(record_for_posttx("efa2logbook", rec, for_insert=True))
        else:
            rec = record_for_posttx("efa2logbook", rec, for_insert=False)
            for k in ("ecrid", "ecrown"):
                rec.pop(k, None)
            out.append(rec)
    return out


def upload_project_file(client: PosttxClient, data_dir: Path) -> str:
    """Upload the .efa2project like desktop SynchControl, so the server knows all books."""
    project = next(data_dir.parent.glob("*.efa2project"))
    txc = build_container(
        user_id=client.user_id,
        password=client.password,
        container_id=client._cid,
        transactions=[
            {
                "id": client._txid,
                "type": "upload",
                "table": "text",
                "record": {"filepath": project.name, "contents": project.read_text(encoding="utf-8")},
            }
        ],
    )
    client._cid += 1
    client._txid += 1
    parsed = parse_container_response(client._post(txc))
    if not parsed["ok"]:
        raise RuntimeError(f"project upload failed: {parsed['decoded'][:300]}")
    return parsed["txs"][0]["result_message"]


def build_clubwork_records(path: Path, year: str) -> list[dict[str, Any]]:
    out = []
    for raw in parse_records(path):
        rec = prepare_record("efa2clubwork", raw)
        rec["ClubworkbookName"] = year
        out.append(record_for_posttx("efa2clubwork", rec, for_insert=True))
    return out


def load_book(client, table, name, records, api_version, failures, limit):
    if limit:
        records = records[:limit]
    ok = 0
    for i in range(0, len(records), BATCH):
        chunk = records[i : i + BATCH]
        txs, parsed = send_batch(client, table, chunk, api_version)
        if parsed["cresult"] != "300":
            raise RuntimeError(f"{table} {name} container failed at {i}: {parsed['decoded'][:400]}")
        results = {t["id"]: t for t in parsed["txs"]}
        for tx in txs:
            res = results.get(str(tx["id"]))
            if res and res["result_code"] in ("300", "303"):
                ok += 1
            else:
                failures.append(
                    {
                        "table": table,
                        "book": name,
                        "record": tx["record"],
                        "result": res,
                    }
                )
        print(f"    {name}: {min(i + BATCH, len(records))}/{len(records)} sent, {ok} ok", end="\r", flush=True)
    print()
    return ok


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--backup-zip", required=True, type=Path)
    ap.add_argument("--extract-dir", type=Path, default=None)
    ap.add_argument("--base-url", required=True)
    ap.add_argument("--user-id", type=int, default=int(os.environ.get("EFACLOUD_ADMIN_ID", "0")))
    ap.add_argument("--password-env", default="EFACLOUD_ADMIN_PASSWORD")
    ap.add_argument("--years", default="", help="e.g. 2006-2025 (default: all closed years in backup)")
    ap.add_argument("--clubwork", action="store_true", help="also load closed clubwork books")
    ap.add_argument("--api-version", type=int, default=2, choices=(2, 3), help="for logbooks")
    ap.add_argument("--limit", type=int, default=0, help="max records per book (testing)")
    ap.add_argument(
        "--upload-project",
        action="store_true",
        help="upload the backup's .efa2project first (registers all logbooks/clubwork books)",
    )
    ap.add_argument("--dry-run", action="store_true")
    ap.add_argument("--failures-file", type=Path, default=Path("load_history_failures.json"))
    args = ap.parse_args()

    password = os.environ.get(args.password_env, "")
    if not args.user_id or not password:
        print(f"Need --user-id and ${args.password_env}", file=sys.stderr)
        return 2

    extract = locate_extract_or_unzip(args.backup_zip, args.extract_dir)
    data_dir = find_project_data_dir(extract)
    current_year = datetime.date.today().year
    if args.years:
        lo, _, hi = args.years.partition("-")
        wanted = set(range(int(lo), int(hi or lo) + 1))
    else:
        wanted = None

    def years_of(suffix: str) -> list[tuple[str, Path]]:
        out = []
        for p in sorted(data_dir.glob(f"*.{suffix}")):
            if not p.stem.isdigit():
                continue
            y = int(p.stem)
            if y >= current_year or (wanted is not None and y not in wanted):
                continue
            out.append((p.stem, p))
        return out

    client = PosttxClient(args.base_url, args.user_id, password)
    nop = parse_container_response(client.nop_session())
    if not nop["ok"]:
        print(f"posttx auth failed: {nop['decoded'][:300]}", file=sys.stderr)
        return 1

    if args.upload_project and not args.dry_run:
        print("project upload:", upload_project_file(client, data_dir), flush=True)

    failures: list[dict[str, Any]] = []
    summary: dict[str, Any] = {}
    jobs = [("efa2logbook", "Logbookname", y, p) for y, p in years_of("efa2logbook")]
    if args.clubwork:
        jobs += [("efa2clubwork", "Clubworkbookname", y, p) for y, p in years_of("efa2clubwork")]

    try:
        for table, name_field, year, path in jobs:
            records = (
                build_logbook_records(path, year, args.api_version)
                if table == "efa2logbook"
                else build_clubwork_records(path, year)
            )
            key = "EntryId" if table == "efa2logbook" else "Id"
            api_version = args.api_version if table == "efa2logbook" else 3
            existing = server_keys(client, table, {name_field: year}, key)
            if existing and table == "efa2logbook" and api_version >= 3:
                summary[f"{table}:{year}"] = {"skipped": "server not empty (API v3 cannot resume)"}
                continue
            todo = [r for r in records if str(r.get(key, "")) not in existing]
            print(f"{table} {year}: backup={len(records)} server={len(existing)} to_load={len(todo)}", flush=True)
            if args.dry_run or not todo:
                summary[f"{table}:{year}"] = {"backup": len(records), "server": len(existing), "to_load": len(todo)}
                continue
            ok = load_book(client, table, year, todo, api_version, failures, args.limit)
            after = len(server_keys(client, table, {name_field: year}, key))
            summary[f"{table}:{year}"] = {"backup": len(records), "ok": ok, "server_after": after}
    finally:
        args.failures_file.write_text(json.dumps(failures, indent=1, ensure_ascii=False), encoding="utf-8")
        print(json.dumps(summary, indent=1))
        print(f"failures: {len(failures)} (written to {args.failures_file})")
    return 0


if __name__ == "__main__":
    sys.exit(main())
