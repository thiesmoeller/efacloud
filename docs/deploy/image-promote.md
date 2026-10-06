# Versioned image promote (GHCR → CapRover staging → production)

**Production is not released.** Prefer an immutable GHCR tag (or digest) so
staging sign-off and production deploy use the **same** bytes.

Registry: `ghcr.io/thiesmoeller/efacloud`

Images are **multi-arch** (`linux/amd64` + `linux/arm64`) so CapRover hosts of
either architecture pull the matching variant. An amd64-only image on arm64
CapRover fails at start with `exec format error` on the entrypoint.

## Tags published by Actions

Workflow: `.github/workflows/docker-image.yml`

On push to `main` / `feature/caprover` (and `workflow_dispatch`):

| Tag | Example | Use |
|-----|---------|-----|
| `sha-<short>` | `ghcr.io/thiesmoeller/efacloud:sha-a1b2c3d` | **Preferred** CapRover deploy / promote |
| `sha-<full>` | `ghcr.io/thiesmoeller/efacloud:sha-<40-char>` | Audit / exact commit pin |
| branch name | `ghcr.io/thiesmoeller/efacloud:feature-caprover` | Moving tip only — **do not** promote to prod |

The workflow builds a multi-arch manifest list, writes the registry digest to the
GitHub Actions **job summary**, then deploys
`ghcr.io/thiesmoeller/efacloud:sha-<short>` to CapRover staging via **App Token**
(`caprover/deploy-from-github`).

### GitHub Actions secrets (staging auto-deploy)

In the GitHub repo → **Settings → Secrets and variables → Actions**, add:

| Secret | Value |
|--------|--------|
| `CAPROVER_SERVER` | `https://captain.project-park.de` |
| `CAPROVER_APP` | `efacloud-staging` |
| `CAPROVER_APP_TOKEN` | CapRover → `efacloud-staging` → Deployment → Enable App Token → copy |

Never commit the token. CapRover must be able to **pull** from GHCR (package
visibility public, or CapRover Registries logged into `ghcr.io` with a PAT that
can read `ghcr.io/thiesmoeller/efacloud`).

```bash
# optional CLI equivalent:
gh secret set CAPROVER_SERVER -b 'https://captain.project-park.de'
gh secret set CAPROVER_APP -b 'efacloud-staging'
gh secret set CAPROVER_APP_TOKEN  # paste token at prompt
```

## Copy digest from Actions → CapRover (manual fallback)

1. Open the successful **Docker image** run → Job summary.
2. Copy either:
   - `ghcr.io/thiesmoeller/efacloud:sha-<short>`, or
   - the full digest ref `ghcr.io/thiesmoeller/efacloud@sha256:…`
3. CapRover → staging web app (`efacloud-staging`) → **Deployment** →
   **Method 1: Deploy via ImageName** → paste that reference → deploy.
4. Run staging gates (`docs/deploy/acceptance-checklist.md` rows tagged
   `staging` / `ops`). Confirm HTTPS health:
   `/portal/`, `/api/portal/v1/session`, `/forms/login.php`.
5. **Promote:** set production (`efacloud`) to the **identical** tag or digest.
   Do not rebuild from a branch tip between staging sign-off and prod.
6. Record the digest in the cutover checklist
   (`docs/deploy/cutover-runbook.md`).

### CapRover CLI prerequisite (manual / bootstrap scripts)

```bash
caprover login
# URL: https://captain.project-park.de
# Machine: project-park
```

## One-click template

`caprover-one-click.yml` defaults the web image toward GHCR. For promote, replace
the one-click / App Config image with a specific `sha-…` tag from Actions (not
the branch tag alone).

If GHCR is private to the org/user, configure CapRover registry credentials for
`ghcr.io` in CapRover settings (token out of repo).

## Offline / local digest (no GHCR push yet)

```bash
./scripts/print-local-image-digest.sh
```

Prints local `image_id` (+ `repo_digest` only after a registry push). Use this
for packaging evidence; CapRover staging still needs a pullable GHCR ref once
login works.

## Rollback

Redeploy the previous known-good `sha-*` tag (or digest) on the CapRover app.
Post-cutover rollback also needs trip reconciliation — see
`docs/deploy/cutover-runbook.md`.
