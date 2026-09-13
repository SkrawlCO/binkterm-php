# SysOp Chat — M1B Checkpoint

> This document, and SysOp Chat generally, is a work-in-progress feature.
> This checkpoint was authored by an AI assistant as part of an implementation
> pass; it has not yet been reviewed for accuracy beyond passing its own test
> suite.

**Status (2026-09-13): code committed/pushed (`ec44efc88`), `sysop_chat_messages`
migration APPLIED TO PRODUCTION** via the standard runner, 08:39:13 UTC — the
only pending migration at the time (verified before running). No pending
migrations remain. No service restart performed or required (all 17
supervisor programs stayed up, uptimes unchanged throughout — nothing this
slice touches runs in a long-lived daemon; routes/templates/services load
per-request). Bounded read-only production proof, no real page created:
unauthenticated `GET /admin/sysop-chat` and `GET /api/admin/sysop-chat/state`
both `401`; `SysopChatService::getWaitingPages()` → `[]`,
`getActiveChat()` → `null`; `DashboardStatsService::getStats()`'s
`pending_sysop_pages` → `0` for a real admin user id; the admin template
renders end-to-end with zero exceptions; the ordinary homepage is unaffected;
no new entries in `server.log`. The Web admin side is live and empty — the
first real `sysop_pages` row will come from a genuine caller page in M1C.

M1B builds the **admin side** of "Page SysOp" plus the minimal private
message transport it needs: a waiting-page inbox, accept/decline, a private
chat panel, and the ephemeral message backend behind it. There is still
**no caller-facing UI** — no Web "Page SysOp" button, no terminal menu item.
A caller can only reach `sysop_pages` today by whatever bounded test path
proves the service layer; nothing in this slice lets an ordinary caller
create a real page. That is M1C.

## Message model

Table: `sysop_chat_messages` (migration `v20260913081637_add_sysop_chat_messages.sql`).

| Column | Notes |
|---|---|
| `id` | PK |
| `page_id` | FK `sysop_pages(id)`, cascade delete |
| `sender_user_id` | FK `users(id)`, cascade delete |
| `body` | `VARCHAR(2000)`, `CHECK (char_length(body) > 0)` |
| `created_at` | bookkeeping; ordering key alongside `id` |

One index, `(page_id, id)`, is the only query shape this table serves:
"this page's messages, in order."

## Retention / purge

Ephemeral by design (see `docs/SysopChat/M1A.md` "Message transport recon" —
`ChatMessageService` was rejected for exactly this reason). Messages are not
purged on every gap in activity — a brief caller disconnect/reconnect during
a still-`accepted` chat naturally leaves them intact — but
`SysopChatService::completePage()` purges every message for a page the
instant it transitions to `completed`, via
`SysopChatMessageService::purgeForPage()`. There is no other purge path and
no long-term history: once a chat ends, its transcript is gone.

## Message service

`SysopChatMessageService` (`src/SysopChatMessageService.php`) owns
`sysop_chat_messages`; nothing else writes to it.

- **`sendMessage(pageId, senderUserId, body)`** — the page must be
  `ACCEPTED` and `senderUserId` must be exactly its caller or its accepting
  admin (re-checked fresh against `sysop_pages` every call — `page_id` alone
  never authorizes access). Body is trimmed, 1-2000 chars. Emits
  `sysop_chat.message` targeted at the *other* participant, body included in
  the payload (safe because delivery is scoped to that one `user_id`, never
  an admin-wide broadcast).
- **`listMessages(pageId, requestingUserId)`** — authorizes the same way;
  returns `null` (not an empty array) for a non-participant, so a caller can
  distinguish "not authorized" from "no messages yet." Works for a page that
  was `accepted` and has since `completed` too (naturally returns `[]` once
  purged) rather than hard-erroring on a state check.
- **`purgeForPage(pageId)`** — internal lifecycle cleanup called by
  `SysopChatService::completePage()`; takes no acting user and performs no
  participant check, since it is not a caller-facing operation.

## Realtime

`sysop_chat.message` — targeted at the exact other participant (caller → the
accepting admin, or admin → the caller), never broadcast. Payload:
`page_id`, `message_id`, `sender_user_id`, `created_at`, `body`.

Admin-side consumption of the full M1A+M1B event family:

| Event | Admin reaction |
|---|---|
| `sysop_chat.request` | waiting-inbox badge/list refresh |
| `sysop_chat.cancelled` | waiting-inbox refresh |
| `sysop_chat.expired` | waiting-inbox refresh |
| `sysop_chat.accepted` | waiting-inbox + active-chat refresh (in case another admin took it) |
| `sysop_chat.completed` | active-chat panel closes |
| `sysop_chat.message` | if for the currently-open chat, re-fetch the transcript |

The waiting-page **badge** itself does not use a page-specific listener — it
reuses the existing site-wide `dashboard_stats` pipeline
(`DashboardStatsService::getStats()` gained a `pending_sysop_pages` count,
admin-only, computed via `SysopChatService::getWaitingPages()` so it is
never inflated by rows that merely timed out) and the existing
`.admin-menu-link.unread` amber-highlight convention already used for
pending echomail moderation — `public_html/js/notifier.js`'s
`updateAdminModerationIcon()` now also toggles a `.sysop-chat-menu-link`
badge/highlight, and additionally listens to `sysop_chat.request` /
`cancelled` / `expired` / `accepted` to refresh sooner than the existing
mail-state poll cadence would. This means the "someone is paging" indicator
is visible from **any** admin page, not only the SysOp Chat page itself —
the smallest-existing-surface option available, per M1B's design directive.

## Admin routes

All in `routes/admin-routes.php`, guarded by `RouteHelper::requireAdmin()`
(auth + CSRF + admin, the same helper every other admin route uses — no new
authorization code was written). Documented in full in `docs/API.md` →
"SysOp Chat":

- `GET /admin/sysop-chat` — the admin page itself
- `GET /api/admin/sysop-chat/state` — waiting inbox + active chat + `is_mine`
- `POST /api/admin/sysop-chat/{id}/accept`
- `POST /api/admin/sysop-chat/{id}/decline`
- `POST /api/admin/sysop-chat/{id}/end`
- `GET /api/admin/sysop-chat/{id}/messages`
- `POST /api/admin/sysop-chat/{id}/messages`

No route performs its own `sysop_pages`/`sysop_chat_messages` SQL — every
mutation and every authorization decision lives in `SysopChatService` /
`SysopChatMessageService`, already proven by the M1A/M1B test suites. Because
of that, this checkpoint does not duplicate authorization tests at the route
layer: `RouteHelper::requireAdmin()` is exercised everywhere else in the
codebase already, and the actually-new authorization logic (participant
checks, singleton-accept, dead-session accept, etc.) is unit-tested directly
against the services the routes call.

## Admin UI

One self-contained page, `templates/admin/sysop_chat.twig` (extends
`base.twig`, matches the existing small-admin-page pattern used by e.g.
`admin/chat_rooms.twig`) — deliberately not a new dashboard section, per the
"smallest existing surface" directive:

- **Waiting list**: a plain table — caller, surface, age, Accept/Decline.
  "No one is paging right now." when empty. No ticket/case language.
- **Active chat card**: hidden entirely when there is no active chat. When
  one exists and belongs to the viewing admin: transcript + text input +
  Send + End Chat. When one exists but belongs to a *different* admin: the
  transcript area is not shown at all, just "Another admin is currently in
  this chat." — no composer, no End button (a non-accepting admin cannot act
  on someone else's chat).
- Transcript re-syncs on a 4s interval while a chat the viewer owns is open
  (bounded, self-contained polling — no dependency on the page-wide
  `dashboard_stats` pipeline) plus immediately on a same-page
  `sysop_chat.message` BinkStream event, giving both reconnect-tolerance and
  a live feel without inventing a second event-delivery mechanism.
- Nav entry: "SysOp Chat" added to the existing Admin → Community (→ Chat,
  where that shell nests it) menu in all three base-layout variants
  (`templates/base.twig`, `templates/shells/web/base.twig`,
  `templates/shells/bbs-menu/base.twig` — required together per
  `templates/CLAUDE.md`), carrying the waiting-count badge.

## Race / stale handling (reusing M1A service guarantees, not reimplemented)

- **Duplicate accept**: two admins clicking Accept — exactly one succeeds
  (the partial-unique "one accepted page globally" index), the other gets a
  clean 409 `errors.sysop_chat.accept_failed`; the UI's `refresh()` after any
  action reconciles the loser's view.
- **Caller gone**: accepting a page whose caller's live session has vanished
  rolls the page to `expired` rather than opening a phantom chat; the admin
  sees the same 409 and the waiting list simply no longer shows that page
  after refresh.
- **Third-party access**: an admin who is neither the caller nor the
  accepting admin gets `null` from `listMessages()`/`sendMessage()` → a 403
  `errors.sysop_chat.not_participant` from the route, never a 500 or a
  silently-empty transcript.

## Tests

- `tests/Unit/SysopChatMessageServiceTest.php` — 13 tests / new assertions:
  send (caller→admin, admin→caller, unauthorized third user blocked, waiting
  page blocked, completed page blocked, empty/oversized body rejected), list
  (ordered for both participants, unauthorized blocked, unknown page → null),
  purge (completing a chat purges it, an earlier page's purge never touches
  a later unrelated page's messages, purge is idempotent).
- `tests/Unit/SysopChatServiceTest.php` — unchanged 27 tests still pass
  after the `getWaitingPages()`/`getActiveChat()` username-join additions and
  the `completePage()` purge wiring.
- Combined run (both suites, one process): **40/40 passing.**

## M1C contract (for the caller side)

Everything a terminal/Web-caller consumer needs already exists and is
stable:

- `SysopChatService::createPage()` / `cancelPage()` / `getCallerPage()` for
  the caller's own page lifecycle.
- `SysopChatMessageService::sendMessage()` / `listMessages()` for the
  caller's half of the chat, using the exact same participant-authorization
  rule already proven on the admin side.
- `sysop_chat.accepted` / `declined` / `expired` / `completed` /
  `message` events, each targeted at `caller_user_id` — M1C's terminal event
  handler (parallel to `SessionKickHandler`, polled the same way inside
  `BbsSession::pumpRealtimeAndCheckSession()`) has a fixed, already-tested
  contract to react to.

Nothing here changed shape to accommodate M1C speculatively — the M1A
contract already covered the caller side; M1B only added what the admin side
needed.
