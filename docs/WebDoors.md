# WebDoors Specification

## Table of Contents

- [Overview](#overview)
- [Directory Structure](#directory-structure)
- [Manifest Format](#manifest-format)
- [Configuration System](#configuration-system)
- [Discovery and Activation](#discovery-and-activation)
- [Requirements System](#requirements-system)
- [Configuration Overrides](#configuration-overrides)
- [Game Access](#game-access)
- [Integration with BBS Features](#integration-with-bbs-features)
- [Examples](#example-simple-html5-game)
- [Installation](#installation)
- [Best Practices](#best-practices)
- [Security Considerations](#security-considerations)
- [Troubleshooting](#troubleshooting)

## Overview

WebDoors is BinktermPHP's system for integrating external web-based games, applications, and terminal connections into the BBS. Each WebDoor is a self-contained application with its own manifest file that describes its capabilities, requirements, and configuration options.

WebDoors are displayed to users through the `/games` interface and can integrate with BBS features like storage, leaderboards, and the credits system.

The key idea is that a WebDoor is not just an isolated browser game in an iframe. It runs as part of the platform, reusing BinktermPHP identity, session, and API services so it can behave like a real BBS subsystem instead of a disconnected sidecar.

## Curated places and playable references

`src/CuratedPlaceCatalog.php` reads board-owned definitions from
`config/crossroads/places.json`. Each definition has an `id`, `kind: place`,
`name`, `description`, boolean `enabled`, and ordered `members` containing
`reference` strings. Optional `icon`, `association` context and per-member
`title`/`description` provide presentation metadata. `parent: curated` records
placement. Enabled Curated places appear after ordered runtime destinations by
default; an optional numeric `order` supplies an explicit shelf ordering value.
Place definitions with equal ordering retain their configured file order.
There is currently no admin editor for this definition file.

References are an Experience ID with an optional entry ID (`tatham/lightup`).
The resolver consumes `GameCatalog::getEnabledGames()` for the trusted caller
and delegates surface launch resolution to `ExperienceLaunch`. It never merges
member metadata into runtime authorization or launch configuration. Missing,
unauthorized, disabled and unsupported members are hidden consistently, matching
catalog discovery's access boundary. Authorized members playable only on another
surface remain present with a null launch target on the selected surface.

The generic manifest field `experience.default_entry` names the runtime's
existing default playable entry on every supported surface. For grouped
Experiences, the primary manifest owns this contract. Tatham advertises only
`lightup`; Slant, Unequal and Solo references are rejected. This is a named
default-launch alias, not arbitrary subentry selection. Supporting non-default
entries later requires an explicit runtime selection contract; listing
membership alone cannot enable them.

PP's configured order is Wordwright, Hangman, Blackjack, Parlour, Light Up,
BreakLock, Ordinary Puzzles, Dokuel, Everest. All nine declare `primary_presentation: true`.
Everest is Web/mobile-only (no terminal companion); its canonical single-player
puzzle state and save progress are owned entirely by its own unmodified
client-side code, not bridged into any BinkTerm-side persistence.
This existing member flag assigns their standalone shelf presentation to PP on
both Web and terminal. Direct Experience routes, runtime discovery, authorization,
saves and PP launch resolution remain unchanged. Set the flag false (or omit it)
for an explicit future cross-listing. It does not change the Experience category.
Disabled places and unsupported/unresolvable references cannot suppress cards.
Legacy Wordle is independent and retains its existing Game Hall visibility.
Intentional Game Hall entries such as Doom, Duke3D, Galactic Bloodshed, LORD and
Usurper remain unaffected. Shelf filtering uses CuratedPlacePresentation.runtimeEntries;
never use that filtered list as the runtime authorization catalog.

Parlour (the full 23-game canonical card room — see `shared/parlour/README.md`)
replaced Solitaire at PP position #4. It is one composed Experience across two
backends sharing `experience.group: "parlour"`: the `parlour` WebDoor
(`experience.primary: true`, `public_html/webdoors/parlour/`) and the
`parlour-terminal` NativeDoor (`experience.primary: false`,
`native-doors/doors/parlour-terminal/`), so PP shows one card, not one per
surface or per game. Its caller-scoped solo/solo-vs-bots progress persists via
the reserved `parlour` leased-storage namespace (slot 0, `webdoor_storage`) —
see the "Caller-scoped persistence" section of `shared/parlour/README.md`; a shared multiplayer room is
explicitly separate, later work, and is never written through this same path.

Klondike Solitaire itself was not removed: `klondike-solitaire`'s own WebDoor
(`public_html/webdoors/klondike-solitaire/`) stays enabled and its
`/games/klondike-solitaire` route is unchanged. Since it is no longer a PP
member, it no longer carries `primary_presentation: true` and now surfaces on
its own in Game Hall (previously suppressed there while it was PP-only). Its
legacy save/load API calls are unchanged and still currently return 404.

Root search filters the rendered shelf cards. PP remains searchable by its name
and description; searches for a hidden member name do not currently produce an
"inside this place" result. Member-aware search is deferred polish. Clearing a
filter cannot restore suppressed cards.

PP uses the approved 512x512 garden-gnome-and-sunflower PNG at
`public_html/img/places/puzlmastrs-patch.png`, configured through the generic
place `icon` field and visually checked at 48x48. Its caller-facing description
is "A little corner of Crossroads for puzzles, casual games, and things worth
puzzling over." Hangman and Solitaire legacy save endpoint failures remain
separate future maintenance items; this place does not change their storage.

The authenticated `/places/{placeId}` route uses `CuratedPlaceCatalog` and a
fixed `curated_place.twig` template. Missing, invalid and disabled places return
404. `CuratedPlacePresentation` adapts both place destinations and members to
the existing `experience_library_card.twig` partial. Member links use existing
launch URLs; the local Light Up title retains the `tatham` runtime identity.
Surface labels report availability without promising save/resume capabilities.

The `/games` route adds place cards only to shelf composition, after runtime
counts, activity and leaderboard reads. Places never enter the runtime catalog
or create game sessions. The landing returns to `/games#curated-experiences`;
PP member links carry `parent_place_id`. The wrapper resolves this ID through
`CuratedPlaceCatalog::returnTarget()` only when an enabled place contains an
authorized member whose launch backend matches the requested game. Invalid,
disabled, unknown and URL-shaped context falls back to the normal Experience
return. No arbitrary return URL or session-global last-place value is accepted.
The iframe path, backend ID, storage and presence identities remain unchanged.

Both wrapper return controls use the validated destination. Tatham reads the
host's `webdoor-return` link only after acknowledged save and successful lease
release; direct launches retain their usual Experience return. Other members
use the wrapper control without persistence changes. The conceptual contract
is navigation-scoped `parent_place_id`; a future Telnet presenter can resolve
the same parent identity without adopting browser URLs as runtime identity.

## Directory Structure

WebDoors are installed in the `public_html/webdoors/` directory. Each WebDoor resides in its own subdirectory:

```
public_html/webdoors/
├── blackjack/
│   ├── webdoor.json       # Required manifest file
│   ├── index.php          # Entry point
│   ├── icon.svg           # Game icon
│   └── assets/            # Game assets
├── hangman/
│   ├── webdoor.json
│   ├── index.html
│   └── ...
└── revpol/
    ├── webdoor.json
    ├── index.php
    └── ...
```

## Manifest Format

Each WebDoor must include a `webdoor.json` manifest file in its root directory. The manifest describes the game's metadata, requirements, and default configuration.

### Manifest Schema

```json
{
  "webdoor_version": "1.0",
  "game": {
    "id": "unique-game-id",
    "name": "Display Name",
    "version": "1.0.0",
    "author": "Author Name",
    "description": "Brief description of the game",
    "entry_point": "index.html",
    "icon": "icon.svg",
    "screenshots": []
  },
  "requirements": {
    "min_host_version": "1.0",
    "features": ["storage", "leaderboard", "credits"],
    "permissions": ["user_display_name"],
    "admin_only": false
  },
  "storage": {
    "max_size_kb": 256,
    "save_slots": 1
  },
  "multiplayer": {
    "enabled": false
  },
  "experience": {
    "category": "game"
  },
  "config": {
    "comment": "This is a comment that is for informational use only",
    "custom_setting": "default_value"
  }
}
```

### Field Descriptions

#### `webdoor_version` (string, required)
Version of the WebDoor specification. Current version is `"1.0"`.

#### `game` (object, required)
Core game metadata displayed to users.

- `id` (string, required): Unique identifier for the game. Used in configuration and API calls.
- `name` (string, required): Display name shown in the games list.
- `version` (string, required): Game version (semantic versioning recommended).
- `author` (string, required): Creator or developer name.
- `description` (string, required): Brief description shown in the games list.
- `entry_point` (string, required): Entry file relative to the WebDoor directory (e.g., `"index.html"`, `"index.php"`).
- `icon` (string, optional): Icon filename relative to the WebDoor directory. Defaults to `"icon.png"`.
- `screenshots` (array, optional): Array of screenshot filenames for future use.

#### `requirements` (object, optional)
Declares features and permissions the WebDoor needs.

- `min_host_version` (string): Minimum BinktermPHP version required.
- `features` (array): List of BBS features the game requires:
  - `"storage"`: Persistent storage API
  - `"leaderboard"`: Leaderboard/high score API
  - `"credits"`: BBS credits system integration
- `permissions` (array): User data the game needs access to:
  - `"user_display_name"`: Access to user's display name
- `admin_only` (boolean, optional): When `true`, the WebDoor is withheld from
  non-admin discovery and its `/games/{id}` launch route returns 403 for
  non-admins — the same manifest-authoritative gate DOS and native doors use
  (`requirements.admin_only`). Catalog-driven APIs (`/api/webdoor/session`,
  leaderboards, score submission) also fail closed for non-admins because they
  authorize purely through the filtered discovery catalog. There is no
  `config/webdoors.json` override — the manifest is authoritative, matching the
  managed-door contract. Note that static asset files under
  `public_html/webdoors/{id}/` remain directly fetchable by URL regardless;
  `admin_only` gates discovery, launch, and platform APIs, not raw file serving.
  Default: `false`.

#### `storage` (object, optional)
Storage requirements for games that save user data.

- `max_size_kb` (integer): Maximum storage size per user in kilobytes.
- `save_slots` (integer): Number of save slots per user.

#### `multiplayer` (object, optional)
Multiplayer capabilities (reserved for future use).

- `enabled` (boolean): Whether the game supports multiplayer.

#### `experience` (object, optional)
Crossroads presentation metadata for the normalized Experience contract.

- `category` (string, optional): The Crossroads shelf category. Defaults to
  `"game"` (the Game Hall). `"gateway"` places the Experience on the Gateways
  shelf. Any other non-empty value (e.g. `"utility"`, `"chat"`) places it on
  the **Utilities** shelf — the shelf for useful non-game destinations such as
  the Gemini Browser, Gemini Capsule, and MRC Chat — and suppresses the
  "Single player" label (a non-game Experience shows "Multiplayer" only when
  `multiplayer.enabled` is `true`). This is not overridable from
  `config/webdoors.json`; the manifest is authoritative.
- `conversation` (object, optional): Links the Experience to a chat room.
- `group` / `primary` / `surface` (optional): Multi-backend grouping metadata.

#### `config` (object, optional)
Default configuration values. These serve as defaults and can be overridden by the sysop in `config/webdoors.json`.

Common configuration keys:
- `comment`: An informational comment for someone editing the JSON 
- `display_name`: Override the game's display name
- `display_description`: Override the game's description
- Game-specific settings (varies by WebDoor)

## Configuration System

WebDoors are configured through the `config/webdoors.json` file. This file controls which games are enabled and allows sysops to customize game settings.

### Configuration File Location

`config/webdoors.json`

### Configuration Format

```json
{
  "blackjack": {
    "enabled": true,
    "start_bet": 10,
    "display_name": "21 Blackjack"
  },
  "hangman": {
    "enabled": true
  },
  "revpol": {
    "enabled": true,
    "display_name": "Reverse Polarity BBS",
    "display_description": "Connect to the Reverse Polarity BBS",
    "host": "revpol.lovelybits.org",
    "port": "22",
    "proto": "ssh"
  }
}
```

### Configuration Fields

- `enabled` (boolean, required): Whether the game is active and visible to users.
- Custom settings: Any settings defined in the manifest's `config` section can be overridden here.
- `display_name` (string, optional): Override the game's display name in the games list.
- `display_description` (string, optional): Override the game's description.

### Configuration Priority

1. **Sysop Configuration** (highest priority): Values in `config/webdoors.json`
2. **Manifest Defaults** (fallback): Values in the WebDoor's `webdoor.json` config section
3. **Code Defaults** (lowest priority): Hardcoded defaults in the WebDoor's code

## Discovery and Activation

### Auto-Discovery

The system automatically discovers WebDoors by:
1. Scanning `public_html/webdoors/` for subdirectories
2. Looking for `webdoor.json` in each subdirectory
3. Parsing valid manifests and registering the WebDoor

### Admin Interface

Sysops manage WebDoors through **Admin → WebDoors** (`/admin/webdoors-config`), which provides:
- List of discovered WebDoors with enable/disable controls
- **Add New Door** panel listing directories that don't yet have a manifest — click **Create Manifest** to open the manifest editor for that directory
- Manifest editor for creating and editing `webdoor.json` files through a form UI

To activate a new WebDoor:
1. Place the door files under `public_html/webdoors/YOURDOOR/`.
2. Go to **Admin → WebDoors** and find the directory in the **Add New Door** panel.
3. Click **Create Manifest**, fill in the form, and save.
4. Toggle the door on and click **Save Configuration**.

## Requirements System

The requirements system ensures games only run when their dependencies are met.

### Feature Requirements

Games can require BBS features:
```json
"requirements": {
  "features": ["storage", "leaderboard", "credits"]
}
```

If a required feature is not available, the game is not shown in the games list.

### Permission Requirements

Games can request access to user data:
```json
"requirements": {
  "permissions": ["user_display_name"]
}
```

This declares what user information the game needs.

### Checking Requirements

The system validates requirements before displaying games:
```php
function checkManifestRequirements($manifest) {
    $requirements = $manifest['requirements'] ?? [];
    $features = $requirements['features'] ?? [];

    // Check each required feature
    foreach ($features as $feature) {
        if (!isFeatureAvailable($feature)) {
            return false;
        }
    }

    return true;
}
```

## Configuration Overrides

### Display Name Override

Sysops can customize how games appear to users:

**In manifest** (`public_html/webdoors/blackjack/webdoor.json`):
```json
{
  "game": {
    "name": "Blackjack"
  }
}
```

**In configuration** (`config/webdoors.json`):
```json
{
  "blackjack": {
    "enabled": true,
    "display_name": "21 Card Game"
  }
}
```

Users will see "21 Card Game" instead of "Blackjack" in the games list.

### Description Override

Similarly, descriptions can be customized:
```json
{
  "blackjack": {
    "enabled": true,
    "display_description": "Classic card game - try to beat the dealer!"
  }
}
```

### Custom Settings

Game-specific settings from the manifest's `config` section can be overridden:

**Manifest default**:
```json
{
  "config": {
    "start_bet": 10
  }
}
```

**Sysop override**:
```json
{
  "blackjack": {
    "enabled": true,
    "start_bet": 25
  }
}
```

## Game Access

### URL Structure

Games are accessed through standardized URLs:
- Game list: `/games`
- Play game: `/games/{game-id}`

Where `{game-id}` is the directory name of the WebDoor (e.g., `/games/blackjack`).

### Entry Points

When a user accesses `/games/blackjack`, the system:
1. Loads the WebDoor's manifest
2. Applies any configuration overrides
3. Serves the file specified in `entry_point`
4. Injects BBS context (user info, session, etc.)

## Integration with BBS Features

### User Authentication

WebDoors run within authenticated user sessions. Games can access:
- Username
- Display name (with permission)
- User ID
- Session token

Session creation resolves the WebDoor identity from an explicit `game_id` or
the existing `/webdoors/{id}/` referrer convention, then requires exact
membership in the authenticated user's discoverable web Experience catalog.
Missing, disabled, hidden, requirements-failing, stale, non-WebDoor, and
otherwise undiscoverable identities are rejected before existing sessions are
read or new sessions are created.

### Session lifecycle and presence

A `webdoor_sessions` row is a live-presence and resume marker, not a store of
game state (progress lives in per-`(user, game, slot)` storage). While the row
is active it makes the player appear in Crossroads presence — Live Now, lobby
rosters, and "Your Places".

The shared host page (`templates/webdoor_play.twig`) fires a
`navigator.sendBeacon('/api/webdoor/session/end?game_id=…')` on `beforeunload`,
so leaving a WebDoor ends that game's participation immediately instead of
leaving the player shown as active until the session expires. This mirrors the
JS-DOS host page. Managed DOS/native door players deliberately do **not** do
this — they have a live bridge session to reconnect to across a refresh; a
WebDoor does not.

`POST /api/webdoor/session/end` ends the caller's active session for the game
named by `game_id` (query string or JSON body); closing one WebDoor tab
therefore never ends a different WebDoor open in another tab. With no `game_id`
it falls back to ending the user's most recent active session. Re-entering a
WebDoor after leaving creates a fresh session and resumes from the last save;
the lobby's primary action reads **Enter** (not **Return**) once participation
has ended.

### Storage API

Games requiring persistent storage use the BBS storage API to save/load user data.

### Leaderboard API

Games can submit high scores to the BBS leaderboard system for display on the main games page.
Leaderboard reads and score submissions require the resolved WebDoor ID to be
present in the authenticated user's discoverable web Experience catalog. The
API accepts the explicit `game_id` used by the shared SDK and retains referrer
inference for compatible WebDoors, but unresolved, disabled, stale, or
otherwise undiscoverable IDs are rejected. This authorization affects access
to scores; it does not delete historical leaderboard records.

### Credits System

Games can integrate with the BBS credits/economy system to charge for plays or award winnings.

## Workflow: how a WebDoor interacts with the platform

1. A user launches a WebDoor from `/games` using an authenticated browser session.
2. BinktermPHP discovers the WebDoor, loads its manifest, and applies sysop overrides.
3. The WebDoor entry point runs inside the user's existing platform identity and session context.
4. The WebDoor calls platform APIs for storage, leaderboards, credits, or user-facing data instead of implementing its own separate account system.
5. Results show up back in the platform, whether that means saved progress, credit changes, or a shared leaderboard.

## Example: Simple HTML5 Game

```json
{
  "webdoor_version": "1.0",
  "game": {
    "id": "mygame",
    "name": "My Game",
    "version": "1.0.0",
    "author": "Your Name",
    "description": "A simple HTML5 game",
    "entry_point": "index.html",
    "icon": "icon.png"
  },
  "requirements": {
    "min_host_version": "1.0",
    "features": ["leaderboard"]
  }
}
```

## Example: PHP-Based Application

```json
{
  "webdoor_version": "1.0",
  "game": {
    "id": "phpapp",
    "name": "PHP Application",
    "version": "1.0.0",
    "author": "Your Name",
    "description": "Server-side PHP application",
    "entry_point": "index.php",
    "icon": "icon.svg"
  },
  "requirements": {
    "min_host_version": "1.0",
    "features": ["storage", "leaderboard", "credits"],
    "permissions": ["user_display_name"]
  },
  "storage": {
    "max_size_kb": 100,
    "save_slots": 3
  },
  "config": {
    "difficulty": "normal",
    "max_players": 10
  }
}
```

## Example: Terminal Gateway

```json
{
  "webdoor_version": "1.0",
  "game": {
    "id": "mybbs",
    "name": "My BBS",
    "version": "1.0.0",
    "author": "Your Name",
    "description": "Connect to my text-based BBS",
    "entry_point": "index.php",
    "icon": "icon.svg"
  },
  "requirements": {
    "min_host_version": "1.0",
    "permissions": ["user_display_name"]
  },
  "config": {
    "display_name": "My BBS",
    "display_description": "Connect to My BBS via telnet",
    "host": "mybbs.example.com",
    "port": "23",
    "proto": "telnet"
  }
}
```

**Sysop configuration** (`config/webdoors.json`):
```json
{
  "mybbs": {
    "enabled": true,
    "display_name": "Community BBS",
    "host": "bbs.example.org",
    "port": "23",
    "proto": "telnet"
  }
}
```

## Installation

To install a new WebDoor:

1. **Copy the WebDoor directory** to `public_html/webdoors/`:
   ```bash
   cp -r mygame/ public_html/webdoors/
   ```

2. **Verify the manifest** exists and is valid:
   ```bash
   cat public_html/webdoors/mygame/webdoor.json
   ```

3. **Enable the game** in `config/webdoors.json`:
   ```json
   {
     "mygame": {
       "enabled": true
     }
   }
   ```

4. **Access the game** at `/games` or directly at `/games/mygame`

## Best Practices

1. **Use semantic versioning** for game versions
2. **Provide meaningful descriptions** that help users understand what the game does
3. **Declare all requirements** your game needs
4. **Include an icon** (SVG or PNG) for better visual presentation
5. **Test with configuration overrides** to ensure defaults work
6. **Document custom config options** in your game's README
7. **Keep entry points simple** - use `index.html` or `index.php`
8. **Use unique game IDs** that won't conflict with other WebDoors

## Security Considerations

1. **User input validation**: Always validate and sanitize user input
2. **Path traversal**: Don't allow users to specify file paths
3. **SQL injection**: Use prepared statements for database queries
4. **XSS protection**: Escape output when displaying user-generated content
5. **Authentication**: WebDoors run in authenticated sessions - verify the user
6. **File permissions**: Ensure game files are readable but not writable by web server
7. **Configuration validation**: Validate configuration values from `config/webdoors.json`

## Troubleshooting

### Game not appearing in list
- Verify `webdoor.json` exists and is valid JSON
- Check that the game is enabled in `config/webdoors.json`
- Ensure requirements are met (features, permissions)
- Check file permissions on the WebDoor directory

### Configuration not taking effect
- Verify `config/webdoors.json` syntax
- Check that configuration keys match those in the manifest
- Restart PHP-FPM if using opcache
- Clear browser cache

### Entry point not loading
- Verify `entry_point` path is correct relative to WebDoor directory
- Check file permissions on the entry point file
- Look for PHP errors in web server error log

## Related Systems

- [Architecture](ARCHITECTURE.md) — where WebDoors fit into the platform
- [Doors Overview](Doors.md) — the broader door runtime and other door types
- [API Reference](API.md) — authenticated platform APIs used by browser features
- [BinkStream Back-Channel](BinkStreamChannel.md) — realtime events for live browser updates
- [Credit System](CreditSystem.md) — credits and economy integration

### Reserved leased progress namespaces

`LeasedWebDoorStorage` defaults to `tatham` and additionally accepts `breaklock` and `ordinary-puzzles`.
Each namespace uses slot 0 independently within the existing caller-scoped storage
key. All three namespaces reject generic SDK/controller writes; only their leased
facades may update them. BreakLock's persistence and handoff proof are documented in
`shared/breaklock/persistence/README.md`. Its production WebDoor is
`public_html/webdoors/breaklock`, paired with NativeDoor `breaklock-terminal`
under the `breaklock` Experience. Both use the same shared core and leased state.
The Web Save & Return uses the host's validated PP/direct return link; terminal
return uses the existing NativeDoor/Curated-place launch flow.

Ordinary Puzzles uses `public_html/webdoors/ordinary-puzzles` and NativeDoor
`ordinary-puzzles-terminal`, backed by `shared/ordinary-puzzles/persistence/`.
Its slot 0 contains the canonical replay snapshot, including unfinished drags.

### Wordwright mixed-surface Experience

Wordwright uses `shared/wordwright` for its pinned canonical engine and adapters,
`public_html/webdoors/wordwright` for authenticated Web packaging, and
`native-doors/doors/wordwright-terminal` for the trusted terminal launcher. Its
reserved leased storage namespace is `wordwright`, slot 0: session, metadata and
statistics are saved atomically. Build instructions and provenance are in
`shared/wordwright/README.md`. Configure runtime enablement through the normal BBS
administration interfaces. It replaces only Wordle's PP membership; legacy Wordle
remains independently available, with its existing daily puzzle, saves and leaderboard.
