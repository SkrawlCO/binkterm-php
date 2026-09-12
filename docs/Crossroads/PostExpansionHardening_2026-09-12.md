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

**Known future debt (not P1):** other Unit test files were found referencing
`Database::getInstance()` and were NOT individually assessed for mutation
risk. This is a **future test-safety survey**, not an active P1. (Originally
recorded here as "17" — reconciled and corrected to **18** during the survey
itself; see "PEH-P2 item 3 — Database::getInstance() test-safety survey"
below for the reconciliation and the completed, closed survey.)

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

## PEH-P2 — Backup/Recovery coverage closeout — 2026-09-12

Read-only investigation (no production touched, no restarts, no rebuilds).

**Storage topology.** The VPS has one primary disk, `vda` = 200 GB. `vda1` is
the single ext4 root filesystem mounted at `/` (~194G usable, 59% used at
inspection); `vda15` is the small EFI partition at `/boot/efi`. No additional
data disks, no network storage. `/var/lib/docker` and every identified
production bind-mount source reside on this same root filesystem.

The locally observed 200 GB `vda` disk matches, by size, the RackGenius
control-panel identification of the backed-up VPS "Drive A" (also human-
verified 2026-09-12: backups enabled, 11 restore points, multiple AVAILABLE
restore points Sep 5–11, retention "1 week, minimum 3 retained", Restore
function available). This is strong evidence that the single root disk
holding all identified recovery-critical state is the provider-backed Drive
A. Provider-side inclusion/exclusion semantics were intentionally **not**
independently re-investigated, so this is recorded as the supported
backup-coverage conclusion, not a newly proven provider implementation
detail.

**Recovery-critical state map:**

1. Primary BinkTermPHP PostgreSQL DB — `/root/binktermphp/pgdata` — bind
   mount → `binkterm-db`/postgres:16 — **CRITICAL**
2. App/runtime persistent state — `/root/binktermphp/app` (gitignored
   `data/` and similar: users, netmail/echomail attachments, FTN
   inbound/outbound spool, Doot state, config, uploads) — **CRITICAL**
3. MultiZork (player DB/pinned runtime/story) —
   `/root/binktermphp/state/multizork` — **CRITICAL**
4. Chessmata MongoDB (users/games/Elo) —
   `/root/binktermphp/state/chessmata-mongo` — IMPORTANT
5. Chessmata Maia2 model cache —
   `/root/binktermphp/state/chessmata-agent-models` — LOW, rebuildable via
   verified re-download
6. Galactic Bloodshed state — `/root/binktermphp/state/galactic-bloodshed` —
   WATCH, not currently a curated production Experience
7. Production secrets — `/root/binktermphp/secrets/*` (paths/roles recorded
   only; contents not inspected or reproduced) — **CRITICAL**
8. Production deployment definitions —
   `/root/binktermphp/docker-compose.yml`, `/root/binktermphp/docker/` —
   **CRITICAL**. Important recovery fact: the deploy repo (`master`) has
   **no remote**, so this filesystem is the sole known copy of that
   deployment git history/source.
9. Application source — `/root/binktermphp/app`, `experience-lobby-v2`,
   checkpoint `a84353b74` — **CRITICAL but redundantly recoverable** (has
   `origin`/GitHub).
10. LORD runtime state — `/root/lord-binkterm/runtime`,
    `/root/lord-binkterm/drops` — IMPORTANT. Flagged explicitly: these live
    **outside** `/root/binktermphp/` and are easy to overlook during
    recovery.
11. TLS state — `/etc/letsencrypt/` — **CRITICAL** for restored HTTPS.
12. Host Apache configuration (Cloudflare trust/proxy boundary) —
    `/etc/apache2/sites-available/*` — **CRITICAL**. Backup copies of some
    vhost material also exist in deployment repo material, but the active
    host configuration remains part of recovery.
13. ascii-royale-arena / tournament-trivia — no independent recovery-critical
    mutable state; runtime is image/source-defined.

**Live stack reconciliation.** The known 16 in-container supervisor programs
were reconciled and no unexplained ephemeral-only production state was
found. Architectural note: those 16 programs describe processes inside
`binkterm-app` only. The complete production ecosystem additionally includes
sibling containers — `binkterm-db`, Chessmata/Mongo/agents,
`doorparty-connector`, `l33test-lord`, Galactic Bloodshed/provisioning — all
covered in the state map above, all resolving onto the same root filesystem.

**Recovery order / checklist:**

1. Restore VPS/root filesystem from an appropriate RackGenius Drive A
   restore point.
2. Verify recovery-critical deployment/host configuration exists:
   `/root/binktermphp/docker-compose.yml`, `/root/binktermphp/docker/`,
   `/etc/apache2/sites-available/`, `/etc/letsencrypt/`.
3. Verify persistent state before recreating services: `pgdata`, app
   persistent/runtime data, `state/multizork`, `state/chessmata-mongo`,
   `state/galactic-bloodshed`, `/root/lord-binkterm/{runtime,drops}`,
   `secrets/`.
4. Verify required ownership/modes/identities/secrets. Dedicated
   in-container identities (`dosdoor`, `binkterm-admin`) use dynamically
   assigned UIDs and must be re-verified after rebuild/recreate, not
   assumed from prior numeric values (existing PEH guidance).
5. Recreate/start the production stack in dependency-aware order from the
   committed real deployment source under `/root/binktermphp`.
6. Verify stateful services: primary PostgreSQL healthy; BBS Directory
   regression anchor; Doot SQLite/state present; MultiZork state present;
   Chessmata Mongo replica set PRIMARY; other Experience state as
   applicable.
7. Verify application health: 16/16 supervisor RUNNING, cron RUNNING,
   `/crossroads` → 200, `/bbs-directory` → 200, `/doot-app/` → 200.
8. Verify externally facing paths: Cloudflare → Apache → Caddy proxy/trust
   chain, Telnet, SSH, BinkP :24554, other relevant endpoints.

**RESTORE VALIDATION REMAINS UNPROVEN.** No application-consistent restore
drill has been performed or authorized. Provider restore-point availability
plus this storage-location mapping establishes the current backup/recovery
*coverage* assessment — it does **not** establish that a restored VPS has
successfully booted the complete L33TEST stack, or that application data is
transactionally consistent after a restore.

**Follow-up/watch item (not investigated further, not fixed):** a running
container named `binkterm-modern-postgres` (postgres:18, Docker named
volume) is not referenced by the current production
`docker-compose.yml`; the live app is configured against `binkterm-db`
instead. Its named volume still resides on the same Drive-A/root
filesystem, so this does **not** create a backup-coverage gap. Purpose
appears orphaned/experimental from current evidence but is not conclusively
classified. Disposition: P2/P3 follow-up/watch — do not remove, stop,
inspect deeply, or modify absent a dedicated later transaction.

### P2 BACKUP / RECOVERY COVERAGE: CLOSED

Basis: all identified recovery-critical L33TEST persistent state resides on
the single 200 GB root disk; that disk corresponds by size/topology to the
human-verified RackGenius Drive A with active restore points; no
production-critical state was found on another disk, network filesystem, or
unexplained ephemeral-only storage; recovery-critical paths and recovery
order are now documented above.

Separate, standing limitation: **REAL RESTORE DRILL: UNPROVEN / NOT
AUTHORIZED.**

## PEH-P2 item 2 — Incomplete lifecycle sweep — CLOSED 2026-09-12

Bounded lifecycle recon (no broad crawl; already-closed lifecycle/security
work was not reopened). Found one concrete P2 code defect, and — during its
remediation preflight — one intervening P1 production defect. Both fixed,
activated, and verified live. Remaining concerns are explicitly classified
P3/WATCH or N/A below.

### echomail_robots.php overlap — CONFIRMED P2, now CLOSED

Static inspection of `scripts/echomail_robots.php` /
`src/Robots/EchomailRobotRunner.php` (scheduled every 5 minutes) established:
no flock/lockfile/PID guard, no DB advisory lock, no atomic work claiming, no
processor-level idempotency. `EchomailRobotRunner` reads
`last_processed_echomail_id`, selects up to 500 messages after that cursor,
processes the batch, and only then updates the cursor — so two overlapping
executions could select/process the same batch and produce duplicate
externally-visible robot replies/posts.

### Intervening cron log-directory permission defect — CONFIRMED P1, now RESOLVED

Discovered during flock remediation preflight, not part of the original
sweep target. Production cron had only recently been added to the **real**
deployment source (starting with deploy commit `b7984ad`, 2026-09-12) — the
real production build never had cron installed/supervised before that.
`rss_poster.php`, `echomail_robots.php`, and `logrotate.php` run as
`www-data` and redirect output into `/var/www/html/data/logs` (host bind
mount `/root/binktermphp/app/data/logs`), which was `root:root 0755` —
`www-data` had no directory write access. A direct disposable write test as
the exact cron identity confirmed `WRITE_FAIL` / `Permission denied`: shell
redirection aborted before PHP ever executed for any newly-created log
target. **Historical clarification:** an Aug-25 `rss_poster.php` PHP fatal
error found in `php_errors.log` came from some other execution context (real
production cron did not exist yet on that date) — it is not evidence that
real-production cron ever worked previously. This was a newly introduced
production defect from adding cron, not weeks of silent failure.

**Fix (host runtime only, no tracked source change):** identity mapping was
verified first (container `www-data` UID/GID `33:33`, host `www-data` GID
`33`, no user-namespace remapping), then
`/root/binktermphp/app/data/logs` — directory only, non-recursive, parent
`data/` and existing log-file ownership untouched — was corrected from
`root:root 0755` to **`root:www-data 0775`**. Post-fix disposable write test:
`WRITE_OK`, no residue. This is persistent host filesystem metadata on
Drive A and survived the later `binkterm-app` recreate.

**Durability caveat (documentation/deployment-hardening follow-up, P3/WATCH
unless evidence shows a more immediate need):** the real deployment source
still contains no deterministic fresh-host initialization step that
creates/sets `/root/binktermphp/app/data/logs` to `root:www-data 0775`. A
genuinely fresh host or new filesystem deployment would need this permission
re-established manually unless a future narrowly-scoped deployment
initialization mechanism is added. This does **not** reopen backup/recovery
P2 — that closure concerned storage-location coverage, not this specific
directory-permission determinism.

### Echomail flock remediation — activated and verified live

Real deploy source modified: `/root/binktermphp/docker/binkterm.cron`.
Deploy commit `4d485b1dc5943795621d0068446ad7f593948421` "Prevent
overlapping echomail robot cron runs". Live cron now:

```
*/5 * * * * www-data cd /var/www/html && flock -n /var/www/html/data/logs/.echomail_robots.lock php scripts/echomail_robots.php --quiet >> /var/www/html/data/logs/echomail_robots.log 2>&1
```

Schedule remains every 5 minutes, identity remains `www-data`, flock uses
nonblocking `-n`, lock path is
`/var/www/html/data/logs/.echomail_robots.lock`, original PHP
command/arguments/redirection preserved, `rss_poster`/`logrotate` lines
untouched. Activated by rebuilding and recreating only `binkterm-app` via the
existing `docker compose build`/`up -d --no-deps` path — no sibling service
rebuilt or recreated.

**Live acceptance:** BUILD PASS; RECREATE PASS; SUPERVISOR 16/16 RUNNING;
CRON RUNNING; HTTP `/crossroads`→200, `/bbs-directory`→200, `/doot-app/`→200;
live cron confirmed to contain the intended flock line;
`data/logs` confirmed `root:www-data 0775` after recreate (survived);
`www-data` write retest `WRITE_OK`; nonblocking-flock proof PASS (a harmless
`www-data` process briefly held the real lockfile while a concurrent
`flock -n` attempt against the same path correctly failed/skipped — no
PHP/application code invoked for this proof; the resulting empty
`.echomail_robots.lock` file is expected normal flock state, not disposable
residue). No production regression observed.

**echomail_robots.php overlap — P2: CLOSED.**

### Remaining lifecycle items — reclassified, not further investigated

- **Doot/CLASP restart/shutdown** — P3/WATCH. `supervisord.conf` uses
  `autorestart=true`, `stopsignal=TERM`, `stopwaitsecs=10`; no concrete
  orphan/stale-resource defect found.
- **WebDoor/game-service lifecycle leftovers** — P3/WATCH. Prior checkpoint
  language was too general to establish a concrete P2 defect; no specific
  production defect identified during this bounded sweep.
- **`binkterm-modern-postgres`** — P3/WATCH. Running `postgres:18`,
  dev/experimental-looking container; not referenced by current production
  compose; live app uses `binkterm-db`. No current production
  lifecycle/recovery dependency identified. Do not remove merely as part of
  PEH closeout.
- **Disabled IBBS/geo sync jobs** — NOT APPLICABLE while unscheduled;
  reconsider overlap/locking readiness only if activated later.
- **rss_poster/logrotate overlap** — P3/WATCH. Same absence of locking as
  echomail_robots, but interval (hourly / weekly) is sufficiently long
  relative to expected runtime that no current P2 overlap defect was
  established.

### PEH-P2 ITEM 2 — INCOMPLETE LIFECYCLE SWEEP: CLOSED

Basis: bounded lifecycle sweep completed; one concrete P2 defect found and
remediated/activated/verified; one intervening P1 cron-execution defect found
and resolved; no other concrete P2 lifecycle defect established; remaining
concerns explicitly classified P3/WATCH or N/A above.

## PEH-P2 item 3 — Database::getInstance() test-safety survey — CLOSED

### Count reconciliation

The checkpoint originally said "17 other Unit test files" (introduced at
commit `a84353b74`). Targeted reconciliation established this was an
authorship undercount, not a later addition: at `a84353b74`, `tests/Unit`
had 19 textual matches for `Database::getInstance()`; `IbbsImportServiceTest.php`
was already comment-only at that same commit (already remediated under
PEH-5, mentioning `Database::getInstance()` only to explain why it doesn't
use it), leaving an actual code-use count of **18**, unchanged then and now
— the filename set never changed. Corrected count: **18**.

### Static classification (before remediation)

```
TOTAL: 18
SAFE — NO MUTATION:              3
SAFE — ISOLATED:                 8
SAFE — MOCKED:                   0
RISK — DIRECT MUTATION:          0
RISK — INDIRECT MUTATION:        0
RISK — SETUP/TEARDOWN MUTATION:  7
UNCERTAIN:                       0
```

The seven confirmed mutation-risk files: `ChessmataIdentityTest.php`,
`ChessmataTerminalSessionTest.php`, `ChessmataWebSessionTest.php`,
`GalacticBloodshedIdentityTest.php`, `LoginThrottleTest.php`,
`MultiZorkAccessMappingTest.php`, `MultiZorkAccessRateLimitTest.php`. Root
cause: all seven called `Database::getInstance()` against ambient
production-capable configuration and performed real fixture/test mutation
without sufficient test-DB isolation. An initial `beginTransaction()`/
`rollBack()` remediation prevented any commit, but a later runtime preflight
established that `Database::getInstance()` still resolved to
`DB_HOST=binkterm-db` / `DB_NAME=binktermphp` — i.e. real production,
transactionally rolled back. "Production but rolled back" was explicitly
**not** accepted as sufficient test isolation, and the work below replaced
it with genuine database-target isolation.

### Isolated test-DB foundation

App commit `a9ac0058f71b8f5e7fdd88e3d6eccbb10e480831` "Add isolated test
database foundation". `tests/Unit/Support/TestDatabase.php`:
`TestDatabase::pdo()` reuses `DB_HOST`/`DB_PORT`/`DB_USER`/`DB_PASS`, **never**
reads `DB_NAME`, hard-forces `binktermphp_test`, uses
`PDO::ATTR_ERRMODE => ERRMODE_EXCEPTION`, and fails closed via
`SELECT current_database()` — refuses to return a PDO unless the connected
database is exactly `binktermphp_test`. `src/Database.php` gained a
test-only singleton seam: `Database::setInstanceForTesting(PDO $pdo)` and
`Database::resetInstanceForTesting()`. `setInstanceForTesting()`
independently re-checks `current_database() == binktermphp_test` itself
before replacing the singleton — it does not trust the caller's own check.
Production `Database::getInstance()` behavior is unchanged unless a test
explicitly invokes this seam.

### 18-file migration

App commits `e3fc5d5c0ee21a2f5f6d45af1acbf8c47cea31ec` "Isolate direct
database unit tests" and `1694b592ef3b99ff153002940e49a3cfa0d83be4` "Isolate
singleton database unit tests". Result: explicit `binktermphp_test` path
18/18, remaining production-DB test paths 0, uncertain 0. Design property:
fixture SQL and production code reached internally through
`Database::getInstance()` resolve to the **same** already-verified isolated
PDO — no split-brain test path in the surveyed scope.

### Test-DB schema initialization

`binktermphp_test` initially contained only `bbs_directory` and
`geocode_cache`, so the first runtime acceptance attempt failed on missing
application relations. Canonical fresh-schema mechanism established:
`database/postgresql_schema.sql` followed by `scripts/upgrade.php` applying
the committed `database/migrations/`. One pre-existing PEH-6
`geocode_cache` table (created directly by earlier test work, not via
migration) blocked migration `1.11.0.19` "Rename geocode cache"; after
explicit authorization, `DROP TABLE public.geocode_cache` (no CASCADE) was
run against `binktermphp_test` only, then `scripts/upgrade.php` completed
successfully. Final isolated-DB state: full canonical schema, 140 public
tables, all 253 migrations applied, latest `20260912102037`. Production DB
was not touched at any point.

### Nested-transaction follow-up

Runtime testing surfaced a legitimate test/application transaction
interaction in `ChessmataIdentityTest.php`, `ChessmataTerminalSessionTest.php`,
`ChessmataWebSessionTest.php`, `GalacticBloodshedIdentityTest.php`:
`ChessmataIdentity::resolve()` and `GalacticBloodshedIdentity::resolve()`
each legitimately own a real transaction (`pg_advisory_xact_lock`,
transaction-scoped) as production behavior. The tests' outer isolation
transaction therefore caused `PDOException: There is already an active
transaction`. Fixed in tests only — app commit
`732ca82e15c088b56b0aa06c7619ca411471cb35` "Fix nested transaction database
tests": the four tests remain hard-isolated to `binktermphp_test`, no longer
pre-open a conflicting transaction, use deterministic narrowly-scoped
per-fixture-ID cleanup in `tearDown()`, preserve application-owned
production transaction semantics exactly, and leave zero fixture residue.
Production code was not modified.

### Seven-file runtime acceptance — PASSED

The originally risky seven: 75 tests / 252 assertions / 73 passed / 0 failed
/ 0 errors / 2 known unrelated skips (`ChessmataSecretBox::isConfigured()`
environment condition). Test-DB residue: NONE. Production DB touched: NO.

### Complete 18-file runtime acceptance

Hard isolation gate (helper + singleton seam) PASS: both report
`current_database() = binktermphp_test`, same PDO object, reset confirmed.
Result: exit code 2, 183 tests, 539 assertions, 161 passed, 0 failed, 1
error, 21 skipped.

Classification of non-pass results — isolation failures: **0**; schema
failures: **0**; functional regressions: **0**; production DB touches:
**0**; test-DB fixture residue: **NONE**; known environment skips:
`ChessmataSecretBox` configuration (unrelated to DB isolation).

P3/WATCH data-dependency results (the only non-pass category populated):
1. `TerminalMenuDataDirectFetchTest.php` — hardcoded `UID = 3`; isolated DB
   lacks that assumed populated user; class-wide skip.
2. `TerminalMessageListDirectFetchTest.php` — same hardcoded `UID = 3` /
   populated-data assumption; several safe per-test skips; one FK error
   when legitimate `user_settings` write behavior was attempted for
   nonexistent `user_id = 3` — classified as test-data dependency, **not**
   an application functional regression; no row persisted.

Prior P3/WATCH fixture-dependency notes for `MultiZorkAccessMappingTest.php`
/ `MultiZorkAccessRateLimitTest.php` are retained, though both currently
**pass** because the canonical `binktermphp_test` schema/migrations happen
to leave exactly two users available.

### Final test-safety disposition

**PEH TEST-SAFETY P2 ITEM: CLOSED.** Basis: corrected scope reconciled to
18; 18/18 statically classified; the seven confirmed mutation-risk tests
remediated; 18/18 explicitly isolated to `binktermphp_test`; internal
singleton paths redirected safely; test helper and singleton seam both fail
closed; full isolated schema established canonically; seven-risk-file
runtime acceptance passed; complete 18-file runtime acceptance showed zero
isolation failures, zero schema failures, zero functional regressions, zero
production DB touches, zero fixture residue. Remaining non-pass behavior is
exclusively P3/WATCH fixture/environment dependency, tracked below — not a
reason to leave this item open.

## Remaining PEH work

All P0: **CLOSED**. All P1: **CLOSED**.

Completed P2: deployment source ownership documentation, log/disk rotation,
realtime_server/binkp_scheduler/binkp_server privilege drop, admin_daemon
privilege drop.

**Remaining P2 / closeout work:**

1. ~~**Backup/recovery closeout**~~ — **CLOSED 2026-09-12**, see "PEH-P2 —
   Backup/recovery coverage closeout" above. Restore drill remains
   UNPROVEN/not authorized (unchanged, not required for this closure).
2. ~~**Incomplete lifecycle sweep**~~ — **CLOSED 2026-09-12**, see
   "PEH-P2 item 2 — Incomplete lifecycle sweep" above (echomail_robots.php
   overlap fixed/activated/verified; intervening cron log-directory P1
   fixed; remaining items reclassified P3/WATCH or N/A).
3. ~~**Test-safety survey**~~ — **CLOSED**, see "PEH-P2 item 3 —
   Database::getInstance() test-safety survey" above (18/18 isolated to
   `binktermphp_test`; seven mutation-risk tests remediated and
   runtime-accepted; complete 18-file runtime acceptance showed zero
   isolation/schema/functional/production-touch issues). **No substantive
   P2 investigation remains.**
4. **P3/WATCH** — Docker json-file stdout growth; dead
   `DoorSessionManager::startBridge()`/`startDosBox()` cleanup; optional real
   DOS-door human/functional privilege acceptance; low-risk documentation
   polish; Doot/CLASP restart/shutdown; WebDoor/game-service lifecycle
   leftovers; `binkterm-modern-postgres` orphaned container;
   rss_poster/logrotate overlap; fresh-host `data/logs` permission
   initialization determinism; MultiZork real-existing-user/test-data
   dependency; `TerminalMenuDataDirectFetchTest.php` hardcoded UID 3
   fixture dependency; `TerminalMessageListDirectFetchTest.php` hardcoded
   UID 3/populated-data dependency.
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

Items 1–3 (Backup/recovery closeout, Incomplete lifecycle sweep, Database
test-safety survey) are all now **CLOSED**. No substantive P2 investigation
remains. Next candidates are item 4 (classify/finalize the accumulated
P3/WATCH items) or item 5 (PEH final closeout) — pick whichever Matt
prioritizes; both are bounded, narrow, low-risk.
