#!/usr/bin/env bash
#
# Black-box regression for the ascii-royale managed-arena wrapper.
#
#   ./run-regression.sh
#
# Runs ../runtime/ascii-royale-arena.sh (the EXACT committed wrapper) against
# a fake `serve` inside a disposable `binktermphp-binkterm-app` container:
#   - no network, no real ascii-royale binary, no iroh
#   - the production /var/lib/ascii-royale and the running arena are untouched
#   - a throwaway ARENA_ROOT + a throwaway `artest` service account per case
#
# The container is the runtime ABI only (bash, coreutils, util-linux/setpriv).
# See ./README.md for the assertion list.
#
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PKG="$(cd "$HERE/.." && pwd)"
IMAGE="${RUNTIME_IMAGE:-binktermphp-binkterm-app:latest}"

echo "== ascii-royale arena wrapper regression (image: $IMAGE) =="
docker run --rm -v "$PKG":/pkg:ro -w /tmp "$IMAGE" bash /pkg/test/wrapper-contract.sh
