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
