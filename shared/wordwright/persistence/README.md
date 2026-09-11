# Wordwright caller-state persistence (local review only)

Namespace `wordwright`, slot `0`, existing `webdoor_storage`. The sole shared
platform change reserves this namespace in LeasedWebDoorStorage. No production
schema change, final manifests, routes, PP membership or legacy Wordle migration.

## Atomicity and ownership

`client.ts` serializes lease acquire/checkpoint/release operations. Each save sends
one entire existing Wordwright snapshot: canonical game, selected length, meta and
stats. One PostgreSQL row-lock transaction replaces that JSON value and advances
its storage revision. Lease owner token, attempt ID and expected revision protect
against stale writes. They live in storage metadata, not game state. Owner tokens
are hashed server-side. Existing lease duration is 30 seconds; client checkpoints
every 10 seconds. There is no new concurrency model.

Both checkpoint and explicit release call shared prepareHandoff(), then save one
coherent snapshot. Pending reveal settles via canonical REVEAL_COMPLETE and the
canonical stats fold before serialization. Terminal uses its normal immediate
reveal; Web's animation can finish early at an explicit/periodic checkpoint, with
the canonical callback timestamp. No separately raceable stats or meta writes.
This avoids the known raw revealing-to-playing restore ambiguity after recovery.
GameState retains its canonical Guess.evaluation fields; no extra tile projection,
keyboard state, remaining attempts, percentages or averages are stored.

Input stops immediately on explicit release, before queued writes drain. Conflicts
or transport errors stop the writer and heartbeat; they do not overwrite a
successor or silently retry a stale state. An uncertain timed-out write may have
committed; reacquire the authoritative row after lease expiry. Do not merge local
stats into it. Uncatchable shutdown restores the last periodic checkpoint.

## Authentication and local surfaces

`api.php` is deliberately unrouted. A local review host may mount it. It uses
project Auth.requireAuth(), including the normal per-user CSRF token check on
POST, authenticated active caller ID, and no-store responses. Caller/namespace
fields in request bodies are rejected. `webTransport` requires same-origin URLs,
session credentials and X-CSRF-Token. There is no query/body impersonation path.

From the local Web review console, after authenticating on that review origin:

    await window.wordwrightReview.connect('/state', csrfToken)

The surface displays revision/writer status and Checkpoint / Save & Release
buttons. Connect restores the authoritative caller snapshot; arbitrary review
imports are disabled in connected mode. Loss of writer ownership disables game
input and stats resets. Reconnect after a release acquires the stored state.
Presentation-only theme/motion settings remain local memory. No service worker.

`terminal/persistent.mjs` is a trusted local launcher with no CLI identity or
snapshot arguments. `helper.php` obtains inherited DOOR_USER_NUMBER and checks the
caller is active. `node-transport.mjs` exchanges line-delimited requests with that
PHP helper. Normal quit and handled termination save/release before closing the
helper; EOF releases the helper's last lease where possible. SIGKILL uses expiry.
A future NativeDoor supplies the trusted environment; none is registered here.

Caller saves are opaque, caller-owned progress, not a server-authoritative public
scoreboard/anti-cheat system. Canonical restore validates the shared schema and
known answer on each surface. Legacy `wordle` saves and webdoor_leaderboards are
never accessed by these production adapters.

## Tests and review harness

    npm test                                  # parent: 37 shared tests
    node persistence/client.test.mjs          # parent: 6 queue/failure tests
    node terminal/adapter.test.mjs             # parent: 18 terminal tests

Web build remains `npm run build --prefix web`; its browser suite has 31 checks.
The PHP TathamProgressTest suite now includes Wordwright isolation/reserved-slot
coverage: 7 tests. Run it ONLY with the established disposable tatham_slice1 DSN,
never the application database (its fixture truncates its test storage table).

`proof.mjs` exercises actual Web -> authenticated PHP -> application PostgreSQL ->
Node terminal -> PostgreSQL -> Web. It includes completed/partial states, stats
dedupe, reveal completion, length selection, all recent-answer caps, two callers,
CSRF/identity rejection, stale revisions, visible Web conflict, and bounded expiry.
It requires explicit isolated review callers, not real callers' credentials.

Workstation reproduction helpers are under review/, not mounted production paths:
- setup.php creates two isolated active callers and a private credential file;
  requires WORDWRIGHT_REVIEW=1. `cleanup` checks aggregate fingerprints of all
  legacy Wordle saves and all leaderboards, then deactivates the test callers,
  removes their sessions/CSRF tokens and Wordwright state. It retains disabled
  account shells for referential integrity. No passwords are emitted.
- router.php runs on container loopback 43192 and mounts only this API. It skips
  unrelated daily-credit processing only for the isolated fixture identities.
- proxy.php forwards actual HTTP to that loopback API; server.mjs binds host
  loopback 43193, serves the built Web files and proxies /state via docker exec.
  No live HTTP routing or published container port is changed.
- control.php expires only caller A's Wordwright lease for bounded crash recovery;
  requires WORDWRIGHT_REVIEW=1. No gameplay/session mutation is used to force wins.

Copy helpers to the scratch /tmp/ww-* paths referenced by them, start both temporary
loopback processes, and keep credentials at /tmp/wordwright-persistence/callers.json
(mode 0600), or pass WORDWRIGHT_PROOF_CALLERS to proof.mjs. The expiry invocation passes WORDWRIGHT_REVIEW=1 for the guarded helper. Override
WORDWRIGHT_PROOF_URL, PLAYWRIGHT_MODULE and CHROME_BIN as needed. Stop the test
servers, run cleanup and remove private credential files after testing.

No snapshot compaction is needed: measured completed/early/longer snapshots in
this proof were approximately 1.5 / 1.5 / 2.8 KB (existing limit 100 KB).
