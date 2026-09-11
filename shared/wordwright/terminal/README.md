# Wordwright local terminal adapter

Node >=20.19, plain ANSI and built-in readline; no runtime dependencies or daemon.
The shared engine must be built first. From `shared/wordwright`:

    npm run build
    node terminal/cli.mjs

Optional local review flags:

    node terminal/cli.mjs --length 6 --no-color
    node terminal/cli.mjs --export-on-exit /tmp/wordwright-review.json
    node terminal/cli.mjs --restore /tmp/wordwright-review.json

`NO_COLOR=1` also disables colour. A real TTY of at least 80x24 is required. Smaller
sizes show resize guidance; resize events redraw. Layout is capped at 78 printable
columns and 23 rows to avoid edge wrapping at the 80x24 baseline.

## Input

During gameplay every A-Z letter, including N/L/S/Q, is ordinary guess input.
Enter submits; Backspace erases. Esc opens a separate command screen:
N new game, L length, S stats/history, ? help, Q quit. Esc from any auxiliary
screen returns to play. Bare Esc may take readline's short escape-sequence timeout
to resolve, allowing arrow sequences to remain intact. Stats history uses [ / ]
or Left / Right, five records per page. No command steals alphabetic game input.
? opens help directly. Ctrl-C quits from any screen.

Canonical feedback renders as letter plus symbol: = correct position, + present
elsewhere, - absent, . unsubmitted. Colour is supplementary. Both completed rows
and keyboard feedback come from shared projections; there is no terminal evaluator.
A successful shared submit immediately calls shared completeReveal(), so terminal
has no flip animation but still performs canonical REVEAL_COMPLETE. Win/loss,
answer suppression, timing, score and stats all remain shared/canonical.

The terminal completes reveal sooner than the Web's animation by design; the
canonical finishedAt is the actual callback time, not an invented animation time.

## Local snapshots and lifecycle

Adapter snapshot()/prepareHandoff() delegate directly to the shared session.
CLI --restore invokes canonical shared restore; --export-on-exit explicitly exports
one JSON review snapshot on quit or handled Ctrl-C/SIGTERM/SIGHUP. These local
review files contain the answer and statistics: they are not authenticated storage,
a database layer, autosave, or a lease implementation. No identity is accepted.
Normal runs create no save files. SIGKILL/power loss cannot export or restore TTY
modes; future host persistence must checkpoint separately.

The CLI hides the cursor in an alternate screen, saves the prior raw-mode setting,
and restores raw mode, colour, cursor and screen on exit. Handlers are removed once.
No host navigation, NativeDoor configuration, PP membership or legacy Wordle change.

## Checks

From this directory, after the shared build:

    node adapter.test.mjs
    python3 pty.test.py

18 adapter tests cover alphabet/command safety, delegation, rejection, all lengths,
feedback, stats/history pages, snapshot restore, geometry and lifecycle cleanup.
The Python test uses Python's standard-library PTY and canonical dictionary fixtures
created by fixtures.mjs, never mutated game internals. It writes evidence only to
a temporary directory. It exercises separate processes, records ANSI frames,
measures every frame, and compares termios before/after exit. Shared regression:
`npm test` from the parent (37 tests).

Source/provenance: all game modules remain in the parent's exact pinned upstream
copy; see ../upstream.json and ../README.md. This directory contains original
presentation/input/lifecycle glue, not vendored or reimplemented puzzle mechanics.

Optional trusted caller launcher: node terminal/persistent.mjs from the parent, with DOOR_USER_NUMBER supplied by a trusted host. It accepts no caller/snapshot CLI arguments. See ../persistence/README.md for lease/auth/save lifecycle; no NativeDoor is registered.
