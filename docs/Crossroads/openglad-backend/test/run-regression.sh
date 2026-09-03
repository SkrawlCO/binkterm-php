#!/usr/bin/env bash
#
# Black-box regression for the OpenGlad relay runtime.
#
#   ./run-regression.sh
#
# Runs ../../../../scripts/openglad/openglad-relay-runtime.cjs (the EXACT
# tracked runtime) through its full wire contract, authorization boundary, and
# limit table inside a disposable `binktermphp-binkterm-app` container with NO
# network. Nothing on the host or in the live container is touched; the fake
# WebDoor-session authority is in-process (see relay-contract.mjs).
#
# The container is used only as the runtime ABI (node 20). See ./README.md for
# the assertion list. ~90 s.
#
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP="$(cd "$HERE/../../../.." && pwd)"
IMAGE="${RUNTIME_IMAGE:-binktermphp-binkterm-app:latest}"

echo "== openglad relay regression (image: $IMAGE) =="
exec docker run --rm --network none -v "$APP":/app:ro -w /app "$IMAGE" \
  node /app/docs/Crossroads/openglad-backend/test/relay-contract.mjs
