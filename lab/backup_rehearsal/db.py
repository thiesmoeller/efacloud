"""MariaDB helpers via `docker compose exec db mariadb` (no host DB port required)."""

from __future__ import annotations

import os
import shlex
import subprocess
from pathlib import Path
from typing import Any, Iterable


class ComposeDb:
    def __init__(
        self,
        root: Path,
        *,
        project: str | None = None,
        db_name: str | None = None,
        db_user: str | None = None,
        db_password: str | None = None,
    ):
        self.root = root
        self.project = project or os.environ.get("COMPOSE_PROJECT_NAME", "")
        self.db_name = db_name or os.environ.get("EFACLOUD_DB_NAME", "efacloud")
        self.db_user = db_user or os.environ.get("EFACLOUD_DB_USER", "efacloud")
        self.db_password = db_password or os.environ.get("EFACLOUD_DB_PASSWORD", "")

    def _compose_prefix(self) -> list[str]:
        cmd = ["docker", "compose"]
        if self.project:
            cmd += ["-p", self.project]
        return cmd

    def exec_sql(self, sql: str, *, as_root: bool = False) -> str:
        if as_root:
            user = "root"
            password = os.environ.get("MARIADB_ROOT_PASSWORD", "efacloud_root_dev_password")
        else:
            user = self.db_user
            password = self.db_password
        # -N -B batch, no headers
        inner = (
            f"mariadb -u{shlex.quote(user)} -p{shlex.quote(password)} "
            f"{shlex.quote(self.db_name)} -N -B"
        )
        cmd = self._compose_prefix() + ["exec", "-T", "db", "sh", "-c", inner]
        proc = subprocess.run(
            cmd,
            input=sql.encode("utf-8"),
            cwd=str(self.root),
            capture_output=True,
        )
        if proc.returncode != 0:
            err = (proc.stderr or proc.stdout).decode("utf-8", errors="replace")
            raise RuntimeError(f"mariadb failed ({proc.returncode}): {err[:2000]}\nSQL head: {sql[:400]}")
        return proc.stdout.decode("utf-8", errors="replace")

    def query_scalar(self, sql: str) -> str:
        out = self.exec_sql(sql).strip()
        return out.splitlines()[0] if out else ""

    def query_rows(self, sql: str) -> list[list[str]]:
        out = self.exec_sql(sql).strip()
        if not out:
            return []
        return [line.split("\t") for line in out.splitlines()]

    def table_columns(self, table: str) -> set[str]:
        rows = self.query_rows(f"SHOW COLUMNS FROM `{table}`")
        return {r[0] for r in rows if r}

    @staticmethod
    def sql_literal(value: Any) -> str:
        if value is None:
            return "NULL"
        if isinstance(value, bool):
            return "'true'" if value else "'false'"
        if isinstance(value, (int, float)) and not isinstance(value, bool):
            return str(value)
        s = str(value)
        s = s.replace("\\", "\\\\").replace("'", "''")
        return f"'{s}'"

    def insert_rows(self, table: str, rows: Iterable[dict[str, Any]], *, columns: set[str] | None = None) -> int:
        cols = columns or self.table_columns(table)
        batch: list[str] = []
        n = 0
        for row in rows:
            filtered = {k: v for k, v in row.items() if k in cols and v is not None and v != ""}
            # Always keep required numeric defaults
            if "ChangeCount" in cols and "ChangeCount" not in filtered:
                filtered["ChangeCount"] = 0
            if "LastModified" in cols and "LastModified" not in filtered:
                filtered["LastModified"] = 0
            if "ecrown" in cols and "ecrown" not in filtered:
                filtered["ecrown"] = 0
            if not filtered:
                continue
            col_list = ", ".join(f"`{c}`" for c in filtered.keys())
            val_list = ", ".join(self.sql_literal(v) for v in filtered.values())
            batch.append(f"INSERT INTO `{table}` ({col_list}) VALUES ({val_list});")
            n += 1
            if len(batch) >= 40:
                self.exec_sql("\n".join(batch))
                batch = []
        if batch:
            self.exec_sql("\n".join(batch))
        return n

    def upsert_by_ecrid(self, table: str, rows: Iterable[dict[str, Any]], *, columns: set[str] | None = None) -> tuple[int, int]:
        """Second-synch style: update existing ecrid rows; insert missing. Returns (inserted, updated)."""
        cols = columns or self.table_columns(table)
        inserted = updated = 0
        for row in rows:
            filtered = {k: v for k, v in row.items() if k in cols and v is not None and v != ""}
            ecrid = filtered.get("ecrid")
            if not ecrid:
                continue
            exists = self.query_scalar(
                f"SELECT COUNT(*) FROM `{table}` WHERE ecrid={self.sql_literal(ecrid)}"
            )
            if exists not in ("", "0"):
                # Bump LastModified only — no structural change
                lm = filtered.get("LastModified", 0)
                self.exec_sql(
                    f"UPDATE `{table}` SET LastModified={self.sql_literal(lm)}, "
                    f"LastModification='update' WHERE ecrid={self.sql_literal(ecrid)}"
                )
                updated += 1
            else:
                self.insert_rows(table, [filtered], columns=cols)
                inserted += 1
        return inserted, updated

    def php_password_hash(self, password: str) -> str:
        """Hash with PHP PASSWORD_DEFAULT inside the web container."""
        php = "echo password_hash(%s, PASSWORD_DEFAULT);" % (repr(password))
        cmd = self._compose_prefix() + ["exec", "-T", "web", "php", "-r", php]
        proc = subprocess.run(cmd, cwd=str(self.root), capture_output=True)
        if proc.returncode != 0:
            raise RuntimeError(proc.stderr.decode())
        return proc.stdout.decode().strip()
