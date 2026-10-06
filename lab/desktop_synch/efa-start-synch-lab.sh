#!/bin/sh
# Lab entrypoint: prepare backup, retarget project to lab efaCloud, then start desktop.
# Full SYNCH_UPLOAD_ALL: ./scripts/desktop-synch-lab.sh --upload-only
# (lab/desktop_synch/gui-synch-upload-all.sh — xdotool against efaBootshaus).
set -eu

export HOME="${EFA_HOME:-/home/efa}"
export DISPLAY="${DISPLAY:-:99}"
user_dir="${EFA_USER_DIR:-$HOME/efa2}"
cloud_url="${EFA_CLOUD_URL:-http://web/}"
cloud_user="${EFA_CLOUD_USERNAME:-901}"
cloud_pass="${EFA_CLOUD_PASSWORD:-RehearseSync1!}"

# Ensure trailing slash (EFA / TxRequestQueue expect a directory-style base URL).
case "$cloud_url" in
  */) ;;
  *) cloud_url="${cloud_url}/" ;;
esac

echo "=== efa desktop synch lab ==="
echo "EFA_CLOUD_URL=$cloud_url user=$cloud_user"

efa-prepare-backup

project="$(find "$user_dir/data" -maxdepth 1 -name '*.efa2project' | head -1 || true)"
if [ -z "$project" ] || [ ! -f "$project" ]; then
  echo "No *.efa2project under $user_dir/data after prepare-backup" >&2
  exit 1
fi

echo "Retargeting project for lab synch: $project"
# Backup already ships StorageType=file/efaCloud; rewrite URL + numeric client id + password.
# Plaintext password is accepted by DataTypePasswordCrypted on read (re-encrypted on save).
tmp="$(mktemp)"
# shellcheck disable=SC2016
awk -v url="$cloud_url" -v user="$cloud_user" -v pass="$cloud_pass" '
  {
    if ($0 ~ /<StorageType>/) {
      print "      <StorageType>file/efaCloud</StorageType>"
      next
    }
    if ($0 ~ /<StorageUsername>/) {
      print "      <StorageUsername>" user "</StorageUsername>"
      next
    }
    if ($0 ~ /<StoragePassword>/) {
      print "      <StoragePassword>" pass "</StoragePassword>"
      next
    }
    if ($0 ~ /<EfaCloudURL>/) {
      print "      <EfaCloudURL>" url "</EfaCloudURL>"
      next
    }
    print
  }
' "$project" > "$tmp"
mv "$tmp" "$project"

echo "Patched project cloud fields:"
grep -E '<StorageType>|<StorageUsername>|<EfaCloudURL>' "$project" || true

# Reachability probe (desktop container → web /api/posttx.php) before GUI starts.
if [ -x /usr/local/bin/efa-posttx-probe ] || [ -f /usr/local/bin/efa-posttx-probe ]; then
  sh /usr/local/bin/efa-posttx-probe || {
    echo "WARN: posttx probe failed (web may still be installing). Continuing to desktop." >&2
  }
fi

exec efa-start-desktop
