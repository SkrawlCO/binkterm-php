# BinkP Incident Checkpoint — FidoNet/AgoraNet Inbound Hang, 2026-09-13

**Status: H2 fixed locally (source changed, tests green). NOT YET ACTIVATED
in production — no service has been restarted, no config changed, and
production has not been contacted to confirm. See §7.**

This file exists to preserve the evidence and reasoning from two read-only
investigation passes before any fix is attempted, so the diagnosis survives
context loss and can be reviewed independently.

## 1. What was reported

Nick (Fido/AgoraNet admin, peer-side BinkP logs) reported repeated incoming
sessions from L33TEST (`SYS L33Test Gaming`, `AKA 1:104/121@fidonet`,
`VER BinktermPHP/1.10.5 binkp/1.1`) that authenticated successfully, began
receiving a file offer, then went silent for exactly five minutes before
L33TEST closed the connection. Two networks were affected — FidoNet
(`1:154/10`) and AgoraNet (`46:1/100`) — both hosted on the same physical
remote, `binkp.pharcyde.org`.

Nick's timestamps (peer-local, UTC-5 per his own conversion):

| Event | Peer-local | UTC |
|---|---|---|
| Session 1 starts (FidoNet), peer begins sending `a54e0407.sa0` (57572 bytes) | 23:15:09 | 04:15:09 |
| Session 2: peer's own retry poll finds L33TEST's AKA busy, drops | 23:17:39 | 04:17:39 |
| Session 1 ends (L33TEST closes carrier) — exactly 5:00 after start | 23:20:09 | 04:20:09 |
| Session 3 starts (AgoraNet), peer begins sending `a5407f0e.sa0` (2695 bytes) | 23:18:39 | 04:18:39 |
| Session 4: peer's own retry poll finds L33TEST's AKA busy, drops | 23:19:39 | 04:19:39 |
| Session 3 ends — exactly 5:00 after start | 23:23:39 | 04:23:39 |

## 2. What was confirmed on the L33TEST side (pass 1, read-only recon)

App: `/root/binktermphp/app`, branch `experience-lobby-v2`, HEAD at the time
of recon `152c505ea` (docs-only commit, unrelated). Container `binkterm-app`
had not restarted across the incident window; `binkp_scheduler`/`binkp_server`
supervisor processes ran continuously with no crash.

`binkp_session_log` DB rows (all times UTC) matched Nick's report to within
~0.15s:

| id | remote_address | started_at | ended_at | duration | bytes_received | status | error |
|---|---|---|---|---|---|---|---|
| 18163 | 1:154/10 | 04:15:09.130 | 04:20:09.016 | 299.885s | 0 | `success` | — |
| 18164 | 1:154/10 | 04:17:39.254 | 04:17:39.278 | 0.024s | 0 | `failed` | Remote busy: Secure AKA 1:104/121@fidonet busy |
| 18166 | 46:1/100 | 04:18:39.176 | 04:23:39.021 | 299.845s | 0 | `success` | — |
| 18170 | 46:1/100 | 04:19:39.267 | 04:19:39.294 | 0.027s | 0 | `failed` | Remote busy: Secure AKA 46:1/121@agoranet busy |

`config/binkp.json` → `binkp.timeout` = `300`. Both real hangs lasted almost
exactly that value. Neither offered filename (`a54e0407.sa0`, `a5407f0e.sa0`)
ever appeared anywhere in `data/inbound`, its `error/`/`unprocessed/`
subfolders, or `data/outbound` — the files were never received at all, not
partially written or stuck unprocessed. Filesystem/permissions on
`data/inbound` are healthy — it received a different peer's file
(`618:300/1`, `DD6B3DA9.SU0`, 1627 bytes) cleanly in the middle of the same
incident window (`04:18:05` UTC), confirming the write path itself works.

A healthy comparison session (`618:300/1`, MicroNet, L33TEST as **answerer**,
`04:18:05` UTC) completed in under one second with a clean
`Receiving file → File received → Session completed successfully` sequence
in `binkp_server.log`. Both hangs were sessions where L33TEST was the
**originator** (outbound poll).

### Confirmed secondary defects (pass 1)

1. **Status misclassification**: `BinkpSession::processSession()` returns
   `true` whether it terminates cleanly or via its internal hard
   EOB/inactivity timeout break — there is no distinct failure signal for "we
   gave up after the timeout with nothing received." This is why both real
   hangs are logged `status=success, bytes_received=0` instead of as
   failures, which is also why monitoring never caught this on its own.
2. **90-second same-host lock shorter than the 300s session it protects**:
   `BinkpClient::acquireHostLock()` (added in `000a9eb08`, 2026-08-27, "Fix
   FidoNet/AgoraNet simultaneous-poll collision on shared remote host" — a
   fix written in direct response to an earlier report from this same peer
   contact) waits up to 90s for a same-`hostname:port` lock before proceeding
   *without* it. Session 3 (AgoraNet) started at `04:18:39`, exactly 90s
   after the scheduler logged "Scheduled poll starting for: 46:1/100" at
   `04:17:09` — strong circumstantial evidence the AgoraNet poll queued
   behind FidoNet's still-hung session, timed out waiting for the lock at its
   90s ceiling, and dialed the same physical host anyway while the first
   session was still alive.
3. **Admin-daemon RPC loses the detailed session log before a long poll
   finishes**: for a single-uplink `binkp_poll_sync` admin-daemon command,
   the protocol logger is routed to stdout (captured by
   `AdminDaemonServer::runCommand()`) rather than to
   `data/logs/binkp_poll.log`. `AdminDaemonClient::readResponse()` has no
   `stream_set_timeout()` of its own and relies on PHP's
   `default_socket_timeout` (~60s, retried once ≈120s) — shorter than the
   ~300s the real poll can legitimately take. The scheduler's RPC gives up
   and logs `Admin daemon closed connection` (seen in `binkp_scheduler.log`
   at `04:17:09` and `04:19:09`) before the daemon ever finishes and returns
   the detailed stdout log, so the frame-by-frame detail for exactly these
   two sessions was generated but never persisted or delivered anywhere. It
   is not recoverable now.
4. **Unexplained second same-uplink retry**: session 18164 is a *second*
   outbound dial to `1:154/10` at `04:17:39`, not explained by any second
   cron-scheduled poll entry in `binkp_scheduler.log`. Likely origin is
   `Scheduler::pollIfOutbound()`'s separate outbound-queue-triggered retry
   path, which was not traced in pass 1. **Still open — not investigated in
   pass 2 either.**

### Primary hypothesis at the end of pass 1 (STRONG LEAD, not confirmed)

As originator with nothing queued to send and no FREQ requests pending,
`BinkpSession::processSession()` only waits ~2 seconds for the peer's
`M_FILE` before committing to its own `M_EOB`. Separately,
`BinkpFrame::parseFromSocket($socket, true)` ("nonblocking") only checks
`stream_select()` for 100ms before falling into a real blocking
`readExactly()`/`fread()`, which could itself block for the full configured
stream timeout on a fragmented frame. Pass 1 could not distinguish which of
these two mechanisms (if either) actually explains the observed 0-bytes,
exactly-300s stall, since no frame-by-frame log survived for the real
sessions (see defect 3 above).

## 3. Pass 2 — local proof (2026-09-13, same day, continuation)

**No production traffic of any kind was used.** Everything below runs
in-process against `socket_create_pair(AF_UNIX, ...)` — an OS-loopback pair
with no route to any network peer, hostname resolution, or the real BinkP
ports — plus a `pcntl_fork()`'d child process (same machine, same PHP
process image) playing the "peer" side under the test's own script. FidoNet,
AgoraNet, MicroNet, and Nick's server were never contacted.

Test file: `tests/Unit/BinkpOriginatorReceiveWindowIncidentTest.php` (new;
not yet committed).

### Exact state-machine trace (source reading, `BinkpSession::processSession()`)

For an originator session with nothing queued to send and no FREQ requests:

1. `processSession()` sets state to `STATE_FILE_TRANSFER` immediately.
2. `$shouldWaitForFrames = !isOriginator || filesSent || pendingFreqRequests`
   evaluates **false** — the initial "process incoming frames before EOB"
   phase (hardcoded 5s) is skipped entirely; log line
   `"Originator with no files - proceeding to EOB"`.
3. A second, narrower wait loop runs for a hardcoded `$maxWaitTime = 2`
   seconds, polling `BinkpFrame::parseFromSocket($socket, true)` every
   ~100ms. If an `M_FILE` frame arrives inside this window, `$currentFile`
   is set and the loop exits immediately (does not wait for the payload
   itself, just the offer).
4. After that window, `if ($this->state === STATE_FILE_TRANSFER &&
   !$this->currentFile) { sendEOB(); state = STATE_EOB_SENT; }` — **this is
   the premature-EOB moment** if no file offer arrived within the 2 seconds.
5. Critically, the subsequent "waiting for session termination" loop does
   **not** stop reading after sending our own EOB: it keeps calling
   `BinkpFrame::parseFromSocket($socket, true)` every iteration (with a
   100ms idle sleep) all the way until `STATE_TERMINATED`, and the
   3-second self-close "grace period" logic only arms once `state ===
   STATE_EOB_RECEIVED` (i.e. only *after* the peer's own EOB has come back)
   — not merely after we've sent ours. The only thing that can end the
   session early while we're just waiting post-EOB with no active transfer
   is the hard `elapsed >= $eobTimeout` break, where `$eobTimeout = max(30,
   getBinkpTimeout())` — 300s in production.

This means, purely from source reading, that sending `M_EOB` early does
**not**, by itself, stop the loop from later noticing and correctly
processing a legitimately-delivered `M_FILE` — *provided* each
`parseFromSocket()` call actually returns promptly when no complete frame
is available yet. That proviso is exactly hypothesis H2.

### Local test cases and results

All three ran in a single PHPUnit invocation
(`tests/Unit/BinkpOriginatorReceiveWindowIncidentTest.php`), no production
services touched:

**Case A — prompt file (control, peer sends immediately, <2s):**
Result: `processSession()` returned `true`; `PROMPT.TST` (2000-byte payload)
received in full at the test's isolated inbound path. Elapsed: well under
5s. Confirms baseline behavior is correct when the peer is fast.

**Case B — delayed file (peer sends 3s after session start, past the 2s
abbreviated window):** Confirmed via logger output that the session did
commit to its own `M_EOB` (`"No active file transfer, sending EOB"`) before
the peer's file arrived — H1's premise is real. Despite that, the file
(`DELAYED.TST`, 2000 bytes) was **still received in full**, and
`processSession()` still returned `true`. Elapsed: ~5.4s (dominated by the
3s scripted peer delay plus fork/EOB-round-trip overhead — nowhere near the
8s session timeout used in this test, and nothing like the production 300s
stall).

**Case C — fragmented frame (peer sends half of a real `M_FILE` frame, then
never sends the rest and never closes its end):** A 2-second
`stream_set_timeout()` was set on the session's socket (standing in for
production's 300s `binkp.timeout`, same mechanism). `BinkpFrame::
parseFromSocket($socket, true)` was called directly and timed.
**Result: it blocked for `2.0021150112152s` against the 2s stream
timeout — i.e., for (within measurement noise) the *entire* configured
timeout — before returning `null`.** The "nonblocking" call is not actually
nonblocking once any partial data has made the socket appear readable.

### Hypothesis resolution

- **H1 (premature EOB stops frame processing): REFUTED as a standalone
  explanation.** The code demonstrably keeps polling for and correctly
  processes a legitimately late `M_FILE` after sending its own premature
  `M_EOB`, as long as the read primitive underneath actually returns
  promptly. The premature-EOB behavior is real and confirmed to occur
  (Case B), and could still matter for how a *real* binkd peer reacts to
  receiving our early EOB (that peer-behavior half of H1 is out of scope
  for a local, self-controlled mock peer and was not tested — see Open
  Questions), but it does not by itself reproduce a multi-minute local
  stall.
- **H2 (false-nonblocking blocking read): CONFIRMED.**
  `BinkpFrame::parseFromSocket($socket, true)` performed a genuine blocking
  read for the full configured stream timeout against a fragmented frame,
  with no fallback or shorter internal ceiling. This is a plausible,
  demonstrated mechanism for turning "the peer paused mid-delivery" (bytes
  arriving in more than one TCP segment, an entirely ordinary condition
  over a real internet path) into an apparent full-timeout hang on our
  side, matching the exactly-300s, 0-bytes-received shape of both
  production sessions far more directly than H1 does.
- **Final: H2 is the primary, locally-confirmed mechanism.** H1 remains a
  real, secondary contributing behavior (it does needlessly commit to our
  own termination signal early) but was not shown, on its own, to explain a
  stall of this shape or duration.

### Open questions / boundaries not resolved by this checkpoint

- Whether Nick's peer's `M_FILE` delivery to L33TEST was in fact fragmented
  across TCP segments (the packet-level evidence needed to prove this
  exactly is not available — see defect 3 above; this checkpoint proves the
  *mechanism* is real and sufficient, not that it is the only thing that
  happened on 2026-09-13).
- How a real binkd peer (as opposed to this test's own scripted mock)
  behaves upon receiving our premature `M_EOB` while it is still mid-way
  through delivering a file — not testable without contacting a real peer,
  which is explicitly out of scope.
- The unexplained second same-uplink retry (secondary defect 4) — still
  untraced.

## 4. Regression classification

Narrowed `git log` on the exact files/lines involved
(`src/Binkp/Protocol/BinkpSession.php`, `src/Binkp/Protocol/BinkpFrame.php`,
`src/Binkp/Protocol/BinkpClient.php`) found no commit that changed
`BinkpFrame::parseFromSocket()`'s nonblocking/blocking behavior or the
originator's abbreviated wait window in this repository's history — this
class shape (100ms `stream_select()` check, then a real blocking
`readExactly()`) appears to predate the L33TEST-specific hardening work
that has been reasoned about in this incident so far.
**Classification: PRE-EXISTING, not an L33TEST-introduced regression**,
distinct from secondary defect 2 (the 90s host-lock timeout), which *is*
L33TEST-introduced (`000a9eb08`, 2026-08-27) and does actively interact
with this incident by allowing the same-host double-connection to recur
once one side's session runs long — see secondary defects, still parked.

## 5. Status of secondary defects

All four secondary defects listed in §2 remain **PARKED** — not fixed, not
scheduled for fix in this transaction. They are tracked here so they are
not lost, and so a future fix pass has a starting list:

1. Status misclassification (timeout reported as `success`) — PARKED
2. 90s same-host lock timeout vs. 300s session — PARKED
3. Admin-RPC observability gap (detailed log lost on long polls) — PARKED
4. Unexplained second same-uplink retry — PARKED, untraced

## 6. Next smallest fix — IMPLEMENTED (2026-09-13, same-day follow-up transaction)

The fix described as "next smallest" at the end of pass 2 has now been
implemented, tested locally, and is **not yet activated in production**.

### Callers reviewed before changing anything

| Call site | Mode | Contract |
|---|---|---|
| `BinkpSession::handshake()` (line 227) | blocking (default) | intended to wait for the peer during handshake — unchanged |
| `BinkpSession::processSession()` M_GOT wait (line 396) | blocking (default) | outer loop has its own wall-clock timeout; call itself is meant to block — unchanged |
| `BinkpSession::processSession()` ×3 (lines 479, 512, 629) | **nonblocking** | each is inside a `while` polling loop with a `usleep(100000)` fallback on `null` — must never itself block |
| `CrashmailService::waitForGot()` (line ~675) | **nonblocking** | same polling-loop-with-usleep contract as above |
| `CrashmailService::waitForConfirmation()` (line ~727) | **nonblocking** | same polling-loop-with-usleep contract as above |

Every `nonBlocking=true` caller in the codebase shares the identical
contract (poll in a loop, sleep on `null`, never expects the call itself to
block) — confirming the fix could be made once, in `parseFromSocket()`
itself, without touching any caller.

### Fix design

**File:** `src/Binkp/Protocol/BinkpFrame.php`
**Method:** `parseFromSocket()`, plus new private helpers `readAvailableNonBlocking()`,
`storePending()`, `pendingKey()`, and a new public `forgetSocket()`.

**Behavior before:** `nonBlocking=true` only ran one `stream_select()` check
(100ms) before falling into `readExactly()` — a genuinely blocking
`fread()` loop — for the header, command byte, and payload. Once any byte
was visible to `stream_select()`, the rest of the frame's bytes were read
with full blocking semantics, up to the stream's configured timeout (300s
in production) if the peer paused mid-delivery.

**Behavior after:** `nonBlocking=true` now flips the stream to real
non-blocking mode for exactly one `fread()` per attempted field (header,
then command+payload as a single "body" read), then flips it back
immediately, for every stage of the frame — never just the first byte. A
call that finds an incomplete frame returns `null` immediately, exactly as
before, but **only after a read that itself could not have blocked**.

**Partial-frame preservation:** a new per-socket static map,
`BinkpFrame::$pending` (keyed by `get_resource_id($socket)`), holds
whatever prefix of the header/body has been read off the wire so far. A
frame that arrives fragmented across multiple non-blocking calls is
reassembled incrementally — no bytes are read twice, none are discarded,
and the next call resumes exactly where the previous one left off (it does
not misinterpret payload continuation bytes as a fresh header). All of the
original method's edge-case behavior — the `length > MAX_FRAME_SIZE` check,
the defensive command-byte read on a `length=0` command frame, and the
returned `Frame`'s `length` field numbering — is preserved exactly; a
diagnostic run comparing old vs. new byte-for-byte edge cases was done by
inspection, not by a separate test file, since it is exercised indirectly
by the pre-existing collateral tests below.

EOF detection during a non-blocking attempt is preserved: `readAvailableNonBlocking()`
still sets `$lastReadDiagnostics` with reason `eof` when
`stream_get_meta_data()` shows the peer closed the connection, so
`BinkpSession`'s existing "peer closed after EOB" fast-close logic (in the
main termination loop) keeps working unchanged. A benign "nothing available
yet" read is explicitly *not* treated as a diagnostic-worthy event (matches
prior behavior of the upfront `stream_select` check, which also set no
diagnostics when nothing was ready).

`BinkpFrame::forgetSocket($socket)` clears any pending partial-frame state
for a socket. It is called from `BinkpSession::close()` (before `fclose()`,
since the resource ID is meaningless afterward) and from both raw
`fclose()` sites in `CrashmailService.php`. This matters for any
long-running process that opens more than one socket over its lifetime
(e.g. `binkp_poll.php --all` looping over uplinks in one PHP process) —
without it, a freed resource ID could be reassigned to an unrelated later
socket and wrongly try to resume a stale partial frame from a previous,
already-closed connection against it.

### Local regression proof

`tests/Unit/BinkpOriginatorReceiveWindowIncidentTest.php` (the same file
from pass 2), Case C rewritten to assert the fixed contract instead of the
pre-fix bug:

- **Case A** (prompt file): unaffected by the fix, still passes — file
  received in full, fast.
- **Case B** (delayed file, tests H1): unaffected by the fix (H1 was
  already refuted in pass 2), still passes — session commits to its own
  premature `M_EOB`, still receives the file in full afterward.
- **Case C** (fragmented frame, tests H2) — rewritten:
  1. A real `M_FILE` frame is split; only the first 3 bytes (header +
     command byte) are delivered.
  2. First `parseFromSocket($socket, true)` call: returned `null` in
     **~0.000029s** (29 microseconds) against a 2-second configured stream
     timeout — compare to the pre-fix measurement of **~2.0021s** for the
     equivalent scenario. The call no longer blocks at all.
  3. The remaining bytes (rest of the `M_FILE` payload + a following data
     frame) are then delivered.
  4. Polling `parseFromSocket()` again completes the *same* `M_FILE` frame
     correctly (filename/size intact, not corrupted or resynced wrong), and
     the data frame that followed it on the wire also parses correctly in
     order — proof the resumable state machine did not desync the stream.

All three cases pass. No 300-second (or even 30-second) test was needed.

### Collateral tests run

- `tests/Unit/BinkpCramAuthLoggingTest.php` — 4/4 pass (unaffected; confirms
  no credential-logging regression from the touched files)
- `tests/Unit/BinkpServerSocketOwnershipTest.php` — 5/5 pass (unaffected;
  confirms the `BinkpSession::close()` edit did not disturb the existing
  socket-ownership/cleanup guarantees)
- Full incident suite (`BinkpOriginatorReceiveWindowIncidentTest.php`) —
  3/3 pass

12/12 tests green across the three files, run together in one invocation.
No broader suite was run (not needed — the change is narrowly scoped to one
method plus two small, directly-related call sites).

### Activation status

**NOT activated.** The fix is in the working tree, uncommitted. Diff
touches exactly:

- `src/Binkp/Protocol/BinkpFrame.php`
- `src/Binkp/Protocol/BinkpSession.php` (7 lines, in `close()` only)
- `src/Crashmail/CrashmailService.php` (2 lines, `forgetSocket()` calls
  alongside its existing `fclose()` sites)
- `tests/Unit/BinkpOriginatorReceiveWindowIncidentTest.php` (Case C
  rewritten for the post-fix contract)

`binkp_server` and `binkp_scheduler` are long-running supervisor-managed
daemons inside the `binkterm-app` container that load `BinkpFrame.php` once
at process start and keep running for hours — they would need a restart to
pick up this fix for any session they handle directly. `scripts/binkp_poll.php`
(the actual process that ran both hung FidoNet/AgoraNet sessions, spawned
fresh per invocation by the admin daemon or cron) does **not** need a
restart — it reads the current file from disk on its very next scheduled
run. No restart has been performed. No production traffic of any kind
(FidoNet, AgoraNet, MicroNet, or any other peer) has been generated to
confirm this in a real session — that confirmation is still pending and
requires a decision from Matt/ChatGPT on how to proceed.

## 7. Secondary defects — still parked

Unchanged from §5 — none of these were touched in this transaction (H2 only).
