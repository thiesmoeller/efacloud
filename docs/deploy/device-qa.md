# Device QA — dockside PWA

Physical-device gates for plan §5 / `acceptance-checklist.md`. Host Chrome E2E
(`./scripts/portal-browser-e2e.sh`) is **not** a substitute.

**Staging URL (when CapRover staging is live):**  
`https://efacloud-staging.project-park.de/portal/`

**Local lab URL (SynchControl stack):**  
`http://127.0.0.1:18083/portal/` — same workflows; installability/SW may differ on HTTP.

## Accounts

Use the staging (or lab) portal trainer account from ops notes — not fixture users.
Never enable `EFACLOUD_PORTAL_FIXTURES` on CapRover.

## Checklist (release-blocking)

| # | Check | iOS Safari | Android Chrome |
|---|--------|------------|----------------|
| 1 | Open `/portal/`, login, German UI | | |
| 2 | Add to Home Screen / install PWA | | |
| 3 | Cold start from home-screen icon | | |
| 4 | Boat list + seat chips; open boat with damage badge | | |
| 5 | Start trip → Auf Fahrt → finish | | |
| 6 | ACK dialog on unavailable boat (touch) | | |
| 7 | Report damage (three severity labels) | | |
| 8 | Offline: banner; mutation does not falsely succeed | | |
| 9 | Logout | | |
| 10 | Service-worker update banner (after a new deploy) | | |
| 11 | Session cookie Secure on HTTPS staging | | |

Record date, OS/browser versions, and failures in
`docs/plans/production-dockside-pwa-progress.md` (Step 5 device section).

## After device Pass

Continue `docs/deploy/GO_LIVE.md` § image promote + cutover. Production stays
unreleased until acceptance-checklist CapRover + device + cutover rows are Pass.
