#!/usr/bin/env bash
#
# Build the production Galactic Bloodshed runtime images (gb-server, gb-admin)
# from a source checkout, using the SAME pinned builder as build-server.sh.
#
#   ./build-runtime.sh <gb-source-dir>
#
set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRC_DIR="${1:?Usage: build-runtime.sh <gb-source-dir>}"
SRC_DIR="$(cd "$SRC_DIR" && pwd)"

cp "$HERE/../build/toolchain-clang22-libcxx22.cmake" "$SRC_DIR/gb-toolchain.cmake"
cp "$HERE/../provisioning/gb_provisiond.py" "$SRC_DIR/gb_provisiond.py"
trap 'rm -f "$SRC_DIR/gb-toolchain.cmake" "$SRC_DIR/gb_provisiond.py"' EXIT

docker build -f "$HERE/Dockerfile.runtime" --target gb-server -t l33test/galactic-bloodshed-server:latest "$SRC_DIR"
docker build -f "$HERE/Dockerfile.runtime" --target gb-admin -t l33test/galactic-bloodshed-admin:latest "$SRC_DIR"

echo "=== images built ==="
docker images l33test/galactic-bloodshed-server l33test/galactic-bloodshed-admin --format '{{.Repository}}:{{.Tag}}  {{.Size}}'
