# OpenGlad WebDoor — Crossroads Experience #3 (A-leg)

**Status: LIVE for ordinary authenticated users** (M4 Slice 1G).
`webdoor.json` `admin_only: false`; `config/webdoors.json` `openglad.enabled: true`
(site-local admin switch). Anonymous callers are still blocked (WebDoor routes
require login). Telnet/SSH remain **deferred** — the catalog shows Web
*available*, Telnet *planned*.

This WebDoor runs the pinned OpenGlad Web/WASM client — **with one tracked
downstream carry patch** (`docs/Crossroads/openglad-backend/patches/0001-web-persist-namespace.patch`, pending
openglad/openglad#281) — as a BinkTermPHP WebDoor, with multiplayer routed
through a **self-hosted, loopback, multi-room** OpenGlad relay at the same-origin
path `/openglad-relay`, and **per-user browser persistence isolation**. It is the
A-leg of the approved Architecture C (Hybrid). Production deployment:
**`docs/Crossroads/OpenGladProduction.md`**. See also:

- `/root/openglad-assay/OPENGLAD_M4_SLICE0_ARCHITECTURE.md` — architecture
- `/root/openglad-assay/OPENGLAD_M4_SLICE1A_REPORT.md` — mechanism proof (identity gate FAILED as predicted)
- `/root/openglad-assay/OPENGLAD_M4_SLICE1B_PROPOSAL.md` / `..._1C_REPORT.md` / `..._1D_UPSTREAM_READINESS.md` — the persistence-namespace seam
- `/root/openglad-assay/OPENGLAD_M4_SLICE1E_*.md` — this slice (carry + M4-A unblock)
- `/root/openglad-assay/OPENGLAD_UPSTREAM_TRACKING.md` — UT-1 (#281)

## Provenance of the staged build

| | |
|---|---|
| OpenGlad revision | `4565499825c25b0943ab0f6e1e5403af752e63ed` (GPL-2.0) — **pin not advanced** |
| Toolchain | Emscripten SDK `6.0.3` |
| Carried patches | `docs/Crossroads/openglad-backend/patches/0001-web-persist-namespace.patch` (`window.__opengladPersistNamespace`; see `docs/Crossroads/openglad-backend/README.md` for the removal / convergence condition) |
| Build | `docs/Crossroads/openglad-backend/build/build-webdoor.sh` — clones the pin, `git apply`s the carry patch(es), `cmake --preset web-emscripten --target play`, stages here, writes `build.env` |
| Verify | `docs/Crossroads/openglad-backend/build/verify-webdoor.sh` — staged tree vs `EXPECTED.sha256` (the pre-deploy gate) |
| Canonical OpenGlad tree | **never patched** — the carry is applied to a fresh throwaway clone |

## What is tracked vs staged

**Tracked (git):** `webdoor.json`, `index.php`, `crossroads-glue.js`,
`icon.svg`, `README.md`, `.gitignore`. The carried OpenGlad patch, the
build/verify scripts, the relay runtime + its regression harness, and the
runtime reference config all live under `docs/Crossroads/openglad-backend/` and
`scripts/openglad/` (the `multizork-backend` / `ascii-royale-backend` precedent
— **not** under `public_html`).

**Staged, git-ignored** (regenerate with `build-webdoor.sh`):
`play.html`, `play.js`, `play.wasm` (~8 MB), `play.data` (~3 MB),
`manifest.webmanifest`, `build.env`. `play.html` is the pinned OpenGlad shell
**verbatim** — no glue is baked into it. Deployed builds are layered into the
L33TEST image after `verify-webdoor.sh` passes.

## The integration code

### `index.php` — the entry point (server-side)

1. **Fails closed.** Requires a resolvable, immutable `users.id` **and** admin
   authorization; otherwise **HTTP 403, no game served**. It never falls back to
   OpenGlad's shared `/persist` for this multi-user deployment.
2. Derives an opaque per-user **persistence partition** token:
   `substr(sha256("openglad-persist-v1:" . users.id), 0, 40)` —
   deterministic per user, **stable across `APP_SECRET` rotation** (the secret is
   not an input), distinct per user, `[0-9a-f]{40}`. It is a partition
   identifier, **never** authentication or authorization; nothing server-side
   consumes it. It is not a secret (it becomes an IndexedDB database name).
3. Serves the pinned `play.html` with, injected right after `<head>`:
   `<script>window.__opengladPersistNamespace="<token>";</script>` then
   `<script src="crossroads-glue.js"></script>`. Race-free — the global is a
   literal, present before the async `play.js` runs.

### `crossroads-glue.js` — client-side

1. sets `window.__opengladRelayBaseUrlForTests = location.origin + '/openglad-relay'`
   (OpenGlad's own shipped relay override — multiplayer uses the L33TEST
   self-hosted relay, never `openglad.pages.dev`);
2. calls `GET /api/webdoor/session?game_id=openglad` for the normal WebDoor
   session lifecycle (presence + `webdoor_play` footprint; end beacon fired by
   `templates/webdoor_play.twig`).

No IndexedDB manipulation, no save export/import, no direct-connect transport.
Per-user isolation is the carried patch's job, not this file's.

### The relay

`scripts/openglad/openglad-relay-runtime.cjs` — a self-contained, dependency-free
**multi-room** rendezvous, loopback-only, forwarding opaque binary frames. Every
`/api/*` call is authorized by replaying the caller's cookie against
`GET /api/webdoor/session?game_id=openglad` (the same authority that gates this
WebDoor), so the relay follows this manifest's `admin_only` automatically and has
no auth system of its own. Limits, lifecycle, and deployment:
`docs/Crossroads/OpenGladProduction.md`; regression:
`docs/Crossroads/openglad-backend/test/run-regression.sh`.

## Access model (M4 Slice 1G — ordinary users)

`webdoor.json` `requirements.admin_only: false` — **any authenticated L33TEST
user** discovers and launches OpenGlad. Still fail-closed:

- WebDoor routes require login → **anonymous is blocked** (`/games/openglad`
  → `/login`; `GameCatalog::isWebDoorDiscoverable` needs a user; the relay's
  `/api/webdoor/session` auth check needs a session).
- `index.php` still requires a resolvable immutable `users.id` (HTTP 403 with no
  game otherwise) and derives the per-user persistence namespace — **never** the
  shared `/persist` on the L33TEST path.
- The relay still delegates authorization to
  `GET /api/webdoor/session?game_id=openglad` — no OpenGlad-specific auth. When
  `admin_only` was `true` this same call blocked non-admins; with it `false` it
  admits any authenticated user and still rejects anon / stale sessions.
- Raw static assets (`play.wasm`, …) stay directly fetchable (WebDoor-inherent);
  a direct `play.html`/`play.js` load gets no namespace injected and no working
  multiplayer.

Enable/disable via **Admin → WebDoors** or `config/webdoors.json`
(`"openglad": {"enabled": true|false}`). Setting `enabled: false` removes it from
every catalog and every route/relay path 404/403s — the fast kill switch.

## Experience presentation

- `game.icon: "icon.png"` — a 512×512 RGBA PNG, **original L33TEST artwork**
  (hand-authored vector in `icon.svg`, rasterised via headless Chromium; no
  third-party or upstream OpenGlad assets). Motif: a gladius crossed with a
  trident over an arena oval, on the flat dark-rounded-panel used by the
  MultiZork / ascii-royale cards; OpenGlad's own parchment/gold/green palette.
  `GameCatalog::addWebDoors` serves it at `/webdoors/openglad/icon.png`.
- `game.name` / `game.description` — truthful product copy: a real-time action
  RPG, build a Company, host or join a browser match. **No** overclaim of a
  persistent shared world, a permanent communal arena, or mid-match joining.
- Surfaces — `GameCatalog` sets every WebDoor to `web: full`, `telnet: planned`.
  For OpenGlad that is correct: **Web available, Telnet deferred** (the curses
  client needs key press/release semantics mainstream SyncTERM lacked in M2).

## Deployment

Durable — see **`docs/Crossroads/OpenGladProduction.md`**. In summary:

1. `[program:openglad-relay]` (`scripts/openglad/openglad-relay-runtime.cjs`,
   `127.0.0.1:6035`) — **baked into the L33TEST image `supervisord.conf`**
   (the `multizorkd` / `ascii-royale-arena` precedent). Survives
   `--force-recreate`.
2. Container `/etc/caddy/Caddyfile`: the `handle_path /openglad-relay/*` block
   (`docs/Crossroads/openglad-backend/runtime/caddy.openglad-relay.snippet`) —
   **baked into the image Caddyfile**.
3. Host Apache vhost: `ProxyPass /openglad-relay …`
   (`docs/Crossroads/openglad-backend/runtime/apache.openglad-relay.snippet`) —
   **committed to the host's config management**, beside `/ws` and `/dosdoor`.

The built `play.*` are produced by
`docs/Crossroads/openglad-backend/build/build-webdoor.sh` from the pin + the
carry patch, checked by `verify-webdoor.sh` against `EXPECTED.sha256`, and
layered into the image.
