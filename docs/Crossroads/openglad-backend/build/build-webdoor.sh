#!/usr/bin/env bash
#
# Reproducible build of the OpenGlad Web/WASM client for the L33TEST Crossroads
# WebDoor: clone the PIN, apply exactly the tracked downstream carry patch(es),
# build with Emscripten, stage the artifacts into the WebDoor directory, and
# record provenance.
#
#   ./build-webdoor.sh [<workdir>]
#   ./build-webdoor.sh --from-dist <prebuilt-dist-dir>
#
#     <workdir>            scratch dir for the throwaway clone + build
#                          (default: a fresh mktemp dir, removed on success)
#     --from-dist <dir>    skip the clone+build; stage an already-built,
#                          already-patched dist/ (a dir containing play.wasm).
#                          Use only for a dist produced by a prior verified run
#                          of this script; verify-webdoor.sh is still the gate.
#
#   Pinned OpenGlad : 4565499825c25b0943ab0f6e1e5403af752e63ed  (GPL-2.0)
#   Upstream        : https://github.com/openglad/openglad
#   Toolchain       : emsdk 6.0.3 (emcc + ninja on PATH; `emcmake`/`emcc`)
#   Build           : cmake --preset web-emscripten
#                     cmake --build --preset web-emscripten --target play
#
#   Carried patches : docs/Crossroads/openglad-backend/patches/[0-9]*.patch
#                     (currently only 0001-web-persist-namespace.patch --
#                      window.__opengladPersistNamespace; pending
#                      openglad/openglad#281; see ../README.md for the
#                      removal / convergence condition)
#
# Canonical OpenGlad is NEVER modified: the carry is applied to a throwaway
# clone. The staged play.{html,js,wasm,data} + manifest.webmanifest are
# git-ignored compiled output (see public_html/webdoors/openglad/.gitignore) --
# rebuild them, do not commit them. The entry point is the tracked index.php;
# play.html is the pinned shell used verbatim.
#
# After a build, verify the staged tree:
#   ./verify-webdoor.sh
#
set -euo pipefail

readonly PIN="4565499825c25b0943ab0f6e1e5403af752e63ed"
readonly UPSTREAM="https://github.com/openglad/openglad"

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly PATCH_DIR="$(cd "$here/../patches" && pwd)"
readonly WEBDOOR_DIR="$(cd "$here/../../../../public_html/webdoors/openglad" && pwd)"
readonly BUILD_ENV="$WEBDOOR_DIR/build.env"

stage_tree() {
  local dist="$1"
  local staged=(play.html play.js play.wasm play.data)
  for f in "${staged[@]}"; do
    [[ -f "$dist/$f" ]] || { echo "FATAL: missing $dist/$f" >&2; exit 67; }
    cp -f "$dist/$f" "$WEBDOOR_DIR/$f"
  done
  if [[ -f "$dist/manifest.webmanifest" ]]; then
    cp -f "$dist/manifest.webmanifest" "$WEBDOOR_DIR/manifest.webmanifest"
    staged+=(manifest.webmanifest)
  fi
  echo "Staged into $WEBDOOR_DIR :"
  ( cd "$WEBDOOR_DIR" && sha256sum "${staged[@]}" )
}

if [[ "${1:-}" == "--from-dist" ]]; then
  dist="${2:?--from-dist needs a dist directory}"
  [[ -f "$dist/play.wasm" ]] || { echo "FATAL: $dist is not a dist dir" >&2; exit 64; }
  echo "== staging from prebuilt dist: $dist =="
  stage_tree "$dist"
  echo
  echo "NOTE: build.env NOT refreshed (no rebuild). Run ./verify-webdoor.sh."
  exit 0
fi

workdir="${1:-}"
cleanup_workdir=0
if [[ -z "$workdir" ]]; then
  workdir="$(mktemp -d "${TMPDIR:-/tmp}/openglad-webdoor-build.XXXXXX")"
  cleanup_workdir=1
fi
mkdir -p "$workdir"
workdir="$(cd "$workdir" && pwd)"
src="$workdir/openglad"

echo "== OpenGlad WebDoor build =="
echo "  pin      : $PIN"
echo "  workdir  : $workdir"
echo "  patches  : $PATCH_DIR"
echo "  stage to : $WEBDOOR_DIR"

# --- 1. throwaway clone, pinned + verified --------------------------------
if [[ ! -d "$src/.git" ]]; then
  git clone --quiet "$UPSTREAM" "$src"
fi
git -C "$src" fetch --quiet --depth 1 origin "$PIN" 2>/dev/null || git -C "$src" fetch --quiet origin
git -C "$src" checkout --quiet --detach "$PIN"
git -C "$src" reset --hard --quiet "$PIN"
git -C "$src" clean -fdx --quiet
have="$(git -C "$src" rev-parse HEAD)"
if [[ "$have" != "$PIN" ]]; then
  echo "FATAL: clone HEAD $have != pin $PIN" >&2
  exit 65
fi
echo "  HEAD verified == pin"

# --- 2. apply exactly the tracked carry patch(es) ------------------------
patch_lines=()
shopt -s nullglob
for p in "$PATCH_DIR"/[0-9]*.patch; do
  echo "  git apply $(basename "$p")"
  git -C "$src" apply --check "$p"
  git -C "$src" apply "$p"
  patch_lines+=("$(basename "$p")  sha256=$(sha256sum "$p" | cut -d' ' -f1)")
done
shopt -u nullglob
if [[ ${#patch_lines[@]} -eq 0 ]]; then
  echo "FATAL: no carry patches found in $PATCH_DIR" >&2
  exit 66
fi

# --- 3. build -----------------------------------------------------------
echo "  building (emsdk 6.0.3 + ninja required on PATH)..."
( cd "$src" \
  && cmake --preset web-emscripten \
  && cmake --build --preset web-emscripten --target play -j"$(nproc)" )

dist="$src/dist"
for f in play.html play.js play.wasm play.data; do
  [[ -f "$dist/$f" ]] || { echo "FATAL: missing $dist/$f" >&2; exit 67; }
done

# --- 4. stage + provenance --------------------------------------------
staged=(play.html play.js play.wasm play.data)
for f in "${staged[@]}"; do
  cp -f "$dist/$f" "$WEBDOOR_DIR/$f"
done
[[ -f "$dist/manifest.webmanifest" ]] && {
  cp -f "$dist/manifest.webmanifest" "$WEBDOOR_DIR/manifest.webmanifest"
  staged+=(manifest.webmanifest)
}

{
  echo "# OpenGlad WebDoor build provenance -- generated by build-webdoor.sh"
  echo "# This file is git-ignored; it records what produced the staged play.*."
  echo
  echo "built_utc      : $(date -u +%Y-%m-%dT%H:%M:%SZ)"
  echo "pin            : $PIN"
  echo "upstream       : $UPSTREAM"
  echo "host_os        : $(. /etc/os-release 2>/dev/null; echo "${PRETTY_NAME:-unknown}")"
  echo "uname          : $(uname -mrs)"
  echo "emcc           : $(emcc --version 2>/dev/null | head -1 || echo 'unknown')"
  echo "cmake          : $(cmake --version 2>/dev/null | head -1 || echo 'unknown')"
  echo "node           : $(node --version 2>/dev/null || echo 'unknown')"
  echo
  echo "carry_patches  :"
  for line in "${patch_lines[@]}"; do echo "  $line"; done
  echo
  echo "artifacts      :"
  ( cd "$WEBDOOR_DIR" && sha256sum "${staged[@]}" | sed 's/^/  /' )
} > "$BUILD_ENV"

echo
echo "Staged into $WEBDOOR_DIR :"
( cd "$WEBDOOR_DIR" && sha256sum "${staged[@]}" )
echo
echo "Wrote $BUILD_ENV"
echo "Next: ./verify-webdoor.sh"

if [[ "$cleanup_workdir" == "1" ]]; then
  rm -rf "$workdir"
fi
