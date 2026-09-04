# SyncDOOM — installation provenance

This NativeDoor was assembled from a bounded, disposable build/verify assay
(`/root/syncdoom-assay/`, retained temporarily) — nothing here was fetched or
built in place.

## Executable: `syncdoom`

- Upstream: Synchronet source tree, `src/doors/syncdoom/`
  - Canonical repo: https://gitlab.synchro.net/main/sbbs
  - GitHub mirror: https://github.com/SynchronetBBS/sbbs
- Pinned source commit: `fef1a3d46701d3fd5c26a766157e521dd3a91826` (sbbs `master`,
  2026-09-04) — one commit ahead of the prior pin `74898075200f776fe8a4ed23b1b0085b93e2b729`.
  This is a **local, upstream-friendly commit made in the retained assay tree
  (`/root/syncdoom-assay/sbbs`)** — it has not been pushed to or accepted by any
  upstream remote.
- That commit fixes a stale multiplayer registry cleanup defect: a detached match
  server could leave a `.ini` entry behind in the shared games directory after it
  had already exited, naming its own now-dead pid. The fix is local to
  `src/doors/syncdoom/mp_server.c` (dedicated-server registry lifecycle only); no
  other source changed. See the commit message for the full root cause.
- License: GPL-2.0 (syncdoom.c + vendored doomgeneric / Chocolate Doom / id Software DOOM)
- Build host: Ubuntu 22.04 (glibc 2.35, GCC 11), CMake
- Build configuration: `cmake -B build -DWITHOUT_JPEG_XL=ON` (libjxl not packaged for
  the build host / container; sixel + text tiers remain, OGG music via libsndfile) —
  unchanged by the registry-cleanup fix.
- sha256: `c526b05cc1ba3488781605dd0eb64965bb957f1a1dd4a18851a42455ea2049c5`
  (previous pinned build: `233e1ed6e565b68ae7536d88fdfd30ef78ebf48267e49da832017125d811d7a9`)
- Container runtime: proven to `execve` and resolve every shared library inside the
  live `binkterm-app` container (Debian 13 trixie, glibc 2.41) with the container's
  existing libraries — no bundled private libraries, no rebuild.

## Accepted production renderer

- Launch: `syncdoom {dropfile} -home {homedir} -iwad freedoom2.wad -sixel 1`
- Tier: **forced sixel** (`-sixel 1`). This binary was built `-DWITHOUT_JPEG_XL=ON`,
  so SyncDOOM's top graphical tier (JPEG-XL over SyncTERM's APC image protocol) is
  unavailable; sixel is the highest tier compiled in.
- Proven end to end on L33TEST: a real SyncDOOM sixel frame survives the BinkTerm
  NativeDoor output path (`output_encoding=cp437`, bridge CP437→UTF-8, DoorHandler
  raw/cp437) **byte for byte** (pure 7-bit ASCII + ESC), and an authenticated
  SyncTerm caller played Freedoom Phase 2 as real graphics.
- The text/block fallback tier is not used: over the BinkTerm→SyncTerm path it
  sheared badly and, at usable coarseness (`-mode space`), was rejected on
  presentation.

## Game data: `freedoom2.wad`

- Freedoom v0.13.0 (`freedoom-0.13.0.zip`, GitHub release), file `freedoom2.wad`
  ("Freedoom: Phase 2")
- License: modified BSD (see the Freedoom project)
- Zip sha256 verified against the official `freedoom-0.13.0-CHECKSUM`:
  `3f9b264f3e3ce503b4fb7f6bdcb1f419d93c7b546f4df3e874dd878db9688f59`
- `freedoom2.wad` sha256: `a8772e088847032510d97ba2312406a6998f21cbab44d4ff10696faa9c0ecd4b`
- No commercial DOOM IWAD is used.

## Not copied here

Synchronet source checkout, build objects, CMake files, assay logs, the synthetic
DOOR32.SYS, assay `-home` data, the Freedoom zip, and the unused `freedoom1.wad` /
docs were deliberately left in the assay. This directory holds only the runtime
artifacts the door actually needs.
