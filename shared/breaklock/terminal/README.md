# BreakLock local terminal surface

Run from the repository root:

```sh
node shared/breaklock/terminal/play.js
```

Requires Node.js with ESM support (verified on Node 20) and an interactive ANSI
terminal of at least 80 columns by 24 rows. No npm packages, daemon, NativeDoor
manifest, host integration, persistence or lease mechanism are included.
The adapter lives outside the public document root and is not exposed to callers.

## Controls

Menu: `1` Practice, `2` Challenge, `3` Countdown; `4` Easy, `5` Medium, `6` Hard;
Enter or `G` starts. These selections call the existing shared start API.

Game: `1` through `9` select positions `0` through `8`, in reading order on the
3x3 grid. `C` clears the draft; complete patterns submit through the shared core.
Added positions, including canonical automatic midpoints, appear in Current and
in the status message. A submitted draft remains briefly visible until its shared
reset callback; `C` starts a fresh draft sooner.

`N` starts a new round. At a summary it calls shared `newGame()`; elsewhere it
calls shared `start()`, avoiding the summary-only visibility toggle. `S` at a
summary reveals/continues through shared `reveal()`. Numeric input is consumed
by the summary overlay until continuation, matching the Web presentation.
`M` opens the mode menu through shared `home()`. Neither Menu nor Help pauses a
running Countdown. This preserves the upstream cross-mode timer behavior.

`[` / `]` or PageUp / PageDown move through older/newer history windows of eight
entries. New guesses return to the newest window. Reveal rows are labeled SOL
and are not numbered as guesses. `?` opens help; the next non-quit key closes
help without applying a selection. `Q`, Ctrl-C or Ctrl-D exit. The label is Quit,
not Save & Return: no progress is saved in this slice.

## State and scheduling boundary

`adapter.js` imports `../round.js` directly. It maps keys and reads detached
snapshots plus `round.history()` for display. Only the shared core and unchanged
canonical Pattern determine accepted selections, insertion, comparison, counters,
results and continuation. The terminal never stores a second secret, draft,
result or feedback calculation. `mode` and `dotLength` on the adapter are menu
choices; the running board's labels always read shared state.

One repeating 1000ms callback calls shared `tick()` exactly once per delivered
callback. There is no deadline, elapsed-time catch-up or timer-based rule in the
adapter. Scheduling stops when shared state says stopped. A one-shot timeout
calls `clearDraft()` for the shared pending reset. Clearing a draft cancels that
callback; new rounds retain existing pending lifecycle state. Clock injection
exists solely for deterministic callback delivery tests. Production uses Node's
normal timer APIs.

Results remain recorded after continuation; even a correct guess following a
loss remains a loss. History feedback is provided by `round.history()`. UI labels,
keypad conversion, row numbering, page boundaries and width clipping are display
responsibilities only. Full-draft input during feedback display is passed to
shared `select()`, which returns its pending-reset block; duplicate input also
reaches shared `select()` unchanged. Pattern's underlying full rejection remains
covered by the existing core tests, not a second terminal rule.

## Terminal lifecycle

`play.js` handles raw input, alternate-screen entry/exit, cursor restoration,
resize, EOF and termination signals. Quit cancels both callbacks, stops input,
restores the prior raw-mode flag and returns exit code zero. Non-TTY startup
returns code two. A smaller window displays a bounded resize notice and accepts
quit; the running timer continues. Resizing back restores the full screen.

Normal frames have 23 rows, at most 78 printable ASCII columns, leaving margin
against last-column wrap and bottom-row scrolling on an 80x24 terminal. The
layout does not require color or Unicode. No secret is displayed until a shared
reveal history entry exists. English local UI text is contained in this adapter;
BBS localization and launch context belong to a later authorized host slice.

## Verification

```sh
node shared/breaklock/terminal/adapter.test.js
node shared/breaklock/round.test.js
python3 shared/breaklock/terminal/pty-proof.py /tmp/breaklock-terminal-evidence
```

The 15 adapter tests exercise menu combinations, input mapping, frame geometry,
shared rejection responses, result rendering, callback delivery/cancellation,
pagination, continuation and quit. They use controlled RNG only during fixture
construction, not a production secret-injection API. Set
`BREAKLOCK_TERMINAL_EVIDENCE=/tmp/breaklock-terminal-evidence` while running the
adapter tests to write human-readable frames and JSON evidence outside the repo.

The Python standard-library PTY proof runs the actual CLI at 80x24, sends keypad
input, verifies a visible midpoint and real Countdown decrement, resizes down/up,
quits and checks terminal-mode/cursor/alternate-screen restoration. It writes a
raw ANSI transcript and a JSON result to the supplied scratch directory and
terminates its child in cleanup. It never connects to L33TEST Telnet/SSH.

Canonical provenance and the retained upstream MIT notice remain in
`../upstream.json` and `../LICENSE.upstream`. No existing core or Web files are
modified by this slice.
