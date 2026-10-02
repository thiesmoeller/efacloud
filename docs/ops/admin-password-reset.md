# Admin-assisted password reset (v1)

Portal members cannot reset their own password. Administrators set a new
password via the portal API or the existing efaCloud desk user form.

## Who can reset

- Rolle **`admin`** only (portal `privileges.isAdmin` / `Portal_permissions::is_admin`).
- Trainers and ordinary members cannot reset others' passwords.

## Portal API

```http
POST /api/portal/v1/admin/password-reset
Cookie: EFA_PORTAL=…
X-CSRF-Token: …
Content-Type: application/json

{
  "account": "101",
  "newPassword": "Mindestens8!",
  "confirmPassword": "Mindestens8!"
}
```

- `account`: efaCloudUserID, E-Mail, or Kontoname.
- New password ≥ 8 characters.
- Success: `{ "ok": true, "efaCloudUserID": …, "message": "…" }`.
- Non-admin: `403 FORBIDDEN`.

Discover the workflow without calling it: `GET /api/portal/v1/config` →
`passwordReset.steps`.

## Desk UI (existing)

1. Sign in as admin on the efaCloud desk.
2. Open user administration → **Nutzer ändern** (`forms/nutzer_aendern.php`).
3. Set a new password / clear permanent password per form fields.
4. Communicate the new password out-of-band; member signs in at `/portal/`.

## PWA

Login screen states that reset is admin-only. No self-service link in v1
(the anonymous desk `forms/reset_password.php` is not wired into the portal).

## Ops note

Do not enable fixture mode (`EFACLOUD_PORTAL_FIXTURES`) in production; password
hashes must live in the real `efaCloudUsers` table.
