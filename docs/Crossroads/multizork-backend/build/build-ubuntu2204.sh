#!/bin/bash
# Runs INSIDE a disposable `ubuntu:22.04` container (docker run --rm).
# Reproduces the multizorkd production toolchain (Ubuntu 22.04 / gcc 11.4 /
# libsqlite3-dev 3.37.2) and builds the patched daemon.
#
#   docker run --rm -v <srcdir>:/src ubuntu:22.04 bash /src/build-ubuntu2204.sh
#
# <srcdir> must contain: mojozork.c, multizorkd.p1.c, multizorkd.p2.c, multizorkd.p3.c
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive

SRC=/src
OUT=/src/out
ENVF=$OUT/build.env
mkdir -p "$OUT"
exec > >(tee "$OUT/build.log") 2>&1

apt-get update -qq
apt-get install -y -qq --no-install-recommends \
    gcc libc6-dev libsqlite3-dev ca-certificates file binutils >/dev/null

{
  echo "=== build host ==="
  echo "container image : ubuntu:22.04  (disposable, docker run --rm)"
  echo "build date (UTC): $(date -u +%Y-%m-%dT%H:%M:%SZ)"
  grep -E '^(PRETTY_NAME|VERSION_ID)=' /etc/os-release
  echo "uname          : $(uname -mrs)"
  echo
  echo "=== toolchain ==="
  gcc --version | head -1
  dpkg-query -W -f='${Package} ${Version}\n' gcc gcc-11 libc6-dev libsqlite3-dev libsqlite3-0
  ld --version | head -1
  ldd --version | head -1
  echo
  echo "=== build command ==="
  echo 'gcc -O2 -DNDEBUG -Wall -o multizorkd multizorkd.c -lsqlite3'
  echo
} > "$ENVF"

build_one () {
  local src="$1" outbin="$2"
  cp "$SRC/$src" "$SRC/multizorkd.c"
  ( cd "$SRC" && gcc -O2 -DNDEBUG -Wall -o "$outbin" multizorkd.c -lsqlite3 )
  rm -f "$SRC/multizorkd.c"
}

build_one multizorkd.p1.c "$OUT/multizorkd.p1"   # loopback only              (== current production)
build_one multizorkd.p2.c "$OUT/multizorkd.p2"   # + input-line redaction
build_one multizorkd.p3.c "$OUT/multizorkd.p3"   # + instance/join-code redaction   (DELIVERABLE)

{
  echo "=== artifacts ==="
  ( cd "$OUT" && sha256sum multizorkd.p1 multizorkd.p2 multizorkd.p3 )
  file "$OUT/multizorkd.p1"
  file "$OUT/multizorkd.p2"
  file "$OUT/multizorkd.p3"
} >> "$ENVF"

cat "$ENVF"
chmod -R a+rwX "$OUT"
