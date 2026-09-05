# Explicit CMake toolchain file for building Galactic Bloodshed against the
# L33TEST pinned builder image (see ../Dockerfile.builder).
#
# Exists so the compiler/linker choice is a tracked, reviewable file instead
# of an implicit `-D` flag someone has to remember to pass. Baked into the
# builder image at /opt/gb-toolchain.cmake; build-server.sh passes it via
# `cmake --toolchain`.
#
# Why explicit paths, not names: this image co-installs LLVM 21 (the
# unversioned `clang`/`clang++`/`ld.lld` alternatives) and LLVM 22 (only
# available under versioned names). GB's CMakeLists.txt links against a
# static libc++ built for LLVM 22, so every tool in this chain must be the
# *22 one, explicitly -- never the bare/aliased name. See
# UPSTREAM_DEVIMAGE_ISSUES.md for how this went wrong the first time.

set(CMAKE_C_COMPILER   /usr/bin/clang-22   CACHE FILEPATH "Pinned clang (LLVM 22)")
set(CMAKE_CXX_COMPILER /usr/bin/clang++-22 CACHE FILEPATH "Pinned clang++ (LLVM 22)")

# Absolute path via --ld-path= (the non-deprecated form of -fuse-ld= for a
# path argument; clang-22 warns on -fuse-ld=<path>) so this can never
# silently resolve to the unversioned/LLVM-21 linker alternative if one
# happens to exist.
set(GB_LLD /usr/bin/ld.lld-22)
set(CMAKE_EXE_LINKER_FLAGS_INIT    "--ld-path=${GB_LLD}")
set(CMAKE_SHARED_LINKER_FLAGS_INIT "--ld-path=${GB_LLD}")
set(CMAKE_MODULE_LINKER_FLAGS_INIT "--ld-path=${GB_LLD}")

# GB's own CMakeLists.txt separately probes `find_program(NAMES ld.lld lld)`
# and appends its own "-fuse-ld=lld" *after* ours if found. On this image
# that probe correctly finds nothing -- no unversioned ld.lld exists unless
# the generic `lld` meta-package gets installed on top of this Dockerfile
# (don't) -- so nothing is appended, and the explicit absolute-path flags
# above remain the only linker selection in effect. If that probe ever
# starts finding something (e.g. a future upstream change installs the
# generic package), its later "-fuse-ld=lld" would win over ours, since for
# repeated -fuse-ld the last one on the command line applies -- confirm with
# `ninja -v` on any upstream CMakeLists.txt change.
