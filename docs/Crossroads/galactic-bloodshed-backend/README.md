# Galactic Bloodshed backend (Crossroads Experience -- next up)

Server-side build tooling for `kaladron/galactic-bloodshed`, the persistent
multiplayer 4X selected as the next curated Crossroads Experience. Galactic
Bloodshed's own repo is **not vendored** and is **not modified** -- this
directory holds only the L33TEST-owned pinned build image and toolchain file
needed to build it reproducibly, following the `multizork-backend/` /
`ascii-royale-backend/` / `openglad-backend/` precedent.

```
galactic-bloodshed-backend/
├── README.md                          ← this file
└── build/
    ├── Dockerfile.builder              pinned build image (clang-22/libc++-22/lld-22/Ninja)
    ├── toolchain-clang22-libcxx22.cmake  explicit CMake toolchain file, baked into the image
    ├── build-server.sh                 build image -> configure -> build GB/makeuniv/enrol/racegen
    ├── verify-toolchain.sh             print/check resolved compiler+linker+libc++ versions
    └── UPSTREAM_DEVIMAGE_ISSUES.md      the two upstream dev-image defects this works around
```

| | |
|---|---|
| Upstream repo | `github.com/kaladron/galactic-bloodshed` (Apache-2.0) |
| Builder base (human tag) | `ghcr.io/kaladron/cpp-image/dev-env:latest` |
| Builder base (pinned) | `ghcr.io/kaladron/cpp-image/dev-env@sha256:79611d08e671ba4a75b01a96331251b7d27b267e42de74e58c89fa0d14ada5c6` |
| Toolchain | clang-22 / clang++-22 / ld.lld-22 / libc++-22 / CMake 4.2 / Ninja |
| Build | `build/build-server.sh <gb-source-dir>` -- targets `GB makeuniv enrol racegen` |
| Verify | `build/verify-toolchain.sh` |

## Why a pinned image at all

The upstream dev image works, but has two packaging defects (unversioned
`clang`/`clang++` resolve to a different LLVM major than the installed
`libc++-dev`; CMake's std-module path guess lands somewhere that doesn't
exist on this image) that make a build silently version-mismatch or fail
outright unless you already know to route around them. `Dockerfile.builder`
fixes both, once, in a tracked file, instead of rediscovering them on every
future build. Full details: `build/UPSTREAM_DEVIMAGE_ISSUES.md`.

## Future runtime model (not built yet)

The built binaries (`GB`, `makeuniv`, `enrol`, `racegen`) turned out to be
close to fully statically linked -- `ldd` on the Debug build shows only
`libm`, `libresolv`, `libc`, and the dynamic linker. That means the eventual
production **runtime** image can and should be a separate, much smaller
stage/image than this **build** image:

- **Build stage** (this directory): the full LLVM/CMake/Ninja toolchain --
  large, only needed to produce the binaries.
- **Runtime stage** (future slice, not implemented here): a minimal base
  image (matching this build image's glibc, since the binaries are dynamic
  against libc even if static against libc++) with just the four binaries,
  a writable volume for the persistent SQLite universe, and nothing else --
  no compiler, no CMake, no dev headers.

This directory intentionally stops at the build stage. Picking and
validating the minimal runtime base is deliberately left for the slice that
actually stands up the persistent server, not bundled into build tooling.

## Provenance

Everything here was derived from a working isolated proof (build -> disposable
universe -> disposable server -> real Python/curses client login ->
interactive gameplay at 80x24), run against this same pinned digest, in
`/root/galactic-bloodshed-assay/`. This directory converts that proof into
reusable, reviewable build tooling; it does not itself run a server.
