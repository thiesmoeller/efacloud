#!/usr/bin/env bash
# CapRover staging bootstrap for efacloud-staging.
# Fail-closed on expired/invalid CapRover auth — never invents CapRover passwords.
# Default deploy: local working tree (tarball), NOT git branch (avoids stale origin).
# Does NOT create or deploy the production app.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

MACHINE="${CAPROVER_NAME:-project-park}"
APP="${CAPROVER_APP:-efacloud-staging}"
DB_APP="${APP}-db"
ONE_CLICK="${ROOT}/caprover-one-click.yml"
# Empty = deploy from cwd (tarball). Set via --branch for git-archive remote-style deploy.
BRANCH="${CAPROVER_BRANCH:-}"
STAGING_BASE_URL="${EFACLOUD_STAGING_BASE_URL:-}"
SKIP_DEPLOY=0
SKIP_HEALTH=0
AUTO_CREATE=0
CONFIRM_LIVE=0
# CapRover project folder (optional). Empty = leave existing projectId.
PROJECT_ID="${CAPROVER_PROJECT_ID:-c0af3dec-e050-4830-a7ef-6a9c3a8471a7}"
SECRETS_FILE="${ROOT}/var/caprover-staging-secrets.env"
DEPLOY_TAR=""

usage() {
  cat <<EOF
Usage: ./scripts/caprover-staging-bootstrap.sh [options]

  Provision / deploy CapRover staging only (${APP} + ${DB_APP}).

Options:
  --machine NAME     CapRover CLI machine name (default: ${MACHINE})
  --app NAME         Staging web app name (default: ${APP})
  --branch NAME      Deploy via git archive of NAME (committed tree only).
                     Default is local-dir deploy (cwd tarball + captain-definition).
  --auto-create      If staging apps are missing, register/update them via CapRover
                     API (MariaDB 11.4 + web volumes/env), write secrets once, then
                     deploy. Without this flag, missing apps → one-click steps (exit 3).
                     Requires --confirm-live.
  --confirm-live     Required for any CapRover mutation (auto-create and/or deploy).
                     Auth probes (--skip-deploy --skip-health) do not need this.
  --project-id UUID  CapRover project id (default: efacloud project on project-park)
  --base-url URL     HTTPS base for health probes (default: derived from CapRover)
  --skip-deploy      Auth + app presence only; do not run caprover deploy
  --skip-health      Skip post-deploy HTTPS health curls
  -h, --help         Show this help

Auth must already be valid via \`caprover login\`. This script never prompts for
or invents CapRover passwords. Exit 2 = re-login required.
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --machine) MACHINE="${2:-}"; shift 2 ;;
    --app) APP="${2:-}"; DB_APP="${APP}-db"; shift 2 ;;
    --branch) BRANCH="${2:-}"; shift 2 ;;
    --base-url) STAGING_BASE_URL="${2:-}"; shift 2 ;;
    --project-id) PROJECT_ID="${2:-}"; shift 2 ;;
    --auto-create) AUTO_CREATE=1; shift ;;
    --confirm-live) CONFIRM_LIVE=1; shift ;;
    --skip-deploy) SKIP_DEPLOY=1; shift ;;
    --skip-health) SKIP_HEALTH=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) echo "Unknown argument: $1" >&2; usage >&2; exit 2 ;;
  esac
done

require_confirm_live() {
  if [[ ${CONFIRM_LIVE} -ne 1 ]]; then
    die "Refusing CapRover mutation without --confirm-live (operator approval)."
  fi
}

die() { echo "ERROR: $*" >&2; exit 1; }

print_relogin() {
  cat <<EOF

============================================================
CapRover auth invalid / expired for machine: ${MACHINE}
============================================================

Re-login (interactive — do not invent or commit passwords):

  caprover login
  # CapRover URL: https://captain.project-park.de
  # Machine name: ${MACHINE}
  # Enter the real CapRover password when prompted

Then re-run:

  ./scripts/caprover-staging-bootstrap.sh

Until login succeeds, staging provision / deploy is blocked.
EOF
}

require_cmd() {
  command -v "$1" >/dev/null 2>&1 || die "missing command: $1"
}

require_cmd caprover
require_cmd curl
require_cmd python3
require_cmd tar
require_cmd openssl

cleanup() {
  if [[ -n "${DEPLOY_TAR}" && -f "${DEPLOY_TAR}" ]]; then
    rm -f "${DEPLOY_TAR}"
  fi
}
trap cleanup EXIT

# --- Auth probe via caprover api (cheap GET); never prompt for password ---
probe_auth() {
  local out
  # Always pass -d '{}' — CapRover CLI otherwise prompts for "API data JSON"
  # and hangs. Close stdin + timeout for expired-token password prompts.
  set +e
  out="$(timeout 25 caprover api \
    -n "${MACHINE}" \
    -t /user/system/info \
    -m GET \
    -d '{}' \
    -o true </dev/null 2>&1)"
  local ec=$?
  set -e

  if echo "${out}" | grep -qiE \
    'Invalid credentials|not valid anymore|Auth token is no longer valid|Auth token corrupted|"status"[[:space:]]*:[[:space:]]*1106|Please enter password|CapRover machine password'; then
    echo "${out}" | grep -viE 'ExitPromptError|^[[:space:]]*at |Node\.js|create-prompt|signal-exit|^[[:space:]]*\\^$' \
      | sed '/^[[:space:]]*$/d' | sed -n '1,12p' >&2 || true
    echo "Detected: Invalid credentials / expired CapRover auth token." >&2
    print_relogin
    exit 2
  fi

  if echo "${out}" | grep -qiE "Can't find stored machine|no url or machine name"; then
    echo "${out}" | sed -n '1,20p' >&2
    print_relogin
    exit 2
  fi

  # -o true returns the data object (rootDomain / hasRootSsl) without status:100 wrapper.
  if echo "${out}" | grep -qE 'API call completed successfully|"rootDomain"|"hasRootSsl"|"status"[[:space:]]*:[[:space:]]*100'; then
    echo "Auth OK for ${MACHINE} (caprover api GET /user/system/info)."
    return 0
  fi

  # timeout / ExitPromptError / unknown failure → treat as auth blocked
  if [[ ${ec} -eq 124 ]] || echo "${out}" | grep -qiE 'ExitPromptError|User force closed|API data JSON'; then
    echo "${out}" | sed -n '1,20p' >&2
    print_relogin
    exit 2
  fi

  echo "Unexpected CapRover API probe response (exit ${ec}):" >&2
  echo "${out}" | sed -n '1,40p' >&2
  print_relogin
  exit 2
}

print_one_click_steps() {
  cat <<EOF

------------------------------------------------------------
Staging apps missing — create via CapRover one-click (preferred)
------------------------------------------------------------

App names (staging only — do NOT auto-create production):
  web: ${APP}
  db:  ${DB_APP}

1. Open CapRover UI → Apps → One Click Apps/Databases → TEMPLATE
2. Paste contents of:
     ${ONE_CLICK}
3. Set app name to: ${APP}
   (creates ${APP} + ${APP}-db)
4. Review generated passwords; store admin/DB passwords outside the repo
5. Deploy the one-click template
6. Enable HTTPS / Force HTTPS on ${APP}
7. Re-run this script to deploy from the local working tree:

     ./scripts/caprover-staging-bootstrap.sh

Or provision via API then deploy local-dir:

     ./scripts/caprover-staging-bootstrap.sh --auto-create

Manual CLI deploy after apps exist (local cwd tarball — default):

  # script builds a tarball of the working tree (includes uncommitted changes)
  ./scripts/caprover-staging-bootstrap.sh

Optional remote-git deploy (committed tree only — stale if unpushed):

  ./scripts/caprover-staging-bootstrap.sh --branch ${BRANCH:-feature/caprover}
EOF
}

caprover_token() {
  MACHINE="${MACHINE}" python3 - <<'PY'
import json, os
from pathlib import Path
cfg = Path.home() / ".config/configstore/caprover.json"
machine = os.environ["MACHINE"]
data = json.loads(cfg.read_text())
for m in data.get("CapMachines") or []:
    if m.get("name") == machine:
        tok = m.get("authToken") or ""
        if not tok:
            raise SystemExit("empty authToken — re-login required")
        print(tok)
        raise SystemExit(0)
raise SystemExit(f"machine not found: {machine}")
PY
}

caprover_base_url() {
  MACHINE="${MACHINE}" python3 - <<'PY'
import json, os
from pathlib import Path
cfg = Path.home() / ".config/configstore/caprover.json"
machine = os.environ["MACHINE"]
data = json.loads(cfg.read_text())
for m in data.get("CapMachines") or []:
    if m.get("name") == machine:
        print((m.get("baseUrl") or "").rstrip("/"))
        raise SystemExit(0)
raise SystemExit(f"machine not found: {machine}")
PY
}

# Prefer curl over `caprover api -d` — CLI JSON arg parsing breaks on larger payloads.
# data may be a JSON string, or @/path/to/file.json
caprover_api() {
  local path="$1"
  local method="$2"
  # Avoid ${3:-{}} — bash treats the closing brace as outside the default word.
  local data="{}"
  if [[ $# -ge 3 ]]; then
    data="$3"
  fi
  local base token url tmp_body out http_code own_tmp=0
  base="$(caprover_base_url)"
  token="$(caprover_token)"
  if [[ "${path}" != /api/* ]]; then
    url="${base}/api/v2${path}"
  else
    url="${base}${path}"
  fi

  if [[ "${data}" == @* ]]; then
    tmp_body="${data#@}"
    [[ -f "${tmp_body}" ]] || { echo "missing body file: ${tmp_body}" >&2; return 1; }
  else
    tmp_body="$(mktemp /tmp/cr-api-body.XXXXXX.json)"
    own_tmp=1
    printf '%s' "${data}" > "${tmp_body}"
  fi

  # Validate JSON body for mutating methods
  if [[ "${method}" != "GET" && "${method}" != "HEAD" ]]; then
    if ! python3 -c 'import json,sys; json.load(open(sys.argv[1]))' "${tmp_body}" 2>/dev/null; then
      echo "CapRover API ${method} ${path}: body is not valid JSON ($(wc -c <"${tmp_body}") bytes)" >&2
      head -c 200 "${tmp_body}" >&2; echo >&2
      [[ ${own_tmp} -eq 1 ]] && rm -f "${tmp_body}"
      return 1
    fi
  fi

  set +e
  if [[ "${method}" == "GET" || "${method}" == "HEAD" ]]; then
    out="$(curl -skS -X "${method}" "${url}" \
      -H "x-captain-auth: ${token}" \
      -H "x-namespace: captain" \
      -w '\nHTTP_CODE:%{http_code}' 2>&1)"
  else
    out="$(curl -skS -X "${method}" "${url}" \
      -H "x-captain-auth: ${token}" \
      -H "Content-Type: application/json" \
      -H "x-namespace: captain" \
      --data-binary @"${tmp_body}" \
      -w '\nHTTP_CODE:%{http_code}' 2>&1)"
  fi
  local ec=$?
  set -e
  [[ ${own_tmp} -eq 1 ]] && rm -f "${tmp_body}"

  http_code="$(printf '%s' "${out}" | sed -n 's/^HTTP_CODE://p' | tail -1)"
  out="$(printf '%s' "${out}" | sed '/^HTTP_CODE:/d')"
  printf '%s\n' "${out}"

  if echo "${out}" | grep -qiE \
    'Invalid credentials|not valid anymore|Auth token is no longer valid|Auth token corrupted|"status"[[:space:]]*:[[:space:]]*1106'; then
    print_relogin
    exit 2
  fi
  if echo "${out}" | grep -qE '"status"[[:space:]]*:[[:space:]]*100'; then
    return 0
  fi
  if [[ ${ec} -ne 0 || "${http_code}" != "200" ]] \
    || echo "${out}" | grep -qiE '"status"[[:space:]]*:[[:space:]]*11'; then
    echo "CapRover API ${method} ${path} failed (curl exit ${ec}, http ${http_code}):" >&2
    echo "${out}" | sed -n '1,40p' >&2
    return 1
  fi
  return 0
}

list_apps_json() {
  # Return shape compatible with apps_contain / update_app_merged (-o true style).
  local out
  out="$(caprover_api /user/apps/appDefinitions GET '{}')" || return 1
  printf '%s' "${out}" | python3 -c '
import json,sys
raw=sys.stdin.read()
start=raw.find("{")
data=json.loads(raw[start:])
# unwrap {status,data:{appDefinitions}} → {appDefinitions}
apps=(data.get("data") or {}).get("appDefinitions")
if apps is None and "appDefinitions" in data:
    json.dump(data, sys.stdout)
else:
    json.dump({"appDefinitions": apps or []}, sys.stdout)
'
}

apps_contain() {
  local needle="$1"
  local blob="$2"
  printf "%s" "${blob}" | python3 -c '
import json, sys
needle = sys.argv[1]
raw = sys.stdin.read()
# CapRover CLI may print banners before JSON
start = raw.find("{")
if start < 0:
    sys.exit(1)
try:
    data = json.loads(raw[start:])
except json.JSONDecodeError:
    sys.exit(1)
# -o true → top-level appDefinitions; without -o → data.appDefinitions
apps = data.get("appDefinitions") or (data.get("data") or {}).get("appDefinitions") or []
names = {a.get("appName") for a in apps if isinstance(a, dict)}
sys.exit(0 if needle in names else 1)
' "${needle}"
}

rand_hex() {
  local nbytes="$1"
  openssl rand -hex "${nbytes}"
}

write_secrets_file() {
  local db_pass="$1"
  local db_root="$2"
  local admin_pass="$3"
  mkdir -p "$(dirname "${SECRETS_FILE}")"
  umask 077
  cat > "${SECRETS_FILE}" <<EOF
# Generated by caprover-staging-bootstrap.sh — DO NOT COMMIT
# mode 600; path is gitignored under var/
EFACLOUD_DB_NAME=efacloud
EFACLOUD_DB_USER=efacloud
EFACLOUD_DB_PASSWORD=${db_pass}
EFACLOUD_DB_ROOT_PASSWORD=${db_root}
EFACLOUD_ADMIN_FIRST=Club
EFACLOUD_ADMIN_LAST=Admin
EFACLOUD_ADMIN_EMAIL=admin@your-club.example
EFACLOUD_ADMIN_ID=1142
EFACLOUD_ADMIN_NAME=clubadmin
EFACLOUD_ADMIN_PASSWORD=${admin_pass}
EFACLOUD_DB_HOST=srv-captain--${DB_APP}
EOF
  chmod 600 "${SECRETS_FILE}"
  echo
  echo "Wrote staging secrets (once) to ${SECRETS_FILE} (mode 600)."
  echo "Secrets are NOT echoed; open that file offline (do not commit)."
  echo
}

# Fetch app def, merge patch fields, POST update (missing fields reset on CapRover).
update_app_merged() {
  local app_name="$1"
  local patch_json="$2"
  local apps_blob payload_file
  apps_blob="$(list_apps_json)" || true
  payload_file="$(mktemp /tmp/cr-update-payload.XXXXXX.json)"
  printf '%s' "${apps_blob}" | APP_NAME="${app_name}" PATCH_JSON="${patch_json}" OUT_FILE="${payload_file}" python3 -c '
import json, os, sys

app_name = os.environ["APP_NAME"]
patch = json.loads(os.environ["PATCH_JSON"])
out_file = os.environ["OUT_FILE"]
raw = sys.stdin.read()
start = raw.find("{")
if start < 0:
    sys.stderr.write("no JSON in apps list\n")
    sys.exit(1)
data = json.loads(raw[start:])
apps = data.get("appDefinitions") or (data.get("data") or {}).get("appDefinitions") or []
app = next((a for a in apps if isinstance(a, dict) and a.get("appName") == app_name), None)
if app is None:
    sys.stderr.write(f"app not found after register: {app_name}\n")
    sys.exit(1)

payload = dict(app)
payload["appName"] = app_name
if payload.get("instanceCount") is None:
    payload["instanceCount"] = 1
payload.update(patch)
if "instanceCount" not in payload or payload["instanceCount"] is None:
    payload["instanceCount"] = 1

for k in (
    "isAppBuilding",
    "networks",
    "appId",
    "hasDefaultSubDomainSsl",
    "hasPersistentData",
    "deployedVersion",
    "versions",
    "appNameOnHost",
    "currentGitHash",
    "isHttpAuthProtected",
):
    payload.pop(k, None)

with open(out_file, "w", encoding="utf-8") as f:
    json.dump(payload, f, separators=(",", ":"))
'
  echo "Updating app definition: ${app_name} (body $(wc -c <"${payload_file}") bytes)"
  caprover_api "/user/apps/appDefinitions/update" POST "@${payload_file}" >/dev/null
  rm -f "${payload_file}"
}

deploy_image() {
  local app_name="$1"
  local image="$2"
  local body_file
  body_file="$(mktemp /tmp/cr-deploy-image.XXXXXX.json)"
  python3 -c '
import json, sys
json.dump({
  "captainDefinitionContent": json.dumps({"schemaVersion": 2, "imageName": sys.argv[1]}),
  "gitHash": ""
}, open(sys.argv[2], "w"), separators=(",", ":"))
' "${image}" "${body_file}"
  echo "Deploying image ${image} → ${app_name}"
  caprover_api "/user/apps/appData/${app_name}?detached=1" POST "@${body_file}" >/dev/null
  rm -f "${body_file}"
}

auto_create_apps() {
  local base_url db_pass db_root admin_pass
  base_url="$(derive_base_url)"

  if [[ -f "${SECRETS_FILE}" ]]; then
    echo "Reusing existing secrets from ${SECRETS_FILE}"
    # shellcheck disable=SC1090
    set -a; source "${SECRETS_FILE}"; set +a
    db_pass="${EFACLOUD_DB_PASSWORD}"
    db_root="${EFACLOUD_DB_ROOT_PASSWORD}"
    admin_pass="${EFACLOUD_ADMIN_PASSWORD}"
    [[ -n "${db_pass}" && -n "${db_root}" && -n "${admin_pass}" ]] \
      || die "incomplete secrets in ${SECRETS_FILE}"
  else
    db_pass="$(rand_hex 16)"
    db_root="$(rand_hex 16)"
    admin_pass="$(rand_hex 12)"
    echo "== --auto-create: provisioning ${DB_APP} + ${APP} =="
    write_secrets_file "${db_pass}" "${db_root}" "${admin_pass}"
  fi

  echo "== --auto-create: provisioning ${DB_APP} + ${APP} =="

  if [[ ${HAVE_DB} -ne 1 ]]; then
    echo "Registering ${DB_APP} (persistent, notExposeAsWebApp)..."
    # Write JSON to a temp file — CapRover CLI -d can SyntaxError on some shells'
    # nested quoting when JSON is inlined.
    local reg_json
    reg_json="$(mktemp /tmp/cr-register.XXXXXX.json)"
    python3 -c 'import json,sys; json.dump({"appName":sys.argv[1],"hasPersistentData":True}, open(sys.argv[2],"w"))' \
      "${DB_APP}" "${reg_json}"
    caprover_api "/user/apps/appDefinitions/register" POST "$(cat "${reg_json}")" >/dev/null \
      || die "register ${DB_APP} failed"
    rm -f "${reg_json}"
    HAVE_DB=1
  else
    echo "DB app ${DB_APP} already present."
  fi

  update_app_merged "${DB_APP}" "$(python3 -c '
import json, sys
app, db_pass, db_root, project_id = sys.argv[1], sys.argv[2], sys.argv[3], sys.argv[4]
patch = {
  "instanceCount": 1,
  "notExposeAsWebApp": True,
  "forceSsl": False,
  "volumes": [{"volumeName": app + "-data", "containerPath": "/var/lib/mysql"}],
  "ports": [],
  "envVars": [
    {"key": "MARIADB_DATABASE", "value": "efacloud"},
    {"key": "MARIADB_USER", "value": "efacloud"},
    {"key": "MARIADB_PASSWORD", "value": db_pass},
    {"key": "MARIADB_ROOT_PASSWORD", "value": db_root},
    {"key": "MYSQL_DATABASE", "value": "efacloud"},
    {"key": "MYSQL_USER", "value": "efacloud"},
    {"key": "MYSQL_PASSWORD", "value": db_pass},
    {"key": "MYSQL_ROOT_PASSWORD", "value": db_root},
  ],
}
if project_id:
  patch["projectId"] = project_id
print(json.dumps(patch))
' "${DB_APP}" "${db_pass}" "${db_root}" "${PROJECT_ID}")"

  deploy_image "${DB_APP}" "mariadb:11.4"

  if [[ ${HAVE_WEB} -ne 1 ]]; then
    echo "Registering ${APP} (persistent volumes)..."
    local reg_json_web
    reg_json_web="$(mktemp /tmp/cr-register.XXXXXX.json)"
    python3 -c 'import json,sys; json.dump({"appName":sys.argv[1],"hasPersistentData":True}, open(sys.argv[2],"w"))' \
      "${APP}" "${reg_json_web}"
    caprover_api "/user/apps/appDefinitions/register" POST "$(cat "${reg_json_web}")" >/dev/null \
      || die "register ${APP} failed"
    rm -f "${reg_json_web}"
    HAVE_WEB=1
  else
    echo "Web app ${APP} already present."
  fi

  update_app_merged "${APP}" "$(python3 -c '
import json, sys
app, db_app, base_url, db_pass, admin_pass, project_id = sys.argv[1:7]
vols = []
for name, path in [
  ("config", "/var/www/html/config"),
  ("log", "/var/www/html/log"),
  ("uploads", "/var/www/html/uploads"),
  ("attachements", "/var/www/html/attachements"),
  ("pdfs", "/var/www/html/pdfs"),
  ("resources", "/var/www/html/resources"),
]:
  vols.append({"volumeName": f"{app}-{name}", "containerPath": path})
patch = {
  "instanceCount": 1,
  "notExposeAsWebApp": False,
  "forceSsl": False,
  "containerHttpPort": 80,
  "volumes": vols,
  "ports": [],
  "envVars": [
    {"key": "TZ", "value": "Europe/Berlin"},
    {"key": "EFACLOUD_AUTO_INSTALL", "value": "1"},
    {"key": "EFACLOUD_BASE_URL", "value": base_url},
    {"key": "EFACLOUD_DB_HOST", "value": f"srv-captain--{db_app}"},
    {"key": "EFACLOUD_DB_NAME", "value": "efacloud"},
    {"key": "EFACLOUD_DB_USER", "value": "efacloud"},
    {"key": "EFACLOUD_DB_PASSWORD", "value": db_pass},
    {"key": "EFACLOUD_ADMIN_FIRST", "value": "Club"},
    {"key": "EFACLOUD_ADMIN_LAST", "value": "Admin"},
    {"key": "EFACLOUD_ADMIN_EMAIL", "value": "admin@your-club.example"},
    {"key": "EFACLOUD_ADMIN_ID", "value": "1142"},
    {"key": "EFACLOUD_ADMIN_NAME", "value": "clubadmin"},
    {"key": "EFACLOUD_ADMIN_PASSWORD", "value": admin_pass},
  ],
}
if project_id:
  patch["projectId"] = project_id
print(json.dumps(patch))
' "${APP}" "${DB_APP}" "${base_url}" "${db_pass}" "${admin_pass}" "${PROJECT_ID}")"

  echo "Enabling HTTPS for base domain on ${APP} (if API allows)..."
  set +e
  ssl_out="$(caprover_api "/user/apps/appDefinitions/enablebasedomainssl" POST \
    "$(python3 -c 'import json,sys; print(json.dumps({"appName":sys.argv[1]}))' "${APP}")" 2>&1)"
  ssl_ec=$?
  set -e
  if [[ ${ssl_ec} -eq 0 ]]; then
    echo "enablebasedomainssl: OK"
    # Now Force HTTPS is allowed
    update_app_merged "${APP}" '{"forceSsl":true,"instanceCount":1}'
    echo "forceSsl: enabled"
  else
    echo "enablebasedomainssl skipped/failed — enable SSL in CapRover UI, then Force HTTPS:" >&2
    echo "${ssl_out}" | sed -n '1,15p' >&2 || true
  fi

  echo "Auto-create complete for ${APP} + ${DB_APP}."
}

build_local_tar() {
  # CapRover CLI requires -b | -t | -i. Local-dir mode packs the working tree
  # (tracked + untracked non-ignored), including uncommitted changes — unlike
  # `git archive` / `caprover deploy -b` which only ships committed blobs.
  DEPLOY_TAR="$(mktemp /tmp/efacloud-staging-deploy.XXXXXX.tar)"
  echo "Building local deploy tarball (cwd working tree) → ${DEPLOY_TAR}"
  # Prefer git-aware file list so we skip .gitignored junk; fall back to tar excludes.
  if git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    # Include tracked + untracked (exclude-standard). tar reads working-tree bytes.
    if ! git ls-files -z --cached --others --exclude-standard \
      | tar --null -T - -cf "${DEPLOY_TAR}" 2>/dev/null; then
      # Older tar without --null
      git ls-files --cached --others --exclude-standard \
        | tar -T - -cf "${DEPLOY_TAR}"
    fi
  else
    tar -cf "${DEPLOY_TAR}" \
      --exclude='.git' \
      --exclude='node_modules' \
      --exclude='portal/node_modules' \
      --exclude='portal/e2e/node_modules' \
      --exclude='portal/dist' \
      --exclude='var' \
      --exclude='.env' \
      --exclude='*.zip' \
      .
  fi
  if [[ ! -s "${DEPLOY_TAR}" ]]; then
    die "deploy tarball empty — cannot deploy local directory"
  fi
  # CapRover default path is ./captain-definition (no extension).
  # Avoid `tar | grep -q` under pipefail — early grep exit SIGPIPEs tar → false fail.
  if ! tar -tf "${DEPLOY_TAR}" | grep -E '(^|/)captain-definition(\.json)?$' >/dev/null; then
    echo "Tar contents (head):" >&2
    tar -tf "${DEPLOY_TAR}" | head -40 >&2 || true
    die "tarball missing captain-definition (or captain-definition.json)"
  fi
}

deploy_app() {
  if [[ -n "${BRANCH}" ]]; then
    echo "Deploying ${APP} from git branch ${BRANCH} (committed tree only)..."
    caprover deploy \
      -n "${MACHINE}" \
      -a "${APP}" \
      -b "${BRANCH}"
    return
  fi

  build_local_tar
  echo "Deploying ${APP} from local directory (tarball; includes uncommitted work)..."
  echo "  (CapRover CLI needs -t for non-interactive cwd deploy; -b would ship stale commits.)"
  caprover deploy \
    -n "${MACHINE}" \
    -a "${APP}" \
    -t "${DEPLOY_TAR}"
}

derive_base_url() {
  if [[ -n "${STAGING_BASE_URL}" ]]; then
    echo "${STAGING_BASE_URL%/}"
    return
  fi
  MACHINE="${MACHINE}" APP="${APP}" python3 - <<'PY'
import json, os
from pathlib import Path
cfg = Path.home() / ".config/configstore/caprover.json"
machine = os.environ.get("MACHINE", "captain-project-park")
app = os.environ.get("APP", "efacloud-staging")
base = ""
if cfg.is_file():
    data = json.loads(cfg.read_text())
    for m in data.get("CapMachines") or []:
        if m.get("name") == machine:
            base = (m.get("baseUrl") or "").rstrip("/")
            break
# https://captain.example.com → https://app.example.com
if base.startswith("https://captain."):
    root = base[len("https://captain."):]
    print(f"https://{app}.{root}")
elif base.startswith("http://captain."):
    root = base[len("http://captain."):]
    print(f"https://{app}.{root}")
else:
    print(f"https://{app}.project-park.de")
PY
}

health_probe() {
  local base="$1"
  local path="$2"
  local expect="$3"
  local url="${base}${path}"
  local code
  set +e
  code="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 30 -L "${url}")"
  local ec=$?
  set -e
  if [[ ${ec} -ne 0 ]]; then
    echo "FAIL  ${path}  (curl exit ${ec})"
    return 1
  fi
  if [[ "${code}" == "${expect}" ]]; then
    echo "OK    ${path}  → ${code}"
    return 0
  fi
  echo "FAIL  ${path}  → ${code} (expected ${expect})"
  return 1
}

echo "== CapRover staging bootstrap =="
echo "ROOT=${ROOT}"
if [[ -n "${BRANCH}" ]]; then
  echo "MACHINE=${MACHINE} APP=${APP} DB_APP=${DB_APP} DEPLOY=git-branch:${BRANCH} AUTO_CREATE=${AUTO_CREATE}"
else
  echo "MACHINE=${MACHINE} APP=${APP} DB_APP=${DB_APP} DEPLOY=local-dir(cwd-tarball) AUTO_CREATE=${AUTO_CREATE}"
fi
date -u +'%Y-%m-%dT%H:%M:%SZ'

if [[ ! -f "${ONE_CLICK}" ]]; then
  die "missing one-click template: ${ONE_CLICK}"
fi
if [[ ! -f "${ROOT}/captain-definition.json" && ! -f "${ROOT}/captain-definition" ]]; then
  die "missing captain-definition.json (required for caprover deploy)"
fi
if [[ ! -f "${ROOT}/captain-definition" && -f "${ROOT}/captain-definition.json" ]]; then
  cp -a "${ROOT}/captain-definition.json" "${ROOT}/captain-definition"
fi

# Export for python helper
export MACHINE APP

probe_auth

echo "Listing CapRover apps..."
APPS_OUT="$(list_apps_json)" || true

if echo "${APPS_OUT}" | grep -qiE \
  'Invalid credentials|not valid anymore|Auth token is no longer valid|"status"[[:space:]]*:[[:space:]]*1106|Please enter password'; then
  echo "${APPS_OUT}" | sed -n '1,20p' >&2
  print_relogin
  exit 2
fi

HAVE_WEB=0
HAVE_DB=0
if apps_contain "${APP}" "${APPS_OUT}"; then HAVE_WEB=1; fi
if apps_contain "${DB_APP}" "${APPS_OUT}"; then HAVE_DB=1; fi

echo "App ${APP}: $([[ ${HAVE_WEB} -eq 1 ]] && echo present || echo MISSING)"
echo "App ${DB_APP}: $([[ ${HAVE_DB} -eq 1 ]] && echo present || echo MISSING)"

if [[ ${HAVE_WEB} -ne 1 || ${HAVE_DB} -ne 1 ]]; then
  if [[ ${AUTO_CREATE} -eq 1 ]]; then
    require_confirm_live
    auto_create_apps
  else
    print_one_click_steps
    exit 3
  fi
elif [[ ${AUTO_CREATE} -eq 1 ]]; then
  # Apps registered but may still need MariaDB image / env / volumes.
  echo "Apps already registered — re-running auto-create to apply env/volumes/images."
  require_confirm_live
  auto_create_apps
fi

if [[ ${SKIP_DEPLOY} -eq 1 ]]; then
  echo "Skipping deploy (--skip-deploy)."
else
  require_confirm_live
  deploy_app
fi

if [[ ${SKIP_HEALTH} -eq 1 ]]; then
  echo "Skipping health probes (--skip-health)."
  exit 0
fi

BASE="$(derive_base_url)"
echo "Health probes against ${BASE}"
FAILS=0
health_probe "${BASE}" "/portal/" "200" || FAILS=$((FAILS + 1))
health_probe "${BASE}" "/api/portal/v1/session" "200" || FAILS=$((FAILS + 1))
health_probe "${BASE}" "/forms/login.php" "200" || FAILS=$((FAILS + 1))
# After successful install, /install/ must be denied.
health_probe "${BASE}" "/install/" "403" || FAILS=$((FAILS + 1))

if [[ ${FAILS} -gt 0 ]]; then
  echo
  echo "Health: ${FAILS} probe(s) failed."
  echo "If install is still running, wait for EFACLOUD_AUTO_INSTALL then re-check."
  echo "Override URL with: EFACLOUD_STAGING_BASE_URL=https://... ./scripts/caprover-staging-bootstrap.sh --skip-deploy"
  exit 4
fi

echo
echo "Staging bootstrap complete for ${APP}."
echo "Production app was NOT created or deployed."
