# BreakLock shared round controller

This directory contains the first implementation slice only: shared JavaScript
orchestration and tests. It is outside the public document root. No launch
manifest, Web/terminal adapter, storage, host navigation or PP wiring is included.

## Source and license

`upstream.json` records maxwellito/breaklock revision
`a06fb28a3fa6072a089ca664c66a7bf08c0a3e99`, source paths and checksum.
`upstream/pattern.js` is a byte-for-byte copy of `src/models/pattern.js`.
`LICENSE.upstream` is the original MIT license, including copyright
`2017 maxwellito`; it also covers the controller behavior adapted in `round.js`.
Keep both with any redistributed bundle. Do not edit the canonical Pattern file.

Pattern owns random generation, ordered selections, rejection, midpoint insertion,
completion and comparison. The shared controller owns the upstream mode lifecycle.
It imports no DOM, Node-specific runtime, SVG, terminal, PHP or storage APIs.
The local package declares ESM so the same module can be imported by Node or a
future browser bundle, without changing the canonical file's export syntax.

## API

```js
import { BreakLockRound } from './round.js';
const round = new BreakLockRound({ mode: 'practice', dotLength: 4 });
round.clearDraft(); // beginning a new gesture also cancels its pending reset
const event = round.select(0); // canonical position, not a keypad character
const snapshot = round.snapshot();
const restored = BreakLockRound.restore(snapshot);
```

- `start(mode, dotLength)` creates a random secret and resets round data. Modes
  are `practice`, `challenge`, `countdown`; difficulties are 4, 5, 6.
- `newGame()` performs the upstream summary New Game action: start the same mode
  and difficulty, then toggle summary visibility.
- `select(position)` calls Pattern and automatically submits a completed draft.
  Returns canonical `added` positions and an optional attempt result containing
  `matched`, `feedback`, `count`, `ended`. Input during pending feedback reset is
  blocked as in LockCtrl.updatePoint. No generic game-over input block exists.
- `clearDraft()` begins/cancels a gesture or delivers the scheduled draft reset.
- `endGesture()` clears an incomplete gesture; a completed guess remains visible
  until reset. A new gesture can cancel the reset sooner.
- `tick()` delivers exactly one countdown callback, or is a no-op if stopped.
- `reveal()` performs summary SOLUTION (requires a result): append a reveal only
  on loss, set counter to attempts, switch to counter display, toggle summary.
- `home({fromSummary})` emits a navigation effect only. The optional flag applies
  the summary action's visibility toggle. It never stops the clock.
- `attempts` and `history()` derive counts and canonical feedback. Reveal entries
  are presentation entries, not guesses.
- `snapshot(delays?)` returns detached JSON data; static `restore(snapshot)`
  restores it without RNG or callback execution. There is no persistence I/O.

## Scheduling and handoff boundary

The module contains no clock or scheduler. One owner serially delivers input,
countdown and reset callbacks. While running, schedule countdown callbacks at
1000ms, delivering one `tick()` per actual callback. Never infer missed ticks
from wall time or use an absolute deadline. A late callback decrements once.

On suspension, cancel the owner's callbacks and capture scheduler residual delays:

```js
const state = round.snapshot({ nextTickDelayMs: 250 });
// If a guess reset is also pending, include pendingGuessResetDelayMs.
```

Restore schedules the first callback using the stored residual delay; subsequent
countdown callbacks use 1000ms. Residuals are integers from 0 through 1000. No
callbacks run while suspended; suspension/resumption is the portable extension,
not an upstream pause command. Future lease code must ensure one scheduler owns
the round and cancel stale callbacks on stop/clear/transfer.

`snapshot()` without residuals contains the last declared schedule, not a reading
of elapsed time. The surface scheduler must supply residuals for exact phase
handoff. No credentials, lease tokens or authenticated caller IDs belong here.

Preserved quirks:

- Practice win increments attempts without incrementing the displayed counter.
- Challenge decrements only wrong pre-result guesses; a correct tenth wins with
  counter one. Post-result guesses increment the counter regardless of mode.
- Countdown starts after secret creation. Win/zero stop it; Home does not. A
  leftover clock can time out a later Practice/Challenge round, even after a win.
- Starting another Countdown resets remaining to 60 but retains a running
  interval's phase. Switching to counter mode retains a hidden running clock.
- After a result, guesses still compare and enter history; they do not replace
  the result or reopen summary. A correct guess after timeout remains a loss.
- `start()` does not clear summary visibility or an old pending draft reset.
  Summary actions toggle, rather than unconditionally close, their overlay.

## Snapshot schema

Authoritative fields: `schemaVersion`, `upstreamRevision`, `mode`, `dotLength`,
`secret`, `draft`, `history` (typed guess sequences or reveal entries), `ended`,
`counterValue`, and `timer` (`remainingTicks`, `running`, `nextTickDelayMs`).
The timer exists at controller-session scope, even outside Countdown.

Restorable input/presentation fields: `summary` (`visible`, `success`,
`attemptCount`), `statusDisplay`, `pendingGuessResetDelayMs`.
Attempts, feedback, reveal sequence and render geometry are derived.
Counter and summary attempt display cannot be replaced by the current guess count.

Restore rejects incompatible revisions and malformed sequences by replaying them
through Pattern and comparing canonical output. This is structural validation,
not an authentication or anti-cheat boundary. Future caller storage must provide
its own authorization and stale-writer protection.

## Tests

From the repository root, run `node shared/breaklock/round.test.js` or
`node --test shared/breaklock/round.test.js`. No installation or build is required.
Tests use controlled RNG only while constructing deterministic fixtures; production
generation always calls unchanged `Pattern.fillRandomly()`.
