#!/usr/bin/env bash
#
# Prints and checks the exact resolved toolchain versions inside the pinned
# Galactic Bloodshed builder image. Run after build-server.sh has built the
# image (or pass --build to build it first).
#
#   ./verify-toolchain.sh [--build]
#
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
IMAGE_TAG="l33test/galactic-bloodshed-builder:22"

if [ "${1:-}" = "--build" ]; then
  docker build -f "$HERE/Dockerfile.builder" -t "$IMAGE_TAG" "$HERE"
fi

docker run --rm "$IMAGE_TAG" bash -lc '
  set -e
  echo "--- unversioned (generic) alternatives -- EXPECTED to be LLVM 21, never used by the build ---"
  clang --version | head -1
  clang++ --version | head -1
  echo
  echo "--- pinned toolchain actually used by the build (must all say 22) ---"
  clang-22 --version | head -1
  clang++-22 --version | head -1
  ld.lld-22 --version | head -1
  echo
  echo "--- build system ---"
  cmake --version | head -1
  ninja --version
  echo
  echo "--- libc++ module/header provenance (must resolve under llvm-22) ---"
  readlink -f /lib/share/libc++/v1
  test -f /usr/lib/llvm-22/share/libc++/v1/std.cppm && echo "std.cppm: present under llvm-22"
  dpkg -l libc++-22-dev libc++abi-22-dev | tail -2
'
