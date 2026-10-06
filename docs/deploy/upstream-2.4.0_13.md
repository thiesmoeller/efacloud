# Upstream efaCloud 2.4.0_13

Integrated on 2026-10-05 from the official release published 2026-05-20.
The upstream GitHub master/tag still contains 2.4.0_12, so this update uses the
official distribution rather than an unrelated development branch.

- Release feed: https://efacloud.org/src/scanversions.php?own=2.4.0_12
- Release notes: https://efacloud.org/src/2.4.0_13/release_notes.html
- Archive: https://efacloud.org/src/2.4.0_13/efacloud_server.zip
- Archive SHA-256: `bbaac354c4f63a5b227d3adc39a18dd3e4f5dfc7b22c810162b3e97942506291`
- Comparison baseline: upstream commit `d56d35599e055fcd295f9e488a1e05b931d9d955`
  (tag `v2.4.0_12`).

The update includes upstream fixes #118–119, translation updates, longer logbook
names in configuration forms, and corrected message archiving. The database
layout remains V12; no schema migration is introduced by this release.

Upstream changes were merged against the 2.4.0_12 baseline. CapRover/Docker
packaging, the portal, installer locks, SQL/upload hardening, exact-password
login, administrator recovery, and private logbook access are retained. The
maintenance-page merge keeps HTML escaping while adopting upstream translations.

Persistent config volumes need the updated `layouts/configparameter_aendern`
and `lists/efaArchive` definitions. Startup replaces these files only if their
SHA-256 matches the old bundled default (or the file is missing). Customized
definitions are preserved with a log message for manual review. Tenant settings,
database credentials, and accounts are not overwritten.

Deploy through the fork's versioned image pipeline. Do not use the upstream
in-app code updater: it would overwrite fork-specific code.
