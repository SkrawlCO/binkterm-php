# Dokuel shared session (slice 1)

Presentation-neutral in-memory Sudoku session for later Web/mobile, terminal,
and caller-storage consumers. No host registration, UI, network, database, or
statistics writes are included.

## Source and runtime

Canonical source: https://github.com/adrienbrault/dokuel at
`6aad7ccaccb7d354fb7ed88472e48ba2b2cb2f8e`, MIT, copyright 2026 Adrien Brault.
`upstream/LICENSE` retains the full license. `provenance.json` records every
vendored path and its SHA-256. All 13 TypeScript modules are byte-for-byte copies:

- `src/lib/types.ts`, `board-geometry.ts`, `candidates.ts`
- `src/lib/solver.ts`, `sudoku.ts`, `grader.ts`
- `src/lib/board-engine.ts`
- `src/lib/hint-engine.ts`, `technique-hint.ts`, `techniques.ts`, `wings.ts`
- `src/lib/daily.ts`, `date.ts`

The solo seed expression comes from `src/hooks/useResumableSudoku.ts`; its hash
is recorded separately as a reference. The React hook is not imported.
Canonical statistics, streaks, and completion side effects use browser storage,
so they are outside this pure layer. A later host can consume completed status,
elapsed time, assistance, difficulty, daily identity, and hints used.

There are no runtime package dependencies. Node >=20.19 runs compiled ESM;
TypeScript 5.9.3 is the sole development dependency, matching the upstream pin.
The compiler rewrites `.ts` import extensions only in ignored build output.
Bun can also import `session.ts` directly; the supported test path uses Node.

```sh
cd shared/dokuel
npm ci
npm test
```

`npm test` compiles and runs 17 session tests plus one provenance test.
`npm run verify:source` checks vendored hashes. `dist/`, `node_modules/`, and
coverage are ignored. No root package changes or service-worker update is needed:
this slice exposes no served assets.

## API

```js
import { DokuelSession } from './dist/session.js';
const game = DokuelSession.startSolo('medium', 'host-generated-game-key');
game.selectCell(0, 0);
game.toggleNoteAt(0, 0, 3); // canonical toggle; call again to remove
game.enterDigit(3);       // canonical placement; does not overwrite a value
game.undo();
game.requestHint();       // explanation/selection only, never places a value
const view = game.view();
const restored = DokuelSession.restore(game.export());
```

Factories: `startSolo(difficulty, key, assistLevel?)`,
`startDaily(date?, difficulty?, assistLevel?)`, and
`fromPuzzle(originalGivens, difficulty, assistLevel?)`. Solo keys are supplied
by the future host. Daily defaults to the caller runtime's local calendar date;
a server host should pass the intended caller date explicitly.

Actions: `selectCell`, `enterDigit(value, autoEliminateNotes = true, asNote?)`,
`toggleNoteAt`, `toggleNotes`, `erase`, `undo`, `requestHint`, `dismissHint`.
`dispatch` exposes every canonical action except `RESET`; create another session
for another puzzle. Multi-selection uses `SET_SELECTED_CELLS` with cell keys in
a JSON array, converted to the canonical Set at dispatch. Boundary validation
checks payload shapes and ranges, never implements Sudoku rules.

Hints are exactly canonical: logical deduction, wrong-entry warning, or explicit
Reveal. There is no invented apply-hint action. Enter the advised digit normally;
wrong entries must first be erased. Assistance is host metadata; callers choose
note elimination explicitly, just as the canonical hook API allows.

`view()` returns detached canonical state (including Sets), serialized grid,
canonical board projections, and grade/identity/session metadata. It includes the
canonical solution for trusted local consumers. It is not a public network DTO.
Conflicts, solution errors, counts, candidate deductions, grade, and completion
are computed by canonical modules. UI highlights are owned by future surfaces.

## Authoritative snapshot and exact restore

Schema 1 stores engine revision, original givens, declared difficulty, solo key
or daily date (or imported identity), assistance, canonical action journal,
active elapsed milliseconds, and pause state. JSON export contains no Sets.

The journal is the authoritative playable state: current values, pencil notes,
selection, notes mode, undo history, hint usage, active hint, and completed status
are reconstructed by `initState` plus the exact canonical reducer. No parallel
copy of these fields is persisted. Canonical history remains capped at 100 moves;
the journal retains earlier actions needed to reconstruct that history exactly.
Journal size/replay cost grows with input count; schema 1 performs no checkpoint
compaction. It is intended for one Sudoku session, not an indefinitely running log.

The saved 81-character original puzzle is authoritative. Neither solo keys nor
daily dates trigger regeneration during restore. Future generator changes cannot
silently replace the puzzle. A changed engine revision/schema is rejected and
will require an explicit migration or retention of the pinned engine. Identity
fields describe origin; they are not cryptographic proof of a daily challenge.

Upstream's `SavedBoard` initializer drops history/hints/selection and starts in
`playing` status, even for full grids. It is therefore not used to restore a
continuation. `fromPuzzle` has the same initial-state semantics as canonical
`initState`; restore a completed game from its snapshot, not from a solved grid
passed as new original givens. Reducer replay restores genuine completion and its
input guards without patching those semantics.

`advanceTime(integerMilliseconds)` is supplied by the host using active elapsed
time. No wall clock or background interval runs here. Paused and completed games
do not accrue time; paused input is ignored. `pause`, `resume`, and
`setAssistLevel` are session controls. Export captures current elapsed time;
restore adds no offline time. Future hosts must account for elapsed time before
moves/export and avoid double-counting it.

## Validation

Adapter tests cover each difficulty (one identity per level), exact canonical
seed output, uniqueness, grade metadata, entry/erase, notes and peer elimination,
all undo-history shapes, conflicts, logical/mistake/Reveal hints, daily identity,
active hint restore, partial/completed restore, pause/timing, assistance, and
canonical state/projection parity. They also exercise the 100-move undo boundary,
input isolation, malformed snapshots, and unsupported engine versions.

Slice 1 additionally ran the pinned upstream solver, grader, board-engine,
hint-engine, and daily test files against these exact vendored modules in scratch:
63 tests passed. No upstream test framework dependency is added here.

Difficulty metadata reports the requested level and actual canonical grade
separately. Easy has no technique gate, Medium may be singles-only, and exhausted
generator attempts can miss a target band. The adapter does not relabel or patch
canonical output. No Sudoku rules or solving techniques are duplicated.

Caller-scoped PostgreSQL lease plumbing and Web/terminal handoff proof are documented
in [persistence/README.md](persistence/README.md). No final host manifests are registered.

## Production package

    npm ci --ignore-scripts --prefix shared/dokuel
    npm run build --prefix shared/dokuel
    cd shared/dokuel/web && bun install --frozen-lockfile && bun run build
    cd ../../.. && node shared/dokuel/package-host.mjs

Use pinned Bun-compatible dependencies (Bun 1.4.2 used for validation). The generated
shared `dist/` runtime is versioned for Node 20.19+; it is compiled from the exact
pinned canonical modules, not a second engine. Production Web assets live under
`public_html/webdoors/dokuel/assets/`; the authenticated PHP shell initializes its
lease and provides only a read-only snapshot diagnostic. Review imports/connect
hooks are absent in production. Save & Return follows the validated parent host
return link, defaulting to `/experiences/dokuel` for direct access.

The NativeDoor launcher is `native-doors/doors/dokuel-terminal/play.mjs`, with a
sanitized environment carrying trusted DOOR_USER_NUMBER. Both surfaces use the
same `dokuel` slot 0 PostgreSQL lease and canonical replay. Enable Dokuel Web and
native surfaces using the normal admin controls. Terminal daemons must reload
when their shared shelf-presentation code changes; no Dokuel daemon exists.

Dokuel is PP member eight, with `primary_presentation: true`; it remains directly
launchable and is omitted from Game Hall on both surfaces. Existing PP games only
change shelf ownership, not gameplay. Legacy Wordle remains independent.

`art/icon.php` reproducibly renders the original 512x512 Sudoku icon with PHP GD
and system DejaVu Sans; the shipped PNG needs no runtime image library.
