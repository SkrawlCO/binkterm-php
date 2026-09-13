# SysOp Chat — M1A Checkpoint

> This document, and SysOp Chat generally, is a work-in-progress feature.
> This checkpoint was authored by an AI assistant as part of an implementation
> pass; it has not yet been reviewed for accuracy beyond passing its own test
> suite.

M1A is the authoritative backend lifecycle and realtime event contract for
"Page SysOp." It has **no UI** — no Web admin surface, no terminal menu item,
no chat message transport. Those are later slices (M1B/M1C/M1D). Nothing here
is wired into any route, menu, or daemon; the class exists and is tested, but
nothing currently calls it.

## Data model

Table: `sysop_pages` (migration `v20260913074753_add_sysop_pages.sql`).

| Column | Notes |
|---|---|
| `id` | PK |
| `caller_user_id` | FK `users(id)`, cascade delete |
| `caller_session_id` | nullable — see "Session model" below |
| `surface` | `telnet` / `ssh` / `web_terminal` / `web_ui` |
| `status` | `waiting` / `accepted` / `declined` / `expired` / `completed` / `cancelled` |
| `accepted_by_user_id` | nullable FK `users(id)`, set on accept |
| `created_at`, `updated_at` | bookkeeping |
| `accepted_at`, `completed_at` | nullable, set on the matching transition |
| `expires_at` | set at creation from `SYSOP_CHAT_PAGE_TTL_SECONDS` (default 300s) |

Two partial-unique indexes enforce the M1 concurrency invariants **at the data
layer**, not in PHP:

- `idx_sysop_pages_one_waiting_per_caller` — at most one `waiting` row per
  `caller_user_id`.
- `idx_sysop_pages_one_accepted_globally` — at most one `accepted` row across
  the whole table (the M1 "one active SysOp chat for Matt at a time" rule).

Historical `declined`/`expired`/`cancelled`/`completed` rows are left in place
(no archival job) — this is not a permanent chat-history product, but there
was no reason to add a purge job M1A doesn't need yet.

## Lifecycle

```
waiting --cancel (caller)--------> cancelled
waiting --decline (admin)--------> declined
waiting --expire (TTL passed)-----> expired
waiting --accept (admin)---------> accepted --complete (either party)--> completed
                                     \
                                      -- accept succeeds but caller's own
                                         session is gone --> expired (not a
                                         silent no-op "chat")
```

`SysopChatService` (`src/SysopChatService.php`) owns every transition; no
route or terminal handler should write `sysop_pages` directly.

## Session model

- **Telnet / SSH / Web terminal** callers page from a live `BbsSession` bound
  to one `user_sessions.session_id` — `caller_session_id` is populated, and
  `acceptPage()` checks that session is still live (not expired, still
  present) before letting the page become `accepted`. If it's gone, the page
  is rolled forward to `expired` instead of becoming a phantom active chat —
  Matt sees a clean "gone" rather than talking to nobody.
- **Ordinary Web UI** callers have no terminal-session concept at all.
  `caller_session_id` is `null` for these pages by design, not as a
  placeholder to fill in later, and `acceptPage()` skips the liveness check
  entirely for them (identity alone is authoritative).

## SysOp targeting

No admin user id is hardcoded anywhere in this slice. `sysop_chat.request`
(and `cancelled`) are emitted with `user_id = null, admin_only = true` via
`BinkStream::emit()` — the exact convention `DashboardCardRegistry`'s
`admin_only` cards and the existing admin-only realtime audience already use.
Any session where `$user['is_admin']` is true receives it; M1B decides how to
render that (a Web admin indicator).

## Realtime event contract (BinkStream, `sse_events`)

| Event | Target | Payload |
|---|---|---|
| `sysop_chat.request` | admin-only broadcast | `page_id`, `caller_user_id`, `surface` |
| `sysop_chat.accepted` | `caller_user_id` | `page_id`, `surface` |
| `sysop_chat.declined` | `caller_user_id` | `page_id` |
| `sysop_chat.cancelled` | admin-only broadcast | `page_id`, `caller_user_id` |
| `sysop_chat.expired` | `caller_user_id` | `page_id` |
| `sysop_chat.completed` | whichever party did *not* end it | `page_id` |

Every event is emitted only after the row was actually mutated — a refused or
no-op transition (already gone, wrong owner, etc.) emits nothing. This mirrors
`ActiveSessionService::revokeSession()`'s emit-after-mutate discipline exactly.

`sysop_chat.message` is **not** implemented in M1A (explicitly out of scope —
see "Message transport" below).

## Concurrency

Proven by `tests/Unit/SysopChatServiceTest.php` (27 tests):

- a caller cannot hold two waiting pages at once (unique index; `createPage()`
  returns `null`, no row created)
- two admins cannot both accept the same page (second `acceptPage()` call
  sees the row is no longer `waiting`, returns `null`)
- an expired, cancelled, or declined page cannot be accepted
- a page cannot be completed before it is accepted
- the singleton rule: once one page is `accepted`, a second page cannot also
  become `accepted` even though it may remain `waiting` (unique index +
  `runGuardedAgainstUniqueViolation()`)

A caught unique-violation must not poison the surrounding transaction (a real
risk once callers run inside a request-scoped transaction, and the actual
failure mode hit while writing the tests below), so both `createPage()`'s
INSERT and `acceptPage()`'s UPDATE run through
`runGuardedAgainstUniqueViolation()`, which uses a `SAVEPOINT` when already
inside an open transaction and its own `beginTransaction()`/`commit()`
otherwise.

## Message transport recon (for M1B/M1C)

S0 assumed `ChatMessageService`'s direct-message capability (`sendMessage()`
with a `toUserId`) could carry SysOp Chat's private messages. Checked in this
slice:

- **Durable?** Yes — `chat_messages` rows are permanent; there is no
  expiry/purge path, and `sendMessage()` also drives `ActivityTracker` and
  enqueues Matterbridge/PacketBBS cross-network fan-out as a side effect.
- **Suitable for ephemeral SysOp Chat?** No. Both the permanence and the
  automatic cross-network fan-out are wrong for a private, ephemeral,
  BBS-local conversation — using it as-is would make transcripts durable by
  default and could leak a page's existence onto Matterbridge/PacketBBS.
- **Recommendation:** M1B/M1C should use a small, dedicated, short-lived
  message mechanism scoped to one `sysop_pages` row (e.g. a bounded
  `sysop_chat_messages` table purged shortly after the page reaches a
  terminal status, or a purely realtime-event-carried buffer with no
  durable table at all) rather than reusing `ChatMessageService`. This is
  recon only — no such mechanism is built in M1A.

## Security boundaries

- `cancelPage()` only ever matches `caller_user_id = ?` in its own `UPDATE`
  — a caller cannot cancel another caller's page (tested).
- `acceptPage()` / `declinePage()` / `completePage()` all require an explicit
  acting-user-id parameter; there is no "act as system" default a route could
  fall into by omission.
- `completePage()` additionally requires the acting user to be either the
  page's `caller_user_id` or its `accepted_by_user_id` (tested) — an unrelated
  third user cannot end someone else's chat.
- Every targeted event is scoped by `user_id` through the same `sse_events`
  targeting `ActiveSessionService`/`session.kick` already relies on; nothing
  new was added to that trust boundary.
- Route-layer admin authorization (is this caller actually an admin) is
  **not** this service's job and is deliberately left to M1B's route layer,
  matching how the rest of the codebase separates authorization from service
  logic.

## Tests

`tests/Unit/SysopChatServiceTest.php` — 27 tests, DB-backed against the
isolated `binktermphp_test` database (skips cleanly if unavailable), covering
create/duplicate-block, cancel (own-only, idempotent), accept (success,
already-accepted, expired, cancelled/declined, singleton, dead-session →
expired, web_ui skip-check), decline, opportunistic expiry sweep, complete
(both directions, waiting-cannot-complete, unrelated-user-blocked), and the
two query methods. Run in isolation: `27/27 passing`. Run as part of the full
`tests/Unit` suite, an unrelated pre-existing issue — 139 tests across many
suites, including these, see `PDOException: There is already an active
transaction`, traced to an earlier failing test in the run leaving
`TestDatabase`'s shared static PDO connection mid-transaction; this predates
M1A and is not caused by it.

## M1B / M1C consumption points

- **M1B (Web admin):** subscribe to the existing admin-only realtime channel
  for `sysop_chat.request`/`cancelled`; call `getWaitingPages()` to render the
  list; call `acceptPage()`/`declinePage()` from CSRF-protected admin routes;
  call `completePage()` to end a chat.
- **M1C (terminal):** a new `TerminalEventHandlerInterface` implementation
  (parallel to `SessionKickHandler`) reacting to `sysop_chat.accepted` /
  `sysop_chat.declined` / `sysop_chat.expired` / `sysop_chat.completed`,
  polled the same way `session.kick` already is inside
  `BbsSession::pumpRealtimeAndCheckSession()`; `createPage()`/`cancelPage()`
  called from a new Page-SysOp menu action on BBS-owned surfaces only.

## Open questions carried to M1B/M1C

1. Dispatching a second terminal event concern alongside `session.kick` inside
   `pumpRealtimeAndCheckSession()` — one poll with a fan-out dispatcher, or a
   second independent poll — is an M1C design choice, not resolved here.
2. The dedicated short-lived message mechanism recommended above (bounded
   table vs. event-only) is unpicked — M1B/M1C should decide before building
   chat message transport.
3. `SYSOP_CHAT_PAGE_TTL_SECONDS` default (300s) is a placeholder human-scale
   guess, not a tuned UX decision — fine to change later without any schema
   impact.
