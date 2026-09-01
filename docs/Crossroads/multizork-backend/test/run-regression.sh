#!/usr/bin/env bash
#
# End-to-end black-box regression for the multizorkd credential-log
# redaction patches.  Everything runs in throwaway Docker containers; nothing
# is installed on the host and the production binkterm-app container and its
# /var/lib/multizork tree are never touched (the story file is only read).
#
#   ./run-regression.sh [--keep]
#
# Steps:
#   1. reconstruct the daemon source  (pinned upstream + patches 1..3)
#   2. build  multizorkd.p1  (loopback only  = current production logic)
#          .. multizorkd.p2  (+ input-line redaction)
#          .. multizorkd.p3  (+ instance/join-code redaction  = DELIVERABLE)
#      in a disposable ubuntu:22.04 container (the production toolchain)
#   3. run harness.py against p1 (control) and p3 (deliverable) in a disposable
#      binkterm-app container (the production runtime ABI), isolated sqlite db
#   4. diff the two client-visible transcripts -> game/auth behaviour must
#      be byte-identical apart from the build-timestamp banner
#
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BUNDLE="$(cd "$HERE/.." && pwd)"
WORK="$(mktemp -d /tmp/multizork-regression.XXXXXX)"
KEEP=0
[[ "${1:-}" == "--keep" ]] && KEEP=1

BUILD_IMAGE="ubuntu:22.04"
RUNTIME_IMAGE="binktermphp-binkterm-app:latest"
PIN="f94c3104aa18036d9ed5f0243814483f82e486cb"
STORY_SHA="158f1f63b1302591bbce30e1ec23b17909d2a66e39403ed14593a75280b1e7f9"

cleanup() { [[ $KEEP -eq 1 ]] || rm -rf "$WORK"; }
trap cleanup EXIT

say() { printf '\n\033[1m== %s\033[0m\n' "$*"; }

# ---------------------------------------------------------------------------
say "1. reconstruct source  (pin $PIN + patches)"
git clone --quiet https://github.com/icculus/mojozork.git "$WORK/src"
git -C "$WORK/src" checkout --quiet "$PIN"
test "$(sha256sum "$WORK/src/multizorkd.c" | cut -d' ' -f1)" \
     = "6bce2253e7f665baada5200b44a46cd322fc08bee39adbc30e319867fb22b1b0" \
  || { echo "pinned multizorkd.c hash mismatch"; exit 1; }

cp "$WORK/src/mojozork.c" "$WORK/"
git -C "$WORK/src" apply "$BUNDLE/patches/0001-bind-listener-to-loopback-only.patch"
cp "$WORK/src/multizorkd.c" "$WORK/multizorkd.p1.c"
git -C "$WORK/src" apply "$BUNDLE/patches/0002-redact-credential-bearing-input-lines.patch"
cp "$WORK/src/multizorkd.c" "$WORK/multizorkd.p2.c"
git -C "$WORK/src" apply "$BUNDLE/patches/0003-redact-instance-join-codes.patch"
cp "$WORK/src/multizorkd.c" "$WORK/multizorkd.p3.c"

# ---------------------------------------------------------------------------
say "2. build in disposable $BUILD_IMAGE"
cp "$BUNDLE/build/build-ubuntu2204.sh" "$WORK/"
docker run --rm -v "$WORK":/src "$BUILD_IMAGE" bash /src/build-ubuntu2204.sh >/dev/null
ls -l "$WORK/out/multizorkd.p1" "$WORK/out/multizorkd.p2" "$WORK/out/multizorkd.p3"
sha256sum "$WORK/out/multizorkd.p1" "$WORK/out/multizorkd.p2" "$WORK/out/multizorkd.p3"

# ---------------------------------------------------------------------------
say "3. acquire story artifact (read-only, from the running container)"
docker cp binkterm-app:/var/lib/multizork/story/zork1-r88.dat "$WORK/zork1-r88.dat"
test "$(sha256sum "$WORK/zork1-r88.dat" | cut -d' ' -f1)" = "$STORY_SHA" \
  || { echo "story hash mismatch"; exit 1; }

# ---------------------------------------------------------------------------
say "4. run harness in disposable $RUNTIME_IMAGE"
cp "$BUNDLE/test/harness.py" "$WORK/"
docker run --rm -v "$WORK":/w -w /w "$RUNTIME_IMAGE" bash -c '
  set -e
  python3 harness.py --bin out/multizorkd.p1 --story zork1-r88.dat --outdir res-p1 --mode verbatim
  python3 harness.py --bin out/multizorkd.p3 --story zork1-r88.dat --outdir res-p3 --mode redacted
'

# ---------------------------------------------------------------------------
say "5. differential: client-visible behaviour must match"
norm() { sed -E 's/socket [0-9]+/socket N/g; /version 0\.0\.9 built/d; /built [A-Z][a-z]{2} +[0-9]/d' "$1"; }
if diff <(norm "$WORK/res-p1/transcript.txt") <(norm "$WORK/res-p3/transcript.txt") >"$WORK/transcript.diff"; then
  echo "PASS: deliverable is behaviourally identical to the loopback-only build"
else
  echo "FAIL: transcript differs:"; cat "$WORK/transcript.diff"; exit 1
fi

say "regression PASSED"
[[ $KEEP -eq 1 ]] && echo "artifacts kept in $WORK"
exit 0
