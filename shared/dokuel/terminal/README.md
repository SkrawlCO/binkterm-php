# Dokuel 80x24 terminal reference

Slice 3: a local terminal surface over `shared/dokuel/session.ts`. No NativeDoor wiring, PP integration, database, leases, daemon or automatic persistence.

## Run

Node >=20.19; no additional runtime packages or terminal framework. The shared TypeScript build uses its existing pinned compiler. Tests also require Python 3 with the standard library and Unix PTYs.

From `shared/dokuel/terminal`:

```sh
npm run build
npm start
```

For explicit local snapshot review:

```sh
node cli.mjs --snapshot /tmp/dokuel-review.json
node cli.mjs --restore /tmp/dokuel-review.json --snapshot /tmp/dokuel-next.json
```

The snapshot destination is used only when **E** is pressed in the menu. Repeated exports overwrite that explicitly selected file. The adapter never chooses a production storage location or saves automatically. Restore accepts the existing shared JSON format, including Web exports. Invalid restore fails before entering raw mode or the alternate screen. No regenerated puzzle replaces saved givens.

## Controls

| Context | Key | Action |
| --- | --- | --- |
| Board | Arrows / WASD | Move the shared selected cell, clamped at edges |
| Board | 1-9 | Shared digit entry; adds/removes notes when NOTES mode is on |
| Board | N | Toggle shared note mode |
| Board | 0 / Backspace / Delete | Shared erase |
| Board | U | Shared undo |
| Board | H | Request a canonical hint, or reopen the current hint |
| Board | X | Dismiss current hint |
| Board | P | Shared pause/resume |
| Board | ? | Paginated help |
| Board/detail | Esc | Paused menu |
| Hint/help | Arrows / PageUp / PageDown / Space | Page through details |
| Hint/help | Enter | Return to board, preserving active hint |
| Hint | X | Dismiss hint and return |
| Menu | 1 / 2 / 3 / 4 / 5 | Easy / Medium / Hard / Expert / Daily |
| Menu | R / Esc | Return to board; P resumes |
| Menu | E | Export to the explicit `--snapshot` file |
| Menu | Q | Clean quit |
| New puzzle confirmation | Y / N | Replace / cancel |
| Anywhere | Ctrl-C | Clean interrupt |

Digits never act as difficulty shortcuts on the board. New puzzles require confirmation when a session exists. Terminal text is English, isolated from PP's application translation system.

## Board and notes

The 9x9 board has explicit 3x3 boundaries and A-I / 1-9 coordinates. `[5]` marks the selected cell. Plain values are givens; `5+` marks entered values. The selected-cell panel explicitly says GIVEN or EDITABLE, since cursor brackets replace the cell suffix. `!5!` marks a canonical conflict/error, reinforced by the selected-cell status and total count. ANSI red and inverse-video cursor styling enhance these symbols; `NO_COLOR` disables colour. A VT-compatible terminal is still required for screen positioning.

A colon marks a cell containing pencil notes. The selected-cell panel lists every stored note and shows a compact 3x3 note map. These are recorded pencil notes, not freshly calculated candidates. N then 1-9 toggles notes through the shared reducer. Standard/full assistance retain canonical peer-note elimination; restored paper-assistance sessions retain their canonical no-auto-elimination behavior.

At 80x24 all views use at most 79 printable columns and 23 rows, reserving the right/bottom edges to avoid terminal wrap and scroll. Larger windows retain the same layout. Smaller windows pause the shared session and show a bounded resize notice; returning to 80x24 requires an explicit P to resume. Paused boards obscure values and notes.

## Hints and state ownership

`adapter.mjs` imports only `DokuelSession` from the shared compiled build. It owns display, movement/key translation, menu confirmation and detail pagination. Generation, daily seed/identity, Sudoku state, notes, undo, conflicts, completion, hints, pause, timing and snapshots remain shared. No Sudoku rules or solving techniques are copied or implemented here.

The terminal displays the union of canonical conflict and solution-aware error projections. It does not independently validate entries. Hints show the canonical technique label, full explanation, target/value and related-cell coordinates. Long explanations wrap and paginate without truncation. Mistake and Reveal remain explicit; hints never place values. Common Unicode punctuation is rendered as ASCII typography; unexpected non-ASCII/control characters are escaped, preventing terminal control injection while retaining their code points. Help uses the same pagination.

A monotonic host clock supplies active milliseconds to the shared session, at 250ms intervals and before input/resize. Reading help/hints counts as thinking time. Menu, explicit pause, undersized viewport and completed status do not accrue time. Restored elapsed time, pause state, selected cells, notes, undo history, hint usage/active hint and completion all come from the shared snapshot/reducer. Entering the menu explicitly pauses; exports capture that pause state. No opaque state is mutated.

The original pin remains `6aad7ccaccb7d354fb7ed88472e48ba2b2cb2f8e` (MIT, Adrien Brault). See `shared/dokuel/provenance.json` and `shared/dokuel/upstream/LICENSE`. No new upstream copies or canonical source modifications are introduced. Shared schema/revision checks and the existing action-journal growth caveat apply unchanged.

## Lifecycle and proof

The CLI uses Node's keypress decoder for arrow/Delete sequences, raw input, and an alternate screen. Clean quit, Ctrl-C, SIGINT, SIGTERM, SIGHUP and input end restore the prior raw-mode state, show the cursor, reset SGR and leave the alternate screen. Rendering timers/listeners stop before cleanup. It does not change mouse reporting, bracketed paste or autowrap modes.

```sh
npm test
```

Focused proof consists of 17 adapter tests and 9 real PTY tests. The PTY harness launches fresh Node processes at 80x24 and drives actual input bytes, including arrows, Backspace, Delete and Esc. It verifies all difficulties/Daily, notes/peer elimination, undo, conflict/logical/Mistake/Reveal paths, pause/timing, completion, fresh-process exact snapshot equality, resize to 60x18 and 100x30, clean quit, Ctrl-C, SIGTERM, original termios restoration and the final cursor/alternate-screen cleanup sequence with no subsequent output.

Ignored `test-results/` contains representative plain-text frames, raw ANSI transcripts and local proof snapshots. Existing 18 shared adapter/provenance tests, 63 pinned canonical tests, 21 Web browser checks and Web provenance check remain green. This is local terminal proof; no BBS/Telnet/SSH transport or NativeDoor registration is included.

For caller-scoped PostgreSQL use the trusted `persistent.mjs` entrypoint; see
[the persistence slice](../persistence/README.md). `cli.mjs` keeps local file review.
