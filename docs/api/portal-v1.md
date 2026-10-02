# Portal API v1 — OpenAPI-ish contract

Base URL (same-origin): `/api/portal/v1/`  
Consumers: dockside React PWA at `/portal/` (step 4).  
Desktop sync stays on `/api/posttx.php` (unchanged).

Content-Type: `application/json; charset=utf-8`  
Time zone for defaults: `Europe/Berlin`. UI language: German error messages.

## Auth model

| Mechanism | Detail |
| --- | --- |
| Session cookie | Name `EFA_PORTAL`. `HttpOnly`, `SameSite=Strict`, `Secure` when HTTPS (including CapRover TLS termination via `X-Forwarded-Proto: https`). |
| CSRF | After login, `GET /session` returns `csrfToken`. Send on **all mutating** requests as header `X-CSRF-Token` (or body `csrfToken`). |
| Credentials | Browser: `credentials: 'include'` / `fetch(..., { credentials: 'include' })`. |
| Login throttle | ≤5 failures / 15 min per account+IP → `429 LOGIN_THROTTLED`. |
| Revoked accounts | `Rolle=anonymous`, deleted, or empty/`-` password hash → rejected. |
| Password reset | **Admin-assisted only** in v1. See `GET /config` → `passwordReset` workflow and `POST /admin/password-reset` (Rolle `admin`). Desk alternative: `forms/nutzer_aendern.php`. No self-service endpoint. Docs: `docs/ops/admin-password-reset.md`. |

Unauthenticated mutating calls → `401 AUTH_REQUIRED`. Missing/bad CSRF → `403 CSRF_INVALID`.

## Error envelope

```json
{
  "error": {
    "code": "ACK_REQUIRED",
    "message": "Bestätigung erforderlich bevor die Fahrt gestartet werden kann.",
    "details": { }
  }
}
```

| Code | HTTP | Meaning |
| --- | --- | --- |
| `AUTH_REQUIRED` | 401 | Not logged in |
| `ACCOUNT_REVOKED` | 403 | Disabled account |
| `CSRF_INVALID` | 403 | Bad/missing CSRF |
| `FORBIDDEN` | 403 | Permission denied |
| `NOT_FOUND` | 404 | Resource missing |
| `LOGIN_THROTTLED` | 429 | Too many login attempts |
| `STALE_STATE` | 409 | ChangeCount / LastModified conflict |
| `ACK_REQUIRED` | 409 | Departure override needed |
| `ACK_STALE` | 409 | Ack snapshot no longer matches |
| `BOAT_ON_WATER` | 409 | Hard fail: boat already out |
| `CHANGECOUNT_REQUIRED` | 422 | Optimistic lock field missing |
| `DESTINATION_REQUIRED` / `DISTANCE_REQUIRED` / … | 422 | Validation |

## Trainer privilege

Not a Rolle. Stored as **Concession** bit `portalTrainer` = **131072** on `efaCloudUsers.Concessions`.

Admins grant it in the user admin UI (Concession „Portal: manage any crew trips“) or by OR-ing `131072` into `Concessions`. Does **not** grant admin/repair workflows.

Fixture user **102** has `TrainerPrivilege: true` / `Concessions: 131072`.

- Members: manage trips where their `PersonId` is cox or crew.
- Trainers: manage any crew’s trips.
- `admin` / `board` / `bths`: also allowed (desk accounts).

## Idempotency & stale state

Mutating trip bodies may include:

```json
{
  "idempotencyKey": "client-uuid-1",
  "expectedChangeCount": 3
}
```

(`changeCount` accepted as alias of `expectedChangeCount`.)

Replaying the same `idempotencyKey` for the same user returns the cached success payload with `_idempotentReplay: true`. Mismatched `expectedChangeCount` → `STALE_STATE` (no silent overwrite).

## Acknowledgment flow (departure overrides)

EFA order: **on-water hard fail → not-available override → reservation override → damage warn**.

1. Client calls `POST /trips` (or correct with boat change).
2. If overrides needed → `409 ACK_REQUIRED` with `details.checks[]` each containing `kind`, `snapshotHash`, dialog copy.
3. For each required kind, client confirms and calls:

`POST /acknowledgments`

```json
{ "kind": "status_notavailable" | "reservation" | "damage", "boatId": "…" }
```

Response: `{ "token", "kind", "boatId", "snapshotHash" }`.

4. Retry trip mutation with `"acknowledgmentTokens": ["ack_…", …]` (or objects `{ "token": "…" }`).
5. Server re-hashes current conditions; mismatch → `ACK_STALE` (renew confirmation).

Damage warn policy follows club `InputWarnOnlyCriticalBoatDamages` (false → do **not** warn `FULLYUSEABLE`). Open `FULLYUSEABLE` damages are still **listed** (desktop; not efaWeb hide).

---

## Endpoints

### Session

#### `POST /session/login`

```json
{ "account": "101", "password": "…" }
```

→ `{ authenticated, user, csrfToken, privileges }`

#### `POST /session/logout`

Requires CSRF if authenticated.

#### `GET /session`

→ `{ authenticated, user?, csrfToken, privileges? }`

---

### Lookups

#### `GET /boats`

Query: `view=available|onwater|unavailable`, `seatCategory=1|2|…`, `search=…`

→ `{ boats: [ variant+status+damage+badges… ], seatCategories: […] }`

Seat categories use `getGeneralNumberOfSeatsType` (`4X`→Vierer). Name sort uses umlaut-folding (desktop), not ICU.

#### `GET /boats/{boatId}`

→ boat, variants, status, openDamages, reservations in look-ahead, damage summary.

#### `GET /boats/{boatId}/damages`

Query: `onlyOpen=0` to include fixed.

#### `POST /boats/{boatId}/damages`

```json
{
  "severity": "NOTUSEABLE|LIMITEDUSEABLE|FULLYUSEABLE",
  "description": "…",
  "logbookText": "optional"
}
```

→ damage record. **Does not** finish/abort any trip (`tripAborted: false`).

#### `GET /persons?search=`

Scoped to currently valid persons.

#### `GET /destinations`

#### `GET /config`

Boathouse-relevant club keys + trainer grant help + password-reset workflow + current logbook name.

`passwordReset` includes `mode`, `message`, `deskForm`, `apiEndpoint`, and ordered `steps` for administrators.

#### `POST /admin/password-reset`

Requires Rolle **admin** + CSRF. Sets a new password hash for an existing efaCloud user.

```json
{
  "account": "101",
  "newPassword": "Mindestens8!",
  "confirmPassword": "Mindestens8!"
}
```

`account` may be efaCloudUserID, E-Mail, or Kontoname. Min length 8. Members/trainers receive `403 FORBIDDEN`. No self-service in v1.

---

### Trips

Current logbook is server-selected (year / club config).

#### `POST /trips` — start

```json
{
  "boatId": "…",
  "boatVariant": "1",
  "crew": [ { "id": "…" }, … ],
  "coxId": "…",
  "boatCaptain": "1",
  "destinationId": "…",
  "acknowledgmentTokens": [ "ack_…" ],
  "idempotencyKey": "…"
}
```

Does **not** force `BoatCaptain=1`.

#### `GET /trips/{entryId}`

Permission-scoped.

#### `PATCH /trips/{entryId}` — correct open trip

Requires `expectedChangeCount`. Boat change re-runs departure checks.

#### `POST /trips/{entryId}/finish`

Requires `expectedChangeCount`; distance required unless club allows empty (`MustEnterDistance` semantics).

#### `POST /trips/{entryId}/abort`

```json
{
  "expectedChangeCount": 2,
  "withDamage": { "severity": "LIMITEDUSEABLE", "description": "…" }
}
```

Abort **deletes** the open logbook entry (trip never happened). Optional `withDamage` reports damage **before** delete (desktop abort-with-damage order).

---

### Acknowledgments

#### `POST /acknowledgments`

See flow above.

---

## Apache / install notes

See `docs/api/portal-apache.md`. After install completes, `/api/portal/` must be reachable; frontend will later live at `/portal/`. Image already `COPY api` and `COPY classes`.
