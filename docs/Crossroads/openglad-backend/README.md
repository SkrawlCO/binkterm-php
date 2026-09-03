# OpenGlad backend (Crossroads Experience #3) — downstream carry patches

The Crossroads OpenGlad WebDoor (`public_html/webdoors/openglad/`) runs the
pinned OpenGlad Web/WASM client. OpenGlad's own repo is **not vendored** and is
**not modified**; a temporary downstream change is kept here as a tracked
`.patch` file (the `multizork-backend/` / `ascii-royale-backend/` precedent) and
applied by `../../../public_html/webdoors/openglad/build-webdoor.sh` to a fresh
throwaway clone before the build.

| | |
|---|---|
| Upstream repo | `github.com/openglad/openglad` |
| Pinned revision | `4565499825c25b0943ab0f6e1e5403af752e63ed` (GPL-2.0) — **not advanced** |
| Toolchain | Emscripten SDK `6.0.3` |
| Build | `cmake --preset web-emscripten` → `cmake --build --preset web-emscripten --target play` |
| Local patches | **1** (below) |

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
