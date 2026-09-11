# Dokuel Web/mobile reference adapter

Local Slice 2 surface consuming `../session.ts`. No PP routes, manifests, membership, deployment, database, leased storage, or terminal adapter.

## Run

Use Bun >=1.2 and Node >=20.19. Direct dependencies and package resolutions match upstream revision `6aad7ccaccb7d354fb7ed88472e48ba2b2cb2f8e`; no framework upgrades. The narrowed package manifest excludes multiplayer. The lock retains upstream resolution records (including unused records), with the signaling workspace record removed. Only the selected dependency closure installs.

From `shared/dokuel/web`:

```sh
bun install --frozen-lockfile
bun run build
bun run preview --port 43198 --strictPort
```

In another shell, build shared test fixtures and run proof against that preview:

```sh
cd shared/dokuel
npm test
cd web
bunx playwright install chromium
DOKUEL_URL=http://127.0.0.1:43198 bun run test
```

An existing Chromium installation can be used with `PLAYWRIGHT_BROWSERS_PATH`. Browser tests write ignored screenshots and `test-results/results.json`. Tests require the local preview; they do not start production services. Build output and dependencies are ignored.

## Ownership and presentation

`src/hooks/useSudoku.ts` is a compatibility facade over `DokuelSession`, replacing the upstream reducer hook. It projects the session view into the shape expected by the canonical presentation. All selection, digit/erase, explicit and batch notes, undo, hint/dismiss, assist setting, pause, elapsed time, generation, daily identity, completion, export and restore operations use the shared API. No Sudoku rules are implemented here. Two import-forwarding files keep canonical presentation imports pointed at shared `sudoku.ts` and `types.ts`.

Canonical `Board`, `Cell`, `NumPad`, gesture/drag hooks, `digit-intent`, `HintBanner`, `GameControls`, `GameLayout`, difficulty/assistance pickers, styling, haptics and sound synthesis are retained. Tap/hold/drag behavior stays upstream: tap enters into an empty selected cell; hold toggles a note; range taps pencil all selected cells; N toggles keyboard notes. Notes gestures may release selection by canonical intent. Standard/full assistance use canonical peer-note elimination. Conflict presentation uses canonical solution-aware errors, as upstream solo does; the shared view also retains raw conflicts. Hint cells and explanation text come directly from shared canonical hints; Reveal stays explicitly labeled. No synthetic hint application action is introduced.

The solo container receives a session instead of calling the storage/generation hook. Timer chrome displays shared elapsed seconds. The host uses monotonic active-time increments at 250ms intervals and before input; shared state rejects time increments while paused/completed. Tab hiding and leaving a playing game pause it. Returning from review requires resume. No offline time accrues.

The canonical completion dialog retains its focus trap, celebration, time, difficulty and sharing controls. A review action is added; short dialogs can scroll. Global personal best/statistics/streak storage and related displays are omitted because this slice has no caller persistence. Upstream autosave, saved-game listing, multiplayer/signaling, IndexedDB, service worker registration and cache behavior are omitted. Theme, numpad position, sound and picker preference use an in-memory Map only. Reload discards the session unless a snapshot was exported.

## Snapshot review

Choose **Review snapshot** from home, below the game, or in the completion dialog. Opening review pauses a playing session. **Export snapshot** puts the shared JSON export in an editable textarea for copying. Paste JSON and choose **Restore snapshot** to reconstruct the exact authoritative state. Invalid input is reported without replacing the current session.

The original givens, pinned engine revision, difficulty, origin identity, action journal, assistance, elapsed milliseconds and pause flag remain owned by shared schema 1. Restore never regenerates. Values, notes, undo/history, selected cells, hint usage/active hint, conflicts and completion are reconstructed by the pinned canonical reducer. Presentation-only spotlight, settings visibility and gesture animations are transient. This is a trusted local reference, not an authenticated remote API; snapshot limits/migrations and caller persistence remain future work.

## Provenance

MIT, Copyright (c) 2026 Adrien Brault. License: `../upstream/LICENSE`. `provenance.json` enumerates every reused upstream path and its upstream SHA-256, every adapted file and resulting SHA-256, shared import forwarders, and lockfile hashes. Shared source remains unchanged. The provenance test verifies the local files against this manifest. Hashes were checked against `git show` at the exact pin.

Adapted upstream files:

| File | Change |
| --- | --- |
| `src/components/SoloGame.tsx` | Shared hook/session props, shared pause/time, omit storage-backed stats and tips, review action |
| `src/components/TimerPill.tsx` | Render shared elapsed seconds; remove independent timer |
| `src/components/GameResult.tsx` | Add optional snapshot review button |
| `src/hooks/useSudoku.ts` | Replace reducer ownership with shared-session facade; retain feedback calls |
| `src/hooks/useKeyboard.ts` | Ignore editable form targets |
| `src/hooks/useLocalStorage.ts` | In-memory preferences instead of browser storage |
| `src/hooks/useDarkMode.ts` | In-memory preference instead of browser storage |
| `src/lib/sounds.ts` | In-memory sound setting instead of browser storage |

New code: `src/main.tsx` (local home/mode selection/review shell), `src/lib/review-memory.ts`, `src/review.css`, tests and package/build/provenance support. The upstream daily container is not copied: `DokuelSession.startDaily()` supplies its session to the same solo presentation.

## Validation

- Frozen install, strict TypeScript and Vite production build pass.
- 21 browser checks plus 1 provenance check pass.
- All four UI difficulty starts match shared canonical seeded generation; Daily matches canonical date/puzzle and restores exactly.
- UI entry, erase, notes toggle/removal, keyboard note mode, drag multi-selection, peer-note elimination, undo, conflicts, logical/Mistake/Reveal hints, pause/timing, completed state and full snapshot restore pass.
- Desktop 1280x800; touch-emulated phones 375x667 and 320x667: no horizontal overflow; board, nine-key numpad, note holds, hint scrolling, settings and completed dialog checked. Mobile numpad keeps the canonical compact nine-key row (56px tall); side placement is available in settings.
- Zero console/page errors, external requests or WebSockets; no localStorage/sessionStorage/IndexedDB writes or service workers.
- Shared 18 adapter/provenance tests and 63 pinned canonical regression tests remain green (81 total). Canonical regression subset is solver, grader, board engine, hint engine and daily; the selection assay was not repeated.

Browser proof uses Chromium touch emulation, not physical Safari/Android devices. Snapshot journals retain the Slice 1 growth/versioning caveat. This surface is ready for the next adapter slice; no production integration is included.

The optional authenticated caller lease connection is documented in
[the persistence slice](../persistence/README.md); disconnected review stays memory-only.
