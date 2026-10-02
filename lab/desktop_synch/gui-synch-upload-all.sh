#!/bin/sh
# Drive efaBootshaus GUI → RQ_QUEUE_START_SYNCH_UPLOAD_ALL via xdotool on DISPLAY=:99.
# Intended to run inside the efa-desktop container (lab only).
# Requires: xdotool, imagemagick (import), optionally tesseract-ocr.
set -eu

export DISPLAY="${DISPLAY:-:99}"
ACCESS_FILE="${EFA_TEST_ACCESS_FILE:-/fixtures/.access}"
TIMEOUT_SEC="${SYNCH_UPLOAD_TIMEOUT:-600}"
LOG="${EFA_HOME:-/home/efa}/efa2/log/efaCloud"
SHOT_DIR="${SYNCH_SHOT_DIR:-/tmp/synch-upload-shots}"

need() {
  command -v "$1" >/dev/null 2>&1 || {
    echo "missing required tool: $1" >&2
    exit 1
  }
}

need xdotool
need import

mkdir -p "$SHOT_DIR"

shot() {
  import -window root "$SHOT_DIR/$1.png" 2>/dev/null || true
}

win_by_name() {
  xdotool search --name "$1" 2>/dev/null | head -1
}

wait_win() {
  name="$1"
  secs="${2:-60}"
  i=0
  while [ "$i" -lt "$secs" ]; do
    id=$(win_by_name "$name" || true)
    if [ -n "${id:-}" ]; then
      echo "$id"
      return 0
    fi
    i=$((i + 1))
    sleep 1
  done
  echo "timeout waiting for window: $name" >&2
  return 1
}

dismiss_language() {
  id=$(win_by_name "Select Langua" || true)
  [ -z "${id:-}" ] && id=$(win_by_name "Select Language" || true)
  if [ -n "${id:-}" ]; then
    echo "Dismissing language dialog"
    xdotool windowactivate --sync "$id" || true
    sleep 0.2
    xdotool key alt+o
    sleep 1
    xdotool key Return || true
    sleep 2
  fi
}

read_admin_creds() {
  if [ ! -f "$ACCESS_FILE" ]; then
    echo "No access file at $ACCESS_FILE (expected user//password)" >&2
    exit 1
  fi
  # Format: admin//password  (first line)
  line=$(head -1 "$ACCESS_FILE" | tr -d '\r')
  ADMIN_USER="${line%%//*}"
  ADMIN_PASS="${line#*//}"
  if [ -z "$ADMIN_USER" ] || [ -z "$ADMIN_PASS" ] || [ "$ADMIN_USER" = "$line" ]; then
    echo "Could not parse admin credentials from $ACCESS_FILE" >&2
    exit 1
  fi
  echo "Admin user from access file: $ADMIN_USER"
}

boathouse_title() {
  xdotool search --name "Bootshaus" getwindowname 2>/dev/null | head -1 || true
}

wait_boathouse_ready() {
  secs="${1:-180}"
  i=0
  echo "Waiting for efaBootshaus (WORKING/IDLE)…"
  while [ "$i" -lt "$secs" ]; do
    dismiss_language
    title=$(boathouse_title)
    case "$title" in
      *"✔"*|*"⇔"*|*"#"*|*"⟳"*)
        echo "Boathouse: $title"
        return 0
        ;;
    esac
    i=$((i + 1))
    sleep 1
  done
  echo "timeout waiting for boathouse" >&2
  shot "fail-no-boathouse"
  return 1
}

ocr_click_label() {
  # Best-effort: tesseract TSV → click first match of regex
  label_re="$1"
  tmp="/tmp/ocr-synch"
  import -window root /tmp/ocr-synch.png
  if ! command -v tesseract >/dev/null 2>&1; then
    return 1
  fi
  tesseract /tmp/ocr-synch.png "$tmp" -c tessedit_create_tsv=1 >/dev/null 2>&1 || return 1
  # Find first word matching; click mid of its box
  # TSV: level ... left top width height ... text
  awk -v re="$label_re" '
    BEGIN { IGNORECASE=1 }
    $1==5 && $NF ~ re {
      x=$7+($9/2); y=$8+($10/2);
      printf "%d %d\n", x, y; exit
    }
  ' "$tmp.tsv"
}

click_xy() {
  x="$1"
  y="$2"
  echo "Click ($x,$y)"
  xdotool mousemove "$x" "$y" click 1
}

admin_login() {
  read_admin_creds
  bwid=$(wait_win "Bootshaus" 30)
  xdotool windowactivate --sync "$bwid"
  sleep 0.3

  # Prefer OCR for Admin Mode; fallback known lab layout ~620,494 @ 1280x900
  coords=$(ocr_click_label "Admin" || true)
  if [ -n "${coords:-}" ]; then
    # Prefer the "Mode" line if present; else first Admin hit
    mode=$(ocr_click_label "Mode" || true)
    if [ -n "${mode:-}" ]; then
      set -- $mode
    else
      set -- $coords
    fi
    click_xy "$1" "$2"
  else
    click_xy 620 494
  fi
  sleep 1.5

  lwid=$(wait_win "Admin Login" 15)
  xdotool windowactivate --sync "$lwid"
  sleep 0.3
  # Name field then password (Enter submits)
  geom=$(xdotool getwindowgeometry --shell "$lwid")
  # shellcheck disable=SC2086
  eval $geom
  # Relative to dialog: name mid-right, then tab to password
  nx=$((X + WIDTH * 2 / 3))
  ny=$((Y + HEIGHT * 45 / 100))
  click_xy "$nx" "$ny"
  sleep 0.2
  xdotool key ctrl+a
  xdotool type --delay 40 "$ADMIN_USER"
  xdotool key Tab
  sleep 0.2
  xdotool type --delay 40 "$ADMIN_PASS"
  xdotool key Return
  sleep 2
  wait_win "Admin Mode" 20 >/dev/null
  echo "Admin Mode open"
  shot "admin-mode"
}

open_efacloud_and_upload() {
  awid=$(wait_win "Admin Mode" 10)
  xdotool windowactivate --sync "$awid"
  sleep 0.3

  coords=$(ocr_click_label "efaCloud" || true)
  if [ -n "${coords:-}" ]; then
    set -- $coords
    click_xy "$1" "$2"
  else
    # File column, below Projects/Logbooks — lab layout ~300,334
    click_xy 300 334
  fi
  sleep 2

  cwid=$(wait_win "Configure efaCloud" 20)
  xdotool windowactivate --sync "$cwid"
  sleep 0.3
  shot "efacloud-config"

  coords=$(ocr_click_label "upload" || true)
  if [ -z "${coords:-}" ]; then
    coords=$(ocr_click_label "synchronisation" || true)
  fi
  if [ -n "${coords:-}" ]; then
    set -- $coords
    click_xy "$1" "$2"
  else
    # Upload button mid dialog — lab layout ~650,498
    click_xy 650 498
  fi
  echo "Triggered Upload Synchronisation (RQ_QUEUE_START_SYNCH_UPLOAD_ALL)"
  shot "upload-clicked"
}

wait_upload_idle() {
  secs="$TIMEOUT_SEC"
  i=0
  echo "Waiting up to ${secs}s for sync idle (✔)…"
  saw_spin=0
  while [ "$i" -lt "$secs" ]; do
    title=$(boathouse_title)
    case "$title" in
      *"⟳"*) saw_spin=1 ;;
      *"✔"*)
        if [ "$saw_spin" -eq 1 ] || grep -q "Synchronisation client to server (upload) starting" \
          "$LOG"/*/efacloud.log 2>/dev/null; then
          # Prefer log completion if available
          if grep -q "Synchronization transactions completed" "$LOG"/*/efacloud.log 2>/dev/null; then
            echo "Upload idle: $title"
            return 0
          fi
          # checkmark after we saw spinner is enough
          if [ "$saw_spin" -eq 1 ]; then
            echo "Upload idle: $title"
            return 0
          fi
        fi
        ;;
    esac
    # Log-based completion
    if grep -q "Synchronisation client to server (upload) starting" "$LOG"/*/efacloud.log 2>/dev/null \
      && grep -q "Synchronization transactions completed" "$LOG"/*/efacloud.log 2>/dev/null; then
      # ensure last state is WORKING after upload start
      if [ "$saw_spin" -eq 1 ] || echo "$title" | grep -q "✔"; then
        echo "Upload complete per efacloud.log; title=$title"
        return 0
      fi
    fi
    i=$((i + 5))
    sleep 5
  done
  echo "timeout waiting for upload completion" >&2
  shot "fail-timeout"
  boathouse_title || true
  return 1
}

echo "=== gui-synch-upload-all (DISPLAY=$DISPLAY) ==="
# Ensure tools present (lab image may lack them on first run)
if ! command -v xdotool >/dev/null 2>&1; then
  export DEBIAN_FRONTEND=noninteractive
  apt-get update -qq
  apt-get install -y -qq xdotool imagemagick tesseract-ocr >/dev/null
fi

dismiss_language
wait_boathouse_ready 180
# Wait for WORKING/IDLE (auth done) — title ✔ or ⇔ without needing upload yet
i=0
while [ "$i" -lt 120 ]; do
  title=$(boathouse_title)
  case "$title" in
    *"✔"*|*"⇔"*) break ;;
    *"⟳"*) : ;; # still busy (e.g. initial download)
  esac
  i=$((i + 1))
  sleep 1
done
echo "Pre-upload title: $(boathouse_title)"

# If Admin Mode already open, skip login
if [ -z "$(win_by_name "Admin Mode" || true)" ]; then
  admin_login
else
  echo "Admin Mode already open"
fi

# If Configure efaCloud already open, just click upload
if [ -z "$(win_by_name "Configure efaCloud" || true)" ]; then
  open_efacloud_and_upload
else
  echo "Configure efaCloud already open — clicking upload"
  coords=$(ocr_click_label "upload" || true)
  if [ -n "${coords:-}" ]; then
    set -- $coords
    click_xy "$1" "$2"
  else
    click_xy 650 498
  fi
fi

wait_upload_idle
echo "=== gui-synch-upload-all DONE ==="
tail -15 "$LOG"/*/efacloud.log 2>/dev/null | grep -E "STATECHANGE|SYNCH |Synchronization" || true
