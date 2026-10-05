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

### Locked-out administrator: one-time server recovery

`EFACLOUD_ADMIN_*` creates the initial administrator only. Do not delete the
installation markers or rerun the installer to reset a password: the installer
can rebuild the database. Passwords are not synchronized on container restart.

An operator with SSH/Docker access can reset an existing administrator:

```bash
docker exec -it <web-container> php /usr/local/bin/efacloud-recover-admin --id 1142
```

The command prompts twice without echoing the password. Use 12–72 bytes and
store the password in your password manager. An optional `--name clubadmin`
also corrects the login name. It refuses missing/non-admin IDs and duplicate
login names; it preserves roles, other account fields, and other users.

For automation, `--password-stdin` reads the exact bytes from standard input
(no trailing newline). Do not put passwords in command arguments or shell
history. The command is installed outside the web root and never runs during
startup. Remove bootstrap password environment variables after setup/recovery
once credentials are safely recorded. A second named administrator also provides
an application-level recovery route.

Recovery changes credentials; it does not revoke already-active sessions.
Treat suspected account compromise separately from a forgotten password.

Do not enable fixture mode (`EFACLOUD_PORTAL_FIXTURES`) in production; password
hashes must live in the real `efaCloudUsers` table.
