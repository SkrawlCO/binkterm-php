#!/usr/bin/env bash
#
# Restore a backup produced by backup-gb.sh into a (new or emptied) volume.
# Does NOT touch a running server's volume -- point this at a fresh volume,
# verify, then swap it in deliberately (stop the server, change the compose
# volume/mount, start it back up). Restoring into a volume the server is
# actively using is not supported by this script on purpose.
#
#   ./restore-gb.sh <backup-file.db> <target-volume-name>
set -euo pipefail
BACKUP_FILE="${1:?Usage: restore-gb.sh <backup-file.db> <target-volume-name>}"
VOLUME="${2:?Usage: restore-gb.sh <backup-file.db> <target-volume-name>}"
BACKUP_FILE="$(cd "$(dirname "$BACKUP_FILE")" && pwd)/$(basename "$BACKUP_FILE")"

docker volume create "$VOLUME" >/dev/null
docker run --rm -v "$VOLUME":/data -v "$(dirname "$BACKUP_FILE")":/src:ro debian:trixie-slim \
  bash -c "cp /src/$(basename "$BACKUP_FILE") /data/gb.db && chown -R 9010:9010 /data && ls -la /data"
# The directory itself, not just gb.db, must be owned by the service uid:
# GB's WAL mode needs to CREATE gb.db-wal/gb.db-shm alongside it at startup,
# which needs write permission on the containing directory -- an empty
# Docker-managed volume is root-owned by default. A file-only chown here
# reproduces exactly the "attempt to write a readonly database" failure this
# script was fixed to avoid (see the slice report).

echo "restored into volume '$VOLUME' as gb.db -- verify, then point a gb-server container at it"
