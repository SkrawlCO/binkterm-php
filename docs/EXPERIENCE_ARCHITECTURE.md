# BinkTerm Experience Architecture

## Purpose

BinkTerm's Experience architecture provides a common platform model for
interactive experiences without requiring those experiences to share the same
implementation technology.

An Experience may be backed by a classic BBS door, a native application, a
browser application, an emulator, a remote service, or another implementation
added in the future.

The Experience layer is not a replacement for those backends. It provides the
common BinkTerm-facing abstraction above them.

## Design Direction

The long-term goal is for Experiences to become the common integration layer
for interactive BinkTerm features.

Backend-specific systems remain responsible for the behavior they implement,
including installation, configuration, runtime management, and launch
mechanics.

The Experience layer provides a normalized representation that higher-level
BinkTerm systems can consume without needing to understand every backend.

This allows BinkTerm to evolve beyond separate collections of "doors",
"web games", and emulator integrations while preserving compatibility with
classic BBS software and workflows.

## Experience Catalog

`BinktermPHP\GameCatalog` currently provides the unified catalog.

Despite its historical class name, its responsibility is now broader than a
traditional game catalog: it discovers playable Experiences and normalizes
backend-specific metadata into the common Experience representation.

Current backend families include:

- DOS doors
- native doors
- WebDoors
- JS-DOS experiences

Backend managers and manifests remain authoritative for installation,
configuration, and launch behavior.

## Experience Contract

The normalized Experience representation includes common information such as:

- identity and display metadata
- category
- backend information
- capabilities
- presentation surfaces
- presentation metadata
- policy
- terminal relay mode for managed doors
- source metadata

Managed DOS and native Experiences expose terminal relay behavior through the
normalized `terminal.mode` field. Its value is `raw` only when the managed door
manifest explicitly requests raw terminal handling; otherwise it is `doorway`.
Presentation clients must consume this normalized field rather than reaching
into backend manifest structure.

Compatibility fields are also currently retained where existing BinkTerm code
still expects the older game/door representation.

Those compatibility fields allow migration toward the Experience architecture
without requiring a disruptive rewrite of existing launch and presentation
code.

## Presentation Surfaces

Experiences explicitly describe their availability on BinkTerm presentation
surfaces.

Current surface states are:

- `full` — the Experience is currently available on that surface
- `planned` — the Experience is not currently available there, but an
  equivalent or appropriate experience is intended
- `unavailable` — the Experience is intentionally not exposed on that surface

The initial presentation surfaces are:

- `web`
- `telnet`

Additional surfaces may be represented in the future without changing the
identity of the underlying Experience.

Catalog discovery is distinct from surface runnability. The catalog includes
only Experiences that are enabled, satisfy backend requirements, and are
authorized for the current user. An included Experience may still declare its
requested surface as `planned` or `unavailable`; only `full` produces a static
launch action for that surface. Backend launch paths remain authoritative for
dynamic rules such as credits, capacity, and active-session handling.

Explicit discovery controls remain authoritative over cross-surface parity.
For example, a managed door configured with `hide_from_web` is omitted from the
web catalog entirely rather than disclosed as an unavailable Experience.

`ExperiencePresentation` is the shared, backend-independent read model for web
and terminal presentation consumers. It composes normalized `GameCatalog`
metadata with optional `ExperienceState` and viewer participation data. It does
not discover Experiences, authorize users, resolve dynamic runtime policy,
launch sessions, or mutate participation, presence, or activity.

The presentation model keeps visibility, static surface support, and runtime
availability distinct. Its Play/Return/End fields describe presentation state;
backend routes and session managers remain authoritative for credits, capacity,
authorization, and the final launch or termination decision. Runtime counts are
nullable when no state snapshot was supplied, allowing the same model to serve
catalog-only and lobby/detail consumers without fabricating live state.

`ExperienceState` is the shared bulk read-side view of current sessions,
players, and public presence. Collection pages should request state once for
the authorized catalog rather than issue one state query per card.

`ExperienceParticipation` interprets that state for the current viewer using
numeric user identity. It owns the normalized semantics of Play, Return, and
End actions, including explicit participation termination; it does not replace
backend session ownership. It also exposes two pure, transport-agnostic
predicates used to compose arrival surfaces: `findViewerPlayer()` (does the
numeric viewer identity have an active session here?) and
`hasDistinctOtherPlayer()` (is at least one distinct account other than the
viewer active here?). Multiple sessions held by one account collapse to one
person in both. `ExperienceLaunch` resolves static surface support and
canonical launch targets. A resolved target means that presentation may offer
an action, not that launch is guaranteed: backend launch and session routes
remain authoritative for runtime authorization, credits, capacity, and session
creation.

### Authenticated web arrival ("Crossroads")

The web `/games` route presents this model as **Crossroads** — the human-facing
identity of the place that contains the Experiences. "Experience" remains the
domain term for the things within it. The arrival answers, in order: who is
around, where do I belong, who has been through recently, and what else is
here. It uses one collection-level `ExperienceState` read to derive two
optional present-tense sections plus one bounded historical read, then renders
the complete authorized web catalog under Experiences. Hidden entries remain
absent because composition occurs only after `GameCatalog` applies web
discovery policy. The Community Scoreboard remains a separate score-oriented
view and must not be treated as popularity metadata.

The sections render in this order, with distinct membership rules:

- **Live Now** — "where are other people?" Contains every visible, authorized
  web Experience for which `hasDistinctOtherPlayer()` is true against the
  numeric viewer identity. Viewer-only occupancy never qualifies. When empty it
  renders nothing; the one-line "Around the Crossroads" presence summary above
  the sections carries the single truthful quiet state.
- **Your Places** — "where am I participating?" Contains every visible,
  authorized web Experience for which `findViewerPlayer()` is non-null. Viewer-
  only occupancy qualifies. Membership is independent of whether Return is
  currently launchable (`ExperiencePresentation` owns that), and Return takes
  priority over Play. Omitted entirely when empty.
- **Live Now and Your Places overlap intentionally.** An Experience the viewer
  shares with another distinct caller satisfies both questions and appears in
  both sections; the two lists are populated by independent checks, not an
  either/or. This is a semantic decision, not accidental duplication — if the
  composition later reads as too repetitive it is solved as a presentation
  problem, not by narrowing membership.
- **Recently in the Crossroads** — "who has been through recently?" A handful
  (at most five, newest first) of play footprints drawn only from the existing
  play activity already visible in authorized Experience lobbies
  (`ActivityTracker` types `webdoor_play` / `dosdoor_play`), composed by
  `ExperienceActivity::recentAcrossCatalog()`. It is **authenticated-only** and
  never appears on the anonymous `/crossroads` window. The section has no
  subtitle — the heading and the rows carry the meaning. Every row is filtered
  through the viewer's own authorized `GameCatalog` result, so activity for a
  hidden, admin-only, disabled, removed, or renamed/orphaned Experience simply
  never resolves; the current catalog name is shown, not the stale snapshot.
  System users (e.g. `_guest`) are excluded, and rows whose user has been
  deleted are dropped rather than shown as "Unknown user".
  For arrival-page composition only, repeated plays by the **same user in the
  same Experience** collapse to that pair's single newest footprint, and the
  five newest distinct `(user, Experience)` pairs are returned — a purely
  structural rule, **not** time-window de-duplication. The same user may appear
  for different Experiences; different users may appear for the same Experience.
  `ExperienceActivity::recent()` for individual Experience detail is untouched
  and keeps its raw activity semantics. Distinct-pair selection happens in SQL
  before the five-row limit, so the result is always the five newest distinct
  footprints, never five raw rows deduped down afterward. First-play status is
  derived from the **full** matching history alongside a recency window in the
  same query (no N+1): the surviving footprint renders "first played" only when
  it is genuinely that user's first-ever recorded play, and ordinary "played"
  when older plays exist even though they collapsed out.
  It is **historical evidence, not live presence** — "quiet now" does not mean
  the place is dead. It carries no "since your last visit" semantics, no session
  duration, no leave/logout time, and no surface label, because the underlying
  activity data does not record those as lifecycle truth. A door-play footprint
  means only that the user entered or returned to the Experience (see
  **Door-play activity semantics** below); the section does not label a row as
  launch versus return even though both are now recorded. Hidden entirely when
  there is nothing to show — it is not an empty-state card, and it is not a feed.
  The authenticated **telnet** Crossroads arrival mirrors this section between
  Your Places and the Experiences catalog: the same `recentAcrossCatalog()`
  semantics and five-row cap, scoped to the viewer's authorized *telnet* catalog
  (`$doorList`, so a Web-only Experience never leaks a footprint), rendered as a
  terminal-native non-selectable block that consumes no menu number. See
  `docs/TerminalServerDevGuide.md`.
- **Experiences** is the complete visible, authorized web inventory, including
  every entry already shown in the contextual sections. Its browser-side
  filters narrow only these already-authorized rendered entries; they do not
  rediscover Experiences, alter catalog order, or affect the other sections.
- **Community Scoreboard** reports submitted scores for the selected month.
  Scores are not evidence of current occupancy or popularity. Viewer-facing
  scoreboards and leaderboards use the requested surface's authorized catalog
  membership as their visibility boundary. They must not expose an Experience
  through historical activity when discovery policy hides it from that viewer.
  Filtering affects presentation and score submission authorization only;
  historical score records remain stored.

### Door-play activity semantics

Door-play activity records successful Experience entry/return and is historical
event data, independent of presence and session continuity.

A `user_activity_log` row of type `webdoor_play` / `dosdoor_play`
(`ActivityTracker::TYPE_WEBDOOR_PLAY` / `TYPE_DOSDOOR_PLAY`) means exactly:

> The user entered or returned to this Experience.

It does **not** imply that a new runtime or session was created, that the user
completed anything, or anything about duration, score, progress, current
presence, or resumability. Every managed door-play write site records the
footprint — the fresh managed launch **and** the resume branch in
`routes/door-routes.php`, the JS-DOS session endpoint, and the WebDoor
get-or-create session endpoint in `routes/webdoor-routes.php`.

All of those routes are re-hit on a browser reload, bfcache restore, or double
request, so a single genuine entry can arrive several times within seconds.
`BinktermPHP\Crossroads\DoorPlayActivity::record()` is the one shared contract
for these writes: a footprint for the same
`(user_id, activity_type_id, object_name)` written within
`DoorPlayActivity::DEDUP_WINDOW_SECONDS` (60 seconds) is not repeated. A
deliberate later return, outside that window, records normally. The suppression
is scoped to door-play only — it does not touch `ActivityTracker::track()` and no
other activity type is affected; `webdoor_play` and `dosdoor_play` never suppress
one another, and activity for a different Experience or a different user is never
suppressed.

This write-time storm guard is distinct from the purely structural
distinct-`(user, Experience)`-pair collapsing that
`ExperienceActivity::recentAcrossCatalog()` applies at read time for
arrival-page composition — that read behavior is unchanged.

### Dashboard Crossroads pulse

The authenticated dashboard (`/`) carries a small, optional **Crossroads pulse**
card — a truthful glimpse that Crossroads exists and has continuity, so the
arrival is not silent about community life. It is deliberately not a catalogue:
no card grid, filters, scoreboard, occupancy/capacity numbers, credit costs,
bare online count, realtime, or new endpoint.

It is a `DashboardCardRegistry` card (`id: crossroads`, main zone, optional,
hideable/reorderable) gated on the same conditions as the authenticated
Crossroads navigation link. The `/` route composes it — only when the card is
available and the viewer has not hidden it — from **one**
`ExperienceState::getExperienceStates($user, 'web')` read plus **two** bounded
activity reads on that same authorized catalog:
`ExperienceActivity::recentAcrossCatalog(…, 1)` (community) and
`ExperienceActivity::recentForUser(…, $userId, 1)` (the viewer's own newest
footprint). These are reduced by the pure
`BinktermPHP\Crossroads\DashboardPulse::compose()` view model. It shows exactly
one state, in priority order:

1. the viewer's own active participation — rendered with a **Return** button;
2. else distinct other people currently participating — at most three
   `{username} is playing {Experience}` rows, the viewer never counted as
   another;
3. else **the viewer's own most-recent authorized play footprint** — rendered
   `You played {Experience} {relative time}`, day-level relative wording from
   the shared `time.*` ladder;
4. else the community's newest authorized recent footprint;
5. else a quiet line.

Every row links to the canonical `/experiences/{id}` lobby, and every state
offers `Enter the Crossroads` → `/games`. Authorization and naming come entirely
from the authorized `getExperienceStates()` snapshot and the two activity reads
(which filter `object_name` against that same catalog), so a hidden, admin-only,
disabled, unauthorized, or renamed Experience never surfaces — neither in
community activity nor in the viewer's personal footprint. No new table,
endpoint, cache, or realtime mechanism; historical rows are untouched.

State 3 is **historical personal relationship only**. It must never imply
current participation, resumability / Return, saved progress or a persisted
character, current presence, session duration, or completion — the composed
view model carries none of those fields and the partial renders no Return
control and no present-tense wording.

#### Four distinct personal-continuity concepts

The Crossroads surfaces keep these separate on purpose:

| Concept | Question | Surface | Meaning |
| --- | --- | --- | --- |
| **Current participation / Your Places** | *Where am I active right now?* | lobby "Your Places", dashboard pulse state 1 | The viewer has a live session or viewer-occupancy in the Experience now. Return may be offered. |
| **Session continuity / Return** | *Can I rejoin the runtime I left?* | `ExperiencePresentation` Return affordance | A managed runtime is still alive and reconnectable. Owned by presence + session lifetime, not by activity history. |
| **Historical personal relationship** | *What did I last play?* | dashboard pulse state 3 (`You played …`) | The viewer entered or returned to the Experience at some past time. No live runtime, presence, or progress is implied. |
| **Community recent activity** | *Has anyone been through lately?* | lobby "Recently in the Crossroads", dashboard pulse state 4 | Truthful historical evidence that the place is used, collapsed to distinct `(person, Experience)` pairs. Not live presence. |

## SysOp Configuration and Customization

Slice-level Experience behavior is core BinktermPHP functionality. Individual
Experience content and availability remain backend-owned. SysOps add, enable,
and configure Experiences through the existing backend manifests and Admin
interfaces documented in [DOS Doors](DOSDoors.md), [Native Doors](NativeDoors.md),
[WebDoors](WebDoors.md), and [JS-DOS](JSDOSDoors.md). Those systems supply names,
descriptions, artwork, requirements, policy, and backend launch configuration;
this shared layer normalizes their data for presentation.

Shipped translation catalogs provide reusable defaults. Site-specific wording
should use **Admin → BBS Settings → Language Overrides**, which stores overlays
under `config/i18n/overrides/<locale>/<namespace>.json`. This avoids editing
base catalogs that may be replaced by a future BinktermPHP upgrade.

For upgrade-safe layout customization, template resolution checks
`templates/custom/` before `templates/shells/<activeShell>/` and then
`templates/`. A local replacement can therefore customize presentation without
editing the shipped template. Custom templates should consume normalized
Experience and `ExperiencePresentation` fields. They must not hard-code
Experience IDs, backend manifest structure, backend launch URLs, catalog
contents, or site-specific player assumptions.

Core code owns discovery, normalized state, participation semantics, static
launch resolution, and reusable presentation defaults. SysOp overrides own
optional wording and layout changes. Branding, artwork, enabled integrations,
and local content remain site-specific.

## Web and Classic BBS Parity

BinkTerm Modern is intended to expand what a BBS can provide without abandoning
the classic terminal BBS.

Web capabilities may advance ahead of terminal capabilities when the browser
makes new functionality practical or enables richer presentation.

However, new BinkTerm features should provide an equivalent telnet/terminal
experience wherever reasonably possible.

Parity does not require identical interfaces.

The web interface may use graphical controls, richer media, larger layouts,
and browser-specific capabilities while the terminal interface uses ANSI,
keyboard navigation, text-oriented interaction, and traditional BBS
conventions.

The goal is equivalent participation in the BBS ecosystem rather than visual
identity between surfaces.

## Shared Policy

Discovery and launch should use the same capability and requirement rules.

For WebDoors, `BinktermPHP\WebDoorSupport` now owns shared platform capability
and manifest requirement evaluation.

This prevents the catalog from advertising an Experience that the launch path
would subsequently reject because the two paths used different availability
rules.

Additional backend-independent policy should move toward shared Experience
services when appropriate rather than being duplicated by individual
presentation routes.

## Anonymous Experience Discovery

BinkTerm can expose a read-only Experience discovery surface to logged-out
visitors so a prospective member can see what lives on the BBS before creating
an account.

This is **opt-in and default-off**, controlled by the
`anonymous_experience_discovery` feature flag in `bbs.json`
(`BbsConfig::isAnonymousExperienceDiscoveryEnabled()`). When the flag is off the
public route responds as not-found and does not advertise its own existence.

What the anonymous surface may contain:

- Experience identity and display metadata (name, description, category,
  artwork), multiplayer capability, cost/free state, surface availability, and
  maximum capacity.
- **Aggregate occupancy only**: a per-Experience active boolean, session count,
  and distinct-player count, plus a site-wide distinct count of people currently
  active in any Experience.
- Viewer-neutral status: `available`, `at_capacity`, `planned`, or
  `unavailable`.

What it must never contain:

- Any member identity — username, display name, user id, profile link.
- Any roster (`players[]`), per-user presence string, session id, node, or
  session timestamp.
- Recent activity history or scoreboard identities.
- Conversation.
- Viewer participation state, or Play / Return / End authority.
- A launch target, launch URL, or launch token.
- `source` / raw backend manifest / raw backend id.

The presentation projection is owned by `ExperiencePresentation::buildPublic()`,
which composes the same normalized metadata as `build()` but guarantees the
boundary above — no viewer is ever supplied, participation actions are
hard-coded false, and a viewer-specific status can never be produced. The
aggregate state read is `ExperienceState::getPublicExperienceAggregates()` (and
the companion `getPublicActivePeopleCount()`), which build no roster and perform
no `users` join.

The initial implementation is a **server-rendered snapshot** with no polling,
no anonymous state API, and no realtime. The authenticated Experience state API
(`/api/experiences/{id}/state`), the lobby (`/experiences/{id}`), the library
(`/games`), and every launch/participation/chat route remain authenticated and
unchanged.

The discovery route path and any community-specific framing (for example the
name "Crossroads") are presentation concerns. The route serves a generic
BinkTerm capability; SysOps can relabel the surface through the standard
language-override mechanism without changing the platform.

## Current Foundation

Experience foundation v1 establishes:

1. A normalized Experience representation.
2. Unified discovery through `GameCatalog`.
3. DOS/native manager-backed Experience discovery.
4. WebDoor Experience discovery.
5. JS-DOS Experience discovery.
6. Explicit web/telnet surface states.
7. Shared WebDoor requirement evaluation.
8. Web `/games` discovery driven by the unified catalog.
9. Compatibility with existing launch routes and presentation templates.
10. A foundation for terminal and web interfaces to consume the same logical
    Experience inventory.

## Validation

The initial Experience foundation was validated against the running L33Test
BinkTermPHP environment.

Automated validation included:

- `GameCatalogTest`
- `WebDoorSupportTest`
- 12 tests passed
- 429 assertions passed

A broader `tests/Unit` sweep was also run. It exposed unrelated failures in
`ArtFormatDetectorTest` and `I18nCatalogTest`. The Experience branch does not
modify those classes, tests, or i18n configuration paths, so those failures
are outside the Experience foundation checkpoint.

Web validation confirmed that the unified `/games` catalog rendered normally
and representative Experiences from the available backend families launched
successfully.

Terminal validation confirmed that the `Games & Experiences` interface
rendered normally and that the displayed Experiences launched successfully.

The terminal validation included:

- BBSLink
- DoorParty
- Green Dragon
- Lateania
- Usurper Reborn

This demonstrates that the Experience abstraction can support both modern web
presentation and the classic BBS terminal experience without requiring either
surface to imitate the other.

## Relationship to late.sh

late.sh integrations remain separate systems with their own architecture and
authoritative persistence.

BinkTerm's Experience architecture describes how those experiences participate
in the BinkTerm ecosystem.

The previously completed BinkTerm/late.sh identity bridge remains responsible
for identity transport and account resolution where applicable.

Experience metadata must not replace or bypass the established late.sh identity
boundary.

## Architectural Principles

1. An Experience describes what the user can participate in, not how its
   backend happens to be implemented.
2. Backend implementations remain authoritative for their runtime mechanics.
3. Presentation surfaces consume the Experience model rather than independently
   rediscovering backend inventories.
4. Web and terminal presentation may differ while representing the same logical
   ecosystem.
5. New web capabilities should receive terminal equivalents wherever reasonably
   possible.
6. Classic BBS interaction is a first-class BinkTerm surface, not a legacy
   compatibility mode.
7. Surface limitations should be represented explicitly rather than hidden in
   presentation code.
8. Shared policy should be evaluated consistently during discovery and launch.
9. Existing systems should migrate incrementally through compatibility fields
   rather than through unnecessary large rewrites.
10. The architecture should support future Experience types without requiring
    every consumer to understand their backend implementation.

## Next Phase

The foundation currently answers:

> What Experiences exist, and where can they be presented?

The next architectural phase should begin answering:

> What can an Experience participate in as part of the wider BinkTerm
> ecosystem?

Potential future Experience capabilities include:

- shared identity
- presence
- activity
- achievements
- scores and leaderboards
- economy
- social/community integration
- notifications
- multiplayer state
- cross-Experience navigation
- administration and lifecycle management

These capabilities should be added deliberately as shared platform concepts,
not merely as additional metadata fields.

The Experience layer is intended to become an integration boundary through
which BinkTerm can coordinate a broader BBS ecosystem while preserving the
independence of the systems behind it.
