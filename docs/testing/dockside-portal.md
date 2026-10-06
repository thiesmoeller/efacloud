# Dockside portal local validation

Validated locally on 2026-10-06. No staging or production deployment performed.

| Check | Evidence / scope |
| --- | --- |
| TypeScript and production bundle | `cd portal && npm run build` |
| Frontend unit checks | `npm test`: seat filters, ordered acknowledgments, duplicate taps, frozen retry payload/key, service-worker activation and network-only API behavior |
| Existing PHP suite | `tests/portal/run.php`: 60 checks, including authentication, warnings, cancellation rollback and desktop-derived defaults |
| Dockside PHP acceptance | `tests/portal/dockside.php`: three boats without organizer aboard, ownership and account isolation, independent correction/return/cancel, desktop closure, logbook rollover, replacement identity, attribution rollback, midnight, short-trip offsets, cox variants and captain validation |
| MariaDB integration | `tests/portal/database.sh`: real Portal_db_store with a small SQL socket adapter; durable reconnect/replay, current desktop data, stale rejection, competing SQL status write lock, failed-write rollback including attribution, and successful atomic return |
| Mobile browser contracts | `portal/e2e/dockside.mjs`: Chrome emulating Pixel 7 and iPhone 13; three checkouts, lost response and exact retry, offline disabling, dialog Escape/Tab focus wrapping, preserved crew, correction with a desktop free-text destination, stale return, resume refresh, account switch and horizontal overflow |
| Live API and desktop protocol | `bash tests/portal/live.sh`: built PWA, actual PHP socket/audit hooks and disposable MariaDB; real posttx checkout conflict, separate desktop trip/status upload, desktop return/edit, account isolation, lost-response replay, correction and cancellation with damage |
| Concurrent desktop write | The live suite pauses checkout before commit and submits a desktop status update; the shared advisory lock makes desktop validation wait and reject the stale update |
| Live mobile browser | The same live suite runs Chrome with Pixel 7 and iPhone 13 profiles; authenticated organizer starts three distinct crews without being aboard, retries a committed checkout after a lost response, and returns each boat |
| Visual review | Phone home screenshots from browser checks: light surfaces, navy text, teal primary actions, explicit boat status, numbered crew, individual return |

The live HTTP suite exercises the actual socket and audit hooks; the smaller SQL adapter suite isolates atomic rollback behavior. Desktop behavior was also checked against `EfaBaseFrame.java` for five-minute rounding, short-trip time clamping, captain selection and cancellation semantics. Desktop write validation and persistence now share a connection-scoped advisory lock with portal transactions. Desktop permissions and protocol remain unchanged. A checkout also checks open logbook rows, so the desktop’s separate status upload cannot leave a duplicate-checkout window.

Before staging acceptance, perform an installed-app pass on physical iOS Safari and Android Chrome (installation, safe areas, keyboard, app resume, connectivity interruption and an update arriving during a form). Run VoiceOver/TalkBack and keyboard checks, including focus visibility and the confirmation dialogs, on those devices. Verify a full Java desktop synchronization round trip and delayed/offline synchronization conflict reconciliation against a disposable club-data copy. Chrome's iPhone viewport emulation is not a Safari/WebKit or screen-reader certification.

Accessibility design reference: [WCAG 2.2 new criteria](https://www.w3.org/WAI/standards-guidelines/wcag/new-in-22/), especially focus not obscured and target size. Native modal dialogs constrain focus; inputs have labels; sticky primary actions become static while form fields have focus.

Schema, backup and compatibility details are in [the API contract](../api/portal-v1.md#portal-schema-and-operations). Legacy unattributed trips are intentionally not migrated into personal lists.
