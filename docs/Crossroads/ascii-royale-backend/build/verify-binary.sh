#!/usr/bin/env bash
#
# Verify an ascii-royale arena binary is the pinned production candidate.
#
#   ./verify-binary.sh [path-to-binary]
#
# Default path is the deployed location inside binkterm-app. With no docker
# access it checks a local file. Exit 0 only on an exact SHA-256 match.
#
set -euo pipefail

PIN="ac7d9771dfd788b278427db619e43989d4317029"
EXPECT_SHA256="b7d59c4083e4b2ef3664be57145a70bfbb178db170efbb989e2580fe56d8d84e"
DEPLOYED="/var/lib/ascii-royale/${PIN}/ascii-royale"

target="${1:-}"

sha_of() {
  if [[ -n "$target" ]]; then
    sha256sum -- "$target" | cut -d' ' -f1
  elif command -v docker >/dev/null 2>&1 && docker ps --format '{{.Names}}' | grep -qx binkterm-app; then
    echo "checking deployed binary in binkterm-app: $DEPLOYED" >&2
    docker exec binkterm-app sha256sum "$DEPLOYED" | cut -d' ' -f1
  else
    echo "usage: verify-binary.sh <path>   (no binkterm-app container to inspect)" >&2
    exit 2
  fi
}

got="$(sha_of)"
echo "pin            : $PIN"
echo "expected sha256: $EXPECT_SHA256"
echo "actual   sha256: $got"
if [[ "$got" == "$EXPECT_SHA256" ]]; then
  echo "OK — pinned production-candidate binary."
  exit 0
fi
echo "MISMATCH — this is NOT the approved binary. Do not deploy." >&2
exit 1
