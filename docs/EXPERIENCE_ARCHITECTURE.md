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
backend session ownership. `ExperienceLaunch` resolves static surface support
and canonical launch targets. A resolved target means that presentation may
offer an action, not that launch is guaranteed: backend launch and session
routes remain authoritative for runtime authorization, credits, capacity, and
session creation.

The web `/games` route presents this model as the Experiences library. It uses
one collection-level `ExperienceState` read to derive optional Continue Playing
and Live Now sections, then renders the complete authorized web catalog under
All Experiences. Viewer participation is matched by numeric user identity.
Hidden entries remain absent because library composition occurs only after
`GameCatalog` applies web discovery policy. The Community Scoreboard remains a
separate score-oriented view and must not be treated as popularity metadata.

The library sections have distinct membership rules:

- **Continue Playing** contains every visible, authorized web Experience in
  which the numeric viewer identity has active participation. Return takes
  priority over Play.
- **Live Now** contains other visible web Experiences with a player count
  greater than zero. Continue Playing entries are excluded to avoid adjacent
  duplication.
- **All Experiences** is the complete visible, authorized web inventory,
  including entries already shown in the optional activity sections. Its
  browser-side filters narrow only these already-authorized rendered entries;
  they do not rediscover Experiences, alter catalog order, or affect the other
  library sections.
- **Community Scoreboard** reports submitted scores for the selected month.
  Scores are not evidence of current occupancy or popularity. Viewer-facing
  scoreboards and leaderboards use the requested surface's authorized catalog
  membership as their visibility boundary. They must not expose an Experience
  through historical activity when discovery policy hides it from that viewer.
  Filtering affects presentation and score submission authorization only;
  historical score records remain stored.

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
