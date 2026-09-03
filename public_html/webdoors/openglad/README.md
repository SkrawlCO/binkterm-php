# OpenGlad WebDoor — M4 Slice 1A/1E (admin-only integration proof)

**Status: admin-only assay. NOT an ordinary-user Experience. NOT production-complete.**

This WebDoor runs the pinned OpenGlad Web/WASM client — **with one tracked
downstream carry patch** (`docs/Crossroads/openglad-backend/patches/0001-web-persist-namespace.patch`, pending
openglad/openglad#281) — as a BinkTermPHP WebDoor, with multiplayer routed
through a **self-hosted, loopback** OpenGlad relay at the same-origin path
`/openglad-relay`, and **per-user browser persistence isolation**. It is the
A-leg of the approved Architecture C (Hybrid). See:

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
| Build | `build-webdoor.sh <openglad-src>` — applies `docs/Crossroads/openglad-backend/patches/*.patch` to the checkout, `cmake --preset web-emscripten --target play`, stages here |
| Canonical OpenGlad tree | **never patched** — the carry is applied to a fresh throwaway clone |

## What is tracked vs staged

**Tracked (git):** `webdoor.json`, `index.php`, `crossroads-glue.js`,
`build-webdoor.sh`, `icon.svg`, `README.md`, `.gitignore`. The carried OpenGlad
patch + its README live under `docs/Crossroads/openglad-backend/` (the
`multizork-backend` precedent — **not** under `public_html`).

**Staged, git-ignored** (regenerate with `build-webdoor.sh`):
`play.html`, `play.js`, `play.wasm` (~8 MB), `play.data` (~3 MB),
`manifest.webmanifest`. `play.html` is the pinned OpenGlad shell **verbatim** —
no glue is baked into it.

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
   (OpenGlad's own shipped relay override — multiplayer uses the L33TEST relay,
   never `openglad.pages.dev`);
2. calls `GET /api/webdoor/session?game_id=openglad` for the normal WebDoor
   session lifecycle (presence + `webdoor_play` footprint; end beacon fired by
   `templates/webdoor_play.twig`).

No IndexedDB manipulation, no save export/import, no direct-connect transport.
Per-user isolation is the carried patch's job, not this file's.

## Admin-only

`webdoor.json` sets `requirements.admin_only: true` (the WebDoor capability from
commit `899caa1e`). Withheld from non-admin discovery; `/games/openglad` 403s
non-admins; catalog-driven APIs fail closed. `index.php` re-checks (defence in
depth + covers a direct `/webdoors/openglad/index.php` hit). Raw static assets
(`play.wasm`, …) remain directly fetchable — a WebDoor-inherent property; a
direct `play.html`/`play.js` load gets no namespace injected → upstream default
`/persist`, no BinkTerm identity, no working multiplayer.

Enable/disable via **Admin → WebDoors** or `config/webdoors.json`
(`"openglad": {"enabled": true|false}`).

## Deployment (assay — TEMPORARY, container-writable-layer)

The relay + same-origin `wss://` path are not in this directory:

1. `scripts/openglad/openglad-relay-runtime.cjs` (tracked) as
   `[program:openglad-relay]` on `127.0.0.1:6035` **inside `binkterm-app`** — a
   live `supervisord.conf` edit (`[program:multizorkd]` precedent). Lost on
   container recreate.
2. Container `/etc/caddy/Caddyfile`: `handle_path /openglad-relay/* {
   reverse_proxy 127.0.0.1:6035 }` — an in-container edit to an image-baked file,
   lost on recreate.
3. Host Apache vhost: `ProxyPass /openglad-relay ws://127.0.0.1:8090/openglad-relay`
   — a **live public production reverse-proxy edit** (host filesystem; keep a
   timestamped backup).

Applied only for supervised acceptance windows, then reverted (Slice 1A/1E
teardown). **Durable production would require:** a pinned-artifact pipeline for
the built client + patch, the relay built into the image, and the proxy routing
as managed config.
