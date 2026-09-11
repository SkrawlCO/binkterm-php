# Ordinary Puzzles shared board/session adapter

This first implementation slice contains no Web/terminal surface, host route,
manifest, database persistence or PP membership. Both future surfaces can consume
`session.cjs` (CommonJS, also consumable by browser bundlers).

## Canonical ownership

Pinned upstream: `mmazzarolo/ordinary-puzzles-app`, revision
`ee4c9d169ec65a2aad34a3856529a542ff583508`. `upstream.json` records the origin and
SHA-256 of every vendored source/data/license file. All four files are copied
byte-for-byte from that Git revision; `LICENSE.upstream` preserves the MIT notice.

- `upstream/src/op-board/store.ts`: unchanged RootStore, BoardStore, Cell, Line and
  InteractionsStore own placement, collision clipping, reset/commit, occupancy,
  validity, line completion, board clearing and interaction cleanup.
- `upstream/src/op-puzzle-pack/index.ts`: unchanged catalog access, tier names and
  score projection.
- `upstream/src/op-puzzle-pack/puzzle-pack.json`: complete committed pack, **1,200
  unique puzzles, 300 per tier** (small, medium, large, extraordinary).

The application `src/op-core/store.ts` / PuzzleStore is not imported: its menu,
progression and tutorial dependencies are outside this board/session slice. The
adapter indexes the unchanged pack by its existing stable IDs. It exposes the
canonical pack name (`quire` for `e9c2882a25e2`) and application type `puzzle`;
`quire` is a name, not a separate puzzle ruleset. Tutorial messages are not pack
puzzles. Retired entries remain loadable by ID; catalog filtering can include them.

## Build and dependencies

```
cd shared/ordinary-puzzles
npm ci --ignore-scripts
npm test
```

`npm run build` uses TypeScript 6.0.3's `transpileModule` to emit the two canonical
TS modules to ignored `runtime/*.cjs`. This is syntax transpilation, not upstream
whole-application type checking. It removes type-only React Native imports. The
only generated-code path adjustment points the pack import to the single original
JSON file. No canonical source is edited. Runtime dependencies are exact pinned
Lodash 4.18.1, MobX 6.16.1 and React 19.2.3, matching the pinned source's dependency
versions/proof runtime, with a committed-intent npm lockfile.

React is retained because the unchanged board module constructs its context and
exports a hook; this adapter never renders or invokes that hook. No React Native,
DOM, SVG, font assets or font build are required. No private Averta files were
obtained, inspected or copied. A future Web build must use upstream's
`ALLOW_FONT_FALLBACK=1` / licensed Inter path.

## API

```js
const { OrdinaryPuzzlesSession, catalog } = require('./session.cjs');
const choices = catalog({ tier: 'small' }); // detached descriptors
const session = new OrdinaryPuzzlesSession('e9c2882a25e2');
session.begin(0, 1); // B1; all coordinates are zero-based [row, column]
session.leave(0, 1);
session.enter(0, 3); // D1
session.end(0, 3);
const state = session.state();
const saved = JSON.parse(JSON.stringify(session.export()));
const restored = OrdinaryPuzzlesSession.restore(saved);
```

- `catalog({ tier?, includeRetired? })`: deterministic canonical pack order.
- `load(id)`: canonical initialization, fresh interaction state and action log.
- `begin(row,col)`, `enter(row,col)`, `leave(row,col)`, `end(row,col)`: direct calls
  to the correspondingly named canonical cell handlers; no occupied-cell filtering.
- `tap(row,col)`: begin then end. Canonical code decides whether this resets a line.
- `exit()`: canonical grid-touch-exit/commit, **not host navigation**.
- `state()`: detached, presentation-neutral data; mutations cannot affect the core.
- `export()` / static `restore(snapshot)`: JSON-safe interaction replay, detailed below.

Callers deliver leave/enter events as appropriate to their input surface. The
adapter does not invent a completed-board prohibition: upstream's autorun clears
pointer layout and interaction state, while common cell handlers remain exactly
as implemented. `interaction.enabled` reports that canonical layout state. Unit
cell geometry is supplied to canonical initialization; screen pixel layout is not
part of the shared session.

State contains puzzle ID/name/type/tier/geometry/rating/score; cells with canonical
clue/value, line membership, occupancy, orientation, validity and completion;
lines with origin, cells, committed/pending paths, handler/stale state and canonical
completion; board `cleared`; and canonical current handler, dragged line, hover,
move count and enabled/dragging flags. No React or SVG objects are exposed.

## Snapshot/restore

The envelope contains `schemaVersion`, `upstreamRevision`, `packId`, `puzzleId`,
ordered primitive `actions`, and a detached `verification` state projection.
Authoritative reconstruction is **original puzzle + interaction log**. Verification
is deliberately redundant, read-only evidence: restore never assigns it to model
internals. Restore replays only canonical actions and rejects mismatched results,
unknown actions, out-of-range coordinates and incompatible revisions/pack IDs.
Object-key reordering in JSON transport is tolerated; ordered paths remain ordered.

Recording intermediate leave/enter actions preserves unfinished drags, pending vs
committed cells, handler/stale quirks, ignored inputs and completion-time cleanup.
The action log is intentionally not compacted or truncated; export size/replay
cost grow with gestures. Any future persistence size policy or compaction needs
its own parity proof. There is no writer lease, caller identity or database here.

## Focused tests

`npm test`: 15 deterministic tests cover provenance, catalog, the real Small board,
canonical placement/clipping/reset/completion, direct handler delegation, neutral
state ownership, detached export, active-drag/solved restore, reordered JSON,
tamper rejection, alternate-tier loading and boundary validation. The known
solution fixture is the prior pinned upstream-solver result; tests apply all moves
through normal cell interactions. No solver or terminal renderer is shipped in
the runtime adapter, and the 80x24 proof is not repeated.

## Production packaging

Build the served Inter-only assets with
`ALLOW_FONT_FALLBACK=1 node shared/ordinary-puzzles/web/build.cjs --production`.
WebDoor `public_html/webdoors/ordinary-puzzles` authenticates through the SDK and
project Auth/CSRF. NativeDoor `ordinary-puzzles-terminal` uses the trusted
DOOR_USER_NUMBER launcher and the same canonical shared layer. PP membership is
seventh; Save & Return follows the authenticated host return link, and direct
launches return to the Ordinary Puzzles Experience. Runtime enablement is separate
from the checked-in disabled terminal manifest. No service worker is registered.

The custom icon was generated with the built-in image tool: dark grid, bold ivory,
coral and turquoise paths, no text; browser canvas resized it to 512x512 PNG and
verified a 48x48 preview. The image is original and contains no upstream artwork.
