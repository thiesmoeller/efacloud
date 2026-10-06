# Packaging exclusions

The Docker image must contain only runtime application inputs.

`.dockerignore` (and the explicit `COPY` list in `Dockerfile`) keep out:

- Secrets and local env (`.env`, credential files)
- Generated DB settings and installer locks
- Local lab/demo/fixtures and club backup zips
- Frontend `node_modules` and local `portal/dist` (PWA is built in a multi-stage
  Node image; only `portal/dist` lands at `/var/www/html/portal`)
- Git metadata, caches, tests, and agent/editor scratch

Rationale: CapRover builds from the build context; anything left in context can
leak into production images. Prefer explicit `COPY` paths plus ignore rules.
Portal source is allowed in the build context for the Node stage only.

## Verify script

After any packaging change, run:

```bash
./scripts/verify-image-contents.sh
# or reuse a tag: VERIFY_IMAGE_BUILD=0 ./scripts/verify-image-contents.sh efacloud:local
```

Asserts the image includes:

- `classes/efa_boat_concurrency_guard.php` (wired from `efa_api`)
- `portal` in `$tfyh_public_dirs` (`classes/tfyh_audit.php`)
- `Portal_session::request_is_https` + `X-Forwarded-Proto` trust
- Built `/portal/` assets and portal API (incl. admin password-reset route)

And excludes fixtures, `portal/node_modules`, `.env`, and `settings_db`.
