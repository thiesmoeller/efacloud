# Security release review — production dockside PWA

**Scope:** efaCloud fork + portal API (`api/portal/v1/`, `classes/portal/`) for CapRover production.  
**Date:** 2026-10-02  
**Prior hardening:** `ab35fcd` (installer lock + SQL table identifiers) treated as incomplete; this review digs deeper.  
**Evidence:** `./tests/security/run.sh` → **97 passed, 0 failed**; `./scripts/https-cookie-drill.sh --down` → **PASS** (Secure behind local TLS proxy); `./tests/portal/run.sh` → **50 passed, 0 failed**.

## Public surface map

| Path | Reachability (post-install) | Auth |
| --- | --- | --- |
| `/api/posttx.php`, `/api/ping.php` | Public (desktop/efaWeb protocol) | API session / password in tx container |
| `/api/portal/v1/*` | Public JSON API | `EFA_PORTAL` cookie + CSRF on mutations |
| `/portal/` | Static PWA assets | No server auth (API enforces) |
| `/pages/*`, `/forms/*` | Web UI | `init.php` session + `imenu`/`pmenu` role ACL |
| `/public/*` | Mostly public; sensitive pages go through `init.php` | Varies |
| `/install/*` | Blocked by Apache when `.locked` / `.install_complete` | Installer guard |
| `attachements/`, `authentication/`, `classes/`, `config/`, `log/`, `pdfs/`, `tcpdf/`, `templates/`, `uploads/` | Apache `DirectoryMatch` deny | N/A |

## Findings

| ID | Severity | Area | Finding | Status | Evidence / fix |
| --- | --- | --- | --- | --- | --- |
| R1 | **Critical** | `public/phpinfo.php` | Unauthenticated `phpinfo()` (menu listed admin-only but file bypassed `init.php`) | **Fixed** | Now includes `init.php`; admin menu ACL applies. Test: phpinfo goes through init. |
| R2 | **High** | `/api/posttx.php` member `insert`/`update` | Members could modify arbitrary tables (e.g. `efaCloudUsers`, `efa2persons`) and any crew’s trips | **Fixed** | `Efa_member_write_guard` + hook in `efa_api::api_modify`. bths/board/admin unchanged. Members limited to logbook/boatstatus/damages/messages; trips require participant or `portalTrainer`. Tests cover allow/deny matrix. |
| R3 | **High** | `tfyh_socket::clause_for_wherekeyis` | Column names and operators interpolated without validation; `IN (...)` accepted raw SQL | **Fixed** | Identifier checks on table/columns; operator allowlist; `sanitize_sql_in_list`. Field keys validated on insert/update/delete. |
| R4 | **Medium** | `pages/getrecord.php` | `$_GET["table"]` without `is_safe_table_name`; values echoed without encoding (board+ ACL) | **Fixed** | Safe table name + `htmlspecialchars` on keys/values. |
| R5 | **Medium** | Portal API store bootstrap | After install, DB failure silently fell back to fixtures (known `fixture-pass` accounts) if present on disk | **Fixed** | Installed + no DB → HTTP 503. Fixtures only when `EFACLOUD_PORTAL_FIXTURES=1` (or incomplete install with fixtures present). |
| R6 | **Medium** | Upload forms | Upload filenames used as paths (`../` traversal into `log/io` or `uploads`) | **Fixed** | `basename()` + reject `.` / `..` in dateiablage, tabelle/personen/fahrten import. |
| R7 | Low | Legacy web CSRF | Forms use form-sequence tokens, not synchronizer CSRF tokens | **Accepted** | Same-origin + session; portal API has proper CSRF. Residual for admin CSRF if XSS exists. |
| R8 | Low | `logo_hochladen.php` | No image-type validation (admin-only) | **Deferred** | Admin ACL; Apache serves `resources/` as static. Prefer `getimagesize` later. |
| R9 | Low | `dateiablage.php` | Not in `imenu` (unreachable via ACL) but `cdir`/`dfile` GET params are path-sensitive | **Deferred** | Unreachable today; if re-enabled, constrain paths under `uploads/`. |
| R10 | Low | `tfyh_socket::query()` | Caller-built SQL for Tfyh/Efa_tools only | **Accepted** | Framework-internal; not exposed to request params directly. |
| R11 | Info | Session `Secure` cookie | Portal sets `Secure` when `HTTPS` is on **or** CapRover-style `X-Forwarded-Proto: https` / `X-Forwarded-Ssl` | **Partial↑ (local)** | `Portal_session::request_is_https()` + unit tests; `./scripts/https-cookie-drill.sh` proves Secure behind local TLS proxy. CapRover staging still ops. |
| R12 | Info | efaWeb client-side ACL | efaWeb still relies on server API; member writes now server-enforced | **Fixed** (via R2) | Client alone was never sufficient. |
| R13 | Info | Installer | Lock + Apache deny after complete | **Verified** | Unchanged; tests still cover. Do not weaken. |
| R14 | Info | Portal session | HttpOnly, SameSite=Strict, regenerate on login, throttle, revocation | **Verified** | Covered by portal tests. |

## Residual risk (ops)

1. **TLS termination (R11 Partial↑):** App now trusts CapRover's `X-Forwarded-Proto: https` for Secure cookies (local proxy drill PASS). Still confirm Force HTTPS + header on CapRover staging/prod.
2. **Desktop sync accounts:** Keep sync clients on `bths` (or higher). Do **not** use `member` for desktop synch — member write scope is intentionally narrowed.
3. **Trainer grants:** `portalTrainer` (concession **131072**) is powerful for trip/boatstatus writes via both portal and legacy API — grant sparingly.
4. **Admin CSRF / XSS:** Legacy admin UI lacks modern CSRF; keep admins off untrusted links; monitor upgrades of third-party JS.
5. **Fixtures:** Never mount `fixtures/` or set `EFACLOUD_PORTAL_FIXTURES=1` on production.
6. **Backups / CapRover:** Application volumes and DB are outside CapRover’s own backup — practice restore separately (plan step 5 ops).

## Regression suite

```bash
./tests/security/run.sh          # includes request_is_https matrix
./tests/portal/run.sh
./scripts/https-cookie-drill.sh  # local CapRover-style TLS proxy → Secure cookie
```
