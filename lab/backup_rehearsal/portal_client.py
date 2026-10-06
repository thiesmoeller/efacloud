"""Portal /api/portal/v1 HTTP client for rehearsal (cookie + CSRF)."""

from __future__ import annotations

import json
import urllib.error
import urllib.parse
import urllib.request
from http.cookiejar import CookieJar
from typing import Any


class PortalClient:
    def __init__(self, base_url: str):
        self.base = base_url.rstrip("/") + "/api/portal/v1"
        self.jar = CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))
        self.csrf: str | None = None

    def _request(
        self,
        method: str,
        path: str,
        body: dict[str, Any] | None = None,
        *,
        headers: dict[str, str] | None = None,
    ) -> tuple[int, Any]:
        url = self.base + path
        data = None
        hdrs = {"Accept": "application/json"}
        if body is not None:
            data = json.dumps(body).encode("utf-8")
            hdrs["Content-Type"] = "application/json"
        if self.csrf and method.upper() not in ("GET", "HEAD"):
            hdrs["X-CSRF-Token"] = self.csrf
        if headers:
            hdrs.update(headers)
        req = urllib.request.Request(url, data=data, method=method.upper(), headers=hdrs)
        try:
            with self.opener.open(req, timeout=60) as resp:
                raw = resp.read().decode("utf-8", errors="replace")
                code = resp.getcode()
        except urllib.error.HTTPError as e:
            raw = e.read().decode("utf-8", errors="replace")
            code = e.code
        try:
            parsed = json.loads(raw) if raw else None
        except json.JSONDecodeError:
            # Strip PHP warning HTML if present, then retry JSON object/array.
            start = raw.find("{")
            start_arr = raw.find("[")
            if start_arr >= 0 and (start < 0 or start_arr < start):
                start = start_arr
            if start >= 0:
                try:
                    parsed = json.loads(raw[start:])
                except json.JSONDecodeError:
                    parsed = {"_raw": raw}
            else:
                parsed = {"_raw": raw}
        return code, parsed

    def login(self, account: str, password: str) -> dict[str, Any]:
        code, data = self._request("POST", "/session/login", {"account": account, "password": password})
        if code != 200:
            raise RuntimeError(f"portal login failed {code}: {data}")
        if not isinstance(data, dict) or data.get("error") or not data.get("csrfToken"):
            raise RuntimeError(f"portal login missing csrf/session: {data}")
        self.csrf = data.get("csrfToken")
        return data

    def session(self) -> dict[str, Any]:
        code, data = self._request("GET", "/session")
        if code != 200:
            raise RuntimeError(f"session {code}: {data}")
        if data.get("csrfToken"):
            self.csrf = data["csrfToken"]
        return data

    def boats(self, **query: Any) -> dict[str, Any]:
        qs = urllib.parse.urlencode({k: v for k, v in query.items() if v is not None})
        path = "/boats" + (f"?{qs}" if qs else "")
        code, data = self._request("GET", path)
        if code != 200:
            raise RuntimeError(f"boats {code}: {data}")
        return data

    def boat(self, boat_id: str) -> dict[str, Any]:
        code, data = self._request("GET", f"/boats/{urllib.parse.quote(boat_id)}")
        if code != 200:
            raise RuntimeError(f"boat {code}: {data}")
        return data

    def ack(self, kind: str, boat_id: str) -> str:
        code, data = self._request("POST", "/acknowledgments", {"kind": kind, "boatId": boat_id})
        if code not in (200, 201):
            raise RuntimeError(f"ack {code}: {data}")
        return data["token"]

    def start_trip(self, payload: dict[str, Any]) -> tuple[int, Any]:
        return self._request("POST", "/trips", payload)

    def finish_trip(self, entry_id: str, payload: dict[str, Any]) -> tuple[int, Any]:
        return self._request("POST", f"/trips/{urllib.parse.quote(str(entry_id))}/finish", payload)

    def abort_trip(self, entry_id: str, payload: dict[str, Any]) -> tuple[int, Any]:
        return self._request("POST", f"/trips/{urllib.parse.quote(str(entry_id))}/abort", payload)

    def report_damage(self, boat_id: str, payload: dict[str, Any]) -> tuple[int, Any]:
        return self._request("POST", f"/boats/{urllib.parse.quote(boat_id)}/damages", payload)
