# Wordwright shared session

Local shared core only. No UI, routes, caller storage, leases, or PP configuration.
Legacy Wordle remains separate; no migration or shared identity.

## Build and test

Node >=20.19. From this directory:

    npm ci --ignore-scripts
    npm test

Only development dependency: TypeScript 6.0.3 (locked). `tsc` typechecks and emits
ES2022 ESM plus declarations; `build.mjs` mechanically resolves `@/` aliases and
extensionless imports in generated output. Canonical TS files remain byte-for-byte
unchanged. Import `dist/session.js` from Node or a future browser bundle.
`dist/` is versioned production output; `node_modules/` is ignored. No service-worker change is needed:
this directory is not served or registered with the host.

## API and ownership

`await WordwrightSession.create(length?, dependencies?)` loads all three canonical
dictionaries once through upstream's cached loader, then starts a canonical random
game. Loading all lengths up front keeps subsequent actions synchronous and avoids
length-switch races; future packaging may split chunks without changing rules.
Dependencies optionally inject clock, ID factory and RNG for deterministic tests.
There is no answer override, substitute dictionary or evaluation implementation.

Synchronous methods: `setLength`, `newGame`, `inputLetter`, `backspace`, `submit`,
`completeReveal`, `getState`, `getStats`, `getBoardProjection`, `getKeyboardState`,
`getRemainingAttempts`, `resetStatistics`, `resetHistory`, `snapshot`,
`prepareHandoff`. `submit()` returns success or canonical validation error;
`not-playing`/`rejected` indicate an ignored transition. Input/reveal methods return
whether canonical state changed. Both surfaces must call these APIs rather than
own parallel rule state. UI animation timing owns when to call `completeReveal`.

GameProvider's non-presentation sequencing is adapted in `session.ts`:
canonical dictionary validation before reducer submission, new-game selection,
remembering answers, completed-game recording, and duration/scoring invocation.
The unchanged `rememberAnswer`/`recordGame` helpers own recent-answer and stats
folding. Repository factories are present only as transitive source dependencies;
this adapter never invokes them or browser storage.

## Snapshot / restore contract

A detached JSON-safe snapshot contains `schemaVersion:1`, `upstreamRevision`,
`selectedLength`, canonical `game`, canonical `meta` and canonical `stats`.
Game retains upstream Guess.evaluation to preserve the canonical GameState shape;
there is no extra persisted board, keyboard, attempt count or derived metric.
Meta remembers 50 answers per length; stats retain 50 records and 100 dedup IDs.
Stats include overall/per-length counters, streaks, distributions, guesses, score,
solve-time totals and fastest solve. Theme, motion and colour preferences belong
to future presentation, not this gameplay adapter.

`await WordwrightSession.restore(snapshot, dependencies?)` validates envelope and
upstream schema, selected length and known answer, then restores canonical plain
JSON state (the same representation upstream restores, not opaque objects).
Invalid snapshots reject without altering any existing session. Validators are
upstream structural guards, not anti-cheat verification; a later authenticated
storage boundary must not treat client-authored statistics as trusted evidence.

Ordinary restore explicitly preserves upstream repository behavior: revealing
becomes playing, even after a final winning/losing guess. It does NOT infer a result
from those rows. This is a known upstream quirk, not recommended handoff behavior.
`prepareHandoff()` instead dispatches canonical REVEAL_COMPLETE, records the result
synchronously if any, then returns one game/meta/stats snapshot. Thus a future
atomic storage write cannot split the result from its statistics. Mid-reveal
snapshot() itself does not advance gameplay. Hosts must freeze input around save.

Completed sessions record via canonical idempotent recordGame; the in-process last
recorded ID mirrors GameProvider's ref. As upstream, dedup IDs are bounded and reset
history clears them. Do not merge stale snapshots or replay arbitrarily old results.

Clock uses canonical monotonic epoch time and clampDuration (0..24 hours). Restore
preserves timestamps; disconnected wall-clock time counts toward a later result.
Explicit reveal completion timestamps the event when called, with no invented
animation deadline. Switching length discards the game but keeps stats/meta.

## Provenance and license

Canonical: https://github.com/Libin-Samkutty/wordwright
Revision: 1dcf92d0c6d9e873a3d885920c21ae3ff81f0a33

Upstream declares MIT in README/package metadata and has no standalone LICENSE
file. `upstream/README.md` and `upstream/package.json` preserve those declarations;
no invented upstream license file is supplied. Source notices are retained.
`upstream.json` records SHA-256 for every vendored file. The hash test covers them.
All non-test engine modules, dictionary loader/registry/types and exact lists
4/5/6 are vendored, together with canonical storage schemas/stats/session helpers
and their dependencies, clock and Result utility. No React or private assets.
Orchestration provenance: upstream `src/state/GameProvider.tsx` at the same pin.

Optional caller-scoped integration now lives in persistence/; see persistence/README.md. The shared engine itself remains storage-independent.

## Production packaging

From the repository root:

    npm ci --ignore-scripts --prefix shared/wordwright
    npm ci --ignore-scripts --prefix shared/wordwright/web
    npm run build --prefix shared/wordwright
    npm run build --prefix shared/wordwright/web
    node shared/wordwright/package-host.mjs

`dist/` is now deliberately versioned as the generated shared Node runtime, alongside
source and provenance; it is not an independent game implementation. The Web build
bundles the same source into public_html/webdoors/wordwright/assets. Node dependencies
and web/dist remain ignored. Terminal entry is native-doors/doors/wordwright-terminal/play.mjs.
Web shell is authenticated, sets production connection configuration, and exposes
only a read-only snapshot accessor for diagnostics; local restore/connect review
APIs are not installed globally in production. Save & Return follows the validated
host return link, with /experiences/wordwright as the standalone fallback.

Wordwright is PP member #1. Legacy Wordle is retained independently, with no changes
to its game, saved data, daily selection or leaderboard and no migration.
The custom icon is an original tile/pen composition, generated by art/icon.php using
system DejaVu Sans and PHP GD; the shipped asset is 512x512 PNG.
