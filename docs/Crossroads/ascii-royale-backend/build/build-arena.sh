#!/usr/bin/env bash
#
# Reproducible build of the pinned, UNMODIFIED ascii-royale arena binary.
#
#   ./build-arena.sh <destdir>
#
# Runs the Rust toolchain in a disposable container (nothing installed on the
# host or into binkterm-app) and produces:
#   <destdir>/ascii-royale     the release binary
#   <destdir>/build.env         recorded toolchain + hashes
#
# There are NO local patches. The upstream tree is checked out at the pin and
# built with `--locked`. Verify the result with ./verify-binary.sh.
#
set -euo pipefail

DEST="${1:?usage: build-arena.sh <destdir>}"
PIN="ac7d9771dfd788b278427db619e43989d4317029"
UPSTREAM="https://github.com/chad/ascii-royale"
EXPECT_SHA256="b7d59c4083e4b2ef3664be57145a70bfbb178db170efbb989e2580fe56d8d84e"
BUILD_IMAGE="${BUILD_IMAGE:-rust:1-bookworm}"   # any rust image whose toolchain satisfies Cargo.toml

mkdir -p "$DEST"
DEST="$(cd "$DEST" && pwd)"

docker run --rm -v "$DEST":/out -e PIN="$PIN" -e UPSTREAM="$UPSTREAM" "$BUILD_IMAGE" bash -euo pipefail -c '
  export CARGO_TERM_COLOR=never DEBIAN_FRONTEND=noninteractive
  apt-get update -qq
  apt-get install -y -qq --no-install-recommends libasound2-dev pkg-config ca-certificates git >/dev/null
  git clone --quiet "$UPSTREAM" /src
  git -C /src checkout --quiet --detach "$PIN"
  test "$(git -C /src rev-parse HEAD)" = "$PIN"
  ( cd /src && cargo build --release --locked )
  install -m 0555 /src/target/release/ascii-royale /out/ascii-royale
  {
    echo "=== build host ==="
    echo "container image : '"$BUILD_IMAGE"'  (disposable, docker run --rm)"
    echo "build date (UTC): $(date -u +%Y-%m-%dT%H:%M:%SZ)"
    grep -E "^(PRETTY_NAME|VERSION_ID)=" /etc/os-release
    echo "uname          : $(uname -mrs)"
    echo
    echo "=== toolchain ==="
    rustc --version
    cargo --version
    echo
    echo "=== pin ==="
    echo "repo   : '"$UPSTREAM"'"
    echo "commit : $(git -C /src rev-parse HEAD)"
    git -C /src log -1 --format="subject: %s%ndate   : %cI"
    echo
    echo "=== artifact ==="
    sha256sum /out/ascii-royale
    file /out/ascii-royale
  } > /out/build.env
  cat /out/build.env
  chmod -R a+rwX /out
'

echo
got="$(sha256sum "$DEST/ascii-royale" | cut -d' ' -f1)"
echo "built  SHA-256: $got"
echo "expect SHA-256: $EXPECT_SHA256"
if [[ "$got" == "$EXPECT_SHA256" ]]; then
  echo "MATCH — byte-identical to the retained production-candidate binary."
else
  echo "NOTE: Rust release builds are not guaranteed bit-reproducible across"
  echo "toolchain/host differences. If this differs, pin BUILD_IMAGE to the"
  echo "toolchain recorded in build.env of the accepted build and re-run;"
  echo "escalate before deploying a binary whose SHA-256 is not $EXPECT_SHA256."
fi
