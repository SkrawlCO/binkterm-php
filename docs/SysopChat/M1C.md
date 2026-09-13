# SysOp Chat — M1C Checkpoint

> This document, and SysOp Chat generally, is a work-in-progress feature.
> This checkpoint was authored by an AI assistant as part of an implementation
> pass; it has not yet been reviewed for accuracy beyond passing its own test
> suite.

**Status (2026-09-13): HUMAN-ACCEPTED, M1 CLOSED.** Real SyncTerm session on
the shipping Declarative People screen (`People → Connect → [O] Page SysOp`):
page created, waiting state shown, the page reached the Web admin inbox,
Matt accepted, live two-way chat exchanged multiple lines each direction and
felt live, caller `/q` ended it cleanly, and the caller returned directly to
the Declarative People screen — no legacy-menu fallback, no disconnect.
Admin End Chat, caller Cancel (Esc while waiting), and admin Decline were
each retested afterward and all passed. See `docs/SysopChat/M1_CLOSEOUT.md`
for the full close-out record.

Two real defects surfaced during this human pass and were fixed and
retested before acceptance — both are recorded here because they explain
scars visible in the diff (a `runPageSysopFlowInner()` split, a `\r\033[K`
write) that would otherwise look unmotivated:

1. **Declarative-fallback / disconnect blocker.** `runSysopChatModal()`'s
   catch-up closure referenced `$locale` without capturing it in its
   `use (...)` list, throwing `TypeError: ...renderSysopChatLine(): Argument
   #4 ($locale) must be of type string, null given` the instant the modal
   rendered any existing message. Uncaught from the legacy menu (no
   surrounding try/catch there) this was a real PHP fatal error and a hard
   disconnect; caught by `DeclarativeMenuBridge::run()`'s own catch-all, it
   silently fell the *entire remaining session* back to the legacy menu.
   Root-caused from the exact production stack trace in `php_errors.log`,
   fixed (added the missing capture), and — as defense in depth against any
   *future* bug in this flow doing the same thing — `runPageSysopFlow()` was
   split into a thin wrapper with a `try/catch` around the real body
   (`runPageSysopFlowInner()`), so neither caller can ever again observe an
   uncaught exception from Page SysOp. Regression-tested in
   `BbsSessionSysopChatIntegrationTest` (proved to actually fail without the
   fix by reverting it and re-running before restoring it).
2. **Visual run-together artifact.** `redrawSysopChatInput()` deliberately
   never terminates the live input-echo line with a newline (so typing
   redraws in place); `renderSysopChatLine()` wrote the canonical "You: "/
   "SysOp: " line straight after it with nothing to separate them, producing
   `"> Hi! Just wanted to pageYou: Hi! Just wanted to page"` on screen.
   Diagnosed from code alone (no reproduction needed). Fixed by having
   `renderSysopChatLine()` emit `\r\033[K` (clear the current line) before
   writing — idempotent when the cursor is already on a fresh line, so
   multi-message catch-up rendering is unaffected. Regression-tested the
   same way (reverted → failed with the exact artifact bytes → restored →
   passed).

Both fixes are already deployed and were retested clean before the human
acceptance gates above were signed off.

---

M1C builds the **terminal caller side**: Page SysOp from the main menu, a
waiting state, a live chat modal, and a clean return to wherever the caller
was. Nothing here is activated in production yet (no migration to apply —
M1C adds no schema — and no daemon restart has been performed).

## Terminal event dispatch

`BbsSession::pumpRealtimeAndCheckSession()` (already the single realtime pump
every idle-aware read primitive calls) now dispatches each polled event to
**two** handlers in one pass, in a fixed order:

1. `SessionKickHandler` (unchanged — same class, same semantics, same
   `session_id` filter)
2. `SysopChatEventHandler` (new) — reacts only to `sysop_chat.*` types, and
   only when they match an explicit "expected page id" the flow sets/clears
   as it creates/leaves a page

This is one `TerminalEventPoller::poll()` call with a small composite
closure, not a second independent poll — no second DB query, no risk of the
two handlers seeing different batches or getting out of order.
`session.kick` is dispatched first, unconditionally, on every event in the
batch, so it can never be delayed or suppressed by SysOp Chat handling.
`BbsSessionSysopChatIntegrationTest::testKickAndSysopChatEventInTheSameBatchBothDispatchKickWins()`
proves both handlers still see a batch that contains one of each.

`SysopChatEventHandler` itself is intentionally dumb (per
`TerminalEventHandlerInterface`'s contract): it never renders, blocks, or
reads input — it just records "there's a pending transition" /  "there's a
new message," which `runPageSysopFlow()`/`runSysopChatModal()` drain between
reads.

## Page SysOp surfaces

**Enabled (M1D, both dispatch surfaces, one shared implementation):**
- The legacy main menu, as a normal menu action (`page_sysop`, default key
  **O**), wired through the same `AppearanceConfig::DEFAULT_TERM_MENU_KEYS` /
  admin-configurable-key mechanism every other menu action uses.
- The **Declarative Navigation Framework** — the framework this board
  actually ships (`TERMINAL_NAV_RUNTIME=on`) — as a `page_sysop` action
  registered in `TerminalActionCatalog`, bound in `DeclarativeMenuBridge`'s
  handler map, and placed under the `people` node in
  `config/terminal_navigation.json` (live, human-accepted) and
  `config/terminal_navigation.json.example` (tracked reference), hotkey
  **o**. Human-accepted at `People → Connect → [O] Page SysOp`.

Both surfaces call the exact same `runPageSysopFlow()` — no duplicated chat
implementation, no fork of navigation logic. Reachable from Telnet, SSH, and
PubTerm (see "Web terminal identity" below) on whichever of the two the board
is configured to run.

**Intentionally not wired in M1C/M1D** (S0 named these as approved-in-principle;
scoped down to the one highest-value entry point per surface rather than
touch every nested screen — see the M1C recon's explicit permission to do
this "if adding it everywhere creates invasive churn"):
- People's own Who's Online / Local Chat / Shoutbox / Polls children, Messages
  list, message reader, BBS Directory — each would need its own
  `$extraKeys`/handler wiring or its own declarative node entry; none were
  touched this slice.
- The opt-in **Declarative Navigation Framework**
  (`config/terminal_navigation.json` / `TERMINAL_NAV_RUNTIME`) — a sysop
  running that JSON-driven runtime instead of the classic menu will not see
  Page SysOp at all in M1C. This is a real gap for that runtime's users, not
  a silent oversight — closing it is an M1D-or-later task.

**Never available, by construction, not by a special-case check:**
NativeDoor / WebDoor / DOS-door relay loops own their own PTY/session and
never call into `BbsSession`'s menu dispatch at all, so Page SysOp simply
does not exist inside them — nothing needed to be added to keep it out.

**Deliberately excluded this slice, same policy as S0 recommended:**
compose/editor (unsaved text should never be put at risk for a chat
interruption) and Local Chat/MRC (no obviously-safe suspend point yet). This
is intentional M1C policy, not a defect.

## Page creation

`runPageSysopFlow()` calls `SysopChatService::createPage()` with:
- `caller_user_id` = the authenticated session's `user_id`
- `caller_session_id` = `$this->authSessionId` (the real auth session id —
  never spoofed)
- `surface` = `'ssh'` when `$this->isSsh`, else `'telnet'`

If the caller already has a waiting or active page (`getCallerPage()`),
that's reused instead of creating a duplicate — an active page goes straight
into the chat modal; a waiting one resumes the waiting loop.

### Web terminal identity

**PubTerm is the only "Web terminal" for the main navigation surfaces Page
SysOp lives on**, and it is architecturally a real Telnet socket connection
(a browser xterm.js player relayed over loopback into the same Telnet port,
per `docs/PubTerm.md`) — `BbsSession` cannot distinguish a PubTerm caller
from a genuine Telnet client at the point `runPageSysopFlow()` runs. Rather
than spoof a `web_terminal` surface value the code cannot actually verify
(the same discipline M1A already applied to `web_ui`), a PubTerm caller's
page is honestly reported as `surface = 'telnet'`. Functionally this is full
parity — Page SysOp works identically for a PubTerm caller — the only gap is
the stored label reading "telnet" instead of a distinct "web_terminal", which
carries no authorization or behavior consequence (`surface` is informational
only). The "M1B admin UI shows `web_terminal`" case referred to in S0 was
about native **door** Experiences' line-relay bridge specifically, not the
main BBS navigation this slice touches — that distinction does not apply
here.

## Waiting mode

Plain lines, no full-screen framing: "Paging the SysOp... / Press Esc to
cancel." The wait loop reuses `readKeyWithTimeout($conn, $state, 1000)` — the
same public, already-idle-aware, already-pumping primitive `ChatHandler`'s
live modal already uses for its own 250ms loop — with a 1s per-iteration
timeout. Every iteration:
- pumps realtime (kick + SysOp Chat) via the existing call already inside
  that primitive
- checks for `ESC` → `cancelPage()`, exits waiting
- checks `SysopChatEventHandler::takePendingTransition()` → accepted enters
  chat; declined/expired shows a message and returns

**Latency honesty:** a 1s poll interval feels prompt without polling the
realtime bus itself any harder — `TerminalEventPoller` self-throttles to
about one DB query per 2s regardless of how often `poll()` is called, so this
is not "polling the DB aggressively," just calling the same self-limited
function more often than the ~30s worst case the *plain* idle-aware read
loop can reach when nothing else is invoking it.

On disconnect (real drop, idle timeout, or a `session.kick`), the waiting
loop proactively calls `cancelPage()` before returning — a caller who
vanishes while waiting never leaves a page for Matt to accept into a phantom
chat, rather than relying solely on `acceptPage()`'s passive dead-session
check (which only catches an actually-expired `user_sessions` row, not a
raw socket drop within an otherwise-still-valid session).

## Chat mode

`runSysopChatModal()` owns input exclusively via the same `readKeyWithTimeout`
loop, 1s timeout, accumulating keystrokes into a `TerminalLineEditor` (the
existing pure line-editing state machine already shared by every other
line-input surface in the terminal server — no new editing logic). No
underlying menu ever sees a keystroke while this loop owns it.

- **Send:** Enter submits the buffer. `/q` ends the chat
  (`completePage()` as the caller); Esc/Ctrl-C do the same (a natural
  cancel-style exit, matching `TerminalLineEditor`'s own `RESULT_CANCEL`).
  Anything else non-empty goes to `SysopChatMessageService::sendMessage()`
  and is rendered locally immediately (no need to wait for the realtime
  echo of your own message).
- **Receive:** a `sysop_chat.message` event sets a "new message" flag;
  the loop then calls `listMessages()` once and renders only messages newer
  than the highest id already shown (`$lastSeenId`) — a bounded catch-up
  read, not a polling loop, and it can never double-render regardless of how
  many nudges arrive before the next check.
- **End (admin side):** a `sysop_chat.completed` transition shows "Chat
  ended." and exits immediately.
- **Disconnect while chatting:** best-effort `completePage()` before
  returning, so Matt's admin panel does not sit on a phantom active chat
  after a real drop.

## Resume / redraw

No framebuffer snapshot. `runPageSysopFlow()` is called from, and returns
control straight back to, the main menu's own `while (true)` action-dispatch
loop — exactly like every other menu action (`localchat`, `netmail`, …). That
loop already redraws the full menu on its next iteration unconditionally, so
the "logical resume" the S0 architecture called for falls out of the
existing structure for free; nothing new was built for it. Since Page SysOp
is wired only at the main-menu level in M1C, there is no deeper
screen-specific resume case (a message-reader position, a directory page) to
restore yet — that only becomes relevant once/if a future slice wires Page
SysOp into those nested screens too.

## Admin disappearance during an accepted chat

Honest limitation, matching S0's own allowance to report this rather than
build presence infrastructure: there is **no admin heartbeat or server-side
timeout** that ends a chat because the admin's browser vanished. If Matt's
tab disappears without clicking "End Chat," the caller's modal will simply
never receive a `sysop_chat.completed` event. The caller is **not stuck**,
though — `/q` or Esc always works locally regardless of what the admin side
is doing, so the bounded M1 fallback is "the caller can always leave
themselves," not a timer. A real presence/heartbeat mechanism is out of
scope for this slice.

## Tests

- `tests/Unit/SysopChatEventHandlerTest.php` — 12 tests, pure logic (no DB/
  socket): transition surfacing, page-id filtering, message-flag semantics,
  unrelated/request events ignored, state reset on `setExpectedPageId()`.
- `tests/Unit/BbsSessionSysopChatIntegrationTest.php` — 8 tests, socket-pair +
  real DB (mirrors `BbsSessionKickIntegrationTest`'s established harness):
  accepted/message/other-page/unrelated dispatch through the real composite
  pump, **and the critical regression gate** — `session.kick` still
  terminates correctly both alone and in the same batch as a `sysop_chat.*`
  event, with the SysOp Chat handler wired in.
- Re-ran `tests/Unit/BbsSessionKickIntegrationTest.php` +
  `tests/Unit/SessionKickHandlerTest.php` unmodified alongside the above:
  still pass unchanged — the composite dispatch is additive, not a rewrite.
- Combined with the M1A/M1B suites in one process: **74/74 passing, 202
  assertions.**

**Not covered by these tests** (honest gap, not overlooked): the actual raw
keystroke-by-keystroke behavior of `runPageSysopFlow()`/`runSysopChatModal()`
— rendering, `TerminalLineEditor` integration, the exact ANSI output — is
not exercised by an automated test in this slice. `SysopChatEventHandler`
(the new *logic*) and the composite dispatch + kick-coexistence (the
regression-critical *architecture*) are fully covered; the terminal I/O loop
itself would need either a much larger socket-driven scripted-keystroke
harness or human acceptance testing, and M1C stops short of building the
former.

## M1D human acceptance results (2026-09-13, real SyncTerm)

All run against the shipping Declarative People screen unless noted:

| Check | Result |
|---|---|
| Page from Declarative People; waiting message shown | PASS |
| Page reaches the Web admin waiting inbox | PASS |
| Accept from Web admin; "answered your page" + chat header appear promptly | PASS |
| Web → terminal messages (multiple lines) | PASS |
| Terminal → Web messages (multiple lines) | PASS |
| Felt live | YES |
| No duplicate/lost messages | confirmed |
| Caller `/q` ends chat, returns to Declarative People | PASS |
| Admin End Chat ends chat, returns to Declarative People | PASS |
| Caller Cancel (Esc while waiting), returns to Declarative People | PASS |
| Admin Decline, clean message, returns to Declarative People | PASS |
| No legacy-menu fallback (post-fix) | confirmed |
| No disconnect (post-fix) | confirmed |

**Not covered by this human pass** (deliberately parked, not defects):
5-minute real-time expiry observation (automated proof only —
`SysopChatServiceTest::testStaleWaitingPageIsExpiredByOpportunisticSweep` et
al.); SSH and PubTerm (architecture-supported via the same `BbsSession` path,
not human-tested in M1); a real mid-chat kick/disconnect human observation;
admin-disappearance behavior. See `docs/SysopChat/M1_CLOSEOUT.md` for the
full parked-work list.
