# Galactic Bloodshed production setup

This records the exact commands used to stand up the permanent host data
directory, the provisioning secret/socket, and the hourly backup -- all of
which have now actually been done (permanent 70-star universe created,
`galactic-bloodshed` + `galactic-bloodshed-provisioner` running, first real
cron backup taken and integrity-checked). Kept here as the authoritative
record of exactly what exists and why, not merely a plan. See the parent
slice reports for the full architecture/design rationale and validation
evidence.

## Host data directory

Following the existing, already-live L33TEST convention (`/root/binktermphp/state/<service>`
-- see `multizork`, `chessmata-mongo` on the host today):

```bash
install -d -o 9010 -g 9010 -m 755 /root/binktermphp/state/galactic-bloodshed
install -d -o 9010 -g 9010 -m 755 /root/binktermphp/state/gb-provisiond-run
```

- **Owner/group:** uid/gid `9010` (`galacticbloodshed`), matching the identical
  service user baked into both the `gb-server` and `gb-admin`/provisioner
  images (see `runtime/Dockerfile.runtime`). Both containers run as this
  same UID, so both get full read/write via ordinary single-owner
  permissions -- no shared-group or `chmod 777` needed, matching the
  existing convention's own pattern (single numeric owner, mode 755).
- **The backup process does NOT need to match this UID at all**: `755`
  already grants "other" read+execute, which is sufficient for the
  throwaway backup container's own UID to open the source database. That
  mount is NOT `:ro`, despite being read-only in intent -- a WAL-mode
  SQLite database (which this is) needs its opening connection to be able
  to create/manage the `-wal`/`-shm` sidecar files even to only read it;
  found the hard way in this slice's validation (see `backup-gb.sh`'s own
  comments). Safety is enforced by which SQL runs (`VACUUM INTO` /
  `PRAGMA integrity_check` only), not by the mount flag.
- `gb-provisiond-run` holds only the Unix socket file the provisioner
  daemon creates at startup -- ephemeral, never needs backing up.

## Provisioner shared-secret token and socket -- final permission model

```bash
install -d -o root -g root -m 700 /root/binktermphp/secrets
openssl rand -hex 32 > /root/binktermphp/secrets/galactic_bloodshed_provisiond_token
chown 9010:9010 /root/binktermphp/secrets/galactic_bloodshed_provisiond_token
chmod 400 /root/binktermphp/secrets/galactic_bloodshed_provisiond_token
chmod 750 /root/binktermphp/state/gb-provisiond-run   # socket dir; 9010:9010
```

**This superseded an earlier, more permissive interim choice** (token `444`
world-readable, socket `0666`) made when this repo could not determine which
UID binkterm-app's NativeDoor multiplexing bridge runs doors under. Live
inspection resolved it: `docker exec binkterm-app ps` shows
`scripts/dosbox-bridge/multiplexing-server.js` (the process that spawns
every NativeDoor, including this one) running as **root** -- and
`supervisord.conf`'s `[program:dosdoor_bridge]` says `user=binkterm`, but no
such user exists in the container, so that directive silently fails and it
runs as root instead. (This is a pre-existing platform gap, unrelated to
Galactic Bloodshed, and out of scope to fix here -- noted for whoever owns
`docker/supervisord.conf` next.)

Because root bypasses Unix file-permission checks entirely, the ONE real
consumer of this token and socket (the launcher, running as root) needs no
explicit grant at all -- so both are now locked to owner-only:

- **Token:** `9010:9010`, mode `400` -- readable by the provisioner daemon
  (which owns it) and by root (the launcher, via DAC bypass); denied to
  every other UID, proven live (a test connection as uid 1000 got
  `PermissionError: [Errno 13] Permission denied` reading the file).
- **Socket:** created by the daemon as `9010:9010`, mode `600` -- same
  reasoning; a uid-1000 test connection got `PermissionError` on `connect()`
  too. `handle_client`'s shared-secret token check remains the layer that
  doesn't depend on any of this.
- **Socket directory:** `750`, `9010:9010` -- hygiene on top of the above;
  its practical value is limited since root bypasses execute/traversal
  checks too, but it costs nothing and keeps the directory closed to other
  non-root, non-9010 processes that might ever share the host.

Both files mounted `:ro` into their consuming containers (compose fragment).
Unlike `chessmata_broker_key` (owned `www-data`, mode `400`, exactly one
consumer), this secret has two *specific* consumers with two different
UIDs -- the resolution here is "let root's DAC bypass cover the awkward
consumer" rather than a shared group, since introducing a new shared GID
across two independently-built images seemed like more moving parts than
this warranted.

## Backup scheduling

Method: the already-proven WAL-safe `VACUUM INTO` (`backup/backup-gb.sh`),
run against the live server -- no downtime required. Installed and already
fired for real (the crontab install happened to land right at minute `:17`,
so the very first snapshot came from the actual cron trigger).

```cron
# /etc/cron.d/galactic-bloodshed-backup (installed)
17 * * * * root /root/binktermphp/app/docs/Crossroads/galactic-bloodshed-backend/backup/backup-gb.sh /root/binktermphp/state/galactic-bloodshed /root/binktermphp/backups/galactic-bloodshed >> /var/log/galactic-bloodshed-backup.log 2>&1
```

- **Frequency:** hourly, offset to `:17` -- deliberately not `:07`, which the
  host crontab already uses for BinkP log rotation, so the two never
  contend for I/O in the same minute.
- **Destination:** `/root/binktermphp/backups/galactic-bloodshed/` -- a
  sibling of `state/`, not inside it, so a bug that ever wiped `state/`
  can't take the backups with it.
- **Naming:** `backup-gb.sh` already names each file
  `gb-<UTC timestamp>.db` -- inherently non-colliding, no locking needed.
- **Retention:** 7 days (~168 hourly snapshots at this schedule) -- accepted
  policy. `backup-gb.sh` prunes snapshots older than `GB_BACKUP_RETENTION_DAYS`
  (default 7) after each successful run, matching only its own
  `gb-<timestamp>.db` naming -- never touches an unrelated file in the same
  destination.
- **Failure logging:** the redirect above captures both stdout and stderr;
  `backup-gb.sh` already prints the resulting file's sha256 on success, so a
  truncated/missing hash line in the log is itself the failure signal.
- **Relationship to VPS/RackGenius-level backups:** a whole-VPS or
  whole-disk backup is a second, coarser layer (disaster recovery for the
  host itself) and should NOT be treated as a substitute for this
  application-consistent snapshot -- a disk-level backup taken mid-write
  could capture `gb.db`/`gb.db-wal`/`gb.db-shm` in a torn state, whereas
  `VACUUM INTO` always produces one consistent file. Keep both layers.
