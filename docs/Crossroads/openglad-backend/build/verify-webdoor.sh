#!/usr/bin/env bash
#
# Verify the staged OpenGlad WebDoor artifacts against the accepted hashes.
#
#   ./verify-webdoor.sh [<dir>]
#
#     <dir>  directory holding play.{html,js,wasm,data} + manifest.webmanifest
#            (default: public_html/webdoors/openglad/)
#
# Exit 0 = every artifact matches EXPECTED.sha256. This is THE gate before a
# deploy: Emscripten output is not guaranteed bit-reproducible, so a build that
# does not reproduce these hashes must be investigated (pin the toolchain from
# the accepted build.env) before anything ships.
#
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
expected="$here/EXPECTED.sha256"
target="${1:-$(cd "$here/../../../../public_html/webdoors/openglad" && pwd)}"

[[ -f "$expected" ]] || { echo "FATAL: $expected missing" >&2; exit 2; }
[[ -d "$target" ]] || { echo "FATAL: $target is not a directory" >&2; exit 2; }

echo "== verify OpenGlad WebDoor artifacts =="
echo "  dir      : $target"
echo "  expected : $expected"
echo

rc=0
while read -r want name; do
  [[ -z "${want:-}" || "${want:0:1}" == "#" ]] && continue
  file="$target/$name"
  if [[ ! -f "$file" ]]; then
    echo "  MISSING  $name"
    rc=1
    continue
  fi
  got="$(sha256sum "$file" | cut -d' ' -f1)"
  if [[ "$got" == "$want" ]]; then
    echo "  OK       $name"
  else
    echo "  MISMATCH $name"
    echo "             want $want"
    echo "             got  $got"
    rc=1
  fi
done < "$expected"

echo
if [[ "$rc" == "0" ]]; then
  echo "PASS — staged artifacts match the accepted set."
else
  echo "FAIL — do NOT deploy. See EXPECTED.sha256 for the escalation note."
fi
exit "$rc"
