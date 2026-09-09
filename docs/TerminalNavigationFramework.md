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
never reference a PHP class or callback. The available ids mirror the built-in
menu:

`netmail`, `echomail`, `bulletins`, `qwk`, `shoutbox`, `localchat`, `polls`,
`whosonline`, `doors`, `interests`, `files`, `freqrequests`, `bbslist`,
`nodelist`, `settings`, `quit`.

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

The renderer decides the actual layout for the caller's geometry, charset, and
colour capability.

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
uses. This is the foundation the planned sysop editor's live preview builds on.

```php
$service = new NavigationPreviewService();
$bytes = $service->render($definition, NavigationPreviewProfile::geometry('132x51'));
```

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
