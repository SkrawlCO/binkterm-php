# Dokuel caller persistence — shared production plumbing

Namespace **dokuel**, slot **0**, existing PostgreSQL `webdoor_storage`. The only
shared storage implementation change reserves this name. No schema change or new concurrency system. Production WebDoor and NativeDoor
entrypoints are documented in the parent README.

`Storage.php`, `api.php`, `helper.php`, the transport and client follow the existing
Wordwright integration at `shared/wordwright/persistence/`. PostgreSQL row locks,
30-second leases, hashed owner tokens, attempt IDs and expected revisions remain
owned by `LeasedWebDoorStorage`. Client saves serialize; 10-second checkpoints
renew the lease. Errors stop input and timing and remain visible. A timed-out save
may have committed: acquire authoritative storage after expiry, never retry a
stale local state over its successor. Payloads retain the existing 100 KiB limit.

## Exact state and timing

One shared `DokuelSession.snapshot()` is stored atomically, unmodified: schema 1,
pinned upstream revision, original givens, difficulty, solo key or Daily date,
assistance, canonical action journal, elapsed milliseconds and paused flag.
Canonical replay restores values, notes, selection, undo, hints and completion.
No regenerated puzzle, recomputed active hint, derived conflict/candidate cache,
solution or additional statistics are persisted. Revision/schema mismatches are
rejected by shared restore; an acquired incompatible snapshot is released intact.
There is no migration framework or new Sudoku logic.

Only live surface ticks advance shared elapsed time. Checkpoint does not pause;
release freezes input/ticks and preserves the current pause flag. Disconnected
wall time never counts, including an unpaused handoff. Pause remains explicit.
Completion freezes canonical time and has no persistence side effect to duplicate.

## Web and terminal

Build from the parent with `npm run build`; Web uses its pinned Bun build.
The local host explicitly connects after project authentication:

    await window.dokuelReview.connect('/state', csrfToken)

`api.php` is deliberately unrouted. It uses project Auth.requireAuth(), active
session identity and CSRF checking. Requests cannot supply caller or namespace.
The browser requires same origin, credentials, CSRF and no-store. Connected UI
shows revision/failure, Checkpoint and Save & Return. Release disables the surface;
a host reconnect reacquires. Arbitrary review import is disabled while connected.
Theme settings remain memory-only; no service worker or browser-authoritative save.

The trusted launcher runs `node terminal/persistent.mjs` with inherited
`DOOR_USER_NUMBER`; caller CLI arguments are rejected. The PHP helper checks an
active project user. Existing terminal gameplay/new-mode input remains unchanged;
Esc then E checkpoints to PostgreSQL, Esc then Q saves/releases. Ctrl-C, SIGTERM,
SIGHUP and clean EOF use handled save/release; helper EOF releases the last lease.
SIGKILL/transport loss recovers the last checkpoint after expiry. The previous
`terminal/cli.mjs` remains an independent local snapshot review entrypoint.

These are trusted caller-owned progress snapshots, not an anti-cheat scoreboard.
The local presentation remains English as in prior slices; final host localization
and mounting belong to later integration.

## Proof and reproduction

- `client.test.mjs`: 7 serialization, ownership, restore/version and timing checks.
- `proof.mjs`: 15 real authenticated Web/PostgreSQL/terminal checks, including both
  directions, generated progress/notes/history, logical hint, Daily, completion,
  paused and unpaused timing, caller isolation, stale writer and bounded expiry.
- `mobile-proof.mjs`: 3 checks for exact Mistake handoff, connected 375/320px layout
  and touch Save & Return.
- `pty-proof.py`: 2 real 80x24 persistent CLI checks: quit and SIGTERM save/release,
  fresh process acquisition, canonical identity and terminal-mode restoration.
- Existing 129 shared/Web/terminal checks pass. `TathamProgressTest` passes all 8
  PostgreSQL tests including Dokuel isolation from all four existing namespaces
  and generic SDK/controller reserved-slot protection.

The PHP regression suite truncates fixtures: **only** run against disposable
`tatham_slice1` using `TATHAM_TEST_DSN`, never application database defaults.
Use the project PHP runtime (host PHP 8.1 is too old).

Review helpers under `review/` are not production routes. With DOKUEL_REVIEW=1,
setup.php creates two isolated active callers and mode-0600 credentials at
container `/tmp/dk-proof-callers.json`; copy privately to host
`/tmp/dokuel-persistence/callers.json`. It refuses to overwrite an existing fixture.
Run temporary container loopback PHP on 43202 with review/router.php, then host
`node shared/dokuel/persistence/review/server.mjs` on loopback 43203. The proxy
uses actual HTTP authentication, not injected caller identity. Tests use cached
Chromium through PLAYWRIGHT_BROWSERS_PATH. No production server routing changes.

`control.php expire` expires only recorded test caller A's Dokuel lease, allowing
bounded crash recovery. After tests run setup.php cleanup; audit.php cleanup can
also remove orphaned cryptographically named Dokuel fixture sessions/state from
an interrupted setup. Disabled account shells/settings remain for integrity.
Stop temporary servers and remove private scratch credentials. Aggregate unrelated
live-storage fingerprints are informational: concurrent real caller changes must
not prevent fixture cleanup. Namespace correctness is tested in the isolated DB.

Final measured JSON sizes: early **320 bytes**, 400-note-action/history partial
**24,370 bytes**, completed **375 bytes**. No compaction is needed for these samples;
long sessions can eventually hit the existing limit and visibly stop saving.
Journals remain pinned to upstream semantics; future revisions require an explicit
compatibility decision. No canonical engine source was modified.
