# BinkP Incident Checkpoint — FidoNet/AgoraNet Inbound Hang, 2026-09-13

**Status: H2 (fragmented-frame parser bug) FIXED, ACTIVATED IN PRODUCTION,
and COMMITTED/PUSHED (`650774702`, `experience-lobby-v2`). A separate
observability defect (admin-RPC log loss) has been root-caused precisely
and fixed LOCALLY ONLY — not yet activated. See §8.**

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
   finishes.** *(Pass-1 hypothesis below was superseded by a precise,
   locally-proven root cause in §8 — kept here for the record rather than
   rewritten: the actual mechanism is an ownership/permissions mismatch that
   silently drops the detailed log for every single-uplink poll regardless
   of duration, not a race against the RPC timeout.)* Original hypothesis:
   for a single-uplink `binkp_poll_sync` admin-daemon command, the protocol
   logger is routed to stdout (captured by `AdminDaemonServer::runCommand()`)
   rather than to `data/logs/binkp_poll.log`. `AdminDaemonClient::readResponse()`
   has no `stream_set_timeout()` of its own and relies on PHP's
   `default_socket_timeout` (~60s, retried once ≈120s) — shorter than the
   ~300s the real poll can legitimately take. The scheduler's RPC gives up
   and logs `Admin daemon closed connection` (seen in `binkp_scheduler.log`
   at `04:17:09` and `04:19:09`) before the daemon ever finishes and returns
   the detailed stdout log, so the frame-by-frame detail for exactly these
   two sessions was generated but never persisted or delivered anywhere.
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

Unchanged from §5 — none of these were touched in the H2 transaction.

## 7a. H2 activation confirmation attempt (2026-09-13, post-commit)

After `binkp_server`/`binkp_scheduler` were restarted onto the H2 fix
(old PIDs 11/9 → new PIDs 98351/98365, all 17 supervisor programs verified
RUNNING, no other program touched), one natural `*/15 * * * *` scheduled
cycle to both `1:154/10` and `46:1/100` was observed read-only (no manual
poll, no traffic generated). Result: **inconclusive for H2 specifically**.

- FidoNet (`1:154/10`): started `05:15:15.334`, ended `05:20:15.045`,
  duration `299.71s`, `bytes_received=0`, `status=success`.
- AgoraNet (`46:1/100`): started `05:18:45.302`, ended `05:23:45.095`,
  duration `299.79s`, `bytes_received=0`, `status=success`.
- Both same-host collisions recurred exactly as before (parked defects #2/#4):
  a same-uplink retry for each network was rejected by the peer as
  `Remote busy` a few minutes into each session.
- No crashes, no new parser/session exceptions, all 17 supervisor programs
  stayed healthy throughout.
- **Why inconclusive**: every *other* uplink polled in the same window
  (`227:1/1`, `3323:1/100`, `700:100/0`, `1200:1/1`, `1337:3/100`) also shows
  `bytes_received=0`, yet each completed in well under a minute — so a fast,
  empty poll is normal and unremarkable. Only the two Nick-hosted networks
  ran the full ~300s. That is *consistent* with the pre-fix incident shape,
  but — per explicit instruction not to over-interpret — an empty,
  content-free poll that legitimately has nothing to exchange with Nick's
  side this cycle would *also* run close to the full timeout via the
  ordinary "no active transfer, hard EOB timeout" path, which H2 does not
  touch. Because the admin-RPC observability gap (defect #3, investigated
  precisely in §8 below) was *also* independently confirmed to be losing
  the detailed per-frame log for these exact polls, there was and is no way
  to tell from this natural cycle alone whether a file was offered and
  fragmented (which H2 addresses) or nothing was offered at all (which it
  doesn't). Not a regression signal either way.

## 8. Part B — Admin-RPC observability defect: root-caused precisely and fixed locally

**Scope discipline honored:** this slice touches only the admin-daemon-side
UDP log persistence fallback. It does **not** touch the 90s host-lock
timeout, the unexplained same-uplink retry, the success/status
misclassification, the BinkP protocol state machine, the parser, or
scheduler cadence — all four remain parked exactly as before (see §5/§7).

### Step 1 — exact lifecycle, traced and empirically confirmed

- `Scheduler::processScheduledPolls()` calls `AdminDaemonClient::binkPollSync($address)`
  for every scheduled uplink, every cycle.
- That RPCs `binkp_poll_sync` to `admin_daemon.php`, which (per-connection,
  in a forked child of the daemon — `AdminDaemonServer::run()` calls
  `pcntl_fork()` for every accepted client) runs
  `AdminDaemonServer::runCommand([PHP_BINARY, 'scripts/binkp_poll.php', $upstream])` —
  a **blocking** `proc_open()` that captures the child's stdout/stderr via
  pipes and waits for it to exit.
- `scripts/binkp_poll.php`, for a single-address invocation (no `--all`, no
  `--no-console`), constructs `new Logger($logFile, ...)` with `$logFile`
  defaulting to `Config::getLogPath('binkp_poll.log')` — a **fixed, absolute
  path**, `/var/www/html/data/logs/binkp_poll.log` — independent of cwd/env,
  confirmed by reading `Config::LOG_PATH`'s definition (`__DIR__ . '/../data/logs'`,
  a compile-time constant).
- `Logger::log()` writes to that file with `@file_put_contents(..., FILE_APPEND | LOCK_EX)`
  on every log call — this is a genuinely working, synchronous, unconditional
  write attempt on **every** invocation, not something conditionally routed
  to stdout only. (Pass-1's guess that single-uplink polls route output to
  stdout instead of the file was **wrong** — corrected in §2 above.)
- **Empirically confirmed root cause**: `data/logs/binkp_poll.log` is owned
  `root:root`, mode `644` (verified: `ls -la` on the live file). The
  `admin_daemon.php` process — and therefore every `scripts/binkp_poll.php`
  child it spawns via `proc_open`, since `proc_open` never changes UID —
  runs as `binkterm-admin` (uid 991, gid 991, groups `binkterm-admin`+`www-data`;
  verified via `id binkterm-admin` inside the live container). That user has
  only "other" (read-only) permission on a `root`-owned `644` file, so
  `file_put_contents()` **always fails with permission denied for this
  specific invocation path** — reproduced directly and safely with zero
  external traffic by running `php scripts/binkp_poll.php --test
  --hostname=127.0.0.1 --port=1 <fake-address>` (a pure loopback-refused
  connection, no DNS, no real network) once as `root` (wrote successfully,
  confirming the Logger mechanism itself is fine) and once as `binkterm-admin`
  via `docker exec -u binkterm-admin` (produced zero new bytes in
  `binkp_poll.log`, confirming the failure is specifically about this UID).
- On that failure, `Logger::log()` correctly falls back to
  `AdminDaemonClient::udpLog()` — sending the formatted line as a UDP packet
  to the admin daemon's own UDP log listener (`AdminDaemonServer::handleUdpLogSocket()`,
  polled every ~100ms in the daemon's **parent** process's own accept loop,
  which is never blocked by a forked child's long-running `runCommand()` —
  confirmed by reading `AdminDaemonServer::run()`: it forks per client
  connection, so the parent stays free).
- **The actual loss**: `AdminDaemonClient::udpLog()` returns `true` purely
  because the `fwrite()` to its own UDP socket succeeded — it has no
  acknowledgement/delivery-confirmation from the daemon (UDP is fire-and-
  forget by construction here). On the receiving side,
  `AdminDaemonServer::appendUdpLog()` (pre-fix) wrote the message with
  `@file_put_contents($logPath, ..., FILE_APPEND | LOCK_EX)` and **never
  checked the return value**. Since the daemon runs as the exact same
  `binkterm-admin` user with the exact same permission problem against the
  exact same `root`-owned file, this write **also** silently fails — and
  because `Logger::log()`'s sender-side fallback chain already believed the
  UDP send "succeeded" (tier 2), its final `error_log()` last-resort (tier 3,
  already permitted by this project's own logging convention in
  `src/Binkp/Logger.php`) never fires. The message is lost with **zero
  trace anywhere** — not in `binkp_poll.log`, not in `admin_daemon.log`, not
  in `server.log`, not in PHP's error log. This reproduces for **every**
  single-uplink poll, including fast, fully successful ones (confirmed:
  `227:1/1`, `3323:1/100`, etc. from the 05:15 natural cycle also produced
  zero new `binkp_poll.log` content) — it has **no relationship to session
  duration or the RPC timeout race** described in the original pass-1
  hypothesis. That race is real (the scheduler's own "poll failed" bookkeeping
  is still wrong, and remains parked — see defect list), but it is not what
  destroys the detailed log; the ownership mismatch destroys it
  unconditionally, on its own, independent of timing.

### Step 2 — why not the "obvious" fixes

- **Aligning the RPC client's read timeout with the ~300s BinkP timeout**:
  rejected. `AdminDaemonClient` is a general-purpose RPC client used for
  dozens of unrelated, normally-instant admin operations (config saves,
  license, templates, weather, etc.) via the exact same `sendCommand()`/
  `readResponse()` path. Lengthening its timeout globally would make the
  client hang for minutes on any genuinely stuck admin command, and would
  make the scheduler's own single-threaded polling loop block for up to
  300s+ per uplink serially — undesirable for uplinks with fast, healthy
  polls today. It also would not have fixed anything here: the detailed log
  is lost due to a permission failure, not because nobody waited long enough
  to receive it.
- **Fixing the underlying file ownership** (`chown`/`chmod` on
  `data/logs/binkp_poll.log`): this is very likely the *real*, permanent fix
  needed in production, but it is an operational/filesystem change on a live
  server, explicitly out of scope for "local proof only, no production
  activation" in this transaction, and isn't something a future fresh
  install would inherit just from a source commit (a code-level
  fallback is still worth having regardless of whether ownership gets
  corrected).
- **Chosen fix**: close the specific hole where a failure is silently and
  totally discarded, using the existing logging seam already established by
  this exact codebase's own convention (`Logger::log()`'s own file → UDP →
  `error_log()` fallback chain) — make the *receiving* side of the UDP
  fallback (`AdminDaemonServer::appendUdpLog()`) check its own write result
  and, on failure, surface both the failure and the actual lost message
  content through the one channel this daemon process has always reliably
  been able to write to: **its own `Logger` instance** (`data/logs/admin_daemon.log`,
  confirmed continuously updating throughout this entire incident,
  regardless of the `binkp_poll.log` problem). This does not invent a second
  logging system — it completes the one already there, and needed only a
  return-value check plus a few lines, no protocol/scheduler/architecture
  changes.

### Fix design

**Files:**
- `src/Admin/AdminDaemonServer.php` — `appendUdpLog()` now delegates the
  actual write to a new small private helper, `writeLogLineOrWarn()`
  (extracted specifically so it's testable without fighting
  `Config::getLogPath()`'s hardcoded real path). On a failed write, it calls
  `$this->logger->warning(...)` with the log file name, the original PID,
  and the full lost message text in the context array — so the operator can
  both see that this is happening and recover the actual content from
  `admin_daemon.log`.
- `tests/Unit/AdminDaemonUdpLogFallbackTest.php` — new, isolates
  `writeLogLineOrWarn()` via reflection against a path that is deliberately
  an existing directory (a portable, user-independent way to force a
  guaranteed `file_put_contents()` failure without needing to reproduce the
  real root-vs-`binkterm-admin` ownership mismatch inside a test, and
  without touching the real `data/logs/` directory at all).

**Exact behavior before:** a write failure in `appendUdpLog()` was
completely silent — no warning, no fallback, message permanently lost.

**Exact behavior after:** a write failure now produces exactly one
`WARNING` line in `admin_daemon.log` per lost message, containing the
target log file name, the original process's PID, and the full original
log line — recoverable by grepping `admin_daemon.log` instead of being
gone forever. A successful write is completely unaffected (verified by a
dedicated test case asserting no warning fires and the content lands
byte-for-byte).

### Step 3 — local proof

All three cases run via PHPUnit, zero external traffic, zero production
files touched (a fresh scratch temp directory per test run):

- **Old behavior reproduced**: **YES** — a direct `file_put_contents()`
  against a path that is a directory (standing in for the real permission
  failure) returns `false`, confirmed as a genuine write failure.
- **New behavior**: `writeLogLineOrWarn()` invoked (via reflection) against
  the same guaranteed-to-fail target with a spy `Logger` injected through
  `AdminDaemonServer`'s existing constructor parameter (no reflection
  needed for that part — the constructor already accepts an optional
  `Logger`). Result: exactly one `warning()` call recorded, containing the
  log file name, PID, and the verbatim original message.
- **Output preserved**: **YES** — asserted byte-for-byte equal to the
  original lost message.
- **Elapsed**: full 3-test run in **~0.02 seconds** (no delays, no sleeps,
  no minutes-long waits needed — the failure is immediate and deterministic).
- **Result**: 3/3 new tests pass; full focused collateral run (15 tests
  across `BinkpCramAuthLoggingTest`, `BinkpServerSocketOwnershipTest`,
  `BinkpOriginatorReceiveWindowIncidentTest`, and this new file) — **15/15
  pass, 63 assertions, no regressions**.

### Activation status

**NOT activated.** `AdminDaemonServer.php` is loaded once at
`admin_daemon` process start (long-running supervisor program) — this
change is in the working tree, uncommitted, and would need an
`admin_daemon` restart to take effect in production. No restart has been
performed. This fix does **not** address the underlying file-ownership
mismatch (still requires an ops-level `chown`/`chmod` decision, not made
here) — it only ensures that, once activated, a future write failure of
this shape leaves a recoverable trace instead of vanishing.

## 9. Part B activated, 2026-09-13 — fallback proof, and the incident's actual root cause surfaces

`admin_daemon` was restarted onto the fix (PID `7` → `103469`; `binkp_server`/
`binkp_scheduler` untouched, still the PIDs from H2's activation; 17/17
supervisor programs verified RUNNING). A pre-activation review added one
guard not yet documented above: `writeLogLineOrWarn()` skips warning via
`$this->logger` when the failing target *is* `admin_daemon.log` itself,
since that would route back through `Logger::log()`'s own UDP-fallback
chain targeting the same file this handler is already failing to write —
a latent self-referential loop risk that pre-dated this fix inside
`Logger`'s own design but was only made reachable at this call site by it.
Covered by a fourth test case; 16/16 tests green.

**Fallback proof: immediate and unambiguous**, read-only, no manual polling:

- Within a minute of restart, the fallback began firing for `packets.log`
  (also `root:root`/`644`, the same class of problem) — `process_packets.php`
  concurrency-guard warnings ("Another instance ... already running.
  Exiting.") that would previously have vanished without a trace now landed
  cleanly in `admin_daemon.log`, one warning per lost line, no duplicates,
  no flood, admin_daemon healthy throughout (confirmed via repeated
  `supervisorctl status` and log tails).
- At the next natural `*/15 * * * *` tick (`05:45:xx`), the fallback caught
  the **complete session narrative for `binkp_poll.log`** across multiple
  uplinks, including `1:154/10` (FidoNet) — every line "Polling...",
  "Connecting...", "Handshake completed successfully", etc. — now fully
  recoverable from `admin_daemon.log` for the first time in this incident's
  history.

**This is where the fallback did its job and surfaced the incident's actual
root cause — not H2.** The preserved trace for the `1:154/10` session
starting `05:45:29` reads, verbatim:

```
[1:154/10] Handshake completed successfully
[1:154/10] ERROR: Failed to open file for writing: /var/www/html/data/inbound/a54e0407.sa0
[1:154/10] WARNING: Received file data but no active file transfer   (repeated many times)
```

`a54e0407.sa0` is the **exact filename Nick's original incident report
named**. Confirmed read-only: `data/inbound/` is `drwxr-xr-x`, owned
`www-data:www-data` — mode `755` grants write **only** to the `www-data`
user, not its group (`binkterm-admin` is a secondary member of the
`www-data` group, per `id binkterm-admin`, but group membership only grants
read+execute here, not write). Every single-uplink scheduled poll runs as
`binkterm-admin` (same lifecycle as the `binkp_poll.log`/`packets.log`
findings above — spawned via `admin_daemon.php`'s `binkp_poll_sync`, which
never changes UID). So: **`binkterm-admin` cannot create a new file in
`data/inbound/` at all.** `BinkpSession::handleFileCommand()`'s
`fopen($tmpPath, 'wb')` fails, logs the error, and returns **without**
setting `$this->currentFile`. Every subsequent DATA frame carrying the
peer's actual file bytes then hits `handleFileData()`'s
`if ($this->currentFile && $this->fileHandle)` guard — false — and is
silently discarded with only a WARNING, repeated for as long as the peer
keeps streaming. `$hasActiveTransfer` stays false the entire time, so the
session's ~300s duration is fully explained by the ordinary "no active
transfer" hard EOB/inactivity timeout ceiling (`binkp.timeout=300`) — not
by H2's fragmented-read mechanism. **H2 remains a real, independently
locally-proven bug worth having fixed, but is not what caused this specific
incident.** The true root cause is a **third instance of the same
`binkterm-admin`-vs-`www-data` ownership mismatch class**, this time on
`data/inbound/` rather than a log file, discovered only because Part B's
fallback finally preserved the frame-by-frame detail that had been
invisible through the entire investigation up to this point.

Per instruction, this is reported, not fixed: `data/inbound/` permissions
were **not** touched, `BinkpSession::handleFileCommand()` was **not**
touched, and this finding is **not** one of the four previously-tracked
parked defects — it is new and should be tracked as its own item,
distinct from all four below.

Also observed in the same window: the previously-parked "unexplained
same-uplink retry" (defect #4) recurred exactly as before — a second dial
to `1:154/10` began at `05:46:29`, about a minute after `05:45:29` — left
untouched, as instructed.

## 10. Log ownership / rotation recon (read-only; nothing chmod'd/chown'd)

- `data/logs/binkp_poll.log`: owned `root:root`, mode `644`.
- Sibling convention: `data/logs/binkp_scheduler.log` and
  `data/logs/binkp_server.log` are owned `www-data:www-data` — the same
  user that runs the long-running `binkp_scheduler`/`binkp_server`
  supervisor daemons that write them continuously, i.e. **each log is
  owned by whichever process actually writes it**, the project's clear
  established convention. `data/logs/admin_daemon.log` is owned
  `binkterm-admin:binkterm-admin` — same pattern, correct for what writes
  it. `data/logs/packets.log` is **also** `root:root` — the identical
  class of problem, not unique to `binkp_poll.log`.
- Expected runtime writer for `binkp_poll.log`: whichever identity actually
  invokes `scripts/binkp_poll.php` — for scheduled polls (the incident's own
  case) and interactive admin-terminal syncs, that's `binkterm-admin`
  (`admin_daemon.php`'s own UID); for the standalone `--all` nightly cron
  job noted in `admin_daemon.log` history, it's whatever ran the container's
  own cron.
- **Creation/rotation source, found precisely:** two *separate*, overlapping
  logrotate schedules exist. (1) The project's own built-in, documented
  mechanism: `docker/entrypoint.sh` generates `/etc/cron.d/binkterm` inside
  the container, which runs `scripts/logrotate.php` **explicitly as
  `www-data`**, weekly by default (confirmed live: `0 0 * * 0 www-data cd
  /var/www/html && php scripts/logrotate.php --keep=52 ...`) — and
  `docs/DOCKER.md` explicitly documents `LOGROTATE_SCHEDULE`/`LOGROTATE_MAX_SIZE`
  as the supported way to rotate `binkp_poll.log` more often between weekly
  runs, calling it out by name. (2) A **separate host-level crontab entry**
  (`7 * * * * cd /root/binktermphp && docker compose exec -T binkterm-app
  php scripts/logrotate.php --keep=5 --max-size=10M ...`, matching the
  "BinktermPHP BinkP log hygiene" memory's "host crontab now runs logrotate
  hourly at :07") layered on top, hourly — and critically, `docker compose
  exec` here has **no `--user`/`-u` flag**, so it runs as the container's
  Docker-level default user, confirmed live to be **root** (`docker exec
  binkterm-app whoami` → `root`; no `USER` directive in the `Dockerfile`).
  Any log file this second job actually rotates/recreates gets stamped
  root-owned. `docs/DOCKER.md` even explicitly warns to disable the
  built-in `ENABLE_LOGROTATE` job if scheduling it externally — advice this
  particular host customization did not follow, running both.
- Whether manual `chown` would be durable: **no, not by itself** — the
  moment the hourly host-level job rotates that file again (crosses the
  `--max-size=10M` threshold, or `--keep` cycling), the replacement file
  would again be created as `root` and the mismatch would return. A durable
  fix has to address the host crontab's missing `--user www-data` (or
  disabling that redundant external job entirely and relying on the
  built-in `LOGROTATE_SCHEDULE`/`LOGROTATE_MAX_SIZE` env vars as documented),
  not just the current file's ownership. **Not performed in this
  transaction** — recon only, per instruction.
- **Classification: DEPLOYMENT MISMATCH**, not intentional. The project's
  own documentation and built-in in-container cron already establish and
  recommend the correct pattern (`www-data`, via `LOGROTATE_SCHEDULE`/
  `LOGROTATE_MAX_SIZE`); a separate, later host-level customization
  (matching prior memory of "host crontab now runs logrotate hourly")
  diverges from it by omitting `--user` on `docker compose exec`.

## Secondary + newly-discovered items — status before §11

- Session-timeout-recorded-as-success misclassification: **PARKED**, untouched
- 90s same-host lock timeout vs. 300s session: **PARKED**, untouched
- Admin-RPC observability/log-loss: **root-caused precisely and fixed,
  activated in production, and proven live** (§9); underlying file-ownership
  mismatches (logs *and*, as found next, `data/inbound/`) still require a
  separate ops decision for the log files specifically, not made here
- Unexplained same-uplink retry: **PARKED**, untouched, recurred again live
  during this transaction, still unexplained
- **The actual root cause of the original incident**, found via Part B's own
  fallback: `data/inbound/` (`www-data:www-data`, `755`) was not writable by
  `binkterm-admin`, the user every `binkp_poll_sync`-driven scheduled/manual
  single-uplink poll runs as. **This is fixed as of §11 below.**

## 11. Root cause fixed: `data/inbound/` writer permissions (2026-09-13, same-day follow-up)

### Decisive confirmation, symmetric across three networks

The recovered protocol traces (via the Part B fallback, §9) showed the
identical failure for **all three** networks that offered a file during a
scheduled poll in this session — not just Nick's peer:

| Network | File | Failure |
|---|---|---|
| FidoNet `1:154/10` | `a54e0407.sa0` | `Failed to open file for writing` → repeated `Received file data but no active file transfer` |
| AgoraNet `46:1/100` | `a5407f0e.sa0` | same |
| TQWNet `1337:3/100` | `0000ff75.saf` | same |

`a54e0407.sa0`/`a5407f0e.sa0` are the exact filenames from Nick's original
report. TQWNet has no relation to Nick at all — its appearance confirms this
is **systemic**, affecting any uplink that happens to offer a file during a
`binkp_poll_sync`-driven poll, not something specific to FidoNet/AgoraNet.
**H2 (`650774702`) remains a real, independently locally-proven bug worth
having fixed, but it is confirmed not to be what caused this incident.**

### Runtime identities (read-only, confirmed live)

- `www-data`: `uid=33(www-data) gid=33(www-data) groups=33(www-data)`
- `binkterm-admin`: `uid=991(binkterm-admin) gid=991(binkterm-admin)
  groups=991(binkterm-admin),33(www-data)` — **already a secondary member of
  the `www-data` group**, confirmed via `/etc/group`:
  `www-data:x:33:binkterm-admin`

### Inbound tree, before

- `data/inbound`: `www-data:www-data`, `755` (`drwxr-xr-x`)
- `data/inbound/error`: `root:root`, `755` — wrong group entirely, same
  `root`-touched-it-once class of issue as `binkp_poll.log`/`packets.log`
- `data/inbound/unprocessed`: `www-data:www-data`, `755`
- `data/outbound`: `www-data:www-data`, `755` — **not touched**, out of
  scope for this transaction (inbound-spool only); flagged below as a
  possible sibling issue worth a future look, not confirmed

### Required writers (established by reading the actual call sites)

- `BinkpSession::handleFileCommand()`/`handleFileData()` — inbound file
  receipt for **both** answerer sessions (`binkp_server`, long-running,
  `www-data`) and originator/scheduled polls (`scripts/binkp_poll.php`,
  spawned per-invocation via `AdminDaemonServer::runCommand()`, which never
  changes UID — runs as `binkterm-admin`)
- `scripts/process_packets.php` — moves files into `inbound/error` and
  `inbound/unprocessed`; also spawned via `runCommand()` (the
  `process_packets` admin-daemon command), also `binkterm-admin`

So both `www-data` and `binkterm-admin` are **legitimate, necessary**
writers of all three directories — confirming Model B (shared `www-data`
group) fits exactly, using a relationship that **already existed live**
(`binkterm-admin` ∈ `www-data`), not one introduced here.

### Durable source, and a deeper drift discovered along the way

Investigating "what re-applies these permissions on restart/deploy" surfaced
that this specific deployment is running well behind the current repo:

- The current repo's `Dockerfile` creates a **`binkterm`** system user/group
  and `docker/entrypoint.sh` intends `chown -R binkterm:binkterm` +
  `chmod -R 775` across `data/`, `config/`, `dosbox-bridge/` on every
  container start, with the comment "binkterm owns the files; www-data (in
  binkterm group) gets write access via 775."
- **Neither exists on the live container**: `id binkterm` → *no such user*;
  `/etc/group` has no `binkterm` entry at all, only `binkterm-admin`.
- **`docker/entrypoint.sh` isn't even what runs on this container.**
  `docker inspect` shows the actual `Entrypoint`/`Cmd` is
  `["docker-php-entrypoint"]` / `["/usr/bin/supervisord", "-c",
  "/etc/supervisor/conf.d/supervisord.conf"]` — the stock PHP image
  entrypoint straight into `supervisord`, bypassing the repo's own
  `docker/entrypoint.sh` bootstrap entirely on this deployment.
- The live `/etc/supervisor/conf.d/supervisord.conf` has `user=binkterm-admin`
  on `[program:admin_daemon]` — a line that does **not** exist in the
  current repo's `docker/supervisord.conf` (which has no `user=` override
  for that program at all, so a fresh build would inherit `[supervisord]`'s
  own `user=root`, i.e. **run admin_daemon as root** — worse than what's
  live today).
- Conclusion: **this container's image predates both of these repo changes**
  and was never rebuilt to match. Reconciling that full drift (provisioning
  `binkterm`, deciding whether `admin_daemon` should move to it, rebuilding
  the image) is a **separate, larger effort, explicitly out of scope here**
  — flagged for Matt/ChatGPT, not attempted.
- Given that, and given `data/` is a **host bind-mount**
  (`/root/binktermphp/app` → `/var/www/html`, confirmed via `docker inspect`
  Mounts), permissions set directly on the live tree persist across a plain
  container restart/recreate on the *current* image regardless — nothing in
  this deployment's actual boot path re-provisions them. The risk window is
  specifically a **future rebuild** onto the current Dockerfile/entrypoint.sh
  (or onto a corrected one), which is exactly what the durable repo-level
  fix below targets.

### Selected permission model

- **Shared group: `www-data`** (Model B) — already established, live,
  requires no new user/group creation, no membership change.
- **Directory mode: `2775`** (`rwxrws r-x`) on `data/inbound`,
  `data/inbound/error`, `data/inbound/unprocessed`.
- **Setgid: yes**, on all three — without it, a file `binkterm-admin`
  creates would default to group `binkterm-admin` (its own primary group,
  not `www-data`), leaving it unreadable/unmovable by `www-data`-run
  processes afterward. Confirmed working in the write proof below.
- **Why least privilege**: only the two identities that must write
  (`www-data` as owner, `binkterm-admin` via group) gain write access;
  "other" stays `r-x` (no world-write, never `777`); no new privileged
  identity introduced; `binkterm-admin` gains nothing outside this specific
  tree — its existing, unrelated read-only access elsewhere is unchanged.

### Live fix (applied)

```
chgrp www-data data/inbound/error
chmod 2775 data/inbound data/inbound/error data/inbound/unprocessed
```

Result:
```
drwxrwsr-x 4 www-data www-data data/inbound
drwxrwsr-x 2 root     www-data data/inbound/error
drwxrwsr-x 2 www-data www-data data/inbound/unprocessed
```
(`error/`'s owner is left as `root` — harmless; only its group changed —
consistent with the least-invasive-change principle: adjust only what's
needed for the group-write model to work, not incidental ownership.)

`data/outbound` was deliberately **not** touched (out of scope, per
instruction — inbound-spool only).

### Durable fix (repo-level, `docker/entrypoint.sh`)

Two changes, both defensive/additive, no behavior change for a deployment
where `binkterm` already exists:

1. The `chown -R binkterm:binkterm ...` step is now guarded
   (`if id -u binkterm >/dev/null 2>&1; then ... else warn; fi`) so a
   deployment missing that user (like this one) no longer aborts the *entire
   rest of entrypoint.sh* under `set -e` — the `chmod -R 775` right after it,
   and everything later in the file (i18n sync, `ENABLE_*` daemon
   activation, cron generation), now still runs regardless.
2. A new explicit `chmod 2775` for `data/inbound`, `data/inbound/error`, and
   `data/inbound/unprocessed` specifically — adding the setgid bit these
   three need for the shared-write model to survive future file creation,
   independent of which user/group scheme (`binkterm`-based or
   `www-data`-based) actually ends up owning them on a given deployment.

This does **not** attempt to reconcile the deeper `binkterm` vs.
`binkterm-admin`/`www-data` drift described above — it makes the inbound
spool's specific requirement (group-writable, setgid) durable and resilient
regardless of how that larger question eventually gets resolved.

**Does not take effect on the currently-running container** (its actual
boot path bypasses this script entirely, per above) — it protects a
*future* rebuild, and is committed as source, not activated as a live
change (the live change is the direct `chgrp`/`chmod` above).

### Identity write proof (disposable test files, all removed)

| Identity | `inbound/` | `inbound/error/` | `inbound/unprocessed/` |
|---|---|---|---|
| `www-data` | CREATE/WRITE/DELETE: PASS | CREATE/DELETE: PASS | CREATE/DELETE: PASS |
| `binkterm-admin` | CREATE/WRITE/DELETE: PASS | CREATE/DELETE: PASS | CREATE/DELETE: PASS |

Inherited group confirmed correct: a file created by `binkterm-admin` in
`data/inbound` came out owned `binkterm-admin:www-data` — group `www-data`
via setgid, not `binkterm-admin`'s own primary group — exactly the intended
behavior. No test files left behind (`find ... -iname '.perm_test*'`
returned empty after cleanup).

### Activation

No process restart was needed or performed: `binkterm-admin`'s membership
in `www-data` already existed before this fix, so no running process needed
to acquire a new supplementary group — only the directory metadata changed,
which applies to the very next filesystem operation regardless of process
state. `supervisorctl status` confirmed 17/17 RUNNING, unchanged, throughout.

### Natural poll confirmation

**Observed, at the very next scheduled tick (`06:00:xx`), read-only, no
manual polling.** All three networks that had previously failed now
succeeded cleanly, symmetric confirmation across all of them:

| Network | File | Bytes received | Duration | Result |
|---|---|---|---|---|
| FidoNet `1:154/10` | `a54e0407.sa0` | 57572 (matches Nick's original report exactly) | **0.49s** | `success`, file received in full |
| AgoraNet `46:1/100` | `a5407f0e.sa0` | 2695 (matches Nick's original report exactly) | **1.18s** | `success`, file received in full |
| TQWNet `1337:3/100` | (unnamed in the trace) | 121289 | **1.95s** | `success`, file received in full |

Recovered protocol trace for FidoNet, verbatim:
```
[1:154/10] Handshake completed successfully
[1:154/10] Receiving file: a54e0407.sa0 (57572 bytes)
[1:154/10] File received: a54e0407.sa0 (57572 bytes)
[1:154/10] Session completed successfully
[1:154/10] SUCCESS
[1:154/10]   Files received: a54e0407.sa0
```
No `Failed to open file for writing`, no `Received file data but no active
file transfer`, no 300s hang — compare directly to the identical filename's
failure trace nine minutes earlier in §9. `binkp_session_log` (DB, read-only
query) confirms the same numbers: session id `18229` (FidoNet)
`0.490149s`/`57572` bytes/`success`; id `18230` (AgoraNet) `1.178281s`/`2695`
bytes/`success`; id `18233` (TQWNet) `1.949927s`/`121289` bytes/`success`.
`supervisorctl status` confirmed 17/17 RUNNING throughout, no restart
performed or needed for this confirmation.

### Related log ownership (`binkp_poll.log`, `packets.log`)

**Not fixed in this transaction**, per instruction — the durable mechanism
identified for those (§10: the host crontab's `docker compose exec` missing
`--user www-data`) is a different, host-level fix than the container-internal
group/setgid model used here, and does not "directly and safely apply" to
the same code path. Left as a separate follow-up, unblocked by the Part B
UDP fallback already committed (`c0a2d1bdf`), which ensures content isn't
silently lost from those two files even while their ownership stays wrong.

### Parked, unchanged by this fix

- 90s same-host lock timeout vs. 300s session: **PARKED** (superseded by §12
  below — the timeout itself is kept, but the bypass behind it is removed)
- Unexplained same-uplink retry: **root-caused in §12** (was "unexplained
  same-uplink retry", parked)
- Session-timeout-recorded-as-success misclassification: **PARKED**

## 12. Post-incident hardening S1 — duplicate/overlapping poll recon (read-only)

Separate, later slice: with the primary hang closed, a second-order symptom
remained — a second dial to the same uplink appearing shortly after a
scheduled poll, rejected by the remote as `Secure AKA ... busy`. Read-only
recon (no traffic, no changes) mapped every poll-initiation path in
`Scheduler`, `AdminDaemonClient`/`AdminDaemonServer`, and the host crontab,
and reconstructed the incident-night DB/log rows exactly.

**Host crontab ruled out.** `*/15 * * * * ... docker compose exec -T
binkterm-app php scripts/binkp_poll.php --all >> .../storage/logs/binkp_poll.log
2>&1` looked like the obvious suspect (mirrors the logrotate deployment
mismatch in §10) but was empirically proven dead: `storage/logs/` does not
exist on the host, and `sh -c 'echo x >> <missing dir>/f 2>&1'` was
reproduced to fail at the shell's own redirection setup — the command never
runs at all. Confirmed via `/var/log/syslog` showing cron dispatching the
line every 15 minutes for hours with no corresponding effect. This entry is
inert, not a contributor.

**Confirmed mechanism** (log-timing-consistent for both the incident night
and a later post-fix recurrence at `05:46:29`):

1. `Scheduler::processScheduledPolls()` calls `AdminDaemonClient::binkPollSync()`
   for a due uplink. `AdminDaemonServer` spawns a real `scripts/binkp_poll.php`
   child ("child A"), which dials, handshakes, and (pre-inbound-fix) hangs
   the full 300s.
2. `AdminDaemonClient::readResponse()`'s `fgets()` had no explicit
   `stream_set_timeout()`, so it fell back to PHP's `default_socket_timeout`
   ini (60s). `sendCommand()` retried up to 2 attempts on any
   `\RuntimeException` — including this ambiguous read failure — so after
   ~120s (two stacked ~60s timeouts) the scheduler logged `"Scheduled poll
   failed for <addr>: Admin daemon closed connection"`, exactly matching
   `binkp_scheduler.log`'s observed 04:15:08→04:17:09 (Fido) and
   04:17:09→04:19:09 (Agora) gaps.
3. The retry (attempt 2) opened a **second** RPC connection and re-sent the
   identical `binkp_poll_sync` command. `AdminDaemonServer` spawned a
   **second, independent** `binkp_poll.php` child ("child B") for the same
   uplink — with no awareness that child A was still alive.
4. Child B's `BinkpClient::connect()` called `acquireHostLock()`, found
   child A still holding the `flock()` on `binkp.pharcyde.org:24554`, waited
   its full 90s, then — under the pre-S2 behavior — **logged a warning and
   dialed anyway**. The remote, seeing our AKA already connected via child
   A, immediately rejected child B with `Secure AKA ... busy` — the fast
   (~0.02s) "failed" session rows (`18164`, `18170`) recovered from
   `binkp_session_log`.

Classified **STRONG LEAD** (mechanism fully explained and timing-consistent;
not confirmed via a controlled live repro, per the read-only constraint of
that slice). Full recon detail (initiation-path inventory, per-mechanism
classification, invariants) was delivered inline in that turn and is not
duplicated here.

## 13. Post-incident hardening S2 — eliminate the RPC blind-redial and host-lock bypass

Fixes exactly the two unsafe behaviors confirmed in §12. Does **not** add a
DB/in-flight poll registry (deliberately deferred — see below).

### Fix 1 — `AdminDaemonClient::sendCommand()` retry policy

- Added a `bool $retryable = true` parameter (default preserves prior
  behavior for every other admin RPC — smallest possible blast radius).
- `binkPollSync()` now calls `sendCommand('binkp_poll_sync', [...], false)`.
  When `$retryable` is `false`, exactly one attempt is made; any failure —
  including an ambiguous read timeout — is thrown immediately, never
  re-dispatched.
- Added a narrowly-scoped explicit read timeout for `binkp_poll_sync` only:
  `(int)Config::env('BINKP_TIMEOUT', 300) + 60` seconds via
  `stream_set_timeout()`, applied only when `$cmd === 'binkp_poll_sync'`.
  Derivation: `BinkpConfig::getBinkpTimeout()` (default 300s) bounds how
  long a single legitimate BinkP session's socket I/O can take before the
  session itself fails; +60s buffer covers pipe-draining and dispatch
  overhead. This lets a real, legitimately long poll report back normally
  instead of being cut off by PHP's 60s `default_socket_timeout` — but does
  **not** reintroduce retry: a timeout past this window is still a single,
  clean failure.
- `readResponse()` now distinguishes a genuine `stream_get_meta_data()`
  `timed_out` condition from a plain closed connection in its exception
  message, for clearer logs.

### Fix 2 — `BinkpClient::acquireHostLock()` / `connect()` strictness

- `acquireHostLock()`'s contract is now unconditional: it returns a lock
  handle on success, or `null` for **any** failure to acquire (contention
  timeout, or the lock file itself unavailable) — `null` never again means
  "safe to proceed without the lock." (The lock-file-unavailable branch was
  also tightened to fail closed, not just the 90s-timeout branch, to satisfy
  the invariant literally — this is a rare edge case with no observed
  incident-night role.)
- `connect()` now checks the lock result immediately: `if ($hostLock ===
  null) { throw new HostLockBusyException(...); }`, before any socket/network
  code runs.
- New `BinktermPHP\Binkp\Protocol\HostLockBusyException extends
  \RuntimeException` (`src/Binkp/Protocol/HostLockBusyException.php`) —
  deliberately a plain `\RuntimeException` subclass so it flows unchanged
  through every existing `catch (\Exception $e)` / `catch (Exception $e)`
  poll-failure handler already in place (`Scheduler::processScheduledPolls()`,
  `Scheduler::pollIfOutbound()`, `scripts/binkp_poll.php`'s top-level catch,
  etc.) — a controlled, expected "host busy" outcome, not a new crash class.
- The 90-second wait itself is unchanged in this slice (not "90 raised to
  300" — the wait bound wasn't the defect, the bypass after it was).
- Lock release (`releaseHostLock()`) is untouched; still covered by
  `connect()`'s existing `finally` block for normal completion, failed
  connection, and any exception — including the new `HostLockBusyException`
  case, which now throws *before* the lock is ever acquired in the failure
  branch, so there is nothing to release on that path (the lock was never
  obtained).

### Explicitly NOT done this slice

Per instruction: no DB/in-flight per-uplink registry. The two fixes above
remove the two demonstrated unsafe mechanisms; whether another initiator
(web "Poll Now", netmail-triggered poll, FREQ, manual CLI, hub push) can
still race a concurrent same-uplink/same-host poll is a **remaining
theoretical race**, now believed to be caught by the strict host lock
(same `hostname:port` → same flock, and a failed acquisition now always
defers/fails rather than dialing) but not proven end-to-end for those other
initiators. Recommendation: **observe** before building a registry.

### Local proof (no external traffic)

- `tests/Unit/AdminDaemonClientRetryPolicyTest.php` — a forked, local-only
  TCP fake server (127.0.0.1, no BinkP protocol) that authenticates then
  closes without responding (the exact "ambiguous failure" case):
  `binkPollSync()` (non-retryable) dispatches the command exactly once and
  fails fast; an unmodified default-retryable command (`processPackets()`)
  still dispatches twice, proving the fix is scoped and non-retryable
  commands are otherwise unaffected. 2 tests, both PASS.
- `tests/Unit/BinkpHostLockStrictnessTest.php` — reflects into the private
  `acquireHostLock()`/`releaseHostLock()` methods directly (two file handles
  from one process standing in for two contending processes, since `flock()`
  contention is per open-file-description, not per-process); a short
  test-supplied timeout (1s), never the production 90s default. Proves: a
  second contender cannot acquire while the first holds it; the wait is
  bounded (does not hang past its timeout); after release, a later attempt
  succeeds. 1 test, PASS. (A second, end-to-end `connect()` test was
  considered and deliberately dropped — `connect()`'s only call site uses
  the hardcoded 90s default with no per-call override, so exercising it live
  would require a genuine 90s wait; the wiring is instead verified by direct
  source inspection, documented in the test file.)
- Collateral: `AdminDaemonUdpLogFallbackTest.php`,
  `BinkpCramAuthLoggingTest.php`,
  `BinkpOriginatorReceiveWindowIncidentTest.php`,
  `BinkpServerSocketOwnershipTest.php` — all still green (19 tests total
  across this slice's new + collateral files, 77 assertions, 0 failures).

### Activation

**Not performed this slice** — per instruction, stopping for review before
any restart. `binkp_scheduler`, `binkp_server`, and `admin_daemon` are all
still running the pre-S2 code as of this writing; the fix is local-only
(diff + tests), not yet live.

### Files changed (S2)

- `src/Admin/AdminDaemonClient.php` — `sendCommand()` retryable flag +
  scoped `binkp_poll_sync` read timeout; `binkPollSync()` opts out of retry;
  `readResponse()` timeout-vs-close distinction.
- `src/Binkp/Protocol/BinkpClient.php` — `acquireHostLock()` fail-closed on
  every failure path; `connect()` throws `HostLockBusyException` on a null
  lock instead of proceeding.
- `src/Binkp/Protocol/HostLockBusyException.php` — new.
- `tests/Unit/AdminDaemonClientRetryPolicyTest.php` — new.
- `tests/Unit/BinkpHostLockStrictnessTest.php` — new.
- `docs/checkpoints/BinkP_FidoAgora_InboundHang_2026-09-13.md` — this section.

### Parked, unchanged by S2

- Session-timeout-recorded-as-success misclassification: **fixed in §14**
- `binkp_poll.log` / `packets.log` ownership: **PARKED**
- Duplicate logrotate mechanisms: **PARKED**
- Production entrypoint discrepancy: **PARKED**
- Per-uplink/global in-flight poll registry: **explicitly deferred**, see
  above — not a defect, a deliberate scope boundary for this slice.

## 14. Post-incident hardening S3 — timeout/abnormal session misclassified as success

Fixes the last of the four originally-parked secondary defects: a BinkP
session that authenticates, makes no further progress, and breaks out on the
hard EOB/inactivity timeout was recorded as `success` — the exact shape of
the incident-night sessions before the inbound-permission root cause was
found. Local-only; **not activated in production this slice.**

### Return-contract trace (`BinkpSession::processSession()`)

The "wait for session termination" loop (`while ($this->state <
self::STATE_TERMINATED)`) can end exactly four ways:

| Exit path | Sets `STATE_TERMINATED`? | Previous return | New return |
|---|---|---|---|
| Peer-close-first grace period completes (clean) | Yes | `true` | `true` (unchanged) |
| Peer closes after both sides exchanged M_EOB (clean) | Yes | `true` | `true` (unchanged) |
| Hard EOB/inactivity timeout expires (abnormal) | **No** | `true` (**BUG**) | `false` |
| Peer closes before EOB exchange completed (abnormal) | **No** | `true` (**BUG**) | `false` |
| Uncaught `\Exception` anywhere in the method | N/A (catch block) | `false` | `false` (unchanged) |

Grepping every `STATE_TERMINATED` assignment in the file confirmed only two
call sites ever set it (the two clean paths above) — every other loop exit
is a `break` that leaves `$this->state` below `STATE_TERMINATED`. The method
previously fell through to an unconditional `return true;` after the loop
regardless of which of the five paths was taken, gated only by which log
line it wrote (`'Session completed successfully'` vs. `"Session ended
(final state: {$this->state})"` at WARNING level) — the log already knew
the difference; the return value didn't.

### Fix

- **File**: `src/Binkp/Protocol/BinkpSession.php`, `processSession()`'s
  post-loop block.
- The final `return true;` is now conditional on
  `$this->state === self::STATE_TERMINATED`. When true: unchanged
  `'Session completed successfully'` log + `return true`. Otherwise: log
  changed to `"Session ended abnormally without reaching clean termination
  (final state: {$this->state})"` (WARNING, unchanged level) + `return
  false`. No other line in the method changed — the loop's own timeout
  values, thresholds, and the `max(30, ...)` floor are untouched, per
  instruction not to alter the configured timeout.
- **Caller messages** improved for clarity now that `false` covers this new
  case, not just a hard protocol/file error: `BinkpClient::connect()`'s
  `throw new \Exception('Session processing failed')` and
  `BinkpServer.php`'s `endSession('failed', 'Session processing failed')`
  both now read `'Session ended abnormally without reaching clean
  termination (e.g. a hard EOB/inactivity timeout or premature disconnect -
  see protocol log)'`. Both call sites already treated `false` as failure
  before this change — this only changes the message text, not the control
  flow.
- **Status recorded**: `failed` (not a new `timeout` value) — the schema
  comment on `binkp_session_log.status` and every existing reader
  (`SessionLogger`'s success/failed stats query, `templates/binkp.twig`'s
  `status === 'failed'` check, `Scheduler::processAdvertisingCampaigns()`'s
  unrelated `status === 'failed'`/`'success'` checks) only recognize
  `'active'`, `'success'`, `'failed'`, `'rejected'`. Per instruction not to
  invent a status value existing readers can't understand, the specific
  reason (timeout vs. premature disconnect) goes into the existing
  free-text `error_message` column via the improved caller messages above,
  not into `status` itself.

### Caller / retry-safety review

Both call sites of `processSession()` were checked for what `false`
triggers:

- `BinkpClient::connect()` (originator/outbound): throws `\Exception`,
  caught by its own `finally`/`catch (\Throwable $e)` block, which calls
  `endSession('failed', ...)` and re-throws. The re-thrown exception
  propagates to `scripts/binkp_poll.php`'s top-level `catch (Exception $e)`
  (prints error, `exit(1)`) or `Scheduler::processScheduledPolls()` /
  `pollIfOutbound()`'s `catch (\Exception $e)` (logs `"...poll failed for
  {$address}: ..."`, continues to the next uplink). **Neither performs any
  retry** — the uplink simply waits for its next normal scheduled tick,
  exactly like any other pre-existing poll failure. This is unrelated to
  and does not reopen the S2 RPC-blind-redial fix (that was about one
  `sendCommand()` call re-dispatching itself; this is a session's own
  outcome, one layer up, already handled by existing non-retrying failure
  paths on both call sites).
- `BinkpServer.php`'s answerer path (inbound, per-connection fork): calls
  `endSession('failed', ...)` and logs an ERROR. It never redials anything
  (it doesn't dial out at all — it's the inbound listener), so there is no
  retry surface here either.
- **Conclusion: no unsafe automatic retry introduced.** Not stopped/reported
  per Step 6's escape hatch — there was nothing to escalate.

### Local proof

- **Clean paths preserved**: re-ran the existing
  `tests/Unit/BinkpOriginatorReceiveWindowIncidentTest.php` (Cases A and B,
  both genuinely reach `STATE_TERMINATED` via the grace-period-close path)
  unmodified — both still assert and get `processSession() === true`. 3
  tests, 22 assertions, PASS — proves the fix does not touch the happy
  path.
- **Abnormal timeout, new test**:
  `tests/Unit/BinkpSessionAbnormalTimeoutTest.php` — a mock peer
  (`socket_create_pair(AF_UNIX, ...)` + `pcntl_fork()`, no real network)
  that authenticates nothing and stays completely silent (never sends a
  frame, never closes) for longer than the session's hard timeout. Proves:
  `processSession()` returns `false` (not `true`); the wait is genuinely
  bounded by the production `max(30, ...)` floor (~29-32s observed, not the
  original 300s); the timeout is still logged (unchanged diagnostic text);
  the new distinct "ended abnormally" log line fires instead of "completed
  successfully". 1 test, 7 assertions, PASS, ~31s wall time.
  - Note on timing: the hard EOB/inactivity timeout floor
    (`max(30, (int) $config->getBinkpTimeout())` in `processSession()`) is
    existing, unmodified production behavior — per instruction not to
    change the configured timeout value, this test's mock peer must stay
    silent for the full ~30s floor rather than a sub-second window. Still
    fully deterministic and bounded, and two orders of magnitude short of
    the original 300-second incident duration.
  - The exception-catch path (`catch (\Exception $e) { ...; return false;
    }`) is unchanged by this diff (confirmed by inspection/diff review) and
    was not re-tested.
- Collateral: `AdminDaemonClientRetryPolicyTest`,
  `BinkpHostLockStrictnessTest`, `AdminDaemonUdpLogFallbackTest`,
  `BinkpCramAuthLoggingTest`, `BinkpServerSocketOwnershipTest` — all still
  green (20 tests total across this slice's new + collateral files, 84
  assertions, 0 failures).

### Activation

**Not performed this slice** — per instruction, stopping for review before
any restart. All processes are still running pre-S3 code as of this
writing; the fix is local-only (diff + tests), not yet live.

At activation time (not done here), the process-responsibility check done
for S2 applies again: `Scheduler.php`/`binkp_scheduler.php` never
instantiate `BinkpSession` directly (only a comment references it) - every
outbound poll runs as a fresh `scripts/binkp_poll.php` process spawned by
the admin daemon, which loads current on-disk code every time regardless of
`binkp_scheduler`'s own restart state. `binkp_server.php`, however, IS
long-lived and directly instantiates `BinkpSession` in-process for every
inbound connection (`BinkpServer::handleConnectionSync()`), so it is the
one process that would need restarting to pick up this fix - to be verified
against the actual supervisor process list at activation time, not assumed
here.

### Files changed (S3)

- `src/Binkp/Protocol/BinkpSession.php` — `processSession()`'s post-loop
  return now conditional on `STATE_TERMINATED`.
- `src/Binkp/Protocol/BinkpClient.php` — clearer exception message for the
  `processSession() === false` case (message text only).
- `src/Binkp/Protocol/BinkpServer.php` — clearer `endSession()` error
  message for the same case (message text only).
- `tests/Unit/BinkpSessionAbnormalTimeoutTest.php` — new.
- `docs/checkpoints/BinkP_FidoAgora_InboundHang_2026-09-13.md` — this
  section.

### Parked, unchanged by S3

- `binkp_poll.log` / `packets.log` ownership: **fixed in §15**
- Duplicate logrotate mechanisms: **fixed in §15**
- Production entrypoint discrepancy: **PARKED**
- Per-uplink/global in-flight poll registry: **PARKED**
- S2's long-poll natural confirmation (no naturally-occurring long poll was
  observed in that slice's window): **PARKED**

## 15. Post-incident hardening S4 — log ownership + duplicate logrotate paths

Fixes the last two originally-parked secondary defects together: `data/logs/
binkp_poll.log` and `data/logs/packets.log` were `root:root` while their
actual runtime writers are non-root, and two independent, uncoordinated
logrotate mechanisms existed. **Live infrastructure change (filesystem +
host crontab), no application code changed, nothing committed/pushed** (this
slice is filesystem/host-config only — see Files Changed below).

### Inventory (read-only, these 5 logs only)

| Log | Owner:Group (before) | Mode | Actual writer(s) |
|---|---|---|---|
| `admin_daemon.log` | `binkterm-admin:binkterm-admin` | 644 | `admin_daemon.php` (supervisor `user=binkterm-admin`) — **already correct** |
| `binkp_scheduler.log` | `www-data:www-data` | 644 | `binkp_scheduler.php` (supervisor `user=www-data`) — **already correct** |
| `binkp_server.log` | `www-data:www-data` | 644 | `binkp_server.php` (supervisor `user=www-data`) — **already correct** |
| `binkp_poll.log` | `root:root` | 644 | `scripts/binkp_poll.php`, always spawned as a child of `admin_daemon.php` via `proc_open()` (inherits the parent's uid) → **binkterm-admin**. Also written by manual/CLI-only tools (`freq_pickup.php`, `admin_client.php`, not scheduled anywhere) — occasional, whatever the invoking shell user is, not a design constraint. **MISMATCH** |
| `packets.log` | `root:root` | 644 | `BinkdProcessor` (`src/BinkdProcessor.php`), which is used from multiple contexts: `scripts/process_packets.php` (spawned by `admin_daemon.php`, same proc_open-inherits model → **binkterm-admin**), `BinkpSession.php` used in-process by both `BinkpClient` (originator, spawned as binkterm-admin) AND `BinkpServer`'s answerer path (supervisor `user=www-data`, long-lived, in-process), and web-request paths (`MessageHandler.php`, under php-fpm workers configured `user = www-data`). **Genuinely multi-writer: www-data AND binkterm-admin, both regularly. MISMATCH** |

`data/logs/` itself: `root:www-data`, mode `775` (no setgid) before this fix.
`binkterm-admin`'s groups are `binkterm-admin,www-data` (confirmed via `id`)
— it has been a `www-data` group member since the earlier `data/inbound` fix
investigation, unchanged since.

Many *other* logs in the same directory (`mrc_daemon.log`, `doot_app.log`,
`openglad_relay.log`, `telnetd.log`, `sshd.log`, etc.) are also `root:root`
despite some of their owning processes' supervisor entries declaring
`user=www-data` — this is a wider, pre-existing pattern in the directory.
**Out of scope per instruction** ("Do not inventory unrelated app logs") —
noted here only so it isn't mistaken for something this fix addresses.
`telnet`/`ssh_daemon` have no supervisor `user=` line at all and do
genuinely run as root, which is why their own logs being `root:root` is
correct, not a defect.

### Both logrotate paths, traced independently (not assumed from the earlier broken one)

**Project-native**: `/etc/cron.d/binkterm` (static, `COPY`'d into the image
per its own header comment) — `0 0 * * 0 www-data cd /var/www/html && php
scripts/logrotate.php --keep=52 >> data/logs/logrotate.log 2>&1`. Weekly,
runs as `www-data` (confirmed: file is genuinely static/present in the live
container, not templated). This is the intended, documented mechanism.

**Host root crontab**: `7 * * * * cd /root/binktermphp && docker compose
exec -T binkterm-app php scripts/logrotate.php --keep=5 --max-size=10M >>
/root/binktermphp/app/data/logs/logrotate.log 2>&1`. Hourly. **Verified
independently, not assumed dead like the earlier broken `binkp_poll.php
--all` line** (same crontab, different entry — that one *is* still present
and still genuinely broken/dead, see below): its redirect target
(`data/logs/logrotate.log`) exists and is writable, so a live manual
`docker compose exec -T binkterm-app php scripts/logrotate.php --dry-run
--keep=5 --max-size=10M` was run to confirm real behavior — **exit 0, ran
cleanly**. The log file's own content showed one real successful rotation
(`Rotated binkp_scheduler.log -> binkp_scheduler.log.0.gz`, Sep 12 03:07)
immediately followed by a run of `service "binkterm-app" refers to
undefined secret galactic_bloodshed_provisiond_token: invalid compose
project` failures — a **transient, unrelated** breakage in
`/root/binktermphp/docker-compose.yml` (edited Sep 12 03:17, ten minutes
after that last success) from an entirely different subsystem's secret
reference, since resolved (confirmed via `docker compose config`, exit 0,
no errors, at the time of this investigation) — not a BinkTerm defect and
not touched here. **Conclusion: this entry is a real, live, currently-
functional duplicate rotation mechanism**, running hourly as root (`docker
compose exec` defaults to the image's `Config.User`, which is empty/root
for this image — confirmed via `docker inspect` and a direct `id` check)
with a different policy (`--keep=5 --max-size=10M`) than the project-native
one (`--keep=52`, weekly).

For completeness (not re-litigated, already established in S1): the
*other* host cron line touching BinkP,
`*/15 * * * * ... binkp_poll.php --all >> .../app/storage/logs/binkp_poll.log
2>&1`, remains genuinely dead (`storage/logs/` still does not exist) and is
unrelated to logrotate — left alone, not this slice's concern either.

### Root cause

`scripts/logrotate.php`'s rotation is copy-then-truncate-**in-place**
(`copy($logPath, $rotated)` then `fopen($logPath, 'c+'); ftruncate($fh, 0)`)
— it never deletes/recreates the log file, so **rotation itself cannot
explain root ownership**, confirmed by direct inspection and by the
isolated rotation proof below (a rotation run *as root* against
correctly-owned test files left their ownership/mode completely
unchanged). The exact original moment `binkp_poll.log`/`packets.log` were
first created as `root:root` could not be forensically pinned down from
available evidence (predates the logs retained on this host) — most likely
a stray manual root-context invocation (e.g. an operator running
`docker compose exec` without `-u` to test `binkp_poll.php`/
`process_packets.php` directly) at some point in the past. Once created
root-owned, nothing since has corrected it, and every subsequent legitimate
non-root write attempt has been failing (pre-Part-B: silently; post-Part-B:
falling back to `admin_daemon.log`) ever since — this is exactly the
`binkp_poll.log`-targeted "UDP logger failed to persist message" warning
flood observed continuously since Part B's activation.

Separately, and regardless of whether it caused the *current* mismatch, the
duplicate hourly root-run logrotate entry is a real, independent policy
violation of "one rotation mechanism, running as the correct identity" and
is removed per Step 3's instruction on its own merits — including as a
latent risk (any future change to `logrotate.php` that stopped preserving
ownership on rotation would immediately reintroduce this exact defect if
run as root).

### Single source of truth

**Selected: the project-native `/etc/cron.d/binkterm` weekly, `www-data`-run
rotation.** It is current, working, documented, and runs under a correct
runtime identity already present on every sibling BinkP log. The duplicate
host root crontab entry was removed.

### Per-log ownership model (durable)

| Log | Owner | Group | Mode | Rationale |
|---|---|---|---|---|
| `admin_daemon.log` | `binkterm-admin` | `binkterm-admin` | 644 | unchanged, single writer |
| `binkp_scheduler.log` | `www-data` | `www-data` | 644 | unchanged, single writer |
| `binkp_server.log` | `www-data` | `www-data` | 644 | unchanged, single writer |
| `binkp_poll.log` | `binkterm-admin` | `binkterm-admin` | 644 | single regular writer (binkterm-admin); no group-write needed |
| `packets.log` | `www-data` | `www-data` | **664** | genuinely multi-writer (www-data AND binkterm-admin); group-write lets binkterm-admin append via its existing `www-data` group membership — no new group created, no chmod 777, no broad recursive chown |

### Live fix

```
chown binkterm-admin:binkterm-admin data/logs/binkp_poll.log
chown www-data:www-data             data/logs/packets.log
chmod 0664                          data/logs/packets.log
```

### Durable fix

```
chmod 2775 data/logs
```

Setgid on the directory means any *future* file created inside (by any
user) inherits group `www-data` automatically rather than the creating
process's own primary group — reduces (does not eliminate; mode bits are
still whatever the creating process's umask produces) the chance of a
future re-creation event reproducing this exact class of mismatch. Existing
files' modes were not touched by this step; only the two identified
mismatches were individually corrected above.

Plus removing the duplicate host root crontab logrotate entry (see below) so
only the one, correctly-scoped, `www-data`-run mechanism ever rotates these
files going forward.

`docker/entrypoint.sh` was deliberately **not** touched this slice — the
production entrypoint discrepancy (this script is not what actually starts
the live container; see §11) is explicitly parked and out of scope here per
instruction not to broaden into deployment-entrypoint redesign. The setgid
directory fix and per-file ownership are live filesystem state on the host
bind mount, which — like the `data/inbound` fix in §11 — persists across
container restarts on its own, independent of which entrypoint script (if
any) the container actually runs.

### Host crontab change

Backed up before editing: `/root/L33TEST_host_crontab_backup_pre-S4_<timestamp>.txt`
(full prior crontab, host-local, outside the repo). Removed exactly one
line via `crontab -l | grep -v "scripts/logrotate.php --keep=5 --max-size=10M" | crontab -`;
diffed old vs. new to confirm only that one line changed. Remaining host
crontab entries (`backup_forum.sh`, `rss_poster.php`, the already-dead
`binkp_poll.php --all` line, `database_maintenance.php`) are untouched.

### Write proof (actual runtime identities, disposable single-line markers)

Ran via `docker exec -u <identity> binkterm-app sh -c '...'` directly
against the real production log files (not temp siblings, since a single
clearly-tagged marker line is safe and explicitly permitted by instruction
when "clearly identified" — chose this over a temp-file proxy so the proof
covers the exact files being fixed, not a stand-in). Each line is prefixed
`[PERM-TEST ...] - safe to ignore` and left in place (removing it would mean
truncating/rewriting a production log, explicitly disallowed):

| Identity → target | Result |
|---|---|
| `binkterm-admin` → `binkp_poll.log` | **PASS** |
| `www-data` → `packets.log` | **PASS** |
| `binkterm-admin` → `packets.log` (group-write path) | **PASS** |
| `www-data` → `binkp_scheduler.log` (sibling, confirm unaffected) | **PASS** |
| `www-data` → `binkp_server.log` (sibling, confirm unaffected) | **PASS** |

### Rotation proof (isolated, safe — no incident evidence touched)

Real `scripts/logrotate.php`, invoked with `--logs-dir=/tmp/logrotate_proof_test`
(an isolated temp directory, never the real `data/logs/`) containing two
disposable dummy files built with the exact corrected ownership models
(`binkterm-admin:binkterm-admin 644` and `www-data:www-data 664`), each
padded past a deliberately tiny `--max-size=1000` threshold to force a real
rotation (not a dry run). Run as root (the container's default exec user,
same as the duplicate host cron would have used) to specifically prove
rotation-as-root does not corrupt ownership:

- Rotation completed (`Rotated binkp_poll.log -> binkp_poll.log.0.gz`,
  `Rotated packets.log -> packets.log.0.gz`).
- Recreated (truncated-in-place) `binkp_poll.log`/`packets.log` kept their
  exact pre-rotation owner/group/mode: `binkterm-admin:binkterm-admin 644`
  and `www-data:www-data 664`, unchanged.
- Both intended writers (`binkterm-admin`, `www-data`) appended
  successfully immediately after rotation, including the group-write path
  (`binkterm-admin` → `packets.log`).
- Temp directory removed after the test; nothing left behind.

### Warning-flood check

Observed read-only, no manual traffic, at the next natural scheduled tick
(`07:15:41`-`07:15:50` UTC, all 7 uplinks including FidoNet `1:154/10` and
AgoraNet `46:1/100`). **Cleared.** Zero `"UDP logger failed to persist
message"` warnings for the entire tick, versus 3 per uplink (21 total)
before this fix at every prior tick since Part B's activation. Confirmed by
reading `binkp_poll.log` directly afterward: real, current content (down to
TQWNet's actual file receipt, `0000ff75.su2`, 3969 bytes) is landing
directly in the file, still correctly owned `binkterm-admin:binkterm-admin
644`.

### Activation

No service restart performed or required — this slice changed only host
filesystem ownership/mode and the host crontab; no PHP application code was
modified, so no daemon holds stale bytecode. `supervisorctl status`
confirmed 17/17 RUNNING throughout, unchanged.

### Files changed (S4)

No repository files changed. Live infrastructure only:

- `data/logs/binkp_poll.log` — chown `binkterm-admin:binkterm-admin`
- `data/logs/packets.log` — chown `www-data:www-data`, chmod `0664`
- `data/logs/` — chmod `2775` (setgid added)
- Host crontab (`crontab -e` equivalent, non-interactive) — removed the
  duplicate hourly root-run `scripts/logrotate.php --keep=5 --max-size=10M`
  line; backup at `/root/L33TEST_host_crontab_backup_pre-S4_<timestamp>.txt`
- `docs/checkpoints/BinkP_FidoAgora_InboundHang_2026-09-13.md` — this
  section (the only actual repo file touched)

### Parked, unchanged by S4

- Production entrypoint discrepancy: **PARKED** (deliberately not
  broadened into here, see "Durable fix" above)
- Per-uplink/global in-flight poll registry: **PARKED**
- S2's long-poll natural confirmation: **PARKED**
- Possible future test-timeout injection cleanup: **PARKED**

## 16. Campaign closeout

The incident is **CLOSED**. This section is a short, accurate summary for
anyone who does not want to read sections 1-15 in full; it distinguishes
the primary production root cause from the real-but-secondary defects found
along the way, and does not inflate any of them beyond their evidence.

**Primary production root cause** (what actually caused Nick's report):
`data/inbound/` was `www-data:www-data 0755`, but scheduled/admin-driven
polls run as `binkterm-admin`, which could not create files there. Every
inbound file offered during such a poll silently failed `fopen()` and its
data was discarded frame-by-frame for the full configured session timeout
(300s). Evidence: the exact FidoNet file Nick reported (`a54e0407.sa0`,
57,572 bytes) and the exact AgoraNet file (`a5407f0e.sa0`, 2,695 bytes)
both failed this way; TQWNet was independently found affected by the same
defect, proving it was systemic to the spool, not specific to either
network. Fixed with a shared-`www-data`-group, setgid inbound-spool model
(`cae172010`); all three networks subsequently confirmed transferring
successfully (Fido 0.49s, Agora 1.18s, TQWNet 1.95s/1.95s).

**A separate, real defect found during investigation** (not the primary
cause): `BinkpFrame::parseFromSocket($socket, true)` could block for the
full stream timeout on a fragmented frame despite being the "non-blocking"
code path. Genuinely fixed (`650774702`) with regression tests, but this
was not what produced Nick's report — the inbound-permission defect above
was.

**Observability defect**: `binkp_poll.log`/`packets.log` being
root-owned (see S4 below) meant direct file logging failed for the actual
non-root writers; the existing UDP-fallback-to-admin-daemon path was
silently swallowing that secondary failure too, so evidence of the primary
hang was being lost twice over. Fixed (`c0a2d1bdf`) so a failed write is
always surfaced into `admin_daemon.log` rather than silently discarded —
this is what let the primary root cause (§9) actually be found.

**Duplicate-poll defect**: `AdminDaemonClient::sendCommand()` blindly
retried the side-effecting, non-idempotent `binkp_poll_sync` command after
an ambiguous RPC read timeout, causing a second, independent poll child to
be spawned for the same uplink while the first was still alive. Fixed
(`c4d30f7f9`): `binkp_poll_sync` is no longer retried after dispatch, and
gets its own `BINKP_TIMEOUT + 60`s response timeout so a legitimately long
poll can still report back normally.

**Host-lock defect**: `BinkpClient::acquireHostLock()` waited 90s for the
per-hostname:port lock and then, on timeout, dialed anyway without holding
it — allowing exactly the same-host concurrent-session collision the lock
existed to prevent. Fixed (`c4d30f7f9`, same commit): failure to acquire
the lock now throws `HostLockBusyException` before any socket/network code
runs; dialing without the lock is no longer possible.

**Status-misclassification defect**: `BinkpSession::processSession()`
returned `true` (success) even when the session exited via the hard
EOB/inactivity timeout or a premature disconnect — only an uncaught
exception produced `false`. Fixed (`588e79454`): the return value is now
conditional on genuinely reaching `STATE_TERMINATED`; timeout and premature
disconnect both correctly report failure.

**Log ownership / rotation** (this transaction, S4, filesystem/host-config
only, no application code): `binkp_poll.log`/`packets.log` were
`root:root` while their real writers were `binkterm-admin` (both) and also
`www-data` (`packets.log` only); fixed via targeted `chown`/`chmod` to
match actual writers, plus `data/logs/` given the setgid bit for future
creations. A duplicate, hourly, root-run `scripts/logrotate.php` host cron
entry (independently confirmed live/active, not assumed dead) was removed,
leaving the project-native weekly `www-data`-run `/etc/cron.d/binkterm`
rotation as the sole authority. Confirmed via live write proof (both
identities), an isolated rotation proof (ownership survives rotation even
when run as root), and a full natural poll tick showing the
"UDP logger failed to persist message" warning flood completely cleared.

### Full incident commit ledger (branch `experience-lobby-v2`)

| Commit | Subject |
|---|---|
| `650774702` | Fix nonblocking BinkP fragmented-frame reads |
| `c0a2d1bdf` | Preserve failed BinkP poll logs |
| `cae172010` | Make BinkP inbound-spool group-writable for scheduled polls |
| `c4d30f7f9` | Prevent duplicate BinkP poll retries |
| `588e79454` | Mark abnormal BinkP sessions as failed |
| *(this doc's commit)* | Close BinkP incident log ownership cleanup |

### Parked at closeout (not blockers, not started)

- **Global/per-uplink in-flight poll registry**: theoretical additional
  protection; not justified yet since S2's strict host lock + no-blind-retry
  already removed the one demonstrated duplicate-dial mechanism.
  Disposition: **OBSERVE**, reopen only on evidence of a remaining
  concurrent-poll collision.
- **S2 long-poll natural confirmation**: local deterministic proof exists;
  no naturally-occurring long session arose during any bounded observation
  window across S2-S4. Disposition: optional future AFK observation, not a
  blocker.
- **Production entrypoint discrepancy**: `docker/entrypoint.sh` is not what
  actually starts the live container (`docker-php-entrypoint` → `supervisord`
  directly); the `binkterm` user/group it assumes does not exist on this
  image. Disposition: **PARKED for separate deployment recon**, not touched
  in this campaign.
- **`BinkpSessionAbnormalTimeoutTest` ~31s runtime**: an artifact of
  `processSession()`'s existing, unmodified `max(30, ...)` timeout floor.
  Disposition: **LOW PRIORITY**, not refactored.
- **Dead/broken host BinkP cron fossil**: `*/15 * * * * ... binkp_poll.php
  --all >> .../app/storage/logs/binkp_poll.log 2>&1` — proven (S1) to never
  actually execute (`storage/logs/` does not exist, so the shell redirect
  fails before `docker compose exec` runs) and proven (S1) not responsible
  for the duplicate-poll incident. Still present in the host crontab as of
  this closeout — S4 removed only the unrelated duplicate *logrotate* line,
  not this one. Disposition: **PARKED deployment fossil**, left untouched.

**Campaign status: CLOSED.** No further BinkP work is planned as a
continuation of this incident; any future BinkP change is a new, separately
scoped task.
