# Staging review — 2026-10-05

## Findings and repair

- Database administrator ID 1142 still had the installation-default account
  name, while the deployment environment specified a different name/password.
  Bootstrap variables do not reconcile existing accounts.
- The legacy login applied password-creation complexity rules before checking
  the stored hash. Form processing also modified password punctuation/spaces.
- The public information authorization check treated any configured public flag
  as permission, even when its value was empty/off. The public logbook exposed
  trip/reservation sections without authentication.
- Legacy audit-generated `.htaccess` files contain invalid deny directives,
  causing HTTP 500 rather than a clean access denial on protected paths.

The login now verifies exact credentials without imposing creation rules.
Historical public logbook/information URLs require authentication, including
with old persisted menus/settings. Apache access rules take precedence over
legacy `.htaccess` files; routing overrides remain enabled for the portal/API.

Administrator ID 1142 was recovered once, using the already-configured username
and password. Other accounts and logbook data were preserved. A CLI-only recovery
command is included; no automatic environment-to-account synchronization runs.
See [recovery instructions](../ops/admin-password-reset.md).

## Validation

- Security suite: 176 passed (25 failures reproduced before the fixes).
- Existing portal suite: 57 passed.
- Recovery integration tests on disposable MariaDB: 11 passed, covering reset,
  identity collisions, invalid credentials, ambiguous IDs, and other users.
- Live classic and portal logins succeeded.
- Anonymous logbook and direct information requests returned 303 to login;
  authenticated requests returned their expected content.
- Installer, configuration, and internal class URLs returned 403.

## Deployment limitation

CapRover built `img-captain-efacloud-staging:6`, but its push step failed with
`invalid reference format`. Deploying that local image by name through CapRover
also failed because it tried to pull it from a registry.

The single-node staging service was therefore updated directly with Docker.
The final image, including the Apache correction, is
`efacloud-staging-reviewed:20261005`, image ID
`sha256:71e8b0976b01a3d81d70d09350f532d345228bd323a8da45665e3fea25bc27bf`.
CapRover's displayed deployment metadata still reflects the old release.

**Before the next CapRover deployment, publish these source changes to a versioned
release image and repair the registry configuration.** Redeploying the old
release would discard the code fixes. Normal restarts of the current Docker
service retain the repaired image.

Private server-side backups of configuration, user table, and previous service
specification are under `/root/efacloud-staging-review-20261005`. They contain
sensitive data and must remain private. No production service was changed.
