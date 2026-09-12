# shared/parlour

L33TEST's shared, presentation-neutral **Parlour** deterministic game facade
and reference Web/mobile adapter. Parlour is being integrated as the full
canonical application — **all 23 upstream games**, not a six-solitaire
subset. (An earlier pass of this work was scoped to the six solitaire games
only; that scope was corrected — see "Correction history" below. The six
solitaire games remain the priority for PP position #4, but the shared
facade and Web adapter now cover the whole catalog, matching upstream's own
architecture.)

## Canonical upstream

- Repository: <https://github.com/braedonsaunders/parlour>
- Pinned revision: `ee9aa7e695be507fb8a0f11968ab73474f8dc7f9` (`main`, v0.5.1+5)
- License: MIT, © 2026 Braedon Saunders — preserved verbatim at `vendor/LICENSE`

## The 23 canonical games

Klondike, FreeCell, Spider, Pyramid, Golf, TriPeaks, Blitz, Cribbage, Wild
(Crazy Eights' package is `game-eights`, "Wild"'s is `game-wildpile`),
Rat Screw (`game-ratscrew`), Euchre, Spades, Poker, Oh Hell, Scopa,
Spite & Malice, Hearts, Gin, President, Durak, Palace, Pinochle.

## Source respect

The canonical engine and all 23 canonical game packages (plus the shared
`@parlour/tricks` helper package euchre/hearts/ohhell/pinochle/spades
depend on) are **vendored, not reimplemented**, at
`vendor/packages/{engine,tricks,game-*}/src/*.ts` — byte-identical copies of
the upstream `.ts` source at the pinned revision (test files and each
package's `cli/` dev-simulator entry point excluded; nothing else in any
package imports `cli/`, so it's out of scope rather than trimmed from
something that needed it).

**`PROVENANCE_HASHES.sha256`** lists every vendored file with its SHA-256
hash, in `sha256sum -c` format:

```sh
cd vendor && sha256sum -c ../PROVENANCE_HASHES.sha256
```

Nothing here duplicates: card rules, legal-move rules, dealing rules,
completion rules, scoring, undo semantics, RNG, replay, event validation, or
state hashing. Every one of those is a direct call into the vendored
`engine/src/{runtime,undo}.ts` and each game's own def — see
`facade/index.ts`'s and `facade/games.ts`'s header comments for the exact
mapping.

**No canonical engine or game-package file was modified.** The packages are
wired in as real **pnpm workspace** packages (`pnpm-workspace.yaml` lists
`vendor/packages/*` and `web`) — exactly how upstream's own monorepo wires
them — so their untouched `import ... from '@parlour/engine'` /
`'@parlour/game-*'` specifiers need no rewriting or alias trickery. Each
vendored package carries its own unmodified upstream `package.json`.

## Architecture preserved

Parlour's authority model is: **game type + deterministic seed + resolved
options + canonical event log → exact reconstructed state**, via
`createSession` / `sessionApply` / `replaySession` / `undoSession` /
`stateHash`. This facade preserves that model for every game:

- `ParlourGame.session` (the canonical `GameSession`) is a live, derived
  projection — never the thing that gets persisted.
- `toSnapshot()` exports `{ schemaVersion, upstreamRevision, gameId, seed,
  seats, options, log }` — the log, not the state.
- `fromSnapshot()` / `restoreGame()` reconstruct purely by canonical replay
  (optionally with `verify: true`, which re-validates every event rather than
  trusting the stored state) and never mutate state directly for convenience.
- `undo()` is a direct pass-through to the canonical `undoSession` (truncate
  the last player action + its automatic consequences, then replay) — no
  separate undo logic exists in this facade.

**Multiplayer architecture is preserved, not amputated.** The vendored
engine includes `bots.ts`, `match.ts`, `pacing.ts`, `seats.ts`, `teams.ts`,
`veil.ts`, and `sim/scale.ts` (a scale-simulation harness used by the
canonical multiplayer "duel" test suite, which is also preserved unmodified
under `web/src/app/_multiplayer/`); every non-solitaire game package carries
its own bot policies (`bots/`) intact. This facade's own session API
(`createGame`/`submitAction`/etc.) defaults to a single seat and seat 0 —
that's a deliberate, documented simplification for what this facade slice
actually proves (the deterministic session contract generalizes to all 23
games), **not** a claim that room/host/reconnect/WebRTC/Nostr orchestration
has been integrated into L33TEST yet. `createGame`/`restoreGame` both accept
an explicit `seats` argument, and the underlying `createSession` never
assumed solo play — wiring an actual room layer over it is later,
not-yet-started L33TEST integration work. See "Multiplayer: what works today
vs. what's later work" below.

One game needed a one-line resolution rather than a direct def import:
**Cribbage**'s own `createCribbageMatchDef()` returns an engine `MatchDef`
(a best-of-N wrapper consumed by `createMatch`/`matchApply`, a different,
parallel session API this facade doesn't wire up), not a plain `GameDef`.
`facade/games.ts` uses the single-round `createCribbageDef()` the match
wrapper itself is built from — a real, playable, unmodified canonical round;
a full best-of-N Cribbage match is `createMatch` integration for later, not
a rule this facade invented.

## Shared API (`facade/index.ts`)

- `listSolitaireGames()` — the six solitaire ids; `listAllGames()` /
  `SUPPORTED_GAME_IDS` — all 23
- `optionsFor(gameId)` — canonical option fields/presets
- `createGame(gameId, seed, options, seats?)` — deterministic session from a seed
  (`seats` defaults to 1; multiplayer games' own canonical minimum is enforced
  by their `setup()`, not by this facade — see `tests/full-catalog.test.ts`'s
  `MIN_SEATS` map for what each one requires)
- `restoreGame(gameId, seed, options, log, { verify?, seats? })` — replay-based reconstruction
- `legalMoves(game)` — canonical selectable actions, straight from `def.flow.legalMoves`
- `submitAction(game, moveId, payload, seat?)` — canonical admission; illegal actions come back
  as `{ rejected: { code, message } }`, never a thrown exception or silent mutation
- `undo(game, steps?)`, `canUndo(game)`
- `completion(game)` — canonical win/completion via `def.end(state)`, never reimplemented
- `hashOf(game)` — canonical `stateHash` (desync detector, see its own doc comment)
- `project(game)` — presentation-neutral plain-data projection (id, seed, seats, options,
  status, result, phase, state, log, hash, legal moves) — no React nodes, no DOM
- `toSnapshot(game, meta?)` / `fromSnapshot(snapshot, { verify? })`

## Supported canonical options (six solitaire games, proven with tests)

| Game | Field | Values |
|---|---|---|
| Klondike | `drawCount` | `1` (relaxed) / `3` (classic) |
| FreeCell | `freeCells` | `4` (classic) / `6` (relaxed) |
| Spider | `suitCount` | `1` (relaxed) / `2` (classic) / `4` (hard) |
| Pyramid | `recyclesLimit` | `2` (classic) / `-1` (unlimited) |
| Golf | `wrap` | `false` (classic) / `true` (fairway) |
| TriPeaks | `wrap`, `recycle` | both booleans, classic = both `false` |

These are exactly the canonical fields declared in each game's own
`config.ts` — nothing L33TEST-specific was invented. The other 17 games'
option schemas are equally reachable via `optionsFor(gameId)` (proven
generically by `tests/full-catalog.test.ts`) but don't yet have per-field
assertions the way the six solitaire games do.

## Multiplayer: what works today vs. what's later work

Works today, self-hosted, no L33TEST integration needed:
- Every game's **solo-vs-bots** table (`deal-me-in` in the Web adapter) —
  local bot opponents, canonical rules, no network.
- The canonical **friend-room UI** (`create`/`join`/`play`/`table` routes,
  `components/multiplayer/*`) renders and the deterministic session/replay
  machinery underneath it is unmodified.

Needs L33TEST integration work, not started here:
- Actually hosting rooms across separate BinktermPHP callers (a real
  signaling/relay path) — upstream's own signaling client uses Nostr relays
  + WebRTC + configurable STUN/TURN; no relay server is bundled, and no
  L33TEST-side session/room service exists yet.
- Reconnect/host migration in a caller-authoritative deployment.
- This facade's own seat/seat-index plumbing beyond "pass a seat count and a
  seat index" — a real room layer (who's in seat 2, bot backfill, host
  authority) is not part of `facade/index.ts`.

## Pyramid mobile remediation (kept)

`web/src/styles/pyramid.module.css` carries the proven CSS-only fix: Pyramid
never had the same `@media (orientation: portrait) and (max-width: 700px) {
.actions { ... } }` action-rail-pin override every sibling solitaire game
carries, so its rail fell through to a shared multiplayer hand-fan rule and
landed over its own bottom card row at 375×667/320×667. The fix (+14 lines,
copied from the established sibling pattern) is in place; no engine or
game-rule file was touched. Verified: 0/8 blocked card centres at both
widths, a real pairing move made, undo/hint/restart all exercised, no
overflow — see the Slice 2 report for evidence/screenshots.

## Audio exclusion — hard rule

**Nothing vendored here imports any audio, and none of it needs to.**
`grep`ing every vendored `.ts` file and every facade file for an audio
reference (`.mp3`/`.wav`/`.m4a`/`elevenlabs`) returns nothing — enforced by
`tests/facade.test.ts`'s "audio exclusion" suite so a future change can't
silently reintroduce one.

Separately: Parlour's canonical Web app ships 40 audio files — 13 shared
SFX, 10 solitaire-specific SFX, and 17 music tracks — generated via
ElevenLabs APIs with no redistribution-rights ledger found in the upstream
repository. That gap was proven cleanly omittable (all six solitaires play
with zero page errors and unchanged gameplay when those 40 files are
absent; the full 23-game Web adapter build was verified the same way).
**`web/public/audio/` in this tree contains only `parlour-ambience.wav`**
(pure local waveform synthesis — sine/noise generated by
`scripts/generate-ambience.mjs`, no third party, no rights question at all);
the `sfx/`, `music/`, and `cues/` directories (the 40 unresolved files plus
22 further dead/unreferenced `cues/*.m4a` files upstream ships but nothing
loads) are not present. **Do not add those files back without an
independently established rights record.**

## Snapshot schema

```ts
{
  schemaVersion: 1,
  upstreamRevision: "ee9aa7e695be507fb8a0f11968ab73474f8dc7f9",
  gameId: GameId,     // any of the 23 canonical ids
  seed: number,
  seats: number,
  options: <that game's resolved config>,
  log: AppliedEvent[],
  meta?: { createdAtMs?: number, label?: string }, // never replayed from
}
```

`fromSnapshot()` refuses a snapshot whose `upstreamRevision` doesn't match
this facade's pin, and whose `schemaVersion` isn't the one it understands —
loudly, not by guessing.

## Running the facade tests

```sh
cd shared/parlour
pnpm install   # workspace install: facade + all 24 vendored packages + web
pnpm exec vitest run
pnpm exec tsc --noEmit
```

At last run: **128/128 facade tests pass** (57 six-solitaire-game tests +
71 full-catalog tests proving all 23 games are discoverable and
deterministically initialize through the same session contract),
`tsc --noEmit` clean. A focused upstream regression subset (engine + all six
solitaire game packages, run from the original pinned checkout, same commit
as vendored here) also passes: engine 167/167, klondike 54/54, freecell
42/42, spider 37/37, pyramid 30/30, golf 27/27, tripeaks 29/29 — 386/386.

## Web adapter (`web/`)

A full copy of Parlour's canonical Next.js Web app, wired to consume the
vendored packages above as real pnpm workspace dependencies. Build:

```sh
cd shared/parlour/web
pnpm install   # from the shared/parlour workspace root
pnpm exec next build
node scripts/generate-pwa.mjs
```

Produces a static export (`out/`) with all 23 games, the canonical game
chooser, and the multiplayer friend-room UI intact. See the Slice 2
correction report for the full browser-proof evidence.

## `web/` provenance — adapted files, everything else byte-identical

A recursive diff of `web/` against the pinned canonical `apps/web` (excluding
`node_modules`/`.next`/`out`/`public/audio`, which is a rights-driven asset
omission, not a source edit) shows these adapted files — every other source
file in `web/` is byte-identical upstream:

| File | Change | Why |
|---|---|---|
| `src/styles/pyramid.module.css` | +14 lines, one `@media` block | The Pyramid mobile remediation (see above) |
| `package.json` | Drop `@vercel/analytics`, `@playwright/test`; pin `next`/`react`/`react-dom` to exact tested versions instead of caret ranges; add `@parlour/tricks` as an explicit workspace dep | Self-hosted build, exact pin discipline, explicit dependency on the vendored helper package it already transitively needs |
| `src/app/layout.tsx` | Remove the `Analytics` import + `<Analytics />` element (2 lines); add `<ParlourHostSync />` (1 line + import) | No telemetry beacon; Slice 5 Web-host wiring, see below |
| `tsconfig.json` | `extends` path fixed to a local copy of the base config; `e2e/` and both `playwright.config*.ts` added to `exclude` | These files moved out of the original monorepo root; the canonical Playwright E2E suite is kept as source (untouched) but isn't typechecked here since `@playwright/test` isn't installed in this tree |
| `tsconfig.base.json` | New file | A local copy of the pinned monorepo's own `tsconfig.base.json`, needed because `tsconfig.json`'s `extends` no longer has the original monorepo root to point at |
| `next.config.ts` | Add `basePath: '/webdoors/parlour/assets'` | Slice 5: served from a WebDoor subpath, not the site root — see below |
| `src/app/page.tsx`, `src/components/SplashScreen.tsx`, `src/components/PwaInstall.tsx` | Literal `basePath` prefix on 4 plain `<img src>`/`preload()` calls Next does not rewrite automatically | Same `basePath` deployment reality — `next/image`/`<Link>` get it for free, a raw `<img src>` does not |
| `src/components/ParlourHostSync.tsx` | New file (additive — no canonical file touched or removed to add it) | Slice 5 Web-host wiring, see below |

None of these touch game logic, presentation markup, or canonical behavior.
`public/audio/` is reduced to `parlour-ambience.wav` only (see "Audio
exclusion" above) — an asset omission, not a source modification.

### Web entry: redirect, not an inline shell (Slice 5 production fix)

`public_html/webdoors/parlour/index.php` authenticates the caller
(`Auth::requireAuth()`) and checks `GameConfig::isEnabled('parlour')`, then
**redirects** (HTTP `Location`) to `/webdoors/parlour/assets/?parlourCsrf=…`
— it does not echo the app's HTML itself. This was a real, reproduced
production defect found and fixed during Slice 5 live acceptance: Next's
App Router static export needs `location.pathname` to equal its own
`basePath` root exactly to recognize the current route; the sibling
WebDoors' established pattern (Dokuel/Wordwright: an auth shell that
`file_get_contents()`s and echoes `assets/index.html` at a *different* URL,
e.g. `/webdoors/dokuel/index.php`) works for their plain, non-router SPAs
but silently breaks a Next.js client router served that way — the static
markup renders, but no client-side effect (including this component's own
mount effect, or the splash screen's dismiss timer) ever runs. Reproduced
identically in both headless and real non-headless Chromium (via Xvfb) —
not a headless-only artifact — and with compression, PHP-wrapping, and the
old inline `<head>` script injection each independently ruled out first.

`ParlourHostSync` (mounted once in the root layout) claims the one-time
`parlourCsrf` query parameter on mount, sets `window.parlourHost` (the same
shape the removed `host.js` used to inject server-side), strips the
parameter via `history.replaceState`, and wires the "Save & Return" callback
the launch iframe looks for. Local/dev use (no PP launch, no query param)
leaves `window.parlourHost` unset, which is harmless — only the `/continue`
persisted-session page reads it, and only when actually calling the storage
API.

## Terminal / SSH adapter (`terminal/`)

A local, classic 80x24 Node terminal client for the same full 23-game
catalog, sharing this facade and the vendored canonical engine — no
duplicated card rules, no separate engine build. Run it locally with
`npx tsx terminal/src/cli.ts` (or `--no-color` for a colourless run).

**Design**: one reusable shell (`shell.ts`) with a numbered
legal-move menu as the universal source/destination-selection input model
(the terminal never infers a legal move — every entry in the menu is
exactly one `LegalMove` the canonical `def.flow.legalMoves()` returned) plus
family-specific table renderers:

| Family | Games | Renderer |
|---|---|---|
| `tableau-solitaire` | Klondike, FreeCell, Golf, TriPeaks | `render/tableau.ts` — columns/foundations/stock/waste/cells |
| `spider-solitaire` | Spider | `render/spider.ts` — 10 columns with a "+N above" fold and a `[v]` full-column pan (its deep columns don't fit one screen; panning is the intended UX per the brief) |
| `pyramid-solitaire` | Pyramid | `render/pyramid.ts` — the actual 7-row triangle |
| `trick-taking` / `capture` / `shedding` / `meld` / `special` | the other 17 games | `render/generic.ts` — reads each game's own canonical field names (`hands`/`stock`/`discard`/`trick`/`scores`/`dealer`/`trump`/`bids`/`outcome`, common across the whole vendored catalog) from that seat's `playerView()` projection |

Bots run through `botStep.ts`, a thin wrapper over the engine's own
`actingSeats`/`chooseBotMove`/`makeRng`/`sessionApply` (the same primitives
`engine/src/bots.ts#runBotGame` uses for headless simulation), stepped one
turn at a time so the shell can redraw between them — no bot logic lives in
the terminal. Card ids are parsed only for **display**
(`terminal/src/cards.ts`): every vendored deck's own `${suit}${rank}` shape
plus its two documented variants (Durak's 6–14/Ace-high 36-card deck,
Pinochle's `${suit}${textualRank}-${copy}` double deck) are recognized;
Wild's colour-named ids and Spite & Malice's rank-suffixed ids are shown
verbatim rather than guessed at.

`terminal/tests/terminal.test.ts` adds 31 focused tests (catalog
completeness, card formatting incl. both non-stdDeck shapes, move-menu
resolution across all 23 games' opening deals, chooser pagination, key
decoding, per-family render smoke for all 23 games, the 80x24 frame-bound
invariant, bot orchestration, and snapshot/restore parity) without
duplicating any canonical rule test. Real-PTY acceptance (80x24 chooser,
all 23 games' opening deals, six solitaire deep play + undo, five
non-solitaire deep play with bots, resize/Ctrl-C/SIGTERM/clean-exit,
fresh-*process* snapshot/restore for 5 games) was run against a real
pseudo-terminal outside this tree; see the Slice 3 RETURN report for the
full evidence.

One facade fix landed as part of this slice: `facade/games.ts`'s
`createDurakDef()`/`createEightsDef()`/`createGinMatchDef()` calls were
passing no `bots` option, and those three packages (unlike
euchre/blitz/cribbage, which default internally to their own `TIER_BOTS`)
default `bots` to `[]` when the caller omits it — so those three games had
no bots wired at all since Slice 2. Fixed by passing each package's own
exported roster (`DURAK_BOTS`/`EIGHTS_BOTS`/`GIN_TIER_BOTS`), exactly what
the canonical web app's own solo transports already do
(`apps/web/src/lib/solo/{DurakTransport,EightsTransport}.ts`) — not a new
bot, just wiring the same upstream roster through. No other facade files
changed in this slice.

## Production integration (Slice 5)

Parlour is a production PP experience: `public_html/webdoors/parlour/`
(WebDoor: `webdoor.json`, `index.php` auth+CSRF redirect (see "Web entry:
redirect, not an inline shell" above — `ParlourHostSync` in the app itself
now owns Save & Return), `api.php` persistence proxy, `icon.png`, `assets/`
= this tree's `web/out` static export) + `native-doors/doors/parlour-terminal/` (NativeDoor running
`terminal/src/cli.ts` via `tsx`), sharing `experience.group: "parlour"` so
`GameCatalog`'s `ExperienceComposition` presents them as one card with both
`web` and `telnet` surfaces. Registered in `config/webdoors.json` /
`config/nativedoors.json`, and as PP member #4 (replacing Solitaire) in
`config/crossroads/places.json`, with `primary_presentation: true` (keeps it
out of Game Hall — see `docs/WebDoors.md`). See the Slice 5 RETURN report for
full live-acceptance evidence.

Klondike Solitaire is untouched and independently enabled — see
`docs/WebDoors.md`'s "Parlour replaced Solitaire at PP position #4" note.

## Correction history

Implementation Slice 2 originally scoped the Web adapter to the six
solitaire games only (deleting the other 17 games' routes and the
multiplayer room architecture). That was corrected: Parlour is being
integrated as the complete canonical application. The six-solitaire work
(facade tests, options parity, Pyramid fix, audio exclusion) was kept and
generalized rather than discarded; the deleted multiplayer/17-game surface
was restored from a fresh copy of the canonical, unmodified upstream source
plus the Pyramid CSS fix.

## Caller-scoped persistence (`persistence/`) — Slice 4

Production-style caller-scoped persistence for solo and solo-vs-bots play,
across the full 23-game catalog, reusing the exact same generalized leased
storage as Tatham/BreakLock/Ordinary Puzzles/Wordwright/Dokuel — namespace
`parlour`, slot 0, table `webdoor_storage`. **No schema change.**

- `persistence/Storage.php` — namespace `Parlour`, a thin opaque wrapper
  over `LeasedWebDoorStorage`, identical in shape to Dokuel's own
  `Storage.php`.
- `persistence/api.php` — staged/unrouted HTTP endpoint for the Web adapter;
  auth+CSRF is `\BinktermPHP\Auth::requireAuth()`, the same gate every
  other reserved WebDoor slot already uses (not re-implemented here).
- `persistence/helper.php` — CLI JSON-lines helper for the terminal
  adapter, trusting `DOOR_USER_NUMBER` only after re-validating it against
  `users.is_active` — identical in shape to Dokuel's own `helper.php`.
- `persistence/envelope.ts` — the authoritative envelope
  (`ParlourLeaseEnvelope`/`ParlourLeaseSession`): schemaVersion,
  upstreamRevision, gameId, seed, seats, options, log, plus only the
  caller-owned metadata the canonical engine has no field for at all —
  `mode` (`solo`/`solo-vs-bots`), `humanSeat`, `botSeats`. Derived
  card-table state and completion are never persisted (both are
  reconstructible from the log via the facade). Includes the explicit
  multiplayer-room safety guard (`assertCallerScopedEnvelope`): any
  `roomId`/`peers`/`host` marker, or an unrecognized `mode`, is refused
  before it can ever reach storage.
- `persistence/client.ts` — the transport-agnostic `LeasedClient`
  (acquire/checkpoint/release/game-switching), following the same pattern
  as Dokuel's/Wordwright's own `client.ts`; `webTransport()` is the Web
  adapter's fetch-based transport.
- `terminal/src/persistence.ts` — the terminal's transport: spawns
  `persistence/helper.php` once per run with a trusted `DOOR_USER_NUMBER`
  and speaks its JSON-lines protocol.
- `facade/bots.ts` — bot-turn orchestration, moved here from
  `terminal/src/botStep.ts` (which now just re-exports it) because the
  persistence envelope needs the exact same orchestration for solo-vs-bots
  continuity across a save/restore; `terminal/src/session.ts` and
  `facade/index.ts` also gained a seat-aware `legalMovesFor(game, seat)`
  (the pre-existing `legalMoves(game)` is correctly empty during a
  simultaneous multi-actor phase like Hearts' card-passing — a real latent
  gap discovered and fixed in this slice for both the terminal and the
  persistence layer).

**Game switching**: one active caller Parlour session at a time (slot 0);
choosing a new game calls `LeasedClient.replace()` after an optional final
checkpoint of the old game. No 23-slot scheme — upstream Parlour itself has
no concept of multiple simultaneously-resumable local sessions per caller
either (each game's own browser-local continuation is single-slot), so this
matches both the brief's preferred model and upstream's own behavior.

**Stats/preferences**: inspected narrowly — the vendored packages carry no
persistent statistics/achievement/Daily-record concept independent of a
session's own event log (a "Daily" mode, where present, is just a specific
seed/options preset). Classification: **(D) not relevant to this slice** —
there is nothing to migrate yet; a future statistics feature would be
**(B) separate caller metadata**, not stuffed into this envelope, to avoid
coupling session continuity to a stats schema that may need its own shape
later.

See the Slice 4 RETURN report for full real-PostgreSQL acceptance evidence
(Web↔terminal handoff, bot/undo/completion continuity, stale-writer, caller
isolation, namespace isolation, abrupt-close recovery, snapshot sizes).
