# Go-live path — dockside PWA (ops)

**Production is not released** until CapRover staging, device QA, and cutover gates pass.
Software/local RC is ready.

## Ops pause (2026-10-02)

CapRover live mutations are **paused pending explicit approval**. Do not run
`wait-caprover-then-bootstrap.sh`, `caprover-login-and-bootstrap.sh`, or
`caprover-staging-bootstrap.sh --auto-create` against `captain-project-park`
unless the operator confirms.

Partial state already on CapRover (may be cleaned up or finished later):

- Apps registered: `efacloud-staging`, `efacloud-staging-db`
- CapRover **admin password was reset** via official SSH recovery during auth
  unblock — **change it in CapRover Settings → change password** and discard
  `/tmp/caprover-password` if present
- Full local-tree tarball deploy + HTTPS health gates: **not completed**

Secrets for the staging apps (if kept): `var/caprover-staging-secrets.env`
(gitignored).

## 0. Preconditions (local — already proven)

```bash
cd thiesmoeller-efacloud
./scripts/release-candidate-local.sh --full
```

Expect OVERALL PASS (security, portal, npm, compose, restore drill, live synch/E2E when stack up).

## 1. CapRover login (when ops resume)

```bash
caprover login
# URL:  https://captain.project-park.de
# name: captain-project-park
```

Or non-interactive (password never printed):

```bash
export CAPROVER_PASSWORD='…'   # or: echo '…' > /tmp/caprover-password
./scripts/caprover-login-and-bootstrap.sh
```

Optional poller (only after approval — deploys automatically on login):

```bash
./scripts/wait-caprover-then-bootstrap.sh
```

## 2. Staging provision + deploy

```bash
./scripts/caprover-staging-bootstrap.sh --auto-create
```

This will:

1. Create `efacloud-staging` + `efacloud-staging-db` (API) if missing  
2. Write one-time secrets to `var/caprover-staging-secrets.env` (gitignored)  
3. Deploy the **local working tree** (tarball) — includes uncommitted portal/API fixes  
4. Probe `https://efacloud-staging.project-park.de` (`/portal/`, session, login, `/install/` → 403)

Enable Force HTTPS in CapRover if not already set by the script.

## 3. Staging data rehearsal

On staging (after install completes):

1. Create bths sync user + portal trainer (see `docs/deploy/desktop-synch-lab.md`)  
2. Run desktop SynchControl upload (lab script or noVNC) against the staging URL  
3. One command (health + Secure cookie + API smoke + Chrome E2E):

```bash
./scripts/caprover-staging-release-gates.sh --with-damage --with-ack
```

Or manually: `portal-live-synch-smoke.sh --remote --skip-ensure-user` and
`portal-browser-e2e.sh --remote --skip-ensure-user` with
`PORTAL_LIVE_BASE_URL=https://efacloud-staging.project-park.de`

## 4. Device QA

- Install PWA on **iOS Safari** and **Android Chrome**  
- Offline banner, SW update, logout, touch ACK dialogs  

## 5. Image promote

Prefer digest from CI (`.github/workflows/docker-image.yml`) or:

```bash
./scripts/print-local-image-digest.sh
```

Same digest → staging sign-off → production (`docs/deploy/image-promote.md`).

## 6. Cutover

Follow `docs/deploy/cutover-runbook.md` (freeze → fresh desktop backup → activate → verify → enable desk/PWA).  
Rollback + trip reconciliation required. Do not mark production released until `acceptance-checklist.md` release-blocking rows are Pass.

## Script index

| Script | Purpose |
|--------|---------|
| `release-candidate-local.sh` | Local gates |
| `wait-caprover-then-bootstrap.sh` | Poll login → staging |
| `caprover-staging-bootstrap.sh` | Create/deploy staging |
| `desktop-synch-lab.sh` | Real SynchControl |
| `portal-live-synch-smoke.sh` | Portal on synced DB |
| `portal-browser-e2e.sh` | Chrome E2E |
| `portal-desktop-conflict-race.sh` | Cross-client race |
| `https-cookie-drill.sh` | Secure cookie behind proxy |
| `compose-backup-restore-drill.sh` | DB+files restore |
| `verify-image-contents.sh` | Image packaging checks |
