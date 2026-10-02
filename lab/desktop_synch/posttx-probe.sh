#!/bin/sh
# posttx auth probe from the desktop container (API v3 NOP).
# Desktop image is JRE-only — use precompiled PosttxProbe.class (mounted from lab).
set -eu

cloud_url="${EFA_CLOUD_URL:-http://web/}"
cloud_user="${EFA_CLOUD_USERNAME:-901}"
cloud_pass="${EFA_CLOUD_PASSWORD:-RehearseSync1!}"
case "$cloud_url" in
  */) ;;
  *) cloud_url="${cloud_url}/" ;;
esac
posttx_url="${cloud_url}api/posttx.php"
class_dir="${EFA_POSTTX_PROBE_CLASSPATH:-/opt/efa-synch-lab/bin}"

echo "posttx probe → $posttx_url (user=$cloud_user)"

if [ ! -f "$class_dir/PosttxProbe.class" ]; then
  echo "FAIL: missing $class_dir/PosttxProbe.class (mount lab/desktop_synch/bin)" >&2
  exit 4
fi

java -cp "$class_dir" PosttxProbe "$posttx_url" "$cloud_user" "$cloud_pass"
