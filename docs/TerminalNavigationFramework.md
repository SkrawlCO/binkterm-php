# Terminal Navigation Framework

> Status: platform capability, opt-in. A sysop editor UI is planned; today the
> definition is a JSON file and the runtime is switched on with an environment
> flag.

The Terminal Navigation Framework lets a sysop describe the terminal server's
menu **structure, labels, actions, access rules, help text, and presentation
intent as data**, without editing PHP. BinktermPHP keeps ownership of how those
menus are rendered and how the caller interacts with them, so geometry
adaptation, charset/colour fallback, resize handling, localisation, and the
low-capability line shell all keep working exactly as they do for the built-in
menu.

It is **additive**. With no definition file and the flag off, the terminal
server uses its existing built-in menu, unchanged.

---

## Enabling it

Two gates, both required:

1. Place a definition at `config/terminal_navigation.json` (copy
   `config/terminal_navigation.json.example` and edit). Set
   `TERMINAL_NAV_CONFIG` in `.env` to use a different path.
2. Set `TERMINAL_NAV_RUNTIME=on` in `.env`.

If the flag is off, the built-in menu is used and nothing about it changes. If
the flag is **on** but the definition is missing, unreadable, malformed, an
unsupported schema version, or fails semantic validation, the terminal server
falls back to the built-in menu and writes one actionable line to the terminal
server log naming the file and the reason — a bad definition can never break
terminal login.

Restart the terminal/SSH daemons after changing the file or the flag.

### Failure handling

| Situation | Log line names | Result |
|-----------|----------------|--------|
| File missing (or a directory, or a dangling symlink) | the path, with a hint | built-in menu |
| File exists but not readable | the path + "check file permissions" | built-in menu |
| Empty file / invalid JSON / not a JSON object | the path + the JSON error | built-in menu |
| `schema` missing or unsupported version | the version | built-in menu |
| Semantic errors (unknown action, hotkey clash, dangling submenu, cycle, unreachable node, missing fallback, …) | every error, each with its `nodes[…].items[…]` path | built-in menu |
| Unknown access predicate | the predicate, at parse time (fail closed) | built-in menu |
| An error *during* an active declarative session | the exception + the definition id | the session drops to the built-in menu for the rest of that connection |

The runtime is never partially activated: it is only entered once a definition
has passed every check.

### Caching

The parsed definition is cached per process, keyed on the file's path, size, and
modification time — a replaced file is picked up on the next session without a
stale copy surviving. The telnet/SSH daemons fork one process per connection, so
in normal operation every session re-reads the file anyway. A daemon restart
after editing is still recommended so all worker behaviour is consistent.

The `TERMINAL_NAV_CONFIG` path override is used verbatim for reads. It is a
sysop-controlled `.env` value and grants no access the sysop does not already
have, so it is not sandboxed; a future config *writer* (for the planned editor)
constrains its target separately.

---

## The definition

```json
{
  "schema": 1,
  "id": "my.board",
  "root": "main",
  "nodes": [
    {
      "id": "main",
      "label_fallback": "Main Menu",
      "label_key": "ui.terminalserver.server.menu.title",
      "items": [
        { "id": "messages", "label_fallback": "Messages", "hotkey": "m", "submenu": "messages",
          "presentation": { "emphasis": "primary", "group": "messaging" } },
        { "id": "doors", "label_fallback": "Games & Doors", "hotkey": "g", "action": "doors",
          "access": "feature:webdoors" },
        { "id": "sysop", "label_fallback": "Sysop Tools", "hotkey": "y", "submenu": "sysop",
          "access": "admin" },
        { "id": "quit", "label_fallback": "Log Off", "hotkey": "q", "action": "quit" }
      ]
    },
    {
      "id": "messages",
      "label_fallback": "Messages",
      "items": [
        { "id": "netmail",  "label_fallback": "Netmail",  "hotkey": "n", "action": "netmail" },
        { "id": "echomail", "label_fallback": "Echomail", "hotkey": "e", "action": "echomail" }
      ]
    },
    { "id": "sysop", "label_fallback": "Sysop Tools", "access": "admin", "items": [
      { "id": "settings", "label_fallback": "Terminal Settings", "hotkey": "t", "action": "settings" }
    ] }
  ]
}
```

### Top level

| Field | Required | Meaning |
|-------|----------|---------|
| `schema` | yes | Integer. Currently `1`. |
| `id` | yes | Stable identifier for this definition. |
| `root` | yes | The `id` of the node shown first. |
| `nodes` | yes | Non-empty array of node objects. |

### Node

A menu screen. `id` (unique), a label (`label_fallback` required, `label_key`
optional i18n key), an optional `description` / `description_key`, an ordered
`items` array, an optional `access` expression, and optional `presentation`
hints.

### Item

One selectable entry. Exactly one of `action` or `submenu`:

| Field | Meaning |
|-------|---------|
| `id` | Unique within the node. |
| `label_fallback` / `label_key` | Visible text. A literal fallback is always required. |
| `description` / `description_key` | Optional secondary text. |
| `hotkey` | Single character, case-insensitive. Conflicts within a node are a validation error. |
| `action` | A **registered action id** (string, or `{ "id": "...", "params": { ... } }`). |
| `submenu` | The `id` of another node. |
| `access` | Access expression (see below). Hidden items take their hotkey with them. |
| `enabled` | `false` shows the item greyed-out and non-selectable. |
| `presentation` | Presentation hints (see below). |

### Registered actions

Configuration can only name an action the platform already provides — it can
never reference a PHP class or callback. The available ids:

`newscan`, `netmail`, `echomail`, `bulletins`, `qwk`, `shoutbox`, `localchat`,
`polls`, `whosonline`, `doors`, `interests`, `files`, `freqrequests`, `bbslist`,
`nodelist`, `settings`, `quit`.

`newscan` opens the unified **"What's New"** scan — unread netmail then each
subscribed echomail area with new messages, traversed one message at a time
through the normal reader, plus an unread-bulletins count. It is only reachable
through a declarative definition; the built-in menu does not include it.

Some actions are only available when their feature is enabled (e.g. `doors`
needs `webdoors`); an item bound to an unavailable action is hidden
automatically, so you do not have to repeat the feature check in `access`.

### Access expressions

Not code — a small fixed grammar. A bare predicate:

- `always`, `authenticated`, `guest`, `admin` (alias `sysop`)
- `feature:<name>` — a BbsConfig feature flag (`webdoors`, `shoutbox`,
  `voting_booth`, `chat`, `file_areas`, `bbs_directory`, `qwk`, plus `freq`,
  `interests`, `nodelist`)
- `action:<id>` — true when that action is currently available
- `capability:<name>` — `color`, `utf8`, `sixel`
- `env:<VAR>` — an `.env` variable is set to a truthy value

Composed with objects:

```json
{ "all": [ "authenticated", { "any": [ "admin", "feature:shoutbox" ] }, { "not": "guest" } ] }
```

`all` with an empty list allows; `any` with an empty list denies; an unknown
predicate evaluates to `false` (fail closed).

### Presentation hints

Intent, never coordinates or raw ANSI:

| Hint | Values |
|------|--------|
| `emphasis` | `normal`, `primary`, `muted`, `danger` |
| `group` | Free-text grouping label |
| `glyph` | Semantic glyph name |
| `description_mode` | `compact`, `full` |
| `art` | Art asset reference |
| `badge` | Name of a live-context signal to show next to the item (see below) |

The renderer decides the actual layout for the caller's geometry, charset, and
colour capability.

### Live-context badges

An item may name a `badge` signal. When the caller supplies a badge resolver
(`NavigationScreenBuilder`'s third constructor argument — a
`callable(string): ?string`), the builder calls it for every item that has a
`badge` hint **and** has already passed every access / availability gate, and
attaches the returned short string as the item's annotation. The renderer draws
it as a dim ` · <text>` suffix after the label (it rides the reverse-video row
under the lightbar). A `null`/empty result draws nothing, so a zero-state signal
simply leaves the item as it was.

The signal vocabulary is the resolver's own; the navigation classes only pass the
name through. The resolver is never handed an item the caller cannot see, so it
cannot be used to probe hidden options. Off-session callers (preview, tests) pass
no resolver and get no badges. Resolving live data on a cadence that does not
run per-keystroke is the resolver's responsibility — the built-in terminal
integration snapshots presence once and caches it for a few seconds.

### How the renderer composes a screen

`NavigationScreenRenderer` turns those hints into a composed screen rather than a
bare list:

- **Bounded column.** Content is drawn in a column of at most `CONTENT_MAX` (78)
  columns with a left margin capped at 8, so a 132-column terminal gets a
  designed column instead of edge-to-edge text or a tiny block adrift in the
  middle.
- **Header band + tagline.** A `── Title ─────` band, an orientation crumb on
  submenus, then the node `description` as a dim tagline.
- **Sections.** When a screen carries **two or more** distinct `group` values the
  items are drawn under bold uppercase section headings, in first-seen group
  order (items with no group fall to an unlabelled block at the end). A single
  group value is ignored — one heading is noise, not composition.
- **Descriptions, adaptively.** When the terminal is tall enough for the whole
  block, each item's `description` is rendered inline on a dim line beneath it.
  When it is not, the descriptions collapse to a single roaming status line above
  the footer hints that shows the highlighted item's `label: description`.
- **Positioned, not centred.** The block is placed in the upper-middle with an
  adaptive top margin (capped), and the footer rule + hints follow the content
  instead of being pinned to the last row.
- **Highlight by identity.** The lightbar highlight is resolved by item id
  against `selectableItems()` (definition order), so it stays correct even when
  grouping reorders items for display.

---

## ANSI presentation theme (optional)

A theme is a trusted, absolute-positioned ANSI frame drawn *around* the composed
navigation for one exact geometry. **ANSI is presentation only** — it never
carries navigation structure, actions, ACS, or executable behaviour, and the
flowing `NavigationScreenRenderer` above stays the canonical content composer and
the universal fallback.

**Zero-config** is the flowing renderer. A theme is used only when
`config/terminal_theme.json` exists, validates clean, is not disabled, and the
live terminal's size, charset (`utf8` / `cp437`) and colour all match a themed
geometry. Any mismatch — a different size, ANSI colour off, a missing or unsafe
template, a validation failure — falls back to the flowing renderer for that
frame. A theme can never make navigation unusable.

### `config/terminal_theme.json`

Schema 1 (accepted M1) themes exactly **one** geometry, `80x24`, with exactly
two regions, `MENU` and `FOOTER`. It remains supported unchanged. See
`config/terminal_theme.json.example`. Schema 2 composition is described below.

```json
{
  "schema": 1,
  "id": "l33test.frontdoor",
  "enabled": true,
  "geometries": {
    "80x24": {
      "template": "nav-frontdoor",
      "regions": {
        "MENU":   { "row": 6,  "col": 5, "width": 72, "height": 12 },
        "FOOTER": { "row": 19, "col": 5, "width": 72, "height": 2 }
      }
    }
  }
}
```

- `template` names a trusted sysop asset `telnet/screens/<token>.ans` (token is
  `[A-Za-z0-9_-]`). Authored in CP437 by convention; converted to the terminal's
  charset at render time.
- Region rectangles are **1-based** `row`/`col` with `width`/`height`, all
  integers `>= 1`. Each must sit wholly inside `80x24`, and `MENU`/`FOOTER` must
  not overlap. The config is a set of named placement rectangles, **not a drawing
  language** — no ANSI, no per-cell instructions, no coordinate expressions.
- Any other geometry key (`132x36`, …) is rejected; those sizes always use the
  flowing renderer.

### Template safety — `TemplateArtSanitizer`

Before a template is painted it is: truncated at the DOS EOF byte (`0x1A`, drops
a SAUCE record); stripped of every control sequence except SGR — cursor moves,
erase, scroll region, mode changes, OSC / DCS, charset designation, C0/C1 bytes
are all removed (the same whitelist the message-body read path uses); and
converted to the terminal's charset. A template that relies on cursor
positioning simply loses it; the allowed capability is printable text and colour.

### `ThemedNavigationRenderer`

Wraps `NavigationScreenRenderer`. On each render it re-checks the geometry (it can
change mid-session), paints the sanitised template line by line, then asks the
inner renderer's `composeRegions()` for the MENU and FOOTER blocks — every cell
space-filled to the rectangle — and positions them with `ESC [ r;c H`. The whole
frame is one coalesced write. It never touches the screen model, so hotkeys,
actions, ACS and item order are exactly what the flowing renderer would show.
`NavigationRendererFactory::create()` chooses the renderer for a session.

`composeRegions()` renders the MENU as a **directory**, not a vertical menu:
each destination is one full-width row — hotkey, an uppercase name, its purpose
in a description column, and any live badge (`3 playing`, `2 online`) right-
aligned — under a short section sign; the selected row is a full-width bar. The
FOOTER's first line uses the model's root Recent Callers ambient text when
available, otherwise a summary of existing badge annotations; its
last line is the key hints. Identity and the "place" framing come from the
template around it, so the themed layout drops the flowing renderer's title band
and roaming status line. `composeLines()` — the flowing renderer — is unchanged.

### M2 first slice: semantic composition at 80x24

`config/terminal_theme_m2.json.example` and `telnet/screens/nav-frontdoor-m2.ans`
provide one root front-door proof. The example is not automatically activated.
Schema 2 requires four pairwise-disjoint, in-bounds rectangles:

| Region | Application-provided content |
|---|---|
| `MENU` | Existing access-filtered destinations, grouping, hotkeys, and selection bar. Purpose and badges are moved out of each row. All navigation rows must fit. |
| `DESCRIPTION` | Selected destination label and description; without a selectable destination, the screen title and description. Changes as the existing runtime moves the cursor. |
| `STATUS` | First row: existing root ambient text (Recent Callers). Second row: existing resolved badge annotations, labelled by destination. Missing data leaves blank cells; no synthetic activity. |
| `FOOTER` | Existing application-owned key hints, which must fit in full. |

`NavigationScreenRenderer::composeSemanticRegions()` consumes only the resolved
`NavigationScreenModel` and cursor. It performs no database or service reads.
`NavigationScreenBuilder` and `DeclarativeMenuBridge` continue to own badge and
ambient resolution, including existing access gates and snapshot intervals.
The navigation runtime rebuilds on input, idle redraw, and return from actions;
this slice adds no polling or activity semantics.

The schema-2 optional boolean `root_only` limits presentation to the definition's
root, independently of its node ID. The proof sets it to `true`; submenus use the
normal flowing renderer, without changing their definitions or behavior. Omit it
or use `false` for a theme intended to apply across nodes; no other proof screens
are supplied in this slice.

Schema 2 validates complete template dimensions after the existing sanitization:
empty/control-only art, tabs, or art exceeding 80x24 cause fallback. Invalid
regions, missing assets, ASCII/mono terminals and unmatched geometry also fall
back. MENU/FOOTER overflow falls back instead of hiding navigation. DESCRIPTION
and STATUS are informational and clip to their authored rectangles. Every
region is reset and space-filled, including when content becomes empty.
CP437 content is measured through UTF-8 and encoded back for output; template
rows that passed the size check retain their original terminal-encoded bytes.
Schema-1 clipping and sanitization behavior remain unchanged.

80x24 is deliberately the only authored geometry in this slice. `132x36` and
`132x51` remain normal declarative presentations; the existing exact-geometry
lookup and per-render fallback boundary are retained for future expanded themes.

F6 preview needs no new editor controls: its existing `renderReport()` path
uses the same compositor and grid flattening. It shows the first selectable
destination's description. Off-session previews have no live badge/ambient
resolvers, so STATUS is honestly empty. Preview neither saves nor activates a
theme, invokes an action, nor changes session/runtime state.

#### Activation and minimal acceptance

This slice leaves the accepted active M1 configuration and asset untouched.
During human-present activation, select the supplied M2 example as
`config/terminal_theme.json` (or through the existing `TERMINAL_NAV_THEME_CONFIG`
override). Theme saving is not implemented in the admin UI, so this remains an
operator-managed file. Reconnect in SyncTerm at 80x24 with ANSI colour enabled.

The changed `src/Terminal/Navigation` classes are Composer-autoloaded when the
session's `DeclarativeMenuBridge::run()` creates the renderer, after the normal
Telnet/SSH connection fork. No daemon entrypoint or eagerly included terminal
class changed. Fresh forked sessions load the new classes; existing sessions
retain loaded PHP classes and their renderer/template cache until disconnect.
The normal forked runtime therefore needs a fresh connection, not a daemon
restart. Non-forking runtimes that have already loaded the classes require a
human-authorized restart. No service restart is performed by this slice.

On the front door, move the highlight and confirm DESCRIPTION follows it.
Compare STATUS with actual caller/badge state; allow the existing snapshot
interval and redraw, rather than expecting new instant push updates. Open
Messages with its hotkey or Enter, then Back; confirm normal submenu behavior
and return to the composed root. Optionally reconnect at 132x36 and confirm the
normal declarative fallback.

Expanded assets, additional authored spaces, per-item coordinates, scripting,
theme preferences, and an ANSI IDE remain deferred. Historical M1 rendering
fidelity defects are closed; they are not M2 blockers.

---

## Authored Crossroads arrival (M2 Slice 2)

Crossroads uses `Directory` and the structured selectable-list runtime, not
`NavigationScreenModel`. It now opts into the same M2 painter through
`showDirectory()` options `theme_surface: crossroads` and `theme_status_lines`.
The latter contains only existing authorized arrival text, resolved by
`DoorHandler::composeArrivalThemeStatus()`; the renderer makes no queries.

The one supplied composition is `config/terminal_theme_crossroads.json.example`
with `telnet/screens/nav-crossroads.ans`, at exact 80x24:

| Region | Position | Content |
|---|---|---|
| STATUS | Rows 4–6, columns 4–75 | Existing Live Now summary; newest existing historical footprint; Your Places summary only when participation exists. |
| MENU | Rows 9–20, columns 4–40 | Original numbered rows and category headings, in a selection-following window over the full directory. |
| DESCRIPTION | Rows 9–20, columns 46–75 | Selected row's name, existing badge, and wrapped description. Long informational text is bounded. |
| FOOTER | Row 23, columns 4–75 | The shell's existing key hints plus the visible destination range. |

Identity and continuity occupy the top; destinations and selected context share
the body horizontally. This is deliberately different from the front door's
stacked proof. Rosters, fuller activity history, participation actions and
Enter/Return remain in existing Live Now, Your Places and Experience detail
views. The arrival keeps its existing snapshot lifetime: returning to it reloads
the shared state; moving the selection adds no polling or queries. Quiet hours
remain explicit, and history is expressed as past play rather than live presence.

`ThemedDirectoryView` composes presentation only. The existing
`TelnetUtils::runSelectableStructuredList()` retains the complete row array,
selected index and input ownership; its optional application-owned
`frame_renderer` callback can paint a frame or return false. Number shortcuts,
arrows, Enter, Q/Back, resize and handler dispatch stay in that same loop. A
viewport does not filter or renumber destinations; its category heading is
repeated when starting inside a category. A required visible row/category or
footer that cannot fit yields to the original directory renderer.

`ThemedNavigationRenderer::tryRenderRegions()` is the shared template loading,
validation, frame and placement path. The root renderer still invokes it with
its original navigation composer; directories invoke it with resolved directory
blocks fitted by `NavigationScreenRenderer::fitSemanticBlock()`. There is no
second ANSI parser, navigation model or input loop. Missing/invalid configuration,
missing/unsafe/non-fitting art, unsupported geometry or colour/charset, and
composition failure use the existing directory frame. LineShell and directories
that do not opt in retain their accepted presentation. F6/root preview is unchanged;
this slice does not add a Crossroads preview editor.

### Selection and activation

`NavigationThemeConfig::loadSurface('crossroads')` reads
`terminal_theme_crossroads.json` beside the selected root theme file (normally
`config/terminal_theme.json`). The surface token is application-owned and path
validated; the file uses the existing schema-2 loader and trusted asset directory.
An absent, invalid or disabled file leaves the existing Crossroads rendering in
place. The example does not activate itself. Root/front-door M2 configuration
and its M1 backup are unaffected by this separate surface selection.

Unlike Slice 1, Slice 2 changes `DoorHandler`, `TuiShell`, and `TelnetUtils`, which
both terminal daemon entrypoints eagerly include before forking connections.
**Human-present activation is required:** select the Crossroads example as the
surface runtime file, then restart the affected Telnet/SSH daemon only with
explicit authorization. A reconnect alone cannot replace classes already loaded
in the daemon parent. This implementation transaction does not select the runtime
file or restart services.

Minimal SyncTerm acceptance after activation: reconnect at effective 80x24,
enter Crossroads, judge spacing and identity, move across several destinations
and a category boundary, inspect quiet/live context, enter an Experience detail
and return, then Q back to the accepted front door. Technical tests are not
visual/product acceptance. Expanded templates and other authored spaces remain
deferred.

## Behaviour at runtime

- **Hotkeys** work as before. Arrow keys / Enter drive a lightbar.
- **Back** = `Left` arrow, `Esc`, or `B` (when no item uses `b`). At the root,
  Back logs off.
- **Home** = `H` (when no item uses `h`) jumps to the root from a submenu.
- **Orientation**: submenu screens show a `Main > Messages` context line and the
  available Back / Home hints.
- **Resize**: a NAWS change reflows on the next redraw, like the built-in menu.
- **Short terminals**: the title, orientation line, status line and footer are
  always kept; the item list is clipped from the bottom with a "more" indicator.
- **Line shell**: low-capability sessions get a plain numbered list; unavailable
  options are simply omitted.
- Access-hidden and disabled items never leave a dangling hotkey.

---

## Preview

`BinktermPHP\Terminal\Navigation\NavigationPreviewService` renders a definition
off-session — no socket, no login, no database — at an arbitrary geometry /
charset / colour / access profile, through the same renderer the live session
uses. The existing F6 admin editor consumes this same preview path.

```php
$service = new NavigationPreviewService();
$bytes = $service->render($definition, NavigationPreviewProfile::geometry('132x51'));
```

`render()` always uses the flowing renderer (theme-independent content preview).
`renderReport()` uses the **same renderer selection the live terminal uses** — a
validated, enabled theme where the geometry is themed, the flowing renderer
otherwise — and returns `{bytes, mode: 'themed'|'fallback', reason, lines,
theme_status}`. `lines` is a positioning-resolved 24-row grid (via
`AnsiScreenBuffer`) so a browser preview that only understands SGR can show a
themed layout faithfully. The F6 admin editor's preview pane renders `lines` and
shows a **THEMED** / **FALLBACK** badge with the reason.

`NavigationPreviewService::standardProfiles()` covers the standard matrix
(80x24 UTF-8 / CP437 / ASCII, 132x36, 132x51).

---

## Saving a definition (backend)

The web process cannot write `config/`, so a saved definition goes through the
admin daemon:

```
admin/web layer  →  AdminDaemonClient::saveTerminalNavigationConfig($json)
                 →  admin daemon: save_terminal_navigation_config
                 →  NavigationConfigWriter::write($json, <fixed config path>, <config dir>)
```

`NavigationConfigWriter` is a plain service (no IPC) with these guarantees:

- **Validate before write** — the payload must parse and fully validate as a
  `NavigationDefinition`; on failure the result carries the errors and nothing
  is written.
- **Canonical JSON** — pretty-printed, unescaped slashes/unicode, one trailing
  newline; key order preserved.
- **Atomic** — written to a randomly-named temp file in the same directory
  (`fopen` with `x` so it can't clobber a racing file), flushed and `fsync`-ed,
  `chmod`-ed to the existing file's mode (else `0644`), then `rename`-d into
  place. A failure at any step unlinks the temp file and leaves the previous
  valid config untouched — there is never a partial or truncated destination.
- **Path-constrained** — the target must be a plain `*.json` name sitting
  *directly* inside the caller-supplied base directory (resolved with
  `realpath`); a pre-existing symlink that escapes the base, a traversal path,
  or a non-regular target is refused. The admin daemon always passes the fixed
  `config/terminal_navigation.json` path and its directory — it never forwards a
  `TERMINAL_NAV_CONFIG` override for writes.

The daemon response is structured: `{written, valid, errors[], bytes, path,
canonical, io_error}`. A validation failure comes back with `ok:true` /
`written:false` so an editor can show the errors inline; only a real I/O failure
is an `ok:false` error.

There is no admin route, template, or JS for this yet — that is the editor's
job. This is the write boundary it will call.
