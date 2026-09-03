# OpenGlad production deployment (Crossroads Experience #3, M4 — A-leg)

This document covers the **durable** production runtime for the OpenGlad
Experience: the self-hosted multi-room relay as a managed companion service, the
same-origin `/openglad-relay` routing, the pinned Web-artifact build/verify
pipeline, provenance, and rollback.

The upstream pin, the one carried source patch and its removal condition, the
build scripts and the relay regression harness are in
[`openglad-backend/`](openglad-backend/README.md).

> **M4 Slice 1F** made the relay a durable, image-baked, supervisor-managed
> service, added the same-origin route to the L33TEST image/host baseline, and
> established the reproducible artifact pipeline — replacing the Slice 1A/1E
> acceptance-time `supervisorctl add` + hand-edited proxy config that was
> reverted at teardown.
>
> **Slice 1G** (not this slice) opens the Experience to ordinary authenticated
> users and ships a custom icon. Until then OpenGlad is **admin-only**
> (`webdoor.json` `requirements.admin_only: true`) and the site switch
> (`config/webdoors.json` `openglad.enabled`) is `false`.

---

## Architecture

```
 browser ── wss://binkterm.l33test.com/openglad-relay ─────────────┐   (same origin;
                                                                    │    CSP already allows)
 Cloudflare (pass-through, WebSockets on)                            │
                                                                    ▼
 host Apache :443   ProxyPass /openglad-relay ws://127.0.0.1:8090/openglad-relay
   (vhost baseline, beside /ws and /dosdoor)                         │
                                                                    ▼
 container Caddy :80   handle_path /openglad-relay/* { reverse_proxy 127.0.0.1:6035 }
   (image-baked Caddyfile; @compressible excludes it)               │
                                                                    ▼
 [program:openglad-relay]  node scripts/openglad/openglad-relay-runtime.cjs
   127.0.0.1:6035, loopback only, supervised, image-baked
   • multi-room; upstream limit table
   • per /api/* call: replay Cookie -> GET 127.0.0.1/api/webdoor/session?game_id=openglad
     (authorized == 200 + session_id + game.id=="openglad"; else refused, no state)
   • in-process room map only — no disk / DB / volume / secret-bearing log

 WebDoor (BinkTerm core, unchanged):
   /games/openglad → webdoor_play.twig (same-origin iframe) → /webdoors/openglad/index.php
     • index.php: fail-closed 403 unless authenticated + admin + enabled
     • injects window.__opengladPersistNamespace
         = substr(sha256("openglad-persist-v1:" || users.id), 0, 40)
       + crossroads-glue.js (relay base + /api/webdoor/session presence)
   play.{html,js,wasm,data}: built by openglad-backend/build/build-webdoor.sh from
     pin 4565499… + patches/0001-web-persist-namespace.patch, hash-verified against
     EXPECTED.sha256, layered into the image (git-ignored in the working tree)
```

**No BinkTermPHP source change.** The relay, the routing, the artifact pipeline
and the service management are entirely L33TEST-owned deployment/integration.
Authentication, session, lifecycle and presence remain BinkTerm's, used as-is.

---

## Service lifecycle

`binkterm-app`'s image runs `supervisord`
(`/etc/supervisor/conf.d/supervisord.conf`) for every long-lived companion
(`caddy`, `php-fpm`, `binkp_server`, `dosdoor_bridge`, `telnet`, `realtime_server`,
`multizorkd`, `ascii-royale-arena`, …). The OpenGlad relay is registered the same
way, as **`[program:openglad-relay]`**.

**DURABLE placement:** the block is **baked into the L33TEST image's
`supervisord.conf`** — the same file that already carries `multizorkd` and
`ascii-royale-arena`. Once baked, the relay returns on
`docker compose up --force-recreate` / an image rebuild with **no hand steps**.
This is the difference from the Slice 1A/1E acceptance-time `supervisorctl add`,
which lived on the container writable layer and was reverted at teardown.

The tracked reference block is
[`openglad-backend/runtime/supervisord.openglad-relay.conf.fragment`](openglad-backend/runtime/supervisord.openglad-relay.conf.fragment).

Operational commands (inside `binkterm-app`):

```
supervisorctl status openglad-relay
supervisorctl restart openglad-relay      # drops all live rooms (ephemeral); players relaunch
supervisorctl tail openglad-relay         # recent stdout — carries NO secrets
```

| Concern | Design |
|---|---|
| **Runs as** | the unprivileged `binkterm` account — the relay needs no privilege |
| **Port** | `127.0.0.1:6035`, fixed, **loopback only** (the runtime refuses a non-loopback `OPENGLAD_RELAY_HOST`). Not published to the host; only the container's Caddy reaches it. Reserve `6035` in the port ledger next to `6001` (dosdoor) / `6010` (realtime). |
| **Startup** | `autostart=true`; `startsecs=5` catches a crash-loop |
| **Restart** | `autorestart=true`, `startretries=5`. Rooms are ephemeral (no cross-service coordination, unlike the ascii-royale `dosdoor_bridge` env dependency). |
| **Crash** | non-zero exit → supervisor restart; nothing persistent lost (there is none); a mid-match client sees its WS close and OpenGlad reports the link `Lost` |
| **Health** | `supervisorctl status`; the runtime's 60 s `alive` heartbeat line; `GET /openglad-relay/healthz` → `200 ok` (ungated) as an external probe; `GET /openglad-relay/api/rooms` (authorized) → `200 []` when idle |
| **Logging** | `data/logs/openglad_relay{,_error}.log` (covered by `logrotate.php`). The relay logs **no secrets** — owner tokens, cookies and frame bodies are never logged; only room codes, peer ids and counts. Unlike `multizorkd` / `ascii-royale` there is **no** private-log requirement. |
| **State** | in-process room map + timers only. No disk, no database, no volume. A restart drops rooms — acceptable and matching the `ascii-royale-arena` "endpoint rotates on restart" disclosed limitation. |

### Limits (implemented from `relay/src/shared.ts` — not designed here)

| Limit | Value | Enforcement |
|---|---|---|
| Peers per room | 16 (1 reserved for the owner) | HTTP 409 on join |
| Inbound binary frame | 128 KiB; broadcast body 128 KiB−4 | WS close 1009 |
| Inbound TEXT message | 4 KiB | WS close 1009 |
| Per-connection rate | 2000 msgs or 8 MiB per second | WS close 1008 |
| Room creates per IP | 10 per minute | HTTP 429 |
| Global room ceiling | 256 | HTTP 503 |
| Empty-room TTL | 120 s | sweep deletes |
| Owner never connects | 5 min | sweep deletes |
| Absolute room age | 12 h | sweep deletes |

The per-IP create cap keys on the **real** visitor address: host Apache
`mod_remoteip` resolves it from `CF-Connecting-IP` and passes it as `X-Real-IP`;
the Caddyfile global block turns that into `{client_ip}`; the Caddy snippet
forwards it as `X-Real-IP` to the relay, which reads it (`OPENGLAD_RELAY_TRUST_XREALIP`,
default on).

`relay/README.md`'s "8 peers per room" line is **stale** — `MAX_ROOM_PEERS = 16`
in the source, and the game seats 16.

### Authorization boundary

Every `/api/*` call (not `OPTIONS` preflight, not `/healthz`) is gated by
replaying the caller's `Cookie` against **the existing WebDoor session
authority**:

```
GET http://127.0.0.1/api/webdoor/session?game_id=openglad     (OPENGLAD_RELAY_AUTH_URL)
```

Authorized ⇔ HTTP **200** *and* a non-empty `session_id` *and*
`game.id == "openglad"`. That endpoint already enforces "authenticated **and**
OpenGlad-authorized" via `WebDoorController::getSession()` →
`resolveAuthorizedWebDoorGameId()` → `GameCatalog::isWebDoorDiscoverable()` — so
it already honours `requirements.admin_only`, and it will follow a future
admin-only → all-users flip automatically.

Anything else (401 no session, 404 not authorized / disabled, 5xx, timeout) →
the relay refuses: `POST`/`GET` → **401**, WS upgrade → refused with HTTP 401 —
and **no room or peer state is created**. This is **not a second auth system**;
there is **no bypass** — the check runs in the relay, so a caller reaching
`127.0.0.1:6035` directly (past the reverse proxy) is gated identically. A
positive result is cached ~15 s (`OPENGLAD_RELAY_AUTH_CACHE_MS`) so a burst of
create/join calls does not hammer php-fpm; negative results are never cached.

---

## Same-origin `/openglad-relay` routing

The stock BinkTerm image delegates all reverse-proxy routing to the operator's
front tier (`/ws` itself has no in-repo proxy rule), so `/openglad-relay` is an
L33TEST deployment-config concern — like `/ws` and `/dosdoor` already are.

**Two hops, each carrying `/ws` and `/dosdoor` durably today:**

1. **Container `/etc/caddy/Caddyfile`** (image-baked). Add the `handle_path`
   block from
   [`openglad-backend/runtime/caddy.openglad-relay.snippet`](openglad-backend/runtime/caddy.openglad-relay.snippet)
   inside the existing `route { … }`, and add `/openglad-relay` to the
   `@compressible` `not path` list. `handle_path` **strips** the prefix so the
   relay sees `/api/…` at its root.
2. **Host `/etc/apache2/sites-enabled/binkterm.l33test.com-le-ssl.conf`**. Add the
   two `ProxyPass` lines from
   [`openglad-backend/runtime/apache.openglad-relay.snippet`](openglad-backend/runtime/apache.openglad-relay.snippet),
   **before** the catch-all `ProxyPass /`. Commit that file to the host's config
   management — that is the difference from the Slice 1A/1E hand-edit.

**CSP: no change.** `connect-src 'self' wss://binkterm.l33test.com` already
permits `wss://binkterm.l33test.com/openglad-relay`; `script-src … 'wasm-unsafe-eval'`
already permits the WASM.

**Cloudflare: no change.** Pass-through for this origin; WebSockets are on;
`/ws` already traverses it.

Deploy discipline: `caddy reload` (route add), `apache2ctl configtest` →
`graceful` (vhost add). Never a full restart; `/`, `/ws`, `/dosdoor` untouched.

---

## Pinned Web-artifact pipeline

| Artifact | State |
|---|---|
| `index.php`, `webdoor.json`, `crossroads-glue.js`, `icon.svg`, `README.md`, `.gitignore` | **tracked** — L33TEST integration source |
| `patches/0001-web-persist-namespace.patch`, `build/*`, `runtime/*`, `test/*` | **tracked** — carry patch, pipeline, reference config, regression |
| `scripts/openglad/openglad-relay-runtime.cjs`, `README.md` | **tracked** — the relay |
| `public_html/webdoors/openglad/play.{html,js,wasm,data}`, `manifest.webmanifest`, `build.env` | **git-ignored** — compiled output + provenance; rebuild, do not commit |

### Build + verify

```sh
cd docs/Crossroads/openglad-backend/build
./build-webdoor.sh          # throwaway clone of the pin, git apply the carry patch,
                            # emscripten build, stage play.* into the WebDoor dir, write build.env
./verify-webdoor.sh         # staged tree vs EXPECTED.sha256  ← THE pre-deploy gate
```

`build.env` records: pin commit, `emcc`/`cmake`/`node` versions, host OS, each
carry patch + its sha256, and the sha256 of every staged file. Emscripten output
is **not** guaranteed bit-reproducible across toolchains — `verify-webdoor.sh` is
therefore *the* gate: a rebuild whose hashes differ from `EXPECTED.sha256` means
pin `BUILD_IMAGE`/toolchain to the accepted `build.env` and escalate **before**
deploying an artifact whose hash is not listed.

### Image build

The L33TEST image build (or a pre-image CI step) runs `build-webdoor.sh` against
the pinned clone, runs `verify-webdoor.sh`, and copies the verified `play.*` into
`public_html/webdoors/openglad/` in the image layer. The bind-mounted app dir
keeps its git-ignored copy for dev.

### Not vendored

Canonical OpenGlad is never checked in. The only delta from the pin is
`0001-web-persist-namespace.patch` (one file, `#ifdef __EMSCRIPTEN__` only,
`+78/−24`). `build.env` + `EXPECTED.sha256` make "pin + exactly this patch + this
toolchain → these bytes" auditable.

### Advancing the pin

A deliberate, separately-reviewed change: `build-webdoor.sh` `PIN`,
`webdoor.json` `game.version`, `openglad-backend/README.md`'s pinned-revision
line, and `EXPECTED.sha256` move together in **one commit**; re-run the isolation
+ web-e2e proofs; re-check the carry patch still applies (or update it per the
convergence condition). Until then the pin does not move.

---

## Deploying (controlled live provisioning)

Run inside the **current** `binkterm-app` container for an acceptance window; the
durable form is the same content baked into the image (above).

```sh
# 1. build + verify the artifacts (host, or CI)
docs/Crossroads/openglad-backend/build/build-webdoor.sh
docs/Crossroads/openglad-backend/build/verify-webdoor.sh

# 2. relay program — BACK UP THE CONFIG FIRST
cp -a /etc/supervisor/conf.d/supervisord.conf \
      /etc/supervisor/conf.d/supervisord.conf.bak.<UTC>
#   append openglad-backend/runtime/supervisord.openglad-relay.conf.fragment
supervisorctl reread && supervisorctl add openglad-relay

# 3. container Caddy — BACK UP FIRST
cp -a /etc/caddy/Caddyfile /etc/caddy/Caddyfile.bak.<UTC>
#   add openglad-backend/runtime/caddy.openglad-relay.snippet + the @compressible exclusion
caddy validate --config /etc/caddy/Caddyfile && caddy reload --config /etc/caddy/Caddyfile

# 4. host Apache vhost — BACK UP FIRST (host filesystem)
cp -a /etc/apache2/sites-enabled/binkterm.l33test.com-le-ssl.conf <path>.bak.<UTC>
#   add openglad-backend/runtime/apache.openglad-relay.snippet before ProxyPass /
apache2ctl configtest && systemctl reload apache2

# 5. enable the Experience (admin-only until Slice 1G)
#   config/webdoors.json  "openglad": { "enabled": true }   (Admin → WebDoors)
```

Never restart the whole container or unrelated supervisor programs for this.

---

## Validation (record results in the slice report)

1. `supervisorctl status openglad-relay` → `RUNNING`; the process runs as
   `binkterm`, bound to `127.0.0.1:6035` only.
2. `GET /openglad-relay/healthz` → `200 ok` through the real
   Cloudflare→Apache→Caddy chain.
3. **Recreate survival:** on a container built the durable way (supervisord block
   + Caddy route baked), `docker rm -f` + recreate → the relay auto-starts and
   `/openglad-relay/api/rooms` responds, **with no hand steps**.
4. **Authorization — authorized side:** an authenticated, currently-authorized
   OpenGlad caller creates and joins a room over the real same-origin `wss://`
   path; two such callers converge HOST → JOIN → GO into one lockstep match.
5. **Authorization — rejected side:** an unauthenticated caller, an authenticated
   user not authorized for OpenGlad, and a stale/invalid session are each
   refused (401 / refused upgrade) with **no room/peer state created**; a direct
   hit to `127.0.0.1:6035` is refused identically; rejected attempts leave
   `GET /api/rooms` unchanged.
6. **Identity isolation preserved:** same browser profile, two BinkTerm users →
   distinct IndexedDB stores, no cross-user Company inheritance (Slice 1E Part B,
   re-proven).
7. **Multi-room:** ≥ 2 simultaneous rooms are genuinely isolated (a frame in one
   never reaches a peer of another); the 16-peer, per-IP-create, oversized-frame,
   and empty-room-TTL limits behave.
8. `verify-webdoor.sh` passes against the deployed `play.*`; `build.env` records
   pin `4565499…` + the one patch.
9. `data/logs/openglad_relay*.log` contains no cookie, owner token, or frame body.
10. Unrelated services (`multizorkd`, `ascii-royale-arena`, `dosdoor_bridge`,
    `caddy`, `realtime_server`, `php-fpm`, `telnet`, host Apache) healthy
    before/during/after; `/`, `/ws`, `/dosdoor` unaffected.
11. `docs/Crossroads/openglad-backend/test/run-regression.sh` → all assertions
    pass.

---

## Rollout boundary

| Question | Answer |
|---|---|
| Technically safe to open to ordinary authenticated users, carrying the tracked patch, while #281 is open? | **Yes**, once this slice's infra + a real icon land: persistence isolation and fail-closed identity are runtime-proven; the relay is multi-room + rate-limited + auth-gated; the carry is one reviewable `#ifdef`'d file with a tracked convergence condition. |
| Prefer to wait for upstream #281? | **No.** `multizork` shipped production carrying three tracked patches under the same discipline. #281 silence is not a safety signal. If it lands a different shape, the carry patch + a pin bump absorb it with no BinkTerm core change. |
| Honest scope of the A-leg | "host a private match / join a friend's match" with per-user persistence — **not** "drop into a live arena". The always-populated experience is the deferred B-leg. Rollout copy should say so. |

Ordinary-user enablement + the custom icon are **Slice 1G**, not this slice.
Until then: `webdoor.json` `admin_only: true`, `config/webdoors.json`
`openglad.enabled: false`.

---

## Upgrade / rollback

| Change | Rollback |
|---|---|
| `openglad.enabled` toggle | set `false` (Admin → WebDoors) — Experience vanishes from both catalogs, every route 404/403; the relay keeps running idle. **Primary kill switch.** |
| `admin_only` flip (Slice 1G) | revert the one `webdoor.json` line — back to admin-only. |
| Relay service | `supervisorctl stop openglad-relay`; drop the baseline block on the next image build. Live matches drop (ephemeral); nothing persistent lost. |
| Caddyfile route | remove the block, `caddy reload`. `/openglad-relay` → 404; multiplayer stops, single-player + persistence unaffected. |
| Host vhost `ProxyPass` | remove the 2 lines, `apache2ctl configtest` → `graceful`. Restore from the `.bak.<UTC>` copy. |
| Relay code regression | `git revert` the runtime change, rebuild the image. |
| Artifacts | `EXPECTED.sha256` + `build.env` are the restore reference; keep the previous verified `play.*` in the prior image layer / a retained tarball (the ascii-royale "keep the old SHA tree" pattern). |
| Carry patch | removal is specified in `openglad-backend/README.md`; reverting to no-namespace = upstream `/persist`, and `index.php` still fails closed rather than serving shared persistence. |
| Canonical OpenGlad | never modified — nothing to roll back. |

No rollback step touches BinkTermPHP core, the protected files, or unrelated
services.

---

## Deferred / disclosed (as of this slice)

- **B-leg** (a Crossroads-run persistent arena so a lone user "arrives into
  something happening") is out of scope — deferred.
- **Cloud saves** (`/api/save/<KEY>`, upstream relay issue #155) are **not**
  implemented; the OpenGlad CLOUD menu degrades to "no cloud save configured".
  A device-portability slice could add them later.
- **Ordinary-user rollout + custom icon** — Slice 1G.
- A relay restart drops all live rooms (ephemeral private matches; honest UX
  copy). No persistent identity for rooms — matches the `ascii-royale` posture.
