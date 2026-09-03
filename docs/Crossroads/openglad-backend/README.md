# OpenGlad backend (Crossroads Experience #3)

The Crossroads OpenGlad WebDoor (`public_html/webdoors/openglad/`) runs the
pinned OpenGlad Web/WASM client, with multiplayer through a self-hosted
same-origin relay and per-user browser persistence. OpenGlad's own repo is
**not vendored** and is **not modified**; a temporary downstream change is kept
here as a tracked `.patch` file (the `multizork-backend/` / `ascii-royale-backend/`
precedent) and applied by `build/build-webdoor.sh` to a fresh throwaway clone
before the build.

```
openglad-backend/
├── README.md                     ← this file (pin, the carry patch, its removal condition)
├── patches/
│   └── 0001-web-persist-namespace.patch   window.__opengladPersistNamespace (pending #281)
├── build/
│   ├── build-webdoor.sh           pin -> clone -> apply patch -> emscripten build -> stage + build.env
│   ├── verify-webdoor.sh          staged tree vs EXPECTED.sha256 — THE pre-deploy gate
│   └── EXPECTED.sha256            accepted play.* hashes for (pin, patch-set, toolchain)
├── runtime/
│   ├── supervisord.openglad-relay.conf.fragment   [program:openglad-relay] (bake into the image)
│   ├── caddy.openglad-relay.snippet               same-origin /openglad-relay route
│   └── apache.openglad-relay.snippet              host vhost ProxyPass
└── test/
    ├── run-regression.sh          hermetic relay contract/auth/limits regression
    ├── relay-contract.mjs         33 black-box assertions
    └── README.md
```

Production deployment (the durable relay, proxy routing, artifact pipeline,
rollback) is in [`../OpenGladProduction.md`](../OpenGladProduction.md).

| | |
|---|---|
| Upstream repo | `github.com/openglad/openglad` |
| Pinned revision | `4565499825c25b0943ab0f6e1e5403af752e63ed` (GPL-2.0) — **not advanced** |
| Toolchain | Emscripten SDK `6.0.3` |
| Build | `build/build-webdoor.sh` — clone pin, `git apply` `patches/[0-9]*.patch`, `cmake --preset web-emscripten --target play`, stage, write `build.env` |
| Local patches | **1** (below) |
| Relay | `scripts/openglad/openglad-relay-runtime.cjs` — self-contained, multi-room, loopback-only; auth via the existing WebDoor session authority |

---

## patches/0001-web-persist-namespace.patch

| | |
|---|---|
| **Upstream issue** | **openglad/openglad#281** — <https://github.com/openglad/openglad/issues/281> |
| **Status** | OPEN — capability discussion; **no upstream PR yet** |
| **Scope** | `src/resources/platform_io.cpp` only, entirely `#ifdef __EMSCRIPTEN__` |
| **What it does** | reads `window.__opengladPersistNamespace` (opaque `[A-Za-z0-9_-]`, 1–64 chars) before IDBFS mount; a valid token mounts IDBFS at `/persist_<token>` and roots `get_user_path()` there — a distinct IndexedDB-backed store per token. Unset / empty / invalid keeps upstream's `/persist` behaviour (a present-but-invalid token logs one console warning). |
| **Why we carry it** | OpenGlad's Web build persists to IndexedDB scoped to the page origin, so multiple BinkTerm users on one origin share one set of Companies. Runtime-proven in M4 Slice 1A (identity-isolation gate FAILED as predicted). This patch is the fix. |
| **Native impact** | none — one-blank-line textual delta on the non-Emscripten path; `-fsyntax-only -Wall -Wextra` clean with `__EMSCRIPTEN__` undefined |
| **Runtime-proven** | M4 Slice 1C (25/25 isolation gates + 31/31 existing web e2e) and re-proven on the cleaned form + on the deployed stack in M4 Slice 1E |
| **Provenance line (in the patch header)** | `Downstream carry pending openglad/openglad#281` |
| **Design record** | `/root/openglad-assay/OPENGLAD_M4_SLICE1{B,C,D,E}_*.md`, `OPENGLAD_UPSTREAM_TRACKING.md` (UT-1) |

### BinkTerm side (not in this patch)

- `src/Crossroads/OpengladPersistNamespace.php` — derives the per-user token
  `substr(sha256("openglad-persist-v1:" . users.id), 0, 40)`. **Not** keyed on
  `APP_SECRET` — a persistence-partition id must survive a secret rotation, or
  every user's Companies vanish on rotation. It is a partition identifier, never
  authentication/authorization.
- `public_html/webdoors/openglad/index.php` — the WebDoor entry point: **fails
  closed** (HTTP 403, no game) if it cannot resolve an admin user id, then
  injects `<script>window.__opengladPersistNamespace="<token>";</script>` into
  the served `play.html`. It never lets OpenGlad run on the shared `/persist`
  for this authenticated multi-user deployment.

### Removal / convergence condition

> **Drop `patches/0001-web-persist-namespace.patch`** when
> `window.__opengladPersistNamespace` (or the maintainers' accepted equivalent)
> lands in a **tagged OpenGlad release that the WebDoor pins to** — then bump the
> pin and delete the patch.
>
> **If the upstream-accepted form differs** (API name, validation rules,
> `EM_ASM` vs `EM_JS`), **update this patch to match the merged version** before
> that release, so the carried build always *converges toward* upstream and
> never diverges. Update `index.php` / `crossroads-glue.js` if the accepted API
> name changes.
>
> Re-check #281 at every Crossroads milestone.

### Verify the patch still applies

```sh
git clone https://github.com/openglad/openglad.git /tmp/og && cd /tmp/og
git checkout 4565499825c25b0943ab0f6e1e5403af752e63ed
git apply --check /path/to/docs/Crossroads/openglad-backend/patches/0001-web-persist-namespace.patch
```

## Build + verify the WebDoor artifacts

```sh
cd docs/Crossroads/openglad-backend/build
./build-webdoor.sh                 # throwaway clone of the pin + the carry patch + emscripten build
./verify-webdoor.sh                # staged public_html/webdoors/openglad/ vs EXPECTED.sha256
```

`play.{html,js,wasm,data}` + `manifest.webmanifest` and `build.env` are
git-ignored compiled output. Emscripten output is not guaranteed
bit-reproducible across toolchains, so `verify-webdoor.sh` is the gate: a build
that does not reproduce `EXPECTED.sha256` must be investigated (pin the
toolchain from the accepted `build.env`) before it ships. On a pin bump,
`EXPECTED.sha256`, `webdoor.json` `game.version`, and the pinned-revision line
above move together in one reviewed commit.

## Relay regression

```sh
docs/Crossroads/openglad-backend/test/run-regression.sh   # hermetic; ~90 s; needs docker
```

33 black-box assertions against the exact tracked
`scripts/openglad/openglad-relay-runtime.cjs`: the create/rooms/join/frame wire
contract, both sides of the authorization boundary (rejections leave no
room/peer state; no bypass), multi-room isolation, and the full limit table.
