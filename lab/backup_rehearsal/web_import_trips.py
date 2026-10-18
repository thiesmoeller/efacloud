"""
Drive the admin web form "Fahrten importieren" with CSV files produced by
backup_to_trip_csv.py: log in, upload + check (step 1), confirm import (step 2).

Stops at the first file whose check step reports errors; the form then imports
nothing for that file. Run with: uv run --with requests --with beautifulsoup4 ...
"""

from __future__ import annotations

import argparse
import os
import re
import sys
import time
from pathlib import Path
from urllib.parse import urljoin

import requests
from bs4 import BeautifulSoup


def form_of(html: str, base: str) -> tuple[str, dict[str, str]]:
    soup = BeautifulSoup(html, "html.parser")
    form = soup.find("form")
    if form is None:
        raise RuntimeError("no form on page: " + soup.get_text(" ", strip=True)[:400])
    fields = {
        i["name"]: i.get("value", "")
        for i in form.find_all("input")
        if i.get("name") and i.get("type") not in ("file", "submit")
    }
    return urljoin(base, form.get("action") or base), fields


def text_of(html: str) -> str:
    return BeautifulSoup(html, "html.parser").get_text("\n", strip=True)


def login(s: requests.Session, base: str, account: str, password: str) -> None:
    url = urljoin(base, "forms/login.php")
    action, fields = form_of(s.get(url, timeout=60).text, url)
    fields.update({"Account": account, "Passwort": password, "submit": "submit"})
    r = s.post(action, data=fields, timeout=60)
    if "login.php" in r.url and "Passwort" in r.text:
        raise RuntimeError("login failed: " + text_of(r.text)[:300])


def import_file(s: requests.Session, base: str, path: Path) -> str:
    url = urljoin(base, "forms/fahrten_importieren.php")
    action, fields = form_of(s.get(url, timeout=60).text, url)
    fields["submit"] = "submit"
    with path.open("rb") as f:
        r = s.post(action, data=fields, files={"userfile": (path.name, f, "text/csv")}, timeout=600)
    action, fields = form_of(r.text, action)
    if not re.search(r"fseq=\w{5}2", action):
        raise RuntimeError(f"{path.name}: check step failed:\n{text_of(r.text)[:2000]}")
    fields["submit"] = "submit"
    r = s.post(action, data=fields, timeout=900)
    return text_of(r.text)


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--base-url", required=True)
    ap.add_argument("--account", default=os.environ.get("EFACLOUD_ADMIN_ID", ""))
    ap.add_argument("--password-env", default="EFACLOUD_ADMIN_PASSWORD")
    ap.add_argument("files", nargs="+", type=Path)
    args = ap.parse_args()

    base = args.base_url.rstrip("/") + "/"
    s = requests.Session()
    login(s, base, args.account, os.environ[args.password_env])
    for path in args.files:
        t0 = time.time()
        result = import_file(s, base, path)
        path.with_suffix(".result.txt").write_text(result, encoding="utf-8")
        added = len(re.findall(r"wurde hinzugefügt|has been added", result))
        updated = len(re.findall(r"wurde aktualisiert|has been update", result))
        other = [l for l in result.splitlines() if re.match(r"^(Lade Zeile|Load row)", l)
                 and not re.search(r"hinzugefügt|aktualisiert|added|update", l)]
        print(f"{path.name}: added={added} updated={updated} other={len(other)} ({time.time() - t0:.0f}s)", flush=True)
        for l in other[:10]:
            print("   ", l[:200])
    return 0


if __name__ == "__main__":
    sys.exit(main())
