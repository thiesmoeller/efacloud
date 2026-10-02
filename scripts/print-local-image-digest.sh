#!/usr/bin/env bash
# Build the multi-stage efaCloud image locally and print an immutable digest
# suitable for CapRover promote rehearsal when GHCR/Actions is unavailable.
#
# Usage:
#   ./scripts/print-local-image-digest.sh
#   IMAGE_TAG=efacloud:local-promote ./scripts/print-local-image-digest.sh
#
# Does not push to a registry. No secrets required.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

IMAGE_TAG="${IMAGE_TAG:-efacloud:local-digest}"
SHORT_SHA="$(git rev-parse --short HEAD 2>/dev/null || echo unknown)"
FULL_SHA="$(git rev-parse HEAD 2>/dev/null || echo unknown)"

echo "== docker build -t ${IMAGE_TAG} . =="
docker build -t "${IMAGE_TAG}" .

echo "== smoke (portal + apache config) =="
docker run --rm --entrypoint sh "${IMAGE_TAG}" -c '
  set -e
  test -f /var/www/html/portal/index.html
  test -f /var/www/html/api/portal/v1/index.php
  test -f /etc/apache2/conf-enabled/efacloud.conf
  # apache2 -t needs envvars from the official image
  . /etc/apache2/envvars
  apache2 -t
'
echo "Smoke OK"

# Local images only have an Id until pushed; print Id + RepoDigests if any.
IMAGE_ID="$(docker image inspect "${IMAGE_TAG}" --format '{{.Id}}')"
REPO_DIGEST="$(docker image inspect "${IMAGE_TAG}" --format '{{index .RepoDigests 0}}' 2>/dev/null || true)"
CREATED="$(docker image inspect "${IMAGE_TAG}" --format '{{.Created}}')"

echo ""
echo "=== local image digest evidence ==="
echo "tag:          ${IMAGE_TAG}"
echo "image_id:     ${IMAGE_ID}"
if [[ -n "${REPO_DIGEST}" && "${REPO_DIGEST}" != "<no value>" ]]; then
  echo "repo_digest:  ${REPO_DIGEST}"
else
  echo "repo_digest:  (none — image not pushed; use image_id or retag after GHCR push)"
fi
echo "created:      ${CREATED}"
echo "git_short:    ${SHORT_SHA}"
echo "git_full:     ${FULL_SHA}"
echo ""
echo "After GHCR publish, CapRover should use:"
echo "  ghcr.io/thiesmoeller/efacloud:sha-${SHORT_SHA}"
echo "  (or the registry digest from the Actions job summary)"
echo "See docs/deploy/image-promote.md"
