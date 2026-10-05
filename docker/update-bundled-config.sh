#!/bin/sh
# Upgrade unchanged vendor definitions on persistent config volumes.
set -eu
config_dir="${1:-/var/www/html/config}"
defaults="${2:-/opt/efacloud-defaults/config}"
manifest="${3:-/opt/efacloud-config-baselines.sha256}"
while read -r previous_hash relative; do
    target="$config_dir/$relative"
    source="$defaults/$relative"
    if [ ! -f "$source" ]; then
        echo "efacloud-config: missing bundled definition $relative" >&2
        exit 1
    fi
    if [ -f "$target" ]; then
        current_hash="$(sha256sum "$target" | cut -d ' ' -f 1)"
        latest_hash="$(sha256sum "$source" | cut -d ' ' -f 1)"
        if [ "$current_hash" = "$latest_hash" ]; then
            continue
        fi
        if [ "$current_hash" != "$previous_hash" ]; then
            echo "efacloud-config: preserving customized $relative; review upstream changes manually"
            continue
        fi
    fi
    mkdir -p "$(dirname "$target")"
    cp "$source" "$target"
    echo "efacloud-config: updated $relative"
done < "$manifest"
