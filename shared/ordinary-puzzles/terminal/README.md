# Ordinary Puzzles local terminal adapter

Node.js with built-in readline and plain ASCII/ANSI only. No additional dependencies, daemon, host wiring, or changes to shared/Web code. Uses the pinned canonical implementation through `shared/ordinary-puzzles/session.cjs`.

From `shared/ordinary-puzzles`:

```
npm run build
node terminal/cli.cjs
node terminal/cli.cjs --puzzle 36aab10b5bd4
node terminal/adapter.test.cjs
python3 terminal/pty-proof.py
```

## Controls

- Arrows / WASD: move cursor; active movement calls shared leave/enter using canonical hovered state.
- Space / Enter: begin/end interaction; twice without moving taps.
- R: shared tap at current cell, with no origin/rule filtering.
- Esc: shared exit/release.
- N: catalog menu; 1–4 choose Small, Medium, Large, Extraordinary. Left/Right browse; X random; Enter loads; I accepts stable ID. Esc cancels.
- ?: help. Q / Ctrl-C: quit without automatic save.

Rows are one-based, columns alphabetic, brackets mark cursor. Numbers/dots preserve canonical values. Occupied cells and matching adjacent line IDs supply displayed `-`/`|` connections only. Completion reads canonical `state.cleared`.

The 80x24 viewport shows nine rows/up to fifteen columns. Small `e9c2882a25e2` (9x6) fits fully. Medium `36aab10b5bd4` (10x7), Large `e653beb422ba` (11x8), and Extraordinary `5e5e8642e35b` (11x8) need vertical panning. Cursor-following viewport supports both axes; no puzzle is rejected for geometry. Not every board fits simultaneously. Narrow-screen horizontal panning is also tested.

## Explicit local snapshot interchange

```
node terminal/cli.cjs --export /tmp/ordinary-round.json
node terminal/cli.cjs --restore /tmp/ordinary-round.json --export /tmp/ordinary-next.json
```

These optional files are developer interchange only, not database/caller persistence. Export/restore use the shared journal API without modifying model internals. Quit preserves unfinished canonical interactions; restore anchors the cursor at canonical hover/handler so play can continue.

`TerminalAdapter` exposes `key`, `move`, `load`, `state`, `snapshot`, and `render`. Cursor, viewport and menu state belong to this surface. No placement, collision, reset, completion, solution or restore rules are duplicated. New selection immediately replaces the local board.

Tests: 13 adapter cases plus six real 80x24 PTY runs. PTY proof captures frames, compares snapshots across terminated/restarted Node processes, continues the restored drag, and checks termios/cursor/alternate-screen cleanup. Evidence is written only to a new `/tmp/ordinary-terminal-*` directory. Normal quit, Ctrl-C, EOF, SIGHUP and SIGTERM use cleanup; SIGKILL cannot run cleanup.
