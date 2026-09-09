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

If the file is missing, the flag is off, or the definition fails validation, the
terminal server silently stays on the built-in menu — a bad definition can never
break terminal login. Validation errors are written to the terminal server log.

Restart the terminal/SSH daemons after changing the file or the flag.

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

---

## Behaviour at runtime

- **Hotkeys** work as before. Arrow keys / Enter drive a lightbar.
- **Back** = `Left` arrow, `Esc`, or `B` (when no item uses `b`). At the root,
  Back logs off.
- **Home** = `H` (when no item uses `h`) jumps to the root from a submenu.
- **Orientation**: submenu screens show a `Main > Messages` context line and the
  available Back / Home hints.
- **Resize**: a NAWS change reflows on the next redraw, like the built-in menu.
- **Short terminals**: the title and orientation line are always kept; the item
  list is clipped from the bottom with a "more" indicator.
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
