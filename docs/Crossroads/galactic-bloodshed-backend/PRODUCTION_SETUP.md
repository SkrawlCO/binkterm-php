# Galactic Bloodshed production setup (not yet performed)

This records the exact, reviewed commands for the steps this slice
deliberately stopped short of running: creating the permanent host data
directory, and scheduling backups. Nothing here has been executed. See the
parent slice report for the full architecture/design rationale.

## Host data directory (not created yet)

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

## Provisioner shared-secret token (not created yet)

```bash
install -d -o root -g root -m 700 /root/binktermphp/secrets
openssl rand -hex 32 > /root/binktermphp/secrets/galactic_bloodshed_provisiond_token
chmod 600 /root/binktermphp/secrets/galactic_bloodshed_provisiond_token
```

Mounted `:ro` into both the provisioner container (`GB_PROVISIOND_TOKEN_FILE`)
and binkterm-app (`gb_launcher.py`'s `GB_PROVISIOND_TOKEN_FILE`) -- see the
compose fragment. Same delivery pattern as `chessmata_broker_key`.

## Backup scheduling (not installed yet)

Method: the already-proven WAL-safe `VACUUM INTO` (`backup/backup-gb.sh`),
run against the live server -- no downtime required.

```cron
# /etc/cron.d/galactic-bloodshed-backup (NOT installed yet)
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
