#!/usr/bin/env bash
#
# Reconstruct the exact multizorkd source trees used for the deployed daemon
# and for the credential-redaction candidate, from the pinned upstream commit
# plus the patch files in ../patches/.
#
#   ./reconstruct-source.sh <destdir>
#
# Produces in <destdir>:
#   mojozork.c          - pinned upstream, unchanged (multizorkd.c #includes it)
#   multizorkd.p1.c     - pin + patch 1  (loopback bind)                 == current production
#   multizorkd.p2.c     - pin + patch 1 + patch 2  (+ input redaction)
#   multizorkd.p3.c     - pin + patch 1 + patch 2 + patch 3  (+ join-code redaction)  == DELIVERABLE
#
set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PATCHES="$(cd "$HERE/../patches" && pwd)"
DEST="${1:?usage: reconstruct-source.sh <destdir>}"
PIN="f94c3104aa18036d9ed5f0243814483f82e486cb"
MZD_SHA="6bce2253e7f665baada5200b44a46cd322fc08bee39adbc30e319867fb22b1b0"
MOJO_SHA="fdaff7424d3e35c12711ab87dfe6ed6a1b6f7c00e2d69790d0c737d3dc0db2a1"

mkdir -p "$DEST"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

git clone --quiet https://github.com/icculus/mojozork.git "$tmp/src"
git -C "$tmp/src" checkout --quiet "$PIN"

check() { test "$(sha256sum "$1" | cut -d' ' -f1)" = "$2" || { echo "HASH MISMATCH: $1"; exit 1; }; }
check "$tmp/src/multizorkd.c" "$MZD_SHA"
check "$tmp/src/mojozork.c"   "$MOJO_SHA"

cp "$tmp/src/mojozork.c" "$DEST/mojozork.c"
git -C "$tmp/src" apply --verbose "$PATCHES/0001-bind-listener-to-loopback-only.patch"
cp "$tmp/src/multizorkd.c" "$DEST/multizorkd.p1.c"
git -C "$tmp/src" apply --verbose "$PATCHES/0002-redact-credential-bearing-input-lines.patch"
cp "$tmp/src/multizorkd.c" "$DEST/multizorkd.p2.c"
git -C "$tmp/src" apply --verbose "$PATCHES/0003-redact-instance-join-codes.patch"
cp "$tmp/src/multizorkd.c" "$DEST/multizorkd.p3.c"

echo
echo "reconstructed into $DEST:"
sha256sum "$DEST"/mojozork.c "$DEST"/multizorkd.p1.c "$DEST"/multizorkd.p2.c "$DEST"/multizorkd.p3.c
