"""Minimal EFA desktop /api/posttx.php client (API v3 container encoding)."""

from __future__ import annotations

import base64
import urllib.error
import urllib.parse
import urllib.request
from typing import Any


MSG_SEP = "\n|-eFa-|\n"
DELIM = ";"

# Autoincrement keys must be omitted on insert (EfaCloudStorage strips them; server assigns).
AUTOINCREMENT_FIELDS = {
    "efa2logbook": "EntryId",
    "efa2boatdamages": "Damage",
    "efa2boatreservations": "Reservation",
    "efa2messages": "MessageId",
}


def csv_encode(value: str) -> str:
    if value is None:
        return ""
    if any(c in value for c in (DELIM, '"', "\n")):
        return '"' + value.replace('"', '""') + '"'
    return value


def encode_txc(plain: str) -> str:
    b64 = base64.b64encode(plain.encode("utf-8")).decode("ascii")
    return b64.replace("/", "-").replace("+", "*").replace("=", "_")


def decode_txc(encoded: str) -> str:
    plain = encoded.strip()
    try:
        b = plain.replace("-", "/").replace("*", "+").replace("_", "=")
        pad = "=" * ((4 - len(b) % 4) % 4)
        return base64.b64decode(b + pad).decode("utf-8", "replace")
    except Exception:
        return plain


def build_container(
    *,
    user_id: int,
    password: str,
    transactions: list[dict[str, Any]],
    api_version: int = 3,
    container_id: int = 1,
) -> str:
    """
    transactions: list of {id, type, table, record: dict}
    """
    parts = [
        str(api_version),
        str(container_id),
        str(user_id),
        csv_encode(password),
    ]
    tx_blobs: list[str] = []
    for i, tx in enumerate(transactions, start=1):
        tid = str(tx.get("id", i))
        retries = str(tx.get("retries", 0))
        ttype = tx["type"]
        table = tx["table"]
        fields = [tid, retries, ttype, table]
        record = tx.get("record") or {}
        for k, v in record.items():
            if v is None:
                continue
            fields.append(str(k))
            fields.append(csv_encode(str(v)))
        # Even number of elements required (header 4 + key/value pairs).
        tx_blobs.append(DELIM.join(fields))
    body = MSG_SEP.join(tx_blobs)
    plain = DELIM.join(parts) + DELIM + body
    return encode_txc(plain)


def parse_container_response(raw: str) -> dict[str, Any]:
    """Decode posttx response into container + per-tx results."""
    decoded = decode_txc(raw)
    # Header: version;cID;cresult;cmsg; then txs joined by MSG_SEP
    # cmsg may contain quoted CSV; after explode limit, rest starts at txs.
    # Practical format used by send_response_and_exit:
    #   version;cID;cresult;cmsg;txid;txcode;txmsg[EMS txid;txcode;txmsg...]
    segs = decoded.split(DELIM)
    if len(segs) < 4:
        return {"ok": False, "decoded": decoded, "cresult": "", "txs": [], "raw": raw}
    version, cid, cresult, cmsg_and_rest = segs[0], segs[1], segs[2], DELIM.join(segs[3:])
    # Split off txs after first unquoted ';' segment for cmsg — cmsg is csv-encoded
    # and may contain quotes. Fall back: split on MSG_SEP for tx bodies after header.
    # Header ends at the 4th field; remaining is first tx blob (possibly with leading).
    # Re-parse carefully: version;cID;cresult;cmsg; then first tx fields...
    # cmsg itself is csv_entry_encode'd so it may be quoted. Use a simple state scan.
    rest = decoded
    # Consume version, cID, cresult
    idx = 0
    fields_head: list[str] = []
    for _ in range(3):
        next_semi = rest.find(DELIM, idx)
        if next_semi < 0:
            return {"ok": False, "decoded": decoded, "cresult": cresult, "txs": [], "raw": raw}
        fields_head.append(rest[idx:next_semi])
        idx = next_semi + 1
    # Parse cmsg as CSV field
    if idx < len(rest) and rest[idx] == '"':
        j = idx + 1
        buf = []
        while j < len(rest):
            if rest[j] == '"' and j + 1 < len(rest) and rest[j + 1] == '"':
                buf.append('"')
                j += 2
                continue
            if rest[j] == '"':
                j += 1
                break
            buf.append(rest[j])
            j += 1
        cmsg = "".join(buf)
        if j < len(rest) and rest[j] == DELIM:
            j += 1
        tx_blob = rest[j:]
    else:
        next_semi = rest.find(DELIM, idx)
        if next_semi < 0:
            cmsg = rest[idx:]
            tx_blob = ""
        else:
            cmsg = rest[idx:next_semi]
            tx_blob = rest[next_semi + 1 :]

    txs: list[dict[str, str]] = []
    if tx_blob:
        for part in tx_blob.split(MSG_SEP):
            part = part.strip()
            if not part:
                continue
            # txid;result_code;result_message (message may contain ';')
            p = part.split(DELIM, 2)
            if len(p) < 2:
                continue
            txs.append(
                {
                    "id": p[0],
                    "result_code": p[1],
                    "result_message": p[2] if len(p) > 2 else "",
                }
            )

    c_ok = cresult == "300"
    # 300 completed; 303 completed with key fix — both fine for rehearsal load
    tx_ok = all(t["result_code"] in ("300", "303") for t in txs) if txs else c_ok
    return {
        "ok": c_ok and tx_ok,
        "decoded": decoded,
        "version": fields_head[0] if fields_head else version,
        "cid": fields_head[1] if len(fields_head) > 1 else cid,
        "cresult": cresult,
        "cmsg": cmsg,
        "txs": txs,
        "raw": raw,
    }


def record_for_posttx(table: str, record: dict[str, Any], *, for_insert: bool = True) -> dict[str, Any]:
    """Shape a prepared backup row like EfaCloudStorage.modifyServerRecord for API v3."""
    out: dict[str, Any] = {}
    auto_key = AUTOINCREMENT_FIELDS.get(table) if for_insert else None
    for k, v in record.items():
        if v is None:
            continue
        if auto_key and k == auto_key:
            continue  # server assigns on insert
        s = str(v)
        if s == "" and k not in (
            "TypeDescription",
            "Comment",
            "Description",
            "Notes",
            "BoatName",
            "DestinationName",
            "FirstName",
            "LastName",
            "NameAffix",
        ):
            continue
        out[k] = s
    return out


class PosttxClient:
    def __init__(self, base_url: str, user_id: int, password: str):
        self.base_url = base_url.rstrip("/")
        self.user_id = user_id
        self.password = password
        self.session_id: str | None = None
        self._cid = 1
        self._txid = 1

    def _post(self, txc: str) -> str:
        data = urllib.parse.urlencode({"txc": txc}).encode("utf-8")
        req = urllib.request.Request(
            self.base_url + "/api/posttx.php",
            data=data,
            method="POST",
            headers={"Content-Type": "application/x-www-form-urlencoded"},
        )
        try:
            with urllib.request.urlopen(req, timeout=180) as resp:
                return resp.read().decode("utf-8", errors="replace")
        except urllib.error.HTTPError as e:
            body = e.read().decode("utf-8", errors="replace")
            raise RuntimeError(f"posttx HTTP {e.code}: {body[:500]}") from e

    def nop_session(self) -> str:
        txc = build_container(
            user_id=self.user_id,
            password=self.password,
            container_id=self._cid,
            transactions=[{"id": self._txid, "type": "NOP", "table": "@All", "record": {}}],
        )
        self._cid += 1
        self._txid += 1
        raw = self._post(txc)
        self.session_id = raw
        return raw

    def modify(self, *, type_: str, table: str, record: dict[str, Any]) -> str:
        return self.modify_many([{"type": type_, "table": table, "record": record}])["raw"]

    def modify_many(self, transactions: list[dict[str, Any]]) -> dict[str, Any]:
        """Send one or more insert/update/delete txs in a single container."""
        cred = self.password
        built = []
        for tx in transactions:
            built.append(
                {
                    "id": self._txid,
                    "type": tx["type"],
                    "table": tx["table"],
                    "record": tx.get("record") or {},
                }
            )
            self._txid += 1
        txc = build_container(
            user_id=self.user_id,
            password=cred,
            container_id=self._cid,
            transactions=built,
        )
        self._cid += 1
        raw = self._post(txc)
        parsed = parse_container_response(raw)
        return parsed
