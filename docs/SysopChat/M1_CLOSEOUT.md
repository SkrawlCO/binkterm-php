# SysOp Chat — M1 Closeout

**Status: CLOSED, human-accepted, 2026-09-13.**

> This document, and SysOp Chat generally, was authored/implemented by an AI
> assistant across M1A–M1D; human acceptance testing itself was performed by
> Matt on real clients (SyncTerm over Telnet, real Web admin).

Classic "Page SysOp" — a caller pages, Matt accepts from Web admin, they have
a private live chat, either side ends it, the caller returns to exactly where
they were — built end to end and human-accepted on production.

## What shipped

**M1A — backend lifecycle.** `sysop_pages` table + `SysopChatService`: create/
cancel/accept/decline/complete, one waiting page per caller, one accepted
chat globally, dead-session acceptance rolls to expired, the `sysop_chat.*`
realtime event family via the existing `BinkStream`/`sse_events` bus.
Deployed.

**M1B — Web admin + messages.** `sysop_chat_messages` table +
`SysopChatMessageService`: participant-scoped ephemeral private messages,
purged on chat completion. Web admin page (waiting inbox, Accept/Decline,
live chat panel, site-wide waiting badge). No push notifications, no
Discord, no permanent transcript. Deployed.

**M1C/M1D — terminal caller side.** Page SysOp reachable from both the
legacy main menu and the shipping Declarative Navigation Framework (one
shared `runPageSysopFlow()`, no forked implementation) — waiting state, live
chat modal (`TerminalLineEditor`-based line input, bounded message catch-up,
no polling loop), clean logical resume to whichever surface invoked it.
Two real defects found and fixed during human acceptance (a missing
closure-capture causing a declarative-fallback/disconnect blocker, and a
line-redraw visual artifact) — see `docs/SysopChat/M1C.md` for the full
root-cause record. Human-accepted.

## Supported now

| Surface | Status |
|---|---|
| Telnet/SyncTerm | **Human-accepted** |
| SSH | Architecture-supported (same code path), not human-tested in M1 |
| PubTerm/Web terminal | Architecture-supported (same `BbsSession` path), not human-tested in M1 |
| Web admin (SysOp side) | **Human-accepted** |

## Where Page SysOp lives

- **Declarative** (the framework this board ships): `People → Connect →
  [O] Page SysOp`
- **Legacy main menu** (still fully functional, not removed): `[O] Page SysOp`

## Intentionally not supported

- Interrupting a NativeDoor, WebDoor, or DOS-door relay loop (by
  construction — those loops never call into this flow at all)
- Active compose/editor interruption (unsaved text risk)
- Active Local Chat/MRC interruption (no safe suspend point)
- Page SysOp on People's other children, Messages list, message reader, BBS
  Directory (not wired this slice)
- Phone/Web Push notifications, Discord

## Parked for later (not started, record only)

**vNext (product polish, no urgency):**
- Page SysOp on additional safe BBS-owned screens
- SSH human compatibility pass
- PubTerm human compatibility pass
- Expiry UX tuning (current TTL is a placeholder default)
- Admin-disappearance handling (no heartbeat/timeout exists; the caller's own
  `/q`/Esc is the only escape today)
- Richer ANSI presentation, if real use suggests it
- SysOp availability/status, if real use demonstrates need

**v4.2:**
- Optional Web Push / phone notification: caller pages → push reaches Matt's
  phone → tap opens the waiting page/admin chat. No infrastructure for this
  exists yet; nothing here was built toward it.

## M1 STATUS: CLOSED
