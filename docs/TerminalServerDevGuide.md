# Terminal Server Developer Guide

Architectural and implementation reference for developers working on the BinktermPHP terminal server. For the user-facing feature reference and sysop configuration options see [TerminalServer.md](TerminalServer.md).

## Pipe Code Parser Mode

Terminal-side bulletin rendering in `telnet/src/BulletinsHandler.php` follows the shared `.env` setting `PIPE_CODE_PARSER_MODE` so sysops can experiment with the same detection strategy on both the web and terminal sides.

- `strict` matches the conservative uppercase-only behavior.
- `decimal_relaxed` is the default and greedily accepts two-digit decimal color codes such as `|01` before uppercase text.
- `loose` restores the older permissive matcher for debugging and comparison.

---

## Key Source Files

| Path | Role |
|------|------|
| `telnet/telnet_daemon.php` | Telnet daemon entry point — accepts connections, forks children, bootstraps `BbsSession` |
| `ssh/ssh_daemon.php` | SSH daemon entry point — shares the same `BbsSession` flow and `telnet/src/` handler classes |
| `telnet/src/BbsSession.php` | Core session class — owns the main menu loop, login flow, and handler dispatch |
| `telnet/src/TelnetUtils.php` | Shared utility class — all reusable widgets, ANSI helpers, `apiRequest()`, `buildStatusBar()`, `buildMessageHeaderBox()`, and `getDefaultStyleProfile()` |
| `telnet/src/TelnetServer.php` | Low-level telnet I/O — option negotiation, NAWS, key reading, idle check |
| `telnet/src/TerminalShellInterface.php` | Shell abstraction interface |
| `telnet/src/TuiShell.php` | Full-screen framed shell implementation |
| `telnet/src/LineShell.php` | Plain-text prompt-driven shell implementation |
| `telnet/src/TerminalShellFactory.php` | Selects TuiShell or LineShell based on terminal size and user setting |
| `telnet/src/*Handler.php` | Feature handlers — one per BBS section (netmail, echomail, files, doors, etc.) |
| `telnet/src/MailUtils.php` | Shared compose and drafts flow used by both netmail and echomail handlers |
| `telnet/src/TerminalBoxRenderer.php` | Paged and framed box widgets, configurable border styles |
| `telnet/src/TerminalMarkupRenderer.php` | Markdown and StyleCodes body renderer for message viewing |
| `telnet/src/OutputSink.php` | Interface — the single write target for terminal rendering (`SocketSink` live, `BufferSink` for tests/preview) |
| `telnet/src/TerminalCapabilities.php` | Immutable value object — negotiated facts about the caller's client (type, charset support, colour support, sixel) |
| `telnet/src/TerminalRenderContext.php` | Per-session mutable holder of render inputs (geometry, effective charset/colour, style profile, locale, glyphs) + the `OutputSink` |
| `telnet/src/GlyphPolicy.php` | Pure box-drawing glyph resolution for a charset + border style |
| `telnet/src/TerminalEventPoller.php` | Generic history-skipping / throttled read seam over the BinkStream `sse_events` bus for terminal sessions (F3H) — not yet wired into `BbsSession` |
| `telnet/src/TerminalEventHandlerInterface.php` | Consumer contract for `TerminalEventPoller` |
| `tests/Unit/Support/ScriptedTelnetSession.php` | F4 scripted session harness — drives the real Telnet engine over an in-memory socket pair |

Both daemon entry points manually `require_once` every `telnet/src/` class they use. New classes added under `telnet/src/` must be registered in both `telnet/telnet_daemon.php` and `ssh/ssh_daemon.php` — they are not Composer-autoloaded. See also `telnet/CLAUDE.md` for the include-list rule.

The Telnet server is a long-running process and keeps loaded PHP classes in
memory. After changing Telnet PHP source (including handlers under
`telnet/src/`), restart the deployment's Supervisor-managed `telnet_daemon`
program through the established deployment procedure before runtime validation
(for Supervisor deployments, this is typically `supervisorctl restart
telnet_daemon`). A browser refresh or new terminal connection alone does not
prove that the running daemon has loaded the changed source.

The Games & Experiences handler consumes normalized `GameCatalog` fields for
display metadata and launch behavior. Managed doors publish `terminal.mode` as
either `doorway` or `raw`; `DoorHandler` must use that field so raw native
terminal sessions bypass legacy Doorway key and CP437 conversion.

`GameCatalog` discovery includes authorized WebDoor and JS-DOS Experiences on
the terminal surface with their normalized `planned` state. The current
launch-only `DoorHandler` chooser filters on `actions.launch`, so those entries
remain non-runnable until a later terminal discovery/detail interface presents
non-launchable Experiences explicitly.

Terminal display metadata is composed through `ExperiencePresentation`, the
same read-model boundary available to web library and lobby consumers. Relay
selection remains separate and continues to read the normalized
`terminal.mode`; presentation data must never determine raw versus Doorway I/O.

### Live Now arrival view (telnet Crossroads slice 4)

`DoorHandler::show()` now uses one
`ExperienceState::getExperienceStates($viewer, 'terminal')` collection read as
the authorized source for both the terminal catalog and its Live Now arrival
summary. The viewer context carries numeric `user_id` / `id` and `is_admin`.
The returned snapshots already contain normalized distinct `player_count`,
separate `session_count`, roster rows, and the authorized catalog entry, so the
handler performs no per-Experience state queries.

The first catalog item is **Live Now**. Its detail line reports the distinct
callers present across included Experiences and the number of active
Experiences, or a quiet-state message. Opening it runs `runLiveNowLoop()` using
the existing shell `chooseFromList()` widget. Entries show only the Experience
name, distinct caller count, session capacity where configured, and up to three
roster names. Selecting an entry calls the existing `showExperienceDetail()`;
Back returns to the Live Now loop, whose next iteration performs a fresh
collection read. Returning to the catalog also refreshes its arrival snapshot.
There is no polling or subscription.

`composeLiveNow()` includes only snapshots whose normalized catalog entry has
`actions.launch` and whose normalized `player_count` is positive. It
deduplicates roster rows by numeric user id defensively. An Experience occupied
only by the current viewer is excluded because that state belongs to future
Continue Playing work, not social discovery. If any other distinct caller is
present, the Experience remains in Live Now even when the viewer also
participates. Authorization and terminal visibility remain owned by the
collection-state catalog read.

### Your Places arrival view (telnet Crossroads slice 5)

The same authorized `ExperienceState::getExperienceStates($viewer, 'terminal')`
snapshot used for Live Now and catalog composition also feeds **Your Places**.
`composeYourPlaces()` includes a state only when
`ExperienceParticipation::findViewerPlayer($state, $viewerId)` returns the
authenticated caller's player row. It does not infer participation from
occupancy, session totals, backend type, or launch data. Multiple sessions for
the same caller therefore still produce one entry per Experience.

Each entry uses `ExperiencePresentation::build(..., 'telnet', ...)` as the
source of Return availability. The view adds no participation model or
per-Experience state query. Viewer-only occupancy remains included, while an
Experience occupied only by other callers does not qualify; this deliberately
complements rather than partitions Live Now.

`runYourPlacesLoop()` uses the existing shell `chooseFromList()` widget and
opens the existing `showExperienceDetail()` flow. Each loop iteration performs
one fresh authorized collection read. Consequently Back from detail refreshes
Your Places, including after door return, social interaction, or End
Participation. A participation that ended or became stale simply disappears
on refresh. No polling, caching, or RLogin-specific lifecycle behavior is
introduced.

### Recently in the Crossroads arrival block (telnet)

Between Your Places and the Experiences catalog the arrival shows a short,
non-selectable **Recently in the Crossroads** block: at most five play
footprints (`Bard played LORD - 47m ago`, or `first played` when truthful).

It reuses the SAME shared read model as the web arrival —
`ExperienceActivity::recentAcrossCatalog()` — with the same semantics: play
activity types only (`webdoor_play` / `dosdoor_play`), one newest footprint per
distinct `(user, Experience)` pair, distinct-pair selection before the limit,
newest distinct pairs first, capped at five, system users and deleted users
excluded, first-play derived from full history. `ExperienceActivity::recent()`
for individual Experience detail is untouched and keeps its raw semantics.

The allow-list is `$doorList` — the viewer's **authorized, telnet-launchable**
catalog already built for this arrival — so a Web-only Experience, a hidden or
admin-only one, or an orphaned backend id can never surface a footprint. It is
one bounded query joined to the single pre-chooser collection snapshot: no
second `GameCatalog` discovery, no per-Experience state or activity query.

The block is **historical play evidence, not live presence** — "quiet now" does
not mean nobody has been here. It carries no "since your last visit" claim, no
session duration, no leave time, no launch-vs-return, and no surface label. It
is informational only: no avatars, icons, badges, scores, pagination, refresh,
or actions.

`DoorHandler::composeRecentFootprints()` is a pure formatter over the read-model
rows and applies no second dedupe. The lines are carried as the `Directory`
ambient **context block** (see below), rendered above the first destination
shelf as non-selectable text; they never enter the row array. Selection indices
and the Live Now `0` / Your Places `1` / Experience `$selected - 2` contract are
therefore unchanged. When there are no qualifying footprints the block is
omitted entirely; there is no empty-state line.

### Crossroads arrival presentation (shared directory primitive)

The top-level selector is composed as a `BinktermPHP\Terminal\Presentation\Directory`
and rendered through `TerminalShellInterface::showDirectory()` — the shared
"Terminal Experience Unification" primitive (see **Shared directory presentation
primitive** below). The `Directory` carries:

- **location** `Crossroads` and a one-line **tagline**
  (`ui.terminalserver.doors.tagline`), drawn as a masthead band;
- an untitled leading section holding the **Live Now** and **Your Places**
  rows (flat indices `0` and `1`, unchanged);
- one titled section per non-empty **Crossroads shelf**, in canonical order.

Shelf grouping is delegated wholesale to `BinktermPHP\CrossroadsShelves` — the
exact classifier the web Crossroads uses. `DoorHandler::buildDestinationShelves()`
passes each authorized catalog entry (`category`, `curation.curated`,
`curation.order` — existing normalized metadata only) through
`CrossroadsShelves::group()`, then rebuilds `$doorList` in the resulting shelf
order so `$doorList[$selected - 2]` still lines up with the flattened rows. No
product model, curation flag, or classification is written here; standing
product decisions (Galactic Bloodshed / SyncDOOM in the Game Hall, gateways
distinct from Experiences) belong to the classifier. Terminal section titles:
`ui.terminalserver.doors.shelf_curated` / `shelf_game_hall` / `shelf_utility` /
`shelf_gateway`.

`DoorHandler::buildExperienceDirectoryRow()` emits one `DirectoryRow` per
Experience: normalized name as the label, the catalog description as the row's
secondary context line, and a compact badge (`Multiplayer` for a multiplayer
Game, `Gateway` for a gateway; a single-player Game gets none). Full detail
still lives only in Experience detail.

### Experience detail screen (telnet Crossroads slice 1)

Selecting an experience in the `DoorHandler` chooser now opens
`DoorHandler::showExperienceDetail()` instead of launching immediately. The
journey is `catalog -> detail -> Play/Return -> door -> detail -> Back ->
catalog`.

`showExperienceDetail()` wires up four collaborators and hands them to
`runExperienceDetailLoop()`:

- **reload** — recomposes the shared read models on every iteration:
  `ExperienceState::getExperienceState($id, $user, 'terminal')` (the returned
  `experience` key is the authoritative catalog entry),
  `ExperienceParticipation::findViewerPlayer()`,
  `ExperiencePresentation::build($experience, 'telnet', $state, $viewerPlayer)`,
  and `ExperienceActivity::recent($experience, 5)`. It returns `null` when the
  experience is no longer discoverable, which the loop renders as an alert and
  exits.
- **onLaunch** — logs and calls the existing `launchDoor()` path with
  `resolveTerminalMode()` / `backend.type` exactly as the old direct-launch
  branch did. Because the loop calls `reload` again after `onLaunch` returns,
  the caller lands back on the same experience's detail screen (the Crossroads
  return destination); `Q`/`B` from there returns to the catalog.
- **onSocial** — `('people'|'conversation', $view)`; see the social layer below.
  After it returns, the loop re-runs `reload`, so a social view is also a
  "return to the same experience" destination.
- **onEnd** — posts to the existing authenticated
  `POST /api/experiences/{experienceId}/end` lifecycle with the terminal
  session and CSRF token. The API remains authoritative and delegates to
  `ExperienceParticipation::end()`. Success returns to the loop and reloads the
  same detail; failure is shown through the shell alert and also returns to the
  same detail.

Pure helpers, unit-tested without a daemon:

- `composeExperienceDetailView()` — assembles the view model (name, body lines,
  `roster` snapshot, action set, status-bar segments) from the presentation +
  state snapshot. Takes a `bool $chatEnabled` so it stays a pure formatter.
- `buildExperienceDetailLines()` — formats the compact body (description, type,
  status, occupancy/capacity, cost, roster with node numbers and a `(you)`
  marker, recent activity). No business state is decided here.
- `resolveDetailActions($presentation, $experienceState, $chatEnabled)` — maps
  `presentation.actions.play` / `.return` (mutually exclusive in the normalized
  contract) to the launch key `g`; maps
  `presentation.actions.end_participation` to `e` (End Participation); adds
  `w` (People) when the roster is
  non-empty and `c` (Conversation) when `experienceState.experience`
  `.capabilities.conversation.room_id > 0` **and** chat is enabled. Keys avoid
  every character `showScrollablePanel` reserves in either shell.

The detail screen is a snapshot on open/redraw; it does not poll. Planned /
browser-only experiences are still filtered out of the chooser (unchanged) and
never reach the detail screen.

### End Participation (telnet Crossroads slice 3)

The detail screen offers **E — End Participation** only when the shared
`ExperiencePresentation` action is true. That action comes from
`ExperienceParticipation::viewerActions()`, including its `canEnd()` backend
boundary; `DoorHandler` does not infer support from backend type.

`E` opens `TerminalShellInterface::showConfirmDialog()` with Cancel as the
default. Cancel performs no mutation and returns to the same detail. Confirm
calls the existing API through `TelnetUtils::apiRequest()` with the session and
CSRF token. After success, the loop's normal `reload` path refreshes viewer
participation, occupancy, roster, and available actions for the same
experience. API failure opens a terminal-native error alert and then reloads
the same detail so navigation remains available.

`ExperienceParticipation::canEnd()` currently supports native, DOS, JS-DOS,
and WebDoor backends. RLogin is deliberately absent: the terminal surface does
not invent a lifecycle that the shared backend does not support.

### Experience social layer (telnet Crossroads slice 2)

The detail screen's `W` / `C` actions hang People, Profile, Message and
Conversation off the same "place". `DoorHandler` stays navigation glue: the
`experience -> room` mapping, roster identity, profile data, chat permissions
and DM delivery all come from existing shared services.

- **`experience -> conversation`** — `GameCatalog::normalizeConversationCapability()`
  already resolves a manifest's `experience.conversation` (`room_id` or portable
  `room_name` via `ChatRoomService::resolveActiveRoomByName()`) into the
  normalized `capabilities.conversation = {type:'chat_room', room_id:int}` on
  every surface. Web reads the same field. There is no telnet-side room lookup
  and **no experience-name -> room mapping**.
- **`roster -> identity`** — `ExperienceState` roster rows carry a stable
  `user_id` (they JOIN `users`), which is all the profile/DM paths need. The
  People view uses the `roster` snapshot captured in the detail view model, not
  a new presence query.
- **People / person flow** — `showExperiencePeople()` -> `runExperiencePeopleLoop()`
  (`showSelectableDialog`, self-row marked `(you)` and inert) -> per-person
  `showPersonActions()` -> `runPersonActionLoop()`. The two `run*Loop` helpers
  take injected callbacks and touch no live infra, so the navigation is unit
  tested directly.
- **thin infra seams** (`protected`, overridden in tests):
  - `fetchPersonProfile()` -> `GET /api/user/public-profile/{id}` (the endpoint
    Who's Online uses); `null` on 404 => "no longer available" alert.
  - `invokeDirectMessage()` -> `ChatHandler::showDirectMessage($conn,$state,$session,$userId,$username)`.
  - `invokeRoomConversation()` -> `ChatHandler::showRoom($conn,$state,$session,$roomId)`.
- **`ChatHandler` contextual entrypoints** — `show()` is now a thin wrapper over
  `run($conn,$state,$session,?array $preferTarget)`. `showRoom()` /
  `showDirectMessage()` pass a preferred target; `openPreferredTarget()` opens a
  room only if it is in the authorized `GET /api/chat/rooms` list (the existing
  access boundary — chat rooms are open to any authenticated user when the chat
  feature is on), and a DM from just a positive id + label. Both return `bool`
  (`false` => the caller shows its own contextual alert). No second chat, DM, or
  profile implementation is introduced.
- Profile rendering is delegated to
  `TerminalShellInterface::showPublicProfileViewer()`; conversation/DM rendering,
  posting, polling, history and moderation stay entirely in `ChatHandler`.

---

## Session Flow

Both daemons hand off to the same `BbsSession` flow after transport setup:

1. **Transport handshake** — Telnet negotiates NAWS, echo control, and optional TLS; SSH reads PTY dimensions from `pty-req` and probes Sixel capability. Before negotiation, if the TCP connection originates from a trusted address (`TELNET_TRUSTED_PROXIES`, plus loopback and the daemon's own bind address), `TelnetServer` consumes an optional HAProxy PROXY protocol v1 header (non-destructive peek) and re-points `$peerName` / `$peerIp` at the real client. This is how PubTerm's per-session forwarder conveys the browser visitor's address; connection rate limiting then keys on it.
2. **Pre-login menu** — Login / Register / Reset password / Login and run terminal setup / QWK (when enabled) / Quit. Registration posts to the shared `/api/register` flow, so whether the account stays pending or is auto-approved is controlled centrally by `BbsConfig::shouldRequireRegistrationApproval()`. `BbsSession::attemptRegistration()` first calls `showHouseRulesAndConfirm()`, which renders `AppearanceConfig::getHouseRulesMarkdown()` (falling back to the built-in `ui.rules.*` default rule set when no override exists) in a paged box and requires the user to type `YES` before any fields are prompted. Auto-approved registrations now return a normal authenticated session and continue directly into the terminal session without forcing a reconnect. Choosing **T** sets `force_terminal_setup` on the login result, which makes the post-login setup step in `BbsSession::handle()` run `TerminalSettingsHandler::runDetectionWizard()` even when the user already has saved terminal settings.
3. **Authentication** — Username/password via `POST /api/auth/login`, or auto-login via auto-approved `POST /api/register`; session cookie stored in `$state`.
4. **Session init** — Single `GET /api/config/session-init` call returns timezone, locale, date format, charset, ANSI color flag, idle timeout thresholds, and main menu key bindings.
5. **Main menu loop** — `BbsSession::handle()` runs the menu, dispatches to feature handlers, and processes resize events on each iteration.
6. **Feature handlers** — Each handler runs until the user exits back to the main menu. Handlers share `$conn`, `$state`, and `$session` passed by reference.
7. **Logout** — `POST /api/auth/logout` tears down the session; transport closes the socket.

`$state` is a mutable array threaded through all calls. Key entries:

| Key | Content |
|-----|---------|
| `rows`, `cols` | Current terminal dimensions (updated live from NAWS) |
| `locale` | User's locale string (e.g. `en`, `fr`) |
| `csrf_token` | CSRF token required for all mutating API calls |
| `terminal_type` | Reported TTYPE string (e.g. `syncterm`, `xterm-256color`) |
| `charset` | Effective charset: `utf8`, `cp437`, or `ascii` |
| `ansi_color` | Whether ANSI color is enabled for this session |
| `repaint_fn` | Callable set by the active screen; overlays call it to repaint the background on resize |

---

## Terminal Render Seam

The render seam decouples "produce a frame" from "put bytes on a socket" so that
**one rendering path** serves three consumers: the live Telnet/SSH session,
deterministic render tests, and (later) a sysop preview.

Three abstractions (all in `telnet/src/`, loaded by both daemons before
`BbsSession`):

| Type | Kind | Responsibility |
|------|------|----------------|
| `OutputSink` | interface (`write`/`flush`/`isWritable`) | the single write target. `SocketSink` wraps a live connection resource with the historical `safeWrite()` byte semantics and **never** closes it; `BufferSink` accumulates raw bytes for tests/preview. Writes-only — no semantic cursor/clear/frame API. |
| `TerminalCapabilities` | immutable value object | negotiated **facts** about the client: `clientType`, `charsetSupport`, `colorSupport`, `sixelSupported`. Not preferences, not geometry, not effective values. Replaced (never mutated) via `with*()` when a fact resolves. `forProfile()` names are test/preview vocabulary only — never a config surface. |
| `TerminalRenderContext` | per-session mutable holder | the single source of render inputs: geometry (mutates on NAWS), effective charset, effective colour flag, ascii-text mode, style profile, locale, border-style/glyph policy, and the `OutputSink`. Exposes `write`/`writeLine`/`colorize`/`encodeForTerminal`/`lineDrawingChars`/`t`. |

**`TerminalRenderContext` must never carry:** auth/session lifecycle, session id,
user identity / access (ACS) context, the `$state` array wholesale, API/service
clients, a DB connection, input reading, navigation state, door state, or
Telnet/SSH negotiation. Access-control filtering of what a caller may see is the
job of a future declarative-navigation runtime, which hands the renderer an
already-filtered screen model — the renderer never sees a user.

### The one-renderer invariant

Given the same `capabilities`, `geometry`, effective charset, colour flag, style
profile, border style, and locale, a screen renders **byte-identically**
regardless of whether the sink is a `SocketSink` (live), a `BufferSink` (test),
or a `BufferSink` (preview). What may differ across the three: the sink, the
capability profile, geometry, style/locale, and injected presentation data.
What must **not** differ: layout maths, ANSI-aware truncation, glyph fallback,
charset conversion, colour application, and screen-model rendering.

### Migration rule

- **All new rendering code targets `TerminalRenderContext` / `OutputSink`.** Do
  not add new `$conn` / `$state`-coupled render helpers.
- **Existing rendering code is not migrated as standalone churn** — it moves to
  the context only when a slice already needs to touch it, and never in a way
  that changes output bytes.
- `BbsSession`'s render-accessor methods (`safeWrite`, `writeLine`, `colorize`,
  `colorizeForTerminal`, `encodeForTerminal`, `getTerminalCharset`,
  `getTerminalLineDrawingChars`, `t`) now delegate to the session's
  `TerminalRenderContext`; their signatures and output are unchanged.
- `TelnetUtils::safeWrite` / `TelnetUtils::colorize` / the
  `TelnetUtils::$ansiColorEnabled` static remain as compatibility bridges for
  their many static call sites and are kept consistent with the context.

Byte-parity of the delegation is enforced by
`tests/Unit/BbsSessionRenderSeamParityTest.php`, which renders `renderBox` and
each accessor through both the pre-context inline path and the context path and
asserts identical output across a `{geometry} x {charset} x {colour}` matrix.
The abstractions themselves are covered by `TerminalCapabilitiesTest`,
`TerminalRenderContextTest`, `TerminalOutputSinkTest`, and `GlyphPolicyTest`.

### Deterministic render fixtures

`tests/Unit/Support/TerminalRenderHarness.php` builds a `TerminalRenderContext`
backed by a `BufferSink` at an arbitrary geometry / charset / colour / style /
locale — no socket, no `BbsSession`, no auth, no database. Use it for any test
or (future) preview that needs to render a screen off-session:

```php
$ctx = TerminalRenderHarness::geometry('132x36')->charset('cp437')->mono()->context();
// ... render into $ctx ...
$bytes = TerminalRenderHarness::at(132, 36)->charset('cp437')->mono()->bytes();
```

`TerminalRenderHarness::standardMatrix()` yields the 18-cell
`{80x24, 132x36, 132x51} x {utf8, cp437, ascii} x {colour, mono}` set used by
`tests/Unit/DeterministicRenderTest.php`, which asserts geometry-safe layout
(no content line exceeds the terminal width), glyph + charset fallback, and
colour behaviour through the real `TerminalBoxRenderer` / `TelnetUtils` helpers.

`TerminalRenderContext::selectorRows()` is the canonical "effective rows"
computation (SyncTERM reserves one bottom row); it agrees byte-for-byte with the
legacy `TelnetUtils::getSelectorRows($state)`, whose call sites migrate
opportunistically.

### Capability resolution from negotiation (F3)

`BbsSession::refreshCapabilities()` (called from every `syncRenderContext()`)
now populates the capability value from real negotiation evidence:

| Field | Source |
|-------|--------|
| `clientType` | TTYPE `IS` / SSH `pty-req`, with RFC 1091 **cycling** — the server keeps sending `TTYPE SEND` until the client repeats an entry or `MAX_TTYPE_REQUESTS` (4) is hit. The first reported type stays primary; a later generic entry never overwrites a SyncTERM identification, and a SyncTERM entry anywhere in the list still triggers the local-status-line fix. |
| `colorSupport` | inferred from the reported type: bare `DUMB` / `UNKNOWN` / `NETWORK` → `none`, anything else → `ansi`, empty → `unknown`. **Inference only** — it does not change the effective `ansiColorEnabled` flag, which is still the user's saved preference. |
| `charsetSupport` | RFC 2066 **CHARSET** exchange only. The server sends `WILL CHARSET`; a client that replies `DO CHARSET` gets `IAC SB CHARSET REQUEST ;UTF-8;CP437;US-ASCII IAC SE`; `ACCEPTED <name>` sets `utf8` / `ascii_only`, `REJECTED` leaves it `unknown`. **Never guessed from the terminal type.** The effective charset (saved preference / detection wizard) is untouched; a client `CHARSET REQUEST` is answered `REJECTED`. |
| `sixelSupported` | the DA1 probe flag (`$sixelSupported`), carried through unchanged. |

Geometry: NAWS updates now call `TerminalRenderContext::setGeometry()` directly
from the `readRawChar()` subnegotiation branch, so a resize is visible on the
next render without waiting for a `syncRenderContext()` boundary. `$state['cols'
/'rows']` remains the single source of truth; the context mirrors it.

Conservative option refusal: a DO/WILL for an option the server does not
negotiate is answered once with WONT/DONT (`refuseUnsupportedTelnetOption()`);
WONT/DONT are never answered; each `(cmd,opt)` is refused at most once per
session so a misbehaving client cannot cause a storm.

Transport keepalive: on an idle read the server sends `IAC NOP`
(`maybeSendKeepalive()`), throttled to `TELNET_KEEPALIVE_SECONDS` (default 60,
`0` disables, SSH exempt). This is a liveness probe — it does **not** touch
`last_activity` / `idle_warned`, so the idle-warning and idle-disconnect timers
are unchanged.

One behaviour change in `readRawChar()`: after consuming a DO/DONT/WILL/WONT
triple with nothing else buffered it now returns `"\x00"` (benign no-op),
matching the existing SB branch, instead of `null` (which callers treat as a
disconnect). A client that segments its negotiation is no longer at risk of
being dropped.

### Scripted session harness (F4)

`tests/Unit/Support/ScriptedTelnetSession.php` drives the real `BbsSession`
Telnet engine over an in-memory `stream_socket_pair()` — no network, no external
BBS, no sleeps, and the real parser (never a copy). The script writes
negotiation / keystroke bytes from "the client" end and reads the server's
responses back; `pump()` feeds the wire through the real `readRawChar()`,
`readKey()` calls the real timeout-bounded reader.

```php
$s = (new ScriptedTelnetSession())->negotiate();
$s->sendWill(ScriptedTelnetSession::OPT_TTYPE)->pump();
$s->sendTerminalTypeIs('SYNCTERM')->pump();
$s->sendNaws(132, 50)->pump();
self::assertTrue($s->capabilities()->isSyncTerm());
self::assertSame([132, 50], $s->geometry());
```

`tests/Unit/TelnetNegotiationTest.php` covers the F3 byte exchanges;
`tests/Unit/ScriptedTerminalSessionTest.php` covers full conversations
(negotiation → TTYPE cycling → NAWS → keystrokes → mid-session resize →
malformed bytes → EOF).

### Terminal event substrate (F3H)

`telnet/src/TerminalEventPoller.php` + `TerminalEventHandlerInterface` are a
generic read seam over the existing BinkStream bus (`StreamService` /
`sse_events`) so a future terminal feature can react to realtime events without
each feature reinventing polling. It is history-skipping (`start()` anchors at
the current max id), throttled (`minIntervalSeconds`), forward-only, and has no
side effects — no writes, no pruning (the hourly `sse_events` maintenance owns
that), no activity/idle interaction.

```php
$poller = new TerminalEventPoller(new StreamService($db), ['user_id' => $uid, 'is_admin' => $isAdmin]);
$poller->start();
// ... in the input loop, between keystrokes:
$poller->poll(function (string $type, array $payload, int $id) {
    // cheap, non-blocking; defer any redraw to the session's normal path
});
```

It is **not** wired into `BbsSession` yet: the wiring point is where a
session-monitor / kick / page / MRC UI would attach, and that UI is out of
scope.

### Not yet wired (later stages)

- `TerminalRenderContext.t()` is param-driven for parity; it does not yet
  substitute the stored locale when a caller omits one.
- `TerminalEventPoller` has no `BbsSession` call site (see above).
- The dead `probeAnsiSupport()` / `probeSixelSupport()` methods remain — for
  Telnet, `$sixelSupported` is only set from the SSH `pty-req` path today; the
  capability seam propagates whatever value is set. Re-enabling an active Telnet
  probe is out of scope.

---

## Terminal Shell Abstraction

Terminal feature handlers target a shared shell interface instead of binding directly to raw prompt loops or `TelnetUtils` widgets. The shell owns UI intents — selecting from a list, prompting for text, confirming actions, showing read-only content, and displaying alerts — so handler code remains shell-agnostic.

### Available Shells

| Shell | Selection | Free-form text | Single-key actions | Read-only text | Alerts | Typical use |
|-------|-----------|----------------|--------------------|----------------|--------|-------------|
| `TuiShell` | Framed selector widgets | Framed input dialogs | Framed single-key modals | Paged framed viewers | Framed alerts | Normal-size terminals |
| `LineShell` | Prompt-driven numbered menus | Plain text prompts | Immediate single-key reads | Plain text screens | Plain text notices | Narrow or low-capability terminals |

Shell selection is automatic via `TerminalShellFactory::create($server, $state)`:

- `TuiShell` when rows ≥ 16 **and** cols ≥ 60, or when `term_shell_mode = tui`
- `LineShell` otherwise, or when `term_shell_mode = line`

The shell is re-created on each main menu iteration so a resize that crosses the size threshold switches the shell live without a reconnect.

### Shell Allowlist

User-selectable shell modes are filtered through the `.env` variable
`TERMSERVER_ALLOWEDSHELLS`, parsed centrally by `BinktermPHP\BbsConfig`.
The value is a space-separated allowlist of registered shell IDs such as
`tui`, `tui line`, or `tui retroglass`.

- If the variable is missing or empty, only `tui` is allowed.
- `routes/api-routes.php` uses the allowlist to validate persisted
  `term_shell_mode` values.
- `telnet/src/SettingsHandler.php` uses the allowlist to decide which shell
  choices to render in the terminal settings UI.
- `templates/admin/bbs_settings.twig` and `routes/admin-routes.php` use the
  allowlist to constrain the sysop-facing default shell selector.
- `TerminalShellFactory` treats any disallowed explicit shell request as a
  fallback to `TuiShell`, so stale user preferences do not block login.

### Plugin Shell Discovery

`BinktermPHP\TerminalShellRegistry` owns shell discovery and metadata.

- Built-in shells (`tui`, `line`) are registered in code.
- Additional shell plugins are discovered from `telnet/shells/*.plugin.php`.
- Each plugin file must return either one definition array or a list of
  definition arrays.
- The minimum definition shape is:

```php
[
    'id' => 'retroglass',
    'class' => \Vendor\Binkterm\RetroGlassShell::class,
    'label' => 'Retro Glass',
]
```

- Optional metadata fields:
  - `admin_label` and `settings_label` for context-specific labels
  - `admin_label_key` and `settings_label_key` for built-in translated labels
- If a plugin wants to split class code into another file, the plugin
  definition file should `require_once` that file before returning metadata.
- Plugin classes must accept the same constructor shape as built-ins:
  `__construct(BbsSession $server)`.
- Plugin classes should implement `TerminalShellInterface` directly or extend
  one of the built-in shells.

The registry is used from both daemon and web/admin contexts, so plugin files
must be safe to load outside an active terminal session. Discovery is
metadata-driven; actual shell instantiation happens later inside
`TerminalShellFactory`.

### `TerminalShellInterface`

All shells implement the same five intent methods:

| Method | Intent |
|--------|--------|
| `chooseFromList($conn, &$state, $title, $items, $options)` | Present a selectable list; returns selected index or null (cancel/quit) |
| `showDirectory($conn, &$state, Directory $directory, $options)` | Present an L33TEST-owned directory / junction screen; returns `['action' => 'select'\|'back', 'value' => mixed, 'index' => int]` |
| `promptText($conn, &$state, $title, $prompt, $options)` | Free-form text input; returns string or null (cancel) |
| `promptKey($conn, &$state, $title, $prompt, $allowedKeys, $options)` | Single-key action prompt; returns lowercase key string or null |
| `showText($conn, &$state, $title, $lines, $options)` | Display read-only text; returns when user dismisses |
| `showAlert($conn, &$state, $title, $message, $style)` | Short notice (`'info'` or `'error'`); returns on dismiss |

Handler code calls these methods on whichever shell `TerminalShellFactory` returned:

```php
$shell = TerminalShellFactory::create($this->server, $state);

$index = $shell->chooseFromList($conn, $state, 'Pick an area', $areaNames);
if ($index === null) {
    return; // user quit
}

$confirmed = $shell->promptKey($conn, $state, 'Confirm', 'Delete this message?', ['y', 'n']);
if ($confirmed !== 'y') {
    return;
}
```

### `TuiShell` Capabilities

`TuiShell` wraps `TelnetUtils` widgets and threads the style profile to each call automatically. It also exposes these additional widget methods beyond the interface:

| Method | Widget used |
|--------|-------------|
| `showMessageViewer(...)` | `TelnetUtils::runMessageViewer()` with help overlay profile |
| `showMessageList(...)` | `TelnetUtils::runMessageList()` with help overlay profile |
| `showSelectableList(...)` | `TelnetUtils::runSelectableList()` with help overlay profile; `$options['header_lines']` (string[]) renders a fixed informational block under the title |
| `showScrollablePanel(...)` | Inline framed scrollable panel |
| `showConfirmDialog(...)` | `TelnetUtils::showConfirmDialog()` with dialog profile |
| `showWorkingOverlay(...)` | `TelnetUtils::showWorkingOverlay()` with working overlay profile |
| `showCheckboxListDialog(...)` | `TelnetUtils::showCheckboxListDialog()` with checkbox dialog profile |
| `showSelectableDialog(...)` | `TelnetUtils::showSelectableDialog()` with selectable dialog profile |
| `showPublicProfileViewer(...)` | `TelnetUtils::showPublicProfileViewer()` with profile viewer profile |
| `showPagedBox(...)` | `TerminalBoxRenderer::showPagedBox()` with panel profile |
| `renderPanel(...)` | `TerminalBoxRenderer::renderBox()` with panel profile |
| `showAddressPicker(...)` | `TelnetUtils::runAddressPicker()` |

### `LineShell` Capabilities

`LineShell` maps the same interface onto plain terminal output:

- `chooseFromList` renders a numbered list with typed page navigation and reads the selection via `prompt()`
- `promptText` calls `prompt()` directly
- `promptKey` reads a single keystroke via `readKeyWithIdleCheck()` — no framed UI
- `showText` wraps and prints lines then waits for any key
- `showMessageList` and `showSelectableList` render prompt-driven numbered pages and return the same action strings the handlers use in TUI mode
- `showMessageViewer`, `showPagedBox`, and `showPublicProfileViewer` render wrapped text readers with typed navigation commands instead of full-screen framed widgets
- `showConfirmDialog` and `showSelectableDialog` render plain text prompts instead of centered overlays
- `showAlert` prints a plain text notice

### Shared directory presentation primitive

`BinktermPHP\Terminal\Presentation` is the transport-neutral vocabulary for an
L33TEST-owned **directory / junction** screen — a place the caller has arrived
at, with grouped destinations. It exists so those screens can share the
declarative front door's presentation language (a location masthead, a tagline,
uppercase section headings, per-row secondary context, an ambient activity
block) *without* being pushed into a `NavigationScreenModel` and *without* a new
key loop.

| Class | Role |
|-------|------|
| `Directory` | resolved model: `location`, `tagline`, `sections[]`, `contextLines[]` |
| `DirectorySection` | a titled group of rows (`''` title = unheaded leading block) |
| `DirectoryRow` | `label`, `description`, `badge`, and an opaque `value` payload returned on selection |
| `DirectoryView::compose()` | composes a `Directory` + `TerminalRenderContext` into the structured selectable-list contract (`['title', 'items', 'values']`) that `chooseFromList()` already consumes |
| `TextBlock` | pure `padRight()` / `ellipsize()` text-geometry helpers, shared with `NavigationScreenRenderer` (extracted verbatim; the front door delegates to them) |

`TuiShell::showDirectory()` and `LineShell::showDirectory()` call
`DirectoryView::compose()` then delegate to their own `chooseFromList()` — so
list navigation, resize, disconnect handling and the help overlay are the
existing proven behaviour; only the composition and the payload-based return are
new. `DirectoryView` performs no I/O: `contextLines` must be pre-resolved plain
text.

First consumer: the Crossroads arrival (`DoorHandler::show()`). The
`NavigationScreenRenderer` themed/flowing path is a separate renderer and is not
routed through `DirectoryView` in this milestone.

### Shared dense-list presentation primitive

The sibling of the directory primitive, in the same
`BinktermPHP\Terminal\Presentation` namespace, for L33TEST-owned **dense,
tabular, selectable** lists (echomail areas, file areas, a nodelist) where row
density is the point and cards would be a regression. It shares the M1
vocabulary and the `TextBlock` geometry helpers but **not** `DirectoryView`'s
composition: a dense list spends exactly two chrome lines — a location identity
line with a right-aligned page indicator, and one optional compact context line
— and one screen row per item.

| Class | Role |
|-------|------|
| `DenseList` | resolved model: `location`, `crumbs[]`, `context`, `columns[]`, `rows[]`, `page`, `totalPages` |
| `DenseListColumn` | `key`, fixed `width` (0 = the flexible column), `align`, `minWidth` |
| `DenseListRow` | `cells` (column key => plain text), an opaque `value`, an optional `prefix` badge + `prefixSgr` |
| `DenseListView::compose()` | composes a `DenseList` + `TerminalRenderContext` into `['title', 'headerLines', 'rows', 'values']` for the **flat** selectable-list contract |

`DenseListView::compose()` produces flat pre-formatted row strings; the caller
hands `title`, `rows` and `headerLines` to `$shell->showSelectableList()` with
`['header_lines' => $composed['headerLines']]`. That option is the only renderer
change: `TelnetUtils::runSelectableList()` (flat-row path) and
`LineShell::showSelectableList()` render the header block directly under the
title, above the first selectable row. With no `header_lines` the list is
byte-for-byte identical to its historical behaviour. Column widths are
geometry-aware: the flexible column takes the remainder, and fixed columns are
shrunk widest-first (never below `minWidth`) before the flexible column clips.

First consumer: the Echomail Areas selector (`EchomailHandler::pickEchoarea()`),
which now shows `Messages ` + `Echomail Areas` + a right-aligned `Page n/m`, a
dim context line (`Areas you follow - N` / `All areas - N total` /
`Filter: term - N matching`), and the same `tag / network / description` grid,
lightbar, paging, `Q`, `Ctrl-K` help and `/` `s` `a` `u` `g` `i` `c` keys as
before.

Second consumer: the File Areas selector (`FileHandler::pickFileArea()`), which
shows `Files ` + `File Areas` + a right-aligned `Page n/m`, a dim context line
(`N areas - M files`), and a `tag / file count / description` grid — the
right-aligned `count` column is fixed-width and placed before the flexible
`description` column so it keeps a stable right edge. Lightbar, `L/R` + `n`/`p`
paging, numeric jump, `Enter` and `Q` are unchanged; there are no extra keys and
no `Ctrl-K` overlay (the selector has no secondary actions). The legacy flat
`renderFileAreaSelectionLine()` row is kept only for the no-render-context
(pre-auth / mono) pathway. No primitive change was needed — the M1 Crossroads
and M2 Echomail output is byte-for-byte unchanged.

### Adding a New Shell

For a custom shell plugin:

1. Create a shell class that implements `TerminalShellInterface` or extends an existing shell.
2. Place a plugin definition file in `telnet/shells/` that returns metadata for the shell ID and class name.
3. Add the shell ID to `TERMSERVER_ALLOWEDSHELLS` if users or sysops should be able to select it.
4. Keep handler code shell-agnostic — feature handlers must call interface methods only.
5. Update this document and `docs/TerminalServer.md` if the shell is meant to be part of the supported platform surface.

For a new built-in shell shipped by the project:

1. Add the class under `telnet/src/`.
2. If it is a new `telnet/src/` file, add the required `require_once` entries to both daemon entrypoints.
3. Register it in `BinktermPHP\TerminalShellRegistry::getBuiltInDefinitions()`.
4. Keep `TerminalShellFactory` shell-agnostic — it should instantiate through the registry rather than hardcoding additional branches.

The shell layer is intentionally minimal so a new shell can be added without rewriting existing handlers.

### Shell Abstraction Exemptions

Some handlers must remain on fixed plain-text flows and must never be routed through the shell abstraction:

- **`QwkMenuHandler`** — QWK reader software and expect-style automation scripts parse specific prompt strings (`> `, conference numbers, format prompts) to navigate the QWK menu programmatically. A TUI shell would break those scripts. `QwkMenuHandler` must stay on plain `prompt()`/`writeLine()` calls.
- **`TerminalSettingsHandler::runDetectionWizard()`** — the terminal detection wizard must stay on plain `prompt()`/`writeLine()` calls for every shell. Its job is to determine whether charset rendering and ANSI color can be trusted at all; if it were converted to shell widgets, the capability-detection path would depend on the very rendering features it is trying to verify.

---

## Style Profile

All terminal UI colors are routed through the terminal style profile. Widgets should read the active session profile with `TelnetUtils::getStyleProfile($state)` so shell or plugin overrides apply consistently; `TelnetUtils::getDefaultStyleProfile()` is only the canonical fallback palette.

```php
$profile = TelnetUtils::getStyleProfile($state);
```

### Profile Sections

| Profile key | Sub-keys | Usage |
|-------------|----------|-------|
| `panel` | TerminalBoxRenderer scheme | Framed paged-text and info-panel boxes |
| `list` | `title`, `selected_bg` | List title color and selection highlight |
| `scrollable_panel` | `border`, `divider`, `title_bar`, `body`, `status_bar_bg` | TuiShell scrollable panel widget |
| `status_bar` | `bg`, `key`, `label`, `text`, `fill` | Bottom status bar — `key` = key name color (default red), `label` = label text color (default blue) |
| `header_box` | `bg`, `frame`, `body` | Message header box (From / To / Date / Subj) |
| `help_overlay` | `bg`, `frame`, `body`, `key`, `status_key`, `status_label` | Ctrl-K help overlay |
| `dialog` | `bg`, `frame`, `body`, `hint`, `choice_key`, `choice_label` | Confirmation and multi-choice dialogs |
| `working_overlay` | `bg`, `frame`, `body` | "Please wait" overlay |
| `checkbox_dialog` | `bg`, `frame`, `body`, `hilite`, `dim` | Checkbox picker dialog |
| `selectable_dialog` | `bg`, `frame`, `body`, `hilite`, `dim` | Selectable item dialog |
| `alert` | `info`, `error` (each: `bg`, `frame`, `body`) | Alert dialog color variants |
| `image_prompt` | `bg`, `frame`, `body` | Sixel image prompt overlay |
| `profile_viewer` | `bio_label`, `status_key`, `status_label` | Public profile viewer |
| `file_detail_panel` | `border`, `divider`, `title_bar`, `body`, `status_bar_bg` | File detail panel |

### Building Status Bar Segments

Use the `key`/`label` entries from `status_bar` rather than hardcoding `ANSI_RED`/`ANSI_BLUE`:

```php
$sbProfile = TelnetUtils::getDefaultStyleProfile()['status_bar'];
$keyColor  = $sbProfile['key']   ?? TelnetUtils::ANSI_RED;
$lblColor  = $sbProfile['label'] ?? TelnetUtils::ANSI_BLUE;

$segments = [
    ['text' => 'U/D',       'color' => $keyColor],
    ['text' => ' Scroll  ', 'color' => $lblColor],
    ['text' => 'Q',         'color' => $keyColor],
    ['text' => ' Quit',     'color' => $lblColor],
];
$statusLine = TelnetUtils::buildStatusBar($segments, $width);
```

`TuiShell` methods thread the profile to their widget calls automatically. Handlers that go through `TuiShell` do not need to pass the profile explicitly.

---

## Reusable UI Widgets

**Always check for an existing `TelnetUtils` or `TuiShell` widget before writing custom terminal UI.** Duplicating widget logic creates inconsistency and bugs. The full widget reference lives in `telnet/CLAUDE.md`; a summary of the most-used widgets follows.

| Need | Use |
|------|-----|
| Full-screen scrollable message reader (Ctrl-K help built in) | `TelnetUtils::runMessageViewer()` / `$shell->showMessageViewer()` |
| Paginated selectable list with keyboard nav | `TelnetUtils::runSelectableList()` / `$shell->showSelectableList()` |
| Pre-built message list with compose/read actions | `TelnetUtils::runMessageList()` / `$shell->showMessageList()` |
| Scrollable kludge/header overlay | `TelnetUtils::runKludgeViewer()` (called internally by message viewer) |
| Sixel image viewer | `TelnetUtils::showSixelImageViewer()` |
| Message header box (From/To/Date/Subj) | `TelnetUtils::buildMessageHeaderBox()` |
| Status bar from segments array | `TelnetUtils::buildStatusBar()` |
| Centered confirmation / multi-option dialog | `TelnetUtils::showConfirmDialog()` / `$shell->showConfirmDialog()` |
| Centered alert/notice dialog | `TelnetUtils::showAlertDialog()` / `$shell->showAlert()` |
| Centered single-line text input | `TelnetUtils::showInputDialog()` / `$shell->promptText()` |
| Centered "please wait" overlay | `TelnetUtils::showWorkingOverlay()` / `$shell->showWorkingOverlay()` |
| Checkbox picker dialog | `TelnetUtils::showCheckboxListDialog()` / `$shell->showCheckboxListDialog()` |
| Selectable item dialog | `TelnetUtils::showSelectableDialog()` / `$shell->showSelectableDialog()` |
| ANSI-wrapped text lines (prose) | `TelnetUtils::wrapTextLines()` |
| ANSI-art body lines (authored rows, clip not reflow) | `TelnetUtils::clipArtLines()` |
| Address book / nodelist picker | `TelnetUtils::runAddressPicker()` / `$shell->showAddressPicker()` |
| Public profile viewer | `TelnetUtils::showPublicProfileViewer()` / `$shell->showPublicProfileViewer()` |

If a widget genuinely lacks a capability needed by multiple features, extend it in `TelnetUtils` — do not work around it in a handler. When adding or extending a widget, update the table in `telnet/CLAUDE.md`.

### Line input

All editable single-line input edits through one shared, UTF-8-codepoint state
machine, `TerminalLineEditor` (`telnet/src/TerminalLineEditor.php`) — never a
per-handler key switch. It is pure (no socket) and handles append, backspace,
forward-delete, Left/Right/Home/End (+ Ctrl-A/E), a max length, and
submit/cancel. `TelnetUtils::showInputDialog()` and `LineShell::readPromptLine()`
are its adapters; both honour mid-line editing and place the visible terminal
cursor at the editor's logical insertion point (`TerminalLineEditor::cursor()`),
not always at end-of-text. `showInputDialog()`'s boxed field scrolls
horizontally to keep the insertion point in view when the value is longer than
the field.

- Read keys for a text field with `BbsSession::readLineKeyWithIdleCheck()`, **not**
  `readKeyWithIdleCheck()` — the latter applies a menu-style "swallow a queued
  line terminator after a printable" peek that eats an LF a text field needs to
  submit on. `readLineKeyWithIdleCheck()` still collapses a real CR-LF into one
  ENTER.
- `readRawChar()` reassembles a UTF-8 lead byte and its continuation bytes into
  one codepoint; the key normalisers emit `CHAR:<codepoint>`. Editing is by
  codepoint, so a backspace never bisects a multi-byte character.
- After a line reader accepts or cancels, it calls
  `BbsSession::drainPendingInput()` (non-blocking, IAC/NAWS-aware) to discard
  anything still queued behind the terminator — a multi-line paste, or a
  `hotkey<Enter>` burst — so it cannot run against the next screen or a password
  field.
- History: opt-in only. `showInputDialog()` takes an `history_key` option;
  `readPromptLine()` reads `$state['line_prompt_history_key']`. Recall is
  `TerminalLineHistory` — bounded (30), per-session, non-persistent, walked with
  Up/Down. A masked prompt (`$echo === false`) is never given a history key.
- Single-key surfaces (menus, the lightbar, message-viewer controls, Newscan
  interstitials), the full-screen compose editor, and door/raw input do **not**
  use this — they are not line input.

### Sanitizing untrusted text for terminal display

Message bodies, kludge lines, subjects and author names can come from any local
user or any upstream FTN node and are rendered close to verbatim by the read
paths. Before such text is word-wrapped or written to the terminal it must pass
through `BinktermPHP\TerminalTextSanitizer::sanitize()`, which keeps SGR colour
sequences (`ESC [ … m`) and TAB/CR/LF while removing every other escape sequence
and C0/C1 control byte (cursor/erase moves, OSC title/clipboard writes,
DCS/answerback queries, etc.).

Current call sites: `EchomailHandler` / `NetmailHandler` message viewers
(`message_text` + combined kludge lines), `MailUtils::quoteMessage()` (reply and
forward bodies), `TelnetUtils::formatMessageListEntry()` and
`TelnetUtils::buildMessageHeaderBox()` (list rows and header fields), and
`PacketBbs\PacketBbsTextRenderer` (which then also drops the SGR codes, since
radio links are plain text). Any new surface that renders remote message content
must call the sanitizer too.

### Status Bar Discipline

The bottom status bar has limited width. Keep it to the **most-used primary actions only** — typically scroll, prev/next, reply, and quit. Every other key belongs exclusively in the Ctrl-K help overlay.

```
✅ Status bar:  U/D Scroll  L/R Prev/Next  R Reply  Ctrl-K Help  Q Quit
❌ Status bar:  U/D Scroll  PgUp/PgDn Page  L/R Prev/Next  R Reply  H Headers  X Delete  B Bookmark  T .txt  Q Quit
```

### Adding Actions to the Message Viewer

`TelnetUtils::runMessageViewer()` accepts `$extraKeys` mapping characters to action names and `$helpItems` for the Ctrl-K overlay:

```php
$helpItems = [
    ['key' => 'PgUp / PgDn', 'label' => $this->server->t('ui.terminalserver.message.help_page', 'Scroll one page', [], $locale)],
    ['key' => 'X',           'label' => $this->server->t('ui.terminalserver.netmail.help_delete', 'Delete message', [], $locale)],
];

$result = $shell->showMessageViewer(
    $conn, $state,
    $view['headerLines'], $view['wrappedLines'], $view['statusLine'],
    $state['rows'] ?? 24, 0, false, $kludgeLines, $buildView,
    $imageRefs, $imageFn,
    ['x' => 'delete'],  // $extraKeys
    $helpItems
);

switch ($result['action']) {
    case 'delete': $this->doDelete(...); break;
}
```

### Terminal Resize Handling

All full-screen UI must respond to resize events. The standard pattern is to snapshot dimensions before the key loop and compare after each read:

```php
$lastRows = $state['rows'] ?? 24;
$lastCols = $state['cols'] ?? 80;

while (true) {
    $key = $server->readKeyWithIdleCheck($conn, $state);

    $newRows = $state['rows'] ?? $lastRows;
    $newCols = $state['cols'] ?? $lastCols;
    if ($newRows !== $lastRows || $newCols !== $lastCols) {
        $lastRows = $newRows;
        $lastCols = $newCols;
        $rebuildLayout();
        $render();
    }
    // ... normal key handling ...
}
```

Layout-dependent variables must be recalculated on every resize. Capture them by reference (`&$var`) in closures so a single `$rebuildLayout()` call propagates the new dimensions without recreating closures.

`runMessageViewer()` handles resize internally via its `$rebuildFn` callback. Custom full-screen loops must implement the pattern above themselves.

### Full-Screen Editor Render Modes

`BbsSession::fullScreenEditor()` (the framed compose editor used when the terminal has >= 15 rows) does not repaint the whole screen per keystroke. Its `$renderEditor` closure takes a mode argument:

| Mode | Trigger | Output |
|---|---|---|
| `full` | entry, resize, return from `Ctrl+K` help, draft-save footer notice on/off, any non-ANSI session | `\033[2J` clear, borders, all body rows, separator, footer |
| `body` | Enter (line split), `Ctrl+Y` (delete line), any edit that changes the line count, any viewport scroll | repaint the body rows in place; borders/footer untouched |
| `line` | printable char / Backspace / Delete that leaves the line count and viewport unchanged | repaint only the cursor's row |
| `cursor` | arrow keys, Home/End, `Ctrl+A`, `Ctrl+E` | emit only a `\033[row;colH` move |

`line` and `cursor` self-upgrade to `body` when `$recalcView()` shifts `$viewTop` (the edit scrolled the view). Only `full` and `body` toggle the cursor off/on (`\033[?25l` / `\033[?25h`); `line` and `cursor` never touch cursor visibility, since that toggle is itself a flicker source. Edit handlers snapshot `count($lines)` before mutating and pass `count($lines) === $preLineCount ? 'line' : 'body'` — `wrapEditorLines()` only ever splits lines, so a changed count reliably means rows below shifted.

---

## Adding / Modifying Main Menu Actions

Menu actions are data-driven via the key map in `AppearanceConfig`. Every new action must touch all six of the following — missing any one leaves the system in an inconsistent state:

1. **`src/AppearanceConfig.php`** — add/remove the action ID and its default key in `DEFAULT_TERM_MENU_KEYS`.
2. **`telnet/src/BbsSession.php`** — four touchpoints in `BbsSession::handle()`:
   - Key variable: `$<action>Key = $menuKeys['<action>'] ?? null;`
   - `$keyToAction` foreach: add/remove `'<action>' => $<action>Key`
   - Label variable + display row: `$lbl<Action>` (from `ui.terminalserver.server.menu.<action>`) and a `menuItemCol` call in the correct section of `$rows`
   - Dispatch branch: `elseif ($action === '<action>') { ... }`
3. **`templates/admin/appearance.twig`** — add/remove the action in `TERM_MENU_KEY_DEFAULTS` and `TERM_MENU_KEY_LABELS` in the "Terminal Main Menu Keys" script block.
4. **`config/i18n/*/common.php`** — add/remove `ui.admin.appearance.term_menu_keys.action.<action>` in every locale.
5. **`config/i18n/*/terminalserver.php`** — add/remove `ui.terminalserver.server.menu.<action>` in every locale.
6. **`docs/TerminalServer.md`** — update the default key table in "Customizable Main Menu Keys".

The admin save route derives its valid action list from `array_keys(AppearanceConfig::DEFAULT_TERM_MENU_KEYS)` automatically — no route change needed.

When you add a main-menu action, also add a matching descriptor to
`TerminalActionCatalog::descriptors()` (see below) so a declarative navigation
definition can bind to it, and bind it in `DeclarativeMenuBridge`.

---

## Declarative Navigation Framework

`src/Terminal/Navigation/` is a generic, capability-aware layer that lets a
sysop compose the terminal menu as data. It is **opt-in** and additive — with no
`config/terminal_navigation.json` and `TERMINAL_NAV_RUNTIME` unset, the built-in
menu loop in `BbsSession::handle()` runs exactly as before. See
[TerminalNavigationFramework.md](TerminalNavigationFramework.md) for the sysop
guide and schema.

| Layer | Class(es) | Role |
|-------|-----------|------|
| Model | `NavigationDefinition` / `NavigationNode` / `NavigationItem` / `ActionReference` / `PresentationHints` | Pure parsed structure (schema v1, JSON). No sockets, no session, no DB. |
| Access | `AccessExpression` / `AccessContext` | Safe declarative ACS: bare predicates (`authenticated`, `admin`, `feature:<x>`, `action:<x>`, `capability:<x>`, `env:<x>`) composed with `all`/`any`/`not`. No eval, unknown predicates fail closed. Limited to state the platform actually has (binary `is_admin` + guest + feature flags). |
| Actions | `ActionRegistry` / `TerminalAction` / `TerminalActionCatalog` | The config→PHP boundary. Config names an id only; the registry maps it to metadata (validation/preview) and, separately at runtime, to a bound callable. |
| Loading | `NavigationDefinitionLoader` / `NavigationValidator` / `ValidationError` / `NavigationLoadResult` / `NavigationConfig` | Parse + full semantic validation (unknown actions, hotkey conflicts, dangling/self submenus, cycles, unreachable nodes, missing fallbacks). An invalid definition is never returned; the runtime falls back to legacy. `NavigationConfig` owns the double gate. |
| Screen model | `NavigationScreenModel` / `NavigationScreenItem` / `NavigationPath` / `NavigationScreenBuilder` | `definition → validation → access/action resolution → screen model → renderer → TerminalRenderContext → OutputSink`. Socket-free; constructible in tests/preview. Hidden items are absent (no dangling hotkeys); disabled items are present but non-selectable. An optional badge resolver (3rd builder arg, `callable(string):?string`) resolves each visible-and-enabled item's `presentation.badge` signal to a short live-status string. |
| Rendering | `NavigationRenderer` (interface) / `NavigationScreenRenderer` / `NavigationLineRenderer` | `NavigationScreenRenderer` is the canonical composer for the live session, tests, and preview — differs only in sink and context. Composes sections from `group`, inline-or-status-line descriptions, a bounded/positioned column, and a dim ` · <badge>` suffix. `composeRegions()` renders the themed MENU as a full-width *directory* (hotkey · uppercase name · purpose column · right-aligned live badge · section signs · full-width selection bar) and a two-line FOOTER (ambient activity summary from existing badge annotations + key hints). `NavigationLineRenderer` is the line-shell projection. |
| Presentation theme | `NavigationTheme` / `NavigationThemeLoader` / `NavigationThemeConfig` / `TemplateArtSanitizer` / `ThemedNavigationRenderer` / `AnsiScreenBuffer` / `NavigationRendererFactory` | Optional. `config/terminal_theme.json` (schema 1: one `80x24` geometry, `MENU` + `FOOTER` rectangles) points at a trusted `telnet/screens/<token>.ans`. `TemplateArtSanitizer` strips everything but SGR + printable text and converts charset (CP437/SAUCE aware). `ThemedNavigationRenderer` decorates `NavigationScreenRenderer` — paints the template, positions `composeRegions()` output into the rectangles — and **falls back to it per frame** for any non-themed geometry, ASCII/mono terminal, missing/unsafe template, or validation failure. ANSI is presentation only: no structure, actions, ACS, or scripting. `AnsiScreenBuffer` flattens the positioned output to a grid for the F6 browser preview. |
| Runtime | `NavigationRuntime` | The interactive loop, driven by injected callables (no telnet/SSH dependency): hotkeys + arrow/lightbar + nested Back/Home, action invocation, `quit`/`back_at_root`/`disconnect` exit reasons, rebuild-every-iteration for NAWS reflow. |
| Live context | `telnet/src/DeclarativeMenuBridge::liveBadgeResolver()` | Maps board-agnostic signal names (`callers_online`, `experiences_active`) to text from `Auth::getOnlineSessions()` (the Who's Online source). One presence snapshot, cached ~8s per session, shared by every signal — the lightbar never triggers a query. Any failure yields no badges. |
| Preview | `NavigationPreviewService` / `NavigationPreviewProfile` | Deterministic off-session render at arbitrary geometry/charset/colour/access into a `BufferSink`. `render()` = flowing content only; `renderReport()` = the live renderer selection (themed where the geometry is themed, flowing otherwise) plus `{mode, reason, lines}` where `lines` is the `AnsiScreenBuffer`-flattened grid. `standardProfiles()` = the R5I matrix. |
| Bridge | `telnet/src/DeclarativeMenuBridge` | The only glue into `BbsSession`: builds the registry + bindings from the live handlers, the `AccessContext` from `$state`, and runs `NavigationRuntime`. Any error falls back to the legacy menu. |
| Write boundary | `NavigationConfigWriter` / `NavigationWriteResult` + admin-daemon `save_terminal_navigation_config` | Validated, path-constrained, atomic (temp + fsync + rename) write of a definition. The future editor's save path; no route/UI yet. See [TerminalNavigationFramework.md](TerminalNavigationFramework.md#saving-a-definition-backend). |

The one-renderer invariant holds: `NavigationScreenRenderer` reuses
`TerminalRenderContext` (glyphs, charset, colour) and shares no parallel layout
stack. It is a new *screen type* on the shared seam, like `TerminalBoxRenderer`.
`ThemedNavigationRenderer` does not break it — it owns *placement* only and
delegates every line of content back to `NavigationScreenRenderer::composeRegions()`.

---

## Unified newscan ("What's New")

The `newscan` action (`telnet/src/NewscanHandler`) answers *"what's new for me?"*
by composing canonical read state — it introduces no new read-state table, no
shadow pointer, and no migration.

| Piece | Role |
|-------|------|
| `src/Newscan/UnifiedNewscanService` | Transport-agnostic planner. `plan(array $user, array $opts): NewscanPlan` runs **SELECTs only**. Netmail unread via `MessageHandler::getNetmail(..., 'unread', 'date_asc')` (recipient predicate reused, never re-derived). Echomail "new" = a two-phase pass: phase 1 finds subscribed areas with messages above `user_echoarea_subscriptions.last_read_id` (cheap `idx_echomail(echoarea_id, id)` range scan), phase 2 pulls the bounded, oldest-first id list per candidate area with `em.id > watermark` + a `message_read_status` anti-join + the web's ignore / moderation filters. Bulletins = a count only. QWK is not a source. The four data-access methods are `protected` for deterministic fixture tests. |
| `src/Newscan/NewscanPlan` / `NewscanArea` | Immutable result — netmail ids, per-area new-message ids, bulletin count, `truncated` flag. Building/reading them writes nothing. |
| `telnet/src/TerminalMessageQueueViewer` | Steps a flat cross-source queue, opening each message through the extracted single-message seam and mapping its exit action (quit / prev / next / reply …) to queue navigation. No rendering, no read state of its own. |
| `EchomailHandler::viewSingleMessage()` / `NetmailHandler::viewSingleMessage()` | The single-message read loop (viewer + scroll + reply + save + forward + delete + help), extracted from `displaySearchMessage()` / `displayMessage()` so the newscan and the existing search / list flows share one reader. Opening a message marks it read through the same `GET /api/messages/{type}/…` path the web uses. In `newscan` context the fetched message backfills the header fields, so the queue only needs `{id}` (+ `{echoarea, echoarea_domain}` for echomail). |
| `NewscanHandler` | Orchestration only: summary screen, per-phase interstitials (`Read` / `Skip` / `Quit scan`, plus `Catch up` per echomail area — a deliberate mark-read through `POST /api/messages/echomail/read`, the canonical bulk-read endpoint), bulletin hand-off to `BulletinsHandler::showUnread()`. |

**Read-state contract:** planning and the summary write nothing; a message is
marked read only when actually opened; quitting or disconnecting mid-scan leaves
unopened messages new; skipping an area or the scan marks nothing; re-entry
rescans whatever is still new (canonical state is the only pointer). "New" for
echomail is the watermark (`echomail_badge_mode = 'new'` semantics) — messages
older than the caller's last read in an area are considered seen, exactly like
the classic BBS newscan pointer and the default dashboard badge.

---

## Data Access

The terminal server is a trusted first-party component. Code under `telnet/src/` and `ssh/` may access the application's shared classes and database, but must respect clear boundaries.

### Choosing the Access Path

| Situation | Use |
|-----------|-----|
| Consuming an existing API-shaped feature (user-facing flows that should behave the same as the web) | `TelnetUtils::apiRequest()` |
| Sharing transport-agnostic business logic or domain services | Direct class reuse from `src/` |
| Simple internal read or small internal query where an API would add unnecessary complexity | Direct PDO: `$db = Database::getInstance()->getPdo()` |

Do not bypass important business rules. If validation, permissions, side effects, or invariants already live in a shared service or domain class, use that logic rather than reimplementing with ad hoc SQL.

Terminal code must not depend on web controllers, route handlers, Twig/view helpers, form handlers, middleware, or any code that assumes a browser-driven HTTP request lifecycle.

> **Note on `apiRequest()` and latency.** The daemon's API base is the site's
> public URL, so every `apiRequest()` is a full round trip out to the edge
> (Cloudflare) and back, with a fresh TLS handshake — measured floor ~230 ms,
> tail > 1 s. Where a route is a thin adapter over a canonical `src/` service
> (`MessageHandler::getEchomail()` / `getNetmail()` / `getMessage()`), the
> terminal calls the service directly instead: the message list pages
> (`EchomailHandler`/`NetmailHandler::fetchMessagesPage()`) and message detail
> (`TerminalMessageService::echomailDetail()` / `netmailDetail()`, which
> reproduce the routes' REPLYTO enrichment, activity tracking and 404 envelope
> exactly). `apiRequest()` stays the path for routes that carry real
> controller-side validation not yet factored into a service
> (`/api/user/terminal-mail-state`, `/api/echoareas`) and for mutations.

### `TelnetUtils::apiRequest()` — Response Structure

Always returns:

```php
[
    'status' => $httpCode,   // int HTTP status code
    'data'   => $parsedBody, // decoded JSON body — never the root array
    'error'  => null|string,
]
```

The parsed JSON body is always one level deep under `['data']`:

```php
// WRONG — 'messages' is in the JSON body, not at the return level
$messages = $response['messages'] ?? [];

// CORRECT
$messages = $response['data']['messages'] ?? [];
```

### CSRF Tokens

Every POST, PUT, PATCH, or DELETE call via `apiRequest()` must pass the CSRF token as the 7th argument. Omitting it causes a 403 at runtime with no write-time error:

```php
// CORRECT
TelnetUtils::apiRequest($base, 'POST', '/api/some/endpoint', $payload, $session, 3, $state['csrf_token'] ?? null);
TelnetUtils::apiRequest($base, 'DELETE', '/api/some/endpoint', null, $session, 3, $state['csrf_token'] ?? null);

// WRONG — silent 403
TelnetUtils::apiRequest($base, 'POST', '/api/some/endpoint', $payload, $session);
```

GET requests do not need the token.

**Stale-token self-heal.** The CSRF token is stored **per user** and rotated on
every login, so a long-lived terminal/SSH session's cached `$state['csrf_token']`
can be silently invalidated by a later authentication of the same user
(another terminal/SSH/web/QWK session). `BbsSession` seeds
`TelnetUtils::setCsrfToken()` at login, and `apiRequest()` keeps that
session-authoritative value current: when a mutating call returns `403` with
`error_code == errors.auth.invalid_csrf_token`, it fetches the session's live
token from `GET /api/auth/csrf` (read-only, gated on the terminal secret) and
retries the original request **once**. This never bypasses validation — the
server still checks every request against the live token. Callers keep passing
`$state['csrf_token'] ?? null`; the healed static takes precedence, so one heal
fixes every subsequent call in the session.

The browser has the same exposure — the `<meta name="csrf-token">` value is
frozen at page render, so reconnecting a terminal (or a second browser login)
invalidates an open web page's token. The global `fetch()` wrapper in
`public_html/js/app.js` self-heals identically via `GET /api/auth/web-csrf`
(read-only, session-gated rather than secret-gated, since the browser is the
legitimate caller here).

### Daemon-Side Logging

Self-contained daemon/runtime subsystems (e.g. ZMODEM under `telnet/src/`) may use targeted `error_log(..., 3, $file)` file logging. Web-side and shared application logging must use `BinktermPHP\Binkp\Logger` instead — never `error_log()` in route or controller code.

---

## i18n in the Terminal Server

Terminal handlers use the same key-based i18n as the web interface. Translation keys live in `config/i18n/<locale>/terminalserver.php` (terminal-specific) and `config/i18n/<locale>/common.php` (shared). Access them via `$this->server->t(...)` or `$server->t(...)`:

```php
$label = $this->server->t('ui.terminalserver.netmail.inbox_title', 'Inbox', [], $state['locale'] ?? 'en');
```

When adding any new user-visible string in a handler:

1. Add the key to every locale's `terminalserver.php` (or `common.php` if it is shared with the web).
2. Use `$server->t(key, fallback, params, locale)` — never hardcode an English string inline in terminal output.
3. Run `php scripts/check_i18n_syntax.php --locale=<locale>` after editing any catalog file.

---

## API Endpoints Used

The terminal server uses `TelnetUtils::apiRequest()` for most operations. A subset of direct internal calls are made for performance-critical paths: session validation (`Auth`), login activity tracking (`ActivityTracker`), nodelist presence check, feature flags (`BbsConfig`, `BinkpConfig`), and system news (`AppearanceConfig`).

Primary endpoints used by `BbsSession` and the core handlers:

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/api/auth/login` | POST | User authentication |
| `/api/auth/logout` | POST | Session logout |
| `/api/config/session-init` | GET | Post-login user settings (timezone, locale, date format), terminal settings (charset, ANSI color), idle timeout thresholds, and main menu key bindings |
| `/api/messages/netmail` | GET | List netmail messages (`filter=all` for inbox, `filter=sent` for sent folder; `sort=date_desc\|date_asc\|subject\|author`) |
| `/api/messages/netmail/{id}` | GET | Get netmail message details (includes `is_saved` flag) |
| `/api/messages/netmail/{id}/save` | POST | Bookmark (save) a netmail message |
| `/api/messages/netmail/{id}/save` | DELETE | Remove bookmark from a netmail message |
| `/api/messages/netmail/send` | POST | Send netmail message |
| `/api/messages/netmail/{id}/forward-email` | POST | Forward netmail to the logged-in user's email address |
| `/api/messages/echomail` | GET | List echomail messages |
| `/api/messages/echomail/{id}` | GET | Get echomail message details (includes `is_saved` flag) |
| `/api/messages/echomail/{id}/save` | POST | Bookmark (save) an echomail message |
| `/api/messages/echomail/{id}/save` | DELETE | Remove bookmark from an echomail message |
| `/api/messages/echomail/{id}/download` | GET | Download echomail message as plain text (used by `T` key in viewer) |
| `/api/messages/echomail/{id}/forward-email` | POST | Forward echomail to the logged-in user's email address (used by `E` key in viewer) |
| `/api/messages/echomail/post` | POST | Post echomail message |
| `/api/messages/echomail/ignore-rules` | POST | Create an echomail ignore rule (used by `G` in viewer) |
| `/api/user/echomail-ignore-rules` | GET | List the user's echomail ignore rules (used by `G` on echoarea list) |
| `/api/user/echomail-ignore-rules/{id}` | DELETE | Delete an echomail ignore rule |
| `/api/dashboard/stats` | GET | Main menu dashboard widgets (unread counts, online users, bulletins, credits, Crossroads signal) |

All API requests include cookie-based session management, automatic retry with exponential backoff, and optional SSL certificate verification. Additional endpoints are called by individual feature handlers — see each `*Handler.php` class and the full [API Reference](API.md).

**Crossroads main-menu signal:** `/api/dashboard/stats`'s `crossroads` field is computed server-side by reusing `\BinktermPHP\Crossroads\DashboardPulse::compose()` — the same reducer behind the authenticated web dashboard's Crossroads pulse card — scoped to the terminal's authorized catalog (`ExperienceState::getExperienceStates($user, 'terminal')`). The route strips it down to only what the terminal needs (an aggregate headcount for `others`, or an Experience name for `recent_self`; never usernames or session rows) and returns `null` for every other state. `MailUtils::getDashboardStats()` passes the field through unchanged; `BbsSession::composeCrossroadsSignalLine()` is the pure formatter (mirrors `DoorHandler::composeRecentFootprints()`'s testable shape) that turns it into the localized line rendered in both `renderDashboardSidebar()` and `renderDashboardBottomBar()`.

### Client IP forwarding

The daemon proxies every user's API traffic through the server, so without help the web side records the server's own address as each terminal session's IP. `BbsSession::run()` calls `TelnetUtils::setClientContext($peerIp, TERMINAL_REGISTRATION_SECRET)` once per forked session; `TelnetUtils::apiRequest()` (and the `BbsSession` / `DoorHandler` curl paths, via `TelnetUtils::clientContextHeaders()`) then attach `X-Binkterm-Client-IP` and `X-Binkterm-Client-Token` to every request. Web-side, `Auth::resolveClientIp()` returns that address instead of `REMOTE_ADDR` when the token matches, and it is used for session-row IP attribution and registration screening. `$peerIp` is already the real end user for PubTerm sessions (resolved from the PROXY header in step 1).

---

## Related

- [TerminalServer.md](TerminalServer.md) — User-facing feature reference and sysop configuration
- [telnet/CLAUDE.md](../telnet/CLAUDE.md) — Tactical rules for AI-assisted development in this subsystem (include list rules, widget table, CSRF/data-access rules)
- [TelnetServer.md](TelnetServer.md) — Telnet daemon setup and transport-specific details
- [SSHServer.md](SSHServer.md) — SSH daemon setup
- [API Reference](API.md) — Full HTTP endpoint reference
