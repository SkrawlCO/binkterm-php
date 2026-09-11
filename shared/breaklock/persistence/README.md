# BreakLock leased progress (local review slice)

`Storage.php` delegates to `LeasedWebDoorStorage` with namespace `breaklock`, slot 0.
Tatham remains the default namespace (`tatham`, slot 0). Both are reserved against
ordinary WebDoor/SDK writes. The existing table, transaction locking, 30-second
lease, token hashes, attempt ID and revision checks are unchanged. No migration.

Only the canonical round snapshot occupies `data`; caller, namespace, lease and
revision are storage-envelope fields. This is private caller progress, not a score
or anti-cheat authority. `BreakLockRound.restore` validates state on both surfaces;
the PHP service stores opaque bounded JSON. Invalid stored state stops acquisition
without overwriting it.

`session.js` serializes requests, checkpoints every 10 seconds (renews on an empty
menu), and supports explicit checkpoint and save/release. Conflicts and transport
failures freeze input and callbacks. Requests time out after 8 seconds. Exit freezes
first, queues the final snapshot after any pending write, then releases. It is
idempotent. A failed exit does not claim successful storage or release.

`scheduler.js` captures residual countdown and pending-guess-reset delays using a
monotonic clock. Restore schedules the saved first callback delay, then one callback
per 1000ms interval. It never subtracts disconnected time or catches up ticks.
Only the surfaces own callbacks; the shared round continues to own all rules.

## Staged host seams

Web: a local host explicitly sets `window.breaklockStorage = { endpoint, csrfToken }`
before app.js. The default static build still has no storage endpoint. The staged,
unrouted `api.php` uses SDK bootstrap, project Auth (including POST CSRF validation),
and authenticated user identity. It accepts no caller or namespace from requests.
The local surface supplies Save & Return and read-only snapshot/ready plus checkpoint
and exit hooks on `window.breaklock`. Navigation is left to the host callback.
Storage errors freeze the surface and display an alert. No service worker.

Terminal: `BREAKLOCK_PERSISTENCE=1` opts play.js into the PHP JSONL helper. The trusted
launcher must supply `DOOR_USER_NUMBER`; the helper checks the active user against
the project database. Use the project's PHP 8.2+ runtime with PDO PostgreSQL. Q,
Ctrl-C, Ctrl-D, EOF and handled termination signals attempt save/release before
closing. Helper EOF releases its owned lease; SIGKILL/crash uses lease expiry.
Caller identity is not accepted from terminal keystrokes or JSON requests.

Abrupt browser close cannot guarantee a final asynchronous save. Pagehide freezes
the scheduler; recovery uses the last acknowledged checkpoint after lease expiry.
Use explicit Save & Return for exact handoff. No browser localStorage is used.

## Bounded proof

The test-only `fixture.php` refuses all but the explicit `tatham_slice1` DSN and
checks the actual database name. `proof.mjs` uses a disposable PostgreSQL container,
the real PHP storage class, a local browser review host with fixed caller cookie +
CSRF fixture, and independent terminal helper processes. It does not share state
objects between surfaces. The production Auth/CSRF entry point is staged and linted;
the proof substitutes review authentication, not the production login flow.

Run the existing TathamProgressTest plus the new namespace case against that isolated
database, then the core/terminal suites and `node shared/breaklock/persistence/session.test.js`.
Build the Web surface into `/tmp/breaklock-persistence-site` using web/build.mjs.
With the cached Playwright/Chromium paths supplied as PLAYWRIGHT_MODULE/CHROME_BIN,
run `node shared/breaklock/persistence/proof.mjs` from the repository root.
The script expects a disposable container named `breaklock-persistence-proof` with
PostgreSQL database `tatham_slice1` and the existing test credentials, and uses the
cached application PHP image read-only. Evidence: `/tmp/breaklock-persistence-proof.json`.

No routes, manifests, PP membership, deployment or caller exposure are installed.
