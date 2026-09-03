#!/usr/bin/env bash
#
# Build the OpenGlad Web/WASM client for the L33TEST Crossroads WebDoor:
# clone pinned OpenGlad, apply the tracked downstream carry patch(es), build,
# and stage the artifacts here. Run from anywhere.
#
#   ./build-webdoor.sh <openglad-src-or-dist>
#
#     <openglad-src-or-dist> is either
#       - an OpenGlad *source* checkout at the pinned revision (this script
#         applies patches/ and builds), or
#       - a pre-built *dist/* directory (already patched; this script only stages)
#
#   Pinned OpenGlad : 4565499825c25b0943ab0f6e1e5403af752e63ed  (GPL-2.0)
#   toolchain       : emsdk 6.0.3
#   build           : cmake --preset web-emscripten
#                     cmake --build --preset web-emscripten --target play
#
#   Carried patches : docs/Crossroads/openglad-backend/patches/0001-web-persist-namespace.patch
#                     (window.__opengladPersistNamespace; pending
#                      openglad/openglad#281 -- see
#                      docs/Crossroads/openglad-backend/README.md for the
#                      removal / convergence condition)
#
# Tracked in git   : webdoor.json, index.php, crossroads-glue.js,
#                    build-webdoor.sh, icon.svg, README.md, .gitignore
#                    (the carried patch + its README live under
#                     docs/Crossroads/openglad-backend/, the multizork-backend
#                     precedent -- NOT under public_html)
# Staged (ignored) : play.html, play.js, play.wasm, play.data,
#                    manifest.webmanifest  (compiled output, not source)
#
# The WebDoor entry point is index.php (tracked). It authenticates the caller,
# derives the per-user persistence namespace, and serves play.html (the pinned
# shell) with window.__opengladPersistNamespace + crossroads-glue.js injected
# into <head>. play.html is used verbatim -- no glue tag is baked in here.
#
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
src="${1:-}"
readonly PINNED_REV="4565499825c25b0943ab0f6e1e5403af752e63ed"

stage() {
  local dist="$1"
  for f in play.html play.js play.wasm play.data; do
    [[ -f "$dist/$f" ]] || { echo "missing $dist/$f" >&2; exit 65; }
    cp -f "$dist/$f" "$here/$f"
  done
  [[ -f "$dist/manifest.webmanifest" ]] && cp -f "$dist/manifest.webmanifest" "$here/manifest.webmanifest" || true
  echo "Staged: play.html play.js play.wasm play.data"
  ( cd "$here" && sha256sum play.wasm play.js play.html )
}

if [[ -z "$src" ]]; then
  echo "usage: $0 <openglad-src-checkout | openglad-dist-dir>" >&2
  exit 64
fi

if [[ -f "$src/play.wasm" ]]; then
  echo "Staging from a pre-built dist: $src"
  stage "$src"
  exit 0
fi

if [[ ! -f "$src/src/resources/platform_io.cpp" || ! -d "$src/.git" ]]; then
  echo "not an OpenGlad source checkout and not a dist dir: $src" >&2
  exit 64
fi

echo "OpenGlad source: $src"
have_rev="$(git -C "$src" rev-parse HEAD)"
if [[ "$have_rev" != "$PINNED_REV" ]]; then
  echo "WARNING: checkout HEAD $have_rev != pinned $PINNED_REV" >&2
fi

patchdir="$(cd "$here/../../../docs/Crossroads/openglad-backend/patches" && pwd)"
echo "Applying carried patches from $patchdir ..."
for p in "$patchdir"/[0-9]*.patch; do
  [[ -e "$p" ]] || continue
  echo "  git apply $(basename "$p")"
  git -C "$src" apply --check "$p"
  git -C "$src" apply "$p"
done

echo "Building (needs emsdk 6.0.3 + ninja on PATH)..."
( cd "$src" && cmake --preset web-emscripten \
  && cmake --build --preset web-emscripten --target play -j"$(nproc)" )

stage "$src/dist"
