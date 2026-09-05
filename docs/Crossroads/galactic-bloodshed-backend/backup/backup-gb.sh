#!/usr/bin/env bash
#
# Safe online backup of the live Galactic Bloodshed SQLite universe.
#
#   ./backup-gb.sh <data-dir-or-volume> <output-dir>
#
# <data-dir-or-volume> is whatever was bind-mounted (or, still supported,
# named-volume-mounted) as /var/lib/galactic-bloodshed for the running
# server -- in production, the host path from ../README.md "Persistent data
# path" (e.g. /root/binktermphp/state/galactic-bloodshed).
#
# GB runs its database in WAL mode (PRAGMA journal_mode = WAL -- see
# gb/dal/database.cc), so the live data is actually THREE files (gb.db,
# gb.db-wal, gb.db-shm) and a plain `cp` of just gb.db while the server is
# running can produce a torn/inconsistent copy. This uses SQLite's own
# `VACUUM INTO`, which takes the necessary read locks itself and always
# produces one consistent, defragmented, single-file snapshot -- safe to run
# with the server up and actively being played on.
#
# Neither the server nor admin image carries the sqlite3 CLI (kept out of
# both to keep them minimal -- GB itself links SQLite statically and never
# shells out to the CLI). A throwaway container with it installed is used
# instead.
#
# The source mount is NOT :ro. This was tried first and fails outright
# (SQLITE_CANTOPEN) whenever the database's -wal sidecar doesn't already
# exist (e.g. no server has connected to this volume yet) -- opening any
# WAL-mode SQLite database, even to only read it, requires the opening
# connection to be able to create/manage the -wal/-shm files in the same
# directory; this is standard SQLite WAL-mode behavior, not something a
# read-only bind mount can safely paper over. Safety here comes from which
# SQL is executed (only VACUUM INTO and PRAGMA integrity_check, both against
# a NEW destination file -- nothing here ever mutates a row of game state),
# not from the mount flag.
# Retention: snapshots older than RETENTION_DAYS are pruned after a
# successful backup (accepted L33TEST policy: 7 days / ~168 hourly
# snapshots at the accepted hourly-at-:17 schedule). Only files matching
# this script's own gb-<timestamp>.db naming are ever touched -- an
# unrelated file dropped in the same destination is never pruned.
RETENTION_DAYS="${GB_BACKUP_RETENTION_DAYS:-7}"

set -euo pipefail
VOLUME="${1:?Usage: backup-gb.sh <data-dir-or-volume> <output-dir>}"
OUT_DIR="${2:?Usage: backup-gb.sh <data-dir-or-volume> <output-dir>}"
mkdir -p "$OUT_DIR"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"

docker run --rm \
  -v "$VOLUME":/data \
  -v "$OUT_DIR":/backup \
  debian:trixie-slim \
  bash -c "apt-get update -qq && apt-get install -y -qq --no-install-recommends sqlite3 >/dev/null \
    && sqlite3 /data/gb.db \"VACUUM INTO '/backup/gb-${STAMP}.db'\" \
    && echo 'backup written: /backup/gb-${STAMP}.db' \
    && echo -n 'integrity check: ' && sqlite3 '/backup/gb-${STAMP}.db' 'PRAGMA integrity_check;'"

sha256sum "$OUT_DIR/gb-${STAMP}.db"

echo "pruning snapshots older than ${RETENTION_DAYS} days in $OUT_DIR"
find "$OUT_DIR" -maxdepth 1 -type f -name 'gb-*.db' -mtime "+${RETENTION_DAYS}" -print -delete
