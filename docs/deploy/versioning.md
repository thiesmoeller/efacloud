# Versioning (upstream efaCloud vs club fork)

## Files

| File | Role |
|------|------|
| `public/version` | **Upstream** efaCloud id last integrated (e.g. `2.4.0_13`). Used for feed checks and numeric `app_info` fields. |
| `public/fork_version` | **Club fork** build id (e.g. `wrc.1`). Bump on fork releases independent of upstream. |

Sidebar / footer display (via `Efa_version::display_string()`):

```text
efaCloud 2.4.0_13 · wrc.1
```

## When to bump

| Event | Change |
|-------|--------|
| Club UI / Portal / CapRover / menus | bump `public/fork_version` (`wrc.N`) |
| Merge a new upstream drop | set `public/version` to that upstream id; document under `docs/deploy/upstream-*.md` |
| Deploy staging/prod | CapRover image tag `sha-*` / digest (source of truth for “what’s running”) |

## Update notice

Admin home compares **upstream** `public/version` to https://efacloud.org/src/scanversions.php.

- If the feed has a newer upstream → notice to integrate via **git + image pipeline**.
- The in-app zip page `pages/ec_upgrade.php` is **disabled** for this fork (would overwrite Portal/CapRover code).

## Optional GHCR convenience tag

`ghcr.io/thiesmoeller/efacloud:2.4.0_13-wrc.1` — still promote by digest / `sha-*`.

## Support (fork, not upstream)

In-app **Support / Feedback** (`forms/support.php`) emails the club-fork
maintainer (`Efa_fork` → Thies Möller / `thies.moeller@gmail.com`). It does
**not** POST to `efacloud.org/support/`. Help pages link to the same address and
to GitHub issues on `thiesmoeller/efacloud`.
