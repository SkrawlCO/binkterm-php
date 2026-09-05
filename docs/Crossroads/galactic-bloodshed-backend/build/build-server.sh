#!/usr/bin/env bash
#
# Reproducible build of Galactic Bloodshed's server-side binaries using the
# L33TEST pinned builder image (Dockerfile.builder): builds the image if
# needed, then builds GB/makeuniv/enrol/racegen from a GB source checkout
# using ONLY the pinned toolchain (clang-22 + libc++-22 + lld-22 + Ninja).
#
#   ./build-server.sh <gb-source-dir> [build-dir-name]
#
#     <gb-source-dir>    path to a galactic-bloodshed checkout (the dir
#                        containing CMakeLists.txt)
#     build-dir-name     name of the build directory created inside it
#                        (default: build) -- pass a fresh name for a clean
#                        rebuild without disturbing a prior one
#
#   Pinned builder base : ghcr.io/kaladron/cpp-image/dev-env
#                         @sha256:79611d08e671ba4a75b01a96331251b7d27b267e42de74e58c89fa0d14ada5c6
#   Builder image tag    : l33test/galactic-bloodshed-builder:22
#   Toolchain            : clang-22 / clang++-22 / ld.lld-22 / libc++-22 / Ninja
#   Targets built        : GB makeuniv enrol racegen
#
# Upstream Galactic Bloodshed is never modified by this script -- it only
# builds whatever checkout you point it at. See ../UPSTREAM_DEVIMAGE_ISSUES.md
# for the two upstream *dev-image* (not GB source) defects this build image
# works around.
#
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
IMAGE_TAG="l33test/galactic-bloodshed-builder:22"

SRC_DIR="${1:?Usage: build-server.sh <gb-source-dir> [build-dir-name]}"
BUILD_DIR_NAME="${2:-build}"
SRC_DIR="$(cd "$SRC_DIR" && pwd)"

if [ ! -f "$SRC_DIR/CMakeLists.txt" ]; then
  echo "error: $SRC_DIR does not look like a galactic-bloodshed checkout (no CMakeLists.txt)" >&2
  exit 1
fi

echo "=== building pinned builder image ($IMAGE_TAG) ==="
docker build \
  -f "$HERE/Dockerfile.builder" \
  -t "$IMAGE_TAG" \
  "$HERE"

echo "=== configuring + building GB (targets: GB makeuniv enrol racegen) ==="
docker run --rm -u root \
  -v "$SRC_DIR":/workspace \
  -w /workspace \
  "$IMAGE_TAG" \
  bash -lc "
    set -e
    cmake -S . -B '$BUILD_DIR_NAME' -G Ninja -DCMAKE_BUILD_TYPE=Debug \
      --toolchain /opt/gb-toolchain.cmake
    cmake --build '$BUILD_DIR_NAME' --target GB makeuniv enrol racegen -j\"\$(nproc)\"
  "

echo "=== binaries produced ==="
find "$SRC_DIR/$BUILD_DIR_NAME/gb" -maxdepth 1 -type f -executable \
  \( -name GB -o -name makeuniv -o -name enrol -o -name racegen \)
