# Crossroads Freeze Checkpoint — 2026-08-31

> Formal freeze of the Crossroads development line before **Time Machine M0**
> begins. This document is written so a future developer or agent can resume
> from it **without conversation history**.

---

## 1. Git baseline

| Fact | Value |
|---|---|
| Branch | `experience-lobby-v2` |
| Pre-checkpoint HEAD | `7265cd609329a869d8b7d779581d1c086abd2732` — "Polish BCR Crossroads experience" |
| BCR implementation commit | `9faa196ab3afc534440709335a5b0f05a00031a1` — "Add BCR Games Server to Crossroads" |
| BCR polish commit | `7265cd609329a869d8b7d779581d1c086abd2732` — "Polish BCR Crossroads experience" |
| Custom Crossroads icons commit | `ff1e196e` — "Add custom Crossroads Experience icons" |
| Native-door interpreter deps doc | `49307976` — "Document native-door interpreter dependencies" |
| Tristam Island door commit | `430cd04b` — "Add Tristam Island interactive fiction door" |
| Push state | Branch `experience-lobby-v2` had **not** been pushed at or before the
pre-checkpoint HEAD. This checkpoint commit is the first push of this line. |

### Protected dirty / untracked paths (pre-existing, do NOT disturb)

These were untracked before this checkpoint and must remain untouched — not
modified, staged, restored, cleaned, or deeply inspected:

- `native-doors/doors/lord/lord-bridge.js`
- `src/Binkp/Connection/Scheduler.php.bak`

No other LORD changes are in scope.

---

## 2. BCR Games Server — FROZEN

**Status: complete and frozen. Do not apply further polish unless explicitly
reopened.**

- BCR is exposed as **one Crossroads Gateway Experience**, not three individual
  game cards. The individual BCR games (From Here To Eternity, Freedom Train,
  1NS0MN1A, shared BCR community features) live behind the single gateway.
- Remote endpoint: **`bcrgames.com:31337`** (operated by Shooter Jennings / Black
  Country Rock).
- Transport is the **generic native-door Telnet relay mechanism** — the system
  `telnet` client via a ~10-line wrapper (`native-doors/doors/bcrgames/bcr.sh`:
  `exec telnet -E -K bcrgames.com 31337`). It is **not** RLogin.
- **No BCR-specific platform source changes.** The integration is a native-door
  manifest + wrapper + an original icon.
- **No BinkTerm identity federation into BCR.** `-E` disables the Telnet escape
  character; `-K` disables auto-login. No BinkTerm username, real name, user id,
  password, or drop-file data is offered to BCR. The caller lands on BCR's own
  login / create-character screen.
- **BCR remains authoritative** for its own accounts, characters, authentication,
  content, and internal game/session state.
- **Crossroads knows only local relay facts** — a relay session is active, who is
  connected locally, local relay-session count, prior launches, session end.
  Crossroads has no visibility into BCR's internal player state.
- `experience.multiplayer` **remains `false`** (manifest
  `native-doors/doors/bcrgames/nativedoor.json`). Two L33TEST users who launch the
  gateway get independent BCR sessions with independent BCR logins; Crossroads
  cannot truthfully represent them as co-players.
- `experience.category` **= `gateway`**.
- **Generic `ExperiencePresentation` `player_mode` rule:** `capabilities.player_mode`
  is derived as `multiplayer`/`single_player` **only** for `category: game`
  Experiences, and `null` for gateways (and any non-game). A gateway is therefore
  never labelled "Single Player" merely because its multiplayer flag is `false`.
  The web library card and lobby read `player_mode`; a gateway shows just its
  category ("Gateway"). This rule contains **no BCR-specific conditional**.
- **Original L33TEST-owned icon** installed at
  `native-doors/doors/bcrgames/icon.png` (512×512 PNG, RGBA, 8-bit,
  non-interlaced, ~361 KB), wired via `game.icon` in the manifest. It was
  generated specifically for this integration and is **not** copied, extracted,
  or derived from any Shooter Jennings / Black Country Rock artwork or asset. The
  source artwork lives outside the repo and is not committed.
- **Web lifecycle manually proven:** launch → use → exit → clean return to
  `/experiences/bcrgames`.
- **Telnet / SyncTerm lifecycle manually proven:** launch → login/use → exit →
  clean return to the Crossroads Experience catalog.

---

## 3. Crossroads product baseline

Guiding principles for all future Crossroads work:

- "Crossroads is where the people, the games, and the worlds come together."
- "Crossroads should feel like a place you enter, not a menu you open."
- "ANSI art can decorate a place. Interaction is what makes it feel like a
  place."
- "We're not collecting doors. We're collecting places worth going."
- **Web may lead, but Telnet / classic BBS remains a first-class surface.** Every
  Experience must be reachable and usable from the terminal surfaces.
- **Custom original / license-safe icon creation is now a normal
  Experience-onboarding completion step**, not an optional extra.

---

## 4. Existing frozen / completed nearby work

| Item | State |
|---|---|
| **Tristam Island** (interactive fiction, M1) | **Frozen.** Web + Telnet play/save proven. Uses `frotz`/`dfrotz` at runtime. |
| **Custom icons** — LORD (Legend of the Red Dragon) & Tristam Island | **Complete** (committed `ff1e196e`). |
| **Elsewhere / Tangaria** | **HARD PAUSED** pending developer response. Artwork is staged at `assets/crossroads-icons/elsewhere.png` but is intentionally **not** wired into the Elsewhere manifest. **Do not touch Tangaria/Elsewhere source, config, or integration while paused.** |
| **Terminal Dungeon** | **Blocked** by IP concerns. |
| **Terminal Space Program** | **MAYBE SOMEDAY** — requires a 104×24 Unicode/Braille-capable terminal, which is an unreasonable baseline requirement today. |
| **dmud** | **Parked / blocked** — missing license. |
| **BCR Games Server** | First clean, independently operated **remote shared destination** integrated through the generic native-door Telnet transport. Reference pattern for future remote gateways. |
| **termType exploration** | **Deferred.** Do not resume automatically. |

---

## 5. Runtime / deployment state

- **`config/nativedoors.json` is runtime-only and is NOT tracked in the
  repository** (it is gitignored). It contains the enabled BCR configuration and
  **remains uncommitted by design.** Nothing in this checkpoint tracks it.
- BCR runtime values currently in `config/nativedoors.json`:

  | Key | Value |
  |---|---|
  | `enabled` | `true` |
  | `credit_cost` | `0` |
  | `max_time_minutes` | `120` |
  | `max_concurrent_sessions` | `10` |
  | `allow_anonymous` | `false` |
  | `guest_max_sessions` | `0` |

- The **Telnet supervisor service was restarted** after enabling BCR so it is
  available over Telnet/SSH in already-running sessions.
- The out-of-repo `/root/binktermphp/docker/Dockerfile` has **`frotz` added after
  `dosbox`**. This is a known deployment / reproducibility item (Tristam needs
  `frotz`/`dfrotz` at runtime) and is **not part of this checkpoint commit**.
- Runtime host has `frotz`/`dfrotz` available (used by Tristam Island).

---

## 6. Known test / environment issues (do NOT chase in checkpoint scope)

- **`DoorHandlerCatalogContractTest`** —
  `ReflectionException: Class "BinktermPHP\TelnetServer\TelnetUtils" does not
  exist`. Pre-existing test/autoload ordering + environment artifact in the web
  PHPUnit bootstrap; test-order dependent (passes when another test loads the
  class first). **Unrelated to BCR.**
- **`I18nCatalogTest`** — errors on missing
  `config/i18n/overrides/{common,errors}.php`. Pre-existing environment artifact;
  `overrides/` is explicitly outside the base-locale set.
- **BCR polish regression batch:** 337 tests OK (Experience / DoorHandler /
  GameCatalog / WebCrossroads / BCR manifest+wrapper+catalog / Crossroads icon
  contract), aside from the `TelnetUtils` ordering error above.
- **BCR implementation broader batch (earlier):** 259/260 passed, with the same
  `TelnetUtils` issue as the single failure.

---

## 7. New active frontier

### TIME MACHINE M0 — DENVER, COLORADO

**This begins as research / design only. No application implementation yet.**

**Core concept:**
> "Choose a place and a moment. Enter the surviving historical record without
> hindsight."

**Integrity principle:**
> "The record may be incomplete. The machine must not invent what history failed
> to preserve."

**Working distinctions:**
- Access is not permission.
- Metadata is not evidence.
- Publication is not knowledge.
- OCR is not truth.

**M0 outputs:**
- prior-art survey
- Denver Source Atlas
- rights / access matrix
- temporal / hindsight integrity model
- geographic model
- provenance model
- one deliberately small Denver **proof corridor**, chosen from evidence density,
  not from fame alone

**Unresolved:** the exact Denver dates are **not** chosen. Do not select them
from this checkpoint.

---

## Resuming from here

1. Checkout `experience-lobby-v2` at the checkpoint commit (see §1).
2. Treat everything in §2–§4 as frozen; do not reopen without explicit
   instruction.
3. Restore runtime `config/nativedoors.json` from the values in §5 if working on
   a fresh host (it is not in the repo).
4. Begin Time Machine M0 as **research/design only** per §7.
