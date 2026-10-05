#!/bin/sh
set -eu
root="$(CDPATH= cd -- "$(dirname "$0")/../.." && pwd)"
scratch="$(mktemp -d)"
trap 'rm -rf "$scratch"' EXIT
mkdir -p "$scratch/config/layouts" "$scratch/defaults/layouts"
printf 'old definition\n' > "$scratch/old"
printf '%s  layouts/example\n' "$(sha256sum "$scratch/old" | cut -d ' ' -f 1)" > "$scratch/manifest"
printf 'new definition\n' > "$scratch/defaults/layouts/example"
cp "$scratch/old" "$scratch/config/layouts/example"
run() { sh "$root/docker/update-bundled-config.sh" "$scratch/config" "$scratch/defaults" "$scratch/manifest"; }
run
cmp "$scratch/config/layouts/example" "$scratch/defaults/layouts/example"
echo 'PASS old vendor definition upgraded'
test -z "$(run)"
echo 'PASS repeated startup is idempotent'
printf 'custom definition\n' > "$scratch/config/layouts/example"
run
test "$(cat "$scratch/config/layouts/example")" = 'custom definition'
echo 'PASS customized definition preserved'
rm "$scratch/config/layouts/example"
run
cmp "$scratch/config/layouts/example" "$scratch/defaults/layouts/example"
echo 'PASS missing definition restored'
