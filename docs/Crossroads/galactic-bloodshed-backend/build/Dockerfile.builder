# syntax=docker/dockerfile:1
#
# Galactic Bloodshed pinned BUILD image (L33TEST-owned build tooling).
# This is a BUILD-ONLY image (heavy LLVM/CMake/Ninja toolchain). It is not
# the runtime image -- see ../README.md "Future runtime model" for why.
#
# Upstream pin (human-readable): ghcr.io/kaladron/cpp-image/dev-env:latest
# Upstream pin (immutable):
#   ghcr.io/kaladron/cpp-image/dev-env@sha256:79611d08e671ba4a75b01a96331251b7d27b267e42de74e58c89fa0d14ada5c6
#   (pulled + proven working 2026-09-05; galactic-bloodshed-assay proof)
#
# Two defects were found in the upstream dev image during that proof. Both
# are about WRONG DEFAULT SELECTION, not missing packages -- clang-22,
# clang++-22, lld-22, libc++-22-dev and libc++abi-22-dev are all already
# installed in the pinned image. See UPSTREAM_DEVIMAGE_ISSUES.md for the
# full writeup; summary:
#
#   1. The unversioned `clang`/`clang++` alternatives resolve to LLVM 21,
#      but `libc++-dev` (also unversioned) is LLVM 22. Mixing them makes
#      CMake's "import std" support try to compile the wrong std.cppm and
#      later produces ABI-tag link errors. Fix: never invoke the unversioned
#      names -- always clang-22 / clang++-22 explicitly (see the toolchain
#      file, not this Dockerfile).
#
#   2. CMake 4's built-in std-module-scan support guesses the libc++ module
#      directory relative to the *generic* compiler path and looks for it at
#      /lib/share/libc++/v1/std.cppm, which does not exist at that path on
#      this image. Fix: symlink it to the real (v22) location, below.
#
# Only two packages are genuinely missing from the pinned base and are
# installed here: ninja-build (required -- CMake's C++20 module scanning is
# only supported by the Ninja generator, not Unix Makefiles) and
# libsqlite3-dev (GB's CMakeLists.txt does find_package(SQLite3)).

ARG BASE_DIGEST=sha256:79611d08e671ba4a75b01a96331251b7d27b267e42de74e58c89fa0d14ada5c6
FROM ghcr.io/kaladron/cpp-image/dev-env@${BASE_DIGEST}

USER root

# The only two packages this image actually needs beyond the pinned base.
RUN apt-get update -qq \
 && apt-get install -y -qq --no-install-recommends \
      ninja-build \
      libsqlite3-dev \
 && rm -rf /var/lib/apt/lists/*

# Defect #2 workaround (see header): make CMake's guessed std-module path
# resolve to the real, v22-matched libc++ module directory. A directory
# symlink (not per-file) so std.cppm's own relative #includes (std/*.inc)
# still resolve underneath it.
RUN mkdir -p /lib/share/libc++ \
 && rm -rf /lib/share/libc++/v1 \
 && ln -s /usr/lib/llvm-22/share/libc++/v1 /lib/share/libc++/v1

# Fail the image build loudly if the pinned toolchain ever isn't what we
# think it is, rather than silently drifting to whatever the base image
# ships next time it's rebuilt upstream.
RUN set -e; \
    clang-22 --version | grep -q 'clang version 22'; \
    clang++-22 --version | grep -q 'clang version 22'; \
    /usr/lib/llvm-22/bin/ld.lld --version | grep -qi 'LLD'; \
    test -x /usr/bin/ld.lld-22; \
    test -f /usr/lib/llvm-22/share/libc++/v1/std.cppm; \
    test -L /lib/share/libc++/v1; \
    cmake --version | head -1; \
    ninja --version; \
    echo "galactic-bloodshed builder toolchain verified: clang-22 + libc++-22 + lld-22 + ninja"

# Explicit CMake toolchain file -- see toolchain-clang22-libcxx22.cmake.
# Baked into the image so `cmake --toolchain` needs no extra bind-mount.
COPY toolchain-clang22-libcxx22.cmake /opt/gb-toolchain.cmake

WORKDIR /workspace
