# Post-Expansion Hardening (PEH) — 2026-09-12 Checkpoint

This is the durable resume point for the L33TEST Post-Expansion Hardening
(PEH) track. It is a checkpoint, not a transcript — read this before
resuming, do not re-derive it from conversation memory.

## Verified reality at checkpoint time

| | branch | HEAD | status |
|---|---|---|---|
| **Deploy repo** `/root/binktermphp` | `master` | `fac4177` "Drop admin daemon privileges" | clean |
| **App repo** `/root/binktermphp/app` | `experience-lobby-v2` | `f7716b1a5` "Test geocode provider failure semantics" | clean except protected untracked files below |

Protected app-repo untracked files (never inspect/modify/stage/delete/move,
never `git clean`):
- `native-doors/doors/lord/lord-bridge.js`
- `src/Binkp/Connection/Scheduler.php.bak`

Production: `binkterm-app` container, 16/16 supervisor RUNNING, cron RUNNING.
Health: `/crossroads` 200, `/bbs-directory` 200, `/doot-app/` 200.
Regression anchors: `bbs_directory` = 1,043 rows (1 manual/local L33TEST +
1,042 IBBS), Doot SQLite present, no regression observed.

## The two-repo architecture (do not reopen this question)

**Actual L33TEST production deployment owner:** `/root/binktermphp` (branch
`master`, no remote). Production compose is
`/root/binktermphp/docker-compose.yml`; the production build context is
`/root/binktermphp/docker/` (`Dockerfile`, `supervisord.conf`, `Caddyfile`,
`binkterm.cron` are the authoritative deployment files).

**App repo:** `/root/binktermphp/app` (branch `experience-lobby-v2`) owns
BinkTermPHP/L33TEST application code.

**`app/docker/` is NOT the L33TEST production build source.** This is
already documented in the deploy repo's `README.md` and the app repo's
`docker/README.md` (warning banner). Do not reopen this absent new evidence.

## PEH core rebuild result — CLOSED, do not reopen

The original rebuild-survivability P0 is closed. History: the original audit
found supervisor/source drift; the real production build context was proven
to be the parent deploy repo, not `app/docker/`; the parent Compose had an
undefined Galactic Bloodshed secret reference (fixed); the real production
build lacked cron (fixed); adding cron exposed a UID/GID collision with
`messagebus` pulled in transitively by `apt-get install cron` (fixed by
reserving service account IDs *before* the apt-get layer, not with
`--non-unique` — two accounts must never share one numeric UID/GID). The real
production image was then built and `binkterm-app` was destroyed/recreated
from committed source; persistent state survived; live
supervisor/Caddy/cron configs matched committed source exactly.

## Current production topology (16 programs)

php-fpm, caddy, admin_daemon, binkp_scheduler, binkp_server, realtime_server,
dosdoor_bridge, telnet, ssh_daemon, multizorkd, ascii-royale-arena,
openglad-relay, doot-clasp-relay, doot-app, tournament-trivia-srv, cron.

**Current privilege model:**

Non-root:
- `realtime_server` → `www-data`
- `binkp_scheduler` → `www-data`
- `binkp_server` → `www-data`
- `admin_daemon` → dedicated `binkterm-admin`
- NativeDoor **children** → dedicated `dosdoor` via `setpriv`

Intentionally root:
- `dosdoor_bridge` orchestration process itself — it owns privileged
  orchestration/control-socket behavior; the door children it actually
  spawns are forcibly privilege-dropped (see PEH-3 below).

Dedicated account IDs observed at this checkpoint: `dosdoor` 992/992,
`binkterm-admin` 991/991 — **dynamic**, must be re-verified after any rebuild
(`docker run --rm binktermphp-binkterm-app:latest getent passwd <name>`)
rather than hardcoded for any host-ownership operation.

## PEH-3 — NativeDoor child privilege hardening — CLOSED

Live launch architecture is the persistent supervisor-managed
`scripts/dosbox-bridge/multiplexing-server.js`. `DoorSessionManager::startBridge()`/
`startDosBox()` in PHP are confirmed dead/unreachable (zero call sites,
legacy private methods) — this was traced explicitly, not assumed. The
actual final child launch is hardened with
`setpriv --reuid=<dosdoor> --regid=<dosdoor> --clear-groups`; the identity is
resolved by reading `/etc/passwd` directly (trusted, manifest-independent) —
a manifest object has no path to influence uid/gid; the bridge fails closed
if the dosdoor identity is missing or resolves to uid/gid 0. Mechanism tests
(`scripts/dosbox-bridge/test-privilege-drop.js`, 6 assert-based tests) passed.

Real DOS-door functional launch acceptance was not completed because ADMIN
(the door used for the attempt) is disabled in config, and a subsequent trace
established there is no second live launch path to worry about. This is
**OPTIONAL FUTURE ACCEPTANCE, only when a real DOS door is next actually
used** — not an open security finding. **PEH-3 is CLOSED.**

## IBBS directory state

Total 1,043: 1,042 IBBS + 1 manual/local L33TEST. Source edition `ibbs0926`
from https://www.telnetbbsguide.com/lists/download-list/ (live official
acquisition proven). ~4 likely aliases skipped; 14 distinct shared-endpoint
boards preserved. Monthly sync automation exists but remains **disabled**.

Geocoding: Nominatim, cached, throttled; map visually human-accepted after
CSP/img-src + hidden-tab work; ~83 directory rows geocoded at last verified
checkpoint. Geo automation remains **disabled**.

## PEH-5 — IBBS test isolation — CLOSED

Dedicated Postgres test DB `binktermphp_test`. `IbbsImportServiceTest` no
longer uses `Database::getInstance()`; it explicitly injects a test PDO and
fails closed unless `current_database()` equals `binktermphp_test`.
Interruption proof passed; two consecutive runs: 20 tests / 72 assertions.
Production remained at 1,043 rows with 0 IBBS-test fixture rows throughout.

**Known future debt (not P1):** 17 other Unit test files were found
referencing `Database::getInstance()` and were NOT individually assessed for
mutation risk. This is a **future test-safety survey**, not an active P1.

## PEH-6 — Geocoder failure-semantics hardening — CLOSED

`geocode_cache` now distinguishes `success` from `no_result`; provider/network
errors are never persisted, so a transient Nominatim outage stays retryable
and can never permanently poison the cache. Migration:
`database/migrations/v20260912102037_geocode_cache_status.sql`. Tests:
`tests/Unit/BbsDirectoryGeocoderTest.php` — 8 tests / 19 assertions, two
consecutive PASS runs, zero real Nominatim requests, production cache
untouched.

## PEH-P2A — Log/disk rotation — PASS, CLOSED, no fix required

Filesystem ~59% used, ~81 GB free at audit. Active `data/logs/*.log` ~25 MB.
Weekly `scripts/logrotate.php` uses copy-truncate semantics (safe for
already-open writer file descriptors), `--keep=52`; all current material app
logs are covered by its flat glob. Several services are independently bounded
by Supervisor (`maxbytes=10MB`, `backups=3`).

**Parked WATCH item (P3):** Docker json-file logging for the 5
stdout/stderr-routed services (php-fpm, caddy, dosdoor_bridge, telnet,
ssh_daemon) has no explicit compose cap, but was only ~11 KB at audit. Not a
current action item absent material growth.

## PEH-P2C — realtime/BinkP privilege drop — PASS, CLOSED

`realtime_server`, `binkp_scheduler`, `binkp_server` now run as `www-data`
(deploy commit `85b8e52`). None had a credible root requirement: all
listening ports unprivileged (6010, 24554), `binkp_scheduler` is
outbound-only, spool dirs (`data/inbound`/`data/outbound`) were already
`www-data`-owned. Verified real functionality post-recreate (config loaded,
ports bound, logs/PIDs correctly written as the new user), zero permission
errors, production healthy.

## PEH-P2D — Admin daemon privilege drop — PASS, CLOSED

`admin_daemon` now runs as dedicated `binkterm-admin` (uid/gid 991/991,
supplementary group `www-data`). The supplementary `www-data` group
membership exists *only* because `binkterm-admin` needs read access to the
group-readable `.env` (`root:www-data 0640`) holding `ADMIN_DAEMON_SECRET` —
this is a read-only, admin-reads-from-www-data grant; it does not give
`www-data` any new access to admin-managed config.

Admin-owned config files: `filearea_rules.json`, `jsdosdoors.json`,
`lovlynet.json`, `webdoors.json` — ownership narrowly transferred to
`binkterm-admin`, mode left at world-readable `0644` unchanged. (`aio.json`
is not currently present in this deployment.) `www-data` retains read access
and does **not** gain write access (proven).

Real authenticated admin-protocol acceptance passed end-to-end over the live
socket (port 9065): `get_filearea_rules` then `save_filearea_rules` with the
same content, both `ok=true`. Sensitive-path denial proved: `binkterm-admin`
cannot read the Doot app's `.env`, cannot write `config/binkp.json`.
Production remained healthy throughout. Deploy commit: `fac4177` "Drop admin
daemon privileges".

## RackGenius backup evidence — HUMAN VERIFIED, 2026-09-12

Matt visually verified in the RackGenius control panel: L33TEST VPS Drive A
= 200.0 GB; provider-managed backups enabled; 11 backups shown; most recent
~10 hours old at time of inspection; next scheduled backup displayed;
retention displayed as "1 week, minimum 3 retained"; Restore control
available; multiple historical restore points visibly marked AVAILABLE
across Sep 5, 6, 7, 8, 10, 11 2026.

**Interpretation:** provider-side drive backup *coverage* is human-verified.
**Do NOT claim** application-consistent restore has been tested or that an
actual restore drill has been performed — both remain unproven.

## Remaining PEH work

All P0: **CLOSED**. All P1: **CLOSED**.

Completed P2: deployment source ownership documentation, log/disk rotation,
realtime_server/binkp_scheduler/binkp_server privilege drop, admin_daemon
privilege drop.

**Remaining P2 / closeout work:**

1. **Backup/recovery closeout** — confirm all important L33TEST persistent
   state resides on RackGenius-backed Drive A; document recovery order; no
   restore drill required unless explicitly chosen later.
2. **Incomplete lifecycle sweep** — revisit only unresolved/partially-covered
   P2 lifecycle items from the broader audit (Doot/CLASP cleanup leftovers,
   WebDoor lifecycle leftovers, game-service lifecycle leftovers, unattended
   cron-job safety, disabled IBBS/geo job overlap/locking readiness, minor
   DB/index/migration findings). Do not reassay already-proven rebuild
   persistence.
3. **Test-safety survey** — assess the 17 other Unit tests using
   `Database::getInstance()`; fix only actual production-mutating risks.
4. **P3/WATCH** — Docker json-file stdout growth; dead
   `DoorSessionManager::startBridge()`/`startDosBox()` cleanup; optional real
   DOS-door human/functional privilege acceptance; low-risk documentation
   polish.
5. **PEH final closeout** — classify remaining items FIXED / ACCEPTED /
   PARKED and create the final durable PEH completion checkpoint.

## Do NOT reopen without new evidence

Track A A1–A6; PEH rebuild/recreate survivability; production Docker source
ownership; cron installation/supervision; service UID/GID collision;
NativeDoor child privilege security architecture; IBBS production test
isolation; geocode provider-error cache semantics; logrotate coverage;
realtime/BinkP/admin privilege drops.

## Session workflow rules (preserve)

Small bounded transactions; hard wall-clock budgets; no 20–60 minute
speculative runs; investigate before fixing; one objective per transaction;
PASS means permission to spend one more transaction; stop on unexplained
divergence; no broad refactors during acceptance; never touch protected
files; do not push unless explicitly authorized; do not introduce unrelated
topics midstream; do not create giant durable reports for disposable
candidate assays.

## Recommended next-session objective

Start with **item 1 (Backup/recovery closeout)** — it is the narrowest,
lowest-risk remaining item and was explicitly flagged by Matt tonight: confirm
persistent-state coverage on Drive A and write the recovery-order checklist,
without performing a restore drill.
