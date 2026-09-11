# Ordinary Puzzles local leased progress

Uses the existing `LeasedWebDoorStorage`, namespace `ordinary-puzzles`, slot 0.
The only shared storage change is adding this reserved namespace. Transactions,
30-second leases, hashed ownership tokens, attempt IDs, revision checking and
102400-byte payload limit remain unchanged. No schema or canonical model changes.
Generic SDK/controller writes remain prohibited for all three leased namespaces.

Only the existing session export is stored as data: canonical puzzle ID, pinned
revision, ordered interaction log and replay verification state. Acquisition
replays through `OrdinaryPuzzlesSession.restore` before enabling input. Invalid
saved state is preserved, acquisition releases its lease and input remains frozen.
No direct model mutation, compaction, gameplay rules or database identity fields
are added to the snapshot.

## Local seams

Web: a trusted review host may set `window.ordinaryPuzzlesStorage = {endpoint,
csrfToken}` before loading the existing Web bundle. Await
`window.ordinaryPuzzles.ready`. `checkpoint()`, `exit()` and `revision()` expose
storage operations; Checkpoint and Save & Release buttons are supplied locally.
`api.php` is deliberately unrouted, outside the public tree. It uses project
`Auth.requireAuth()` including normal POST CSRF validation and derives caller ID
from the validated session. Requests cannot select a caller or namespace.
Responses and fetch requests use no-store. No service worker or localStorage.

Terminal: `node shared/ordinary-puzzles/terminal/persistent.cjs` is an opt-in
trusted local launcher. It accepts no CLI arguments. Its inherited
`DOOR_USER_NUMBER` must come from trusted launcher context, never caller input.
The PHP helper verifies an active database user. It requires the project PHP
runtime with PDO PostgreSQL. The normal CLI remains usable without storage.
Q, Ctrl-C, EOF, SIGHUP and SIGTERM restore terminal modes, then attempt queued
save/release; failures report an error and nonzero exit. Helper EOF releases its
last acknowledged checkpoint. Uncatchable termination recovers by lease expiry.

`session.cjs` serializes requests, checkpoints every 10 seconds and times requests
out after 8 seconds. Explicit exit freezes input before capturing the snapshot,
waits behind pending writes, saves and releases. It is idempotent. Errors freeze
input and stop heartbeats; no failed write is reported as saved. Browser pagehide
stops its writer; abrupt close cannot guarantee a final save, so later acquisition
uses the last acknowledged checkpoint after expiry. Use explicit exit for exact
unfinished-drag handoff. No host navigation or manifests are installed.

The PHP facade/helper and JSONL transport follow the existing BreakLock plumbing;
BreakLock files and gameplay are unchanged.

## Bounded proof

`proof.mjs` uses the actual Web bundle, pointer events, terminal adapter and
independent PHP helpers against real PostgreSQL in a disposable `tatham_slice1`
database. It uses active fixture database callers 1 and 2, not live caller data.
A database-backed `binktermphp_session` and `users_meta` CSRF token are validated
by the real project Auth class, not a replacement auth implementation. The test
bootstrap injects only the disposable PDO connection; test-router suppresses
unrelated daily-credit work. No live database or production login is modified.

Test-only files refuse other database names/DSNs. The review server is bound to
host loopback through Docker; it is stopped after the proof. No production route
mounts these fixtures.

Reproduce with existing cached PHP/PostgreSQL images and Playwright/Chromium:

1. Start disposable `ordinary-persistence-proof` using postgres:16,
   POSTGRES_DB=tatham_slice1, POSTGRES_PASSWORD=slice1-test-only and
   port binding `127.0.0.1:43189:8080`.
2. In the cached application PHP image, mount the repository read-only at /work,
   join `container:ordinary-persistence-proof`, set
   `TATHAM_TEST_DSN=pgsql:host=127.0.0.1;dbname=tatham_slice1`, and run
   `php vendor/bin/phpunit --no-configuration --do-not-cache-result --bootstrap vendor/autoload.php tests/Integration/TathamProgressTest.php`.
3. Build with `ALLOW_FONT_FALLBACK=1 npm run build --prefix shared/ordinary-puzzles/web`.
4. In another temporary PHP container with the same mount/network/environment,
   run `php -S 0.0.0.0:8080 -t shared/ordinary-puzzles/web/dist shared/ordinary-puzzles/persistence/test-router.php`.
5. Set PLAYWRIGHT_MODULE and CHROME_PATH to existing local installations; run
   `node shared/ordinary-puzzles/persistence/proof.mjs` from repository root.
6. Stop both disposable containers. Proof evidence is written outside the tree
   to `/tmp/ordinary-persistence-proof.json`.

Checks: 13 database/browser handoff checks; 5 session tests; 6 shared PostgreSQL
regressions (105 assertions); existing 15 shared, 13 terminal and 19 Web checks.
Expiry is advanced only in the disposable database row, without a 30-second wait.

Measured snapshot sizes (UTF-8 JSON): early committed line plus unfinished drag
10602 bytes; longer play including 12 extend/reset cycles 12889 bytes; solved
quire 11840 bytes. The journal remains unbounded over a sufficiently long session;
compaction is deferred. The existing payload limit fails safely and freezes the
writer if exceeded; these ordinary proof sessions require no compaction.
