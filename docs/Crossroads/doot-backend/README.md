# Doot Games backend (Crossroads Curated Experience — CLASP relay)

This directory carries provenance for the two upstream pins this Experience
depends on, and the runtime config fragments used to wire them into the
L33TEST host/container.

## Pins

| Component | Repo | Pinned revision | License |
| --- | --- | --- | --- |
| Doot Games (app) | `virgilvox/doot-games` | `0f78d6d11e7a7863cc9aa575ab2cfa39c5f6bd9b` | MIT (root `LICENSE`) |
| CLASP relay | `lumencanvas/clasp` | `a78a96e2c96e2b638a41fa5ff6931890157a02df` | MIT OR Apache-2.0 (`LICENSE-MIT` / `LICENSE-APACHE`) |

Neither upstream is vendored into this repository. Doot's canonical
`.output/` production build and the `clasp-router` binary are built from a
throwaway clone of the pinned revision and staged into the L33TEST image /
container at deploy time — the same "not vendored, pin + build script"
discipline used for `openglad-backend/`.

## CLASP relay build

Built with the stable Rust toolchain the CLASP repo pins
(`rust-toolchain.toml`: stable, currently resolving to 1.98.1), release
profile, **websocket transport only** (`--no-default-features --features
websocket`) — Doot only ever speaks `wss://`, so QUIC/TCP/bridges/journal are
not built in. No `Cargo.lock` is committed upstream, so the lockfile is
resolved fresh against crates.io at build time; re-verify before advancing the
pin.

```sh
git clone https://github.com/lumencanvas/clasp.git
cd clasp && git checkout a78a96e2c96e2b638a41fa5ff6931890157a02df
cargo build --release -p clasp-router-server --no-default-features --features websocket
# binary at target/release/clasp-router
```

Verified: the release binary built on Ubuntu 22.04 (glibc 2.35) runs
unmodified on the `binkterm-app` container's Debian 13 "trixie" base (glibc
2.41) — dynamic linking is forward-compatible in that direction. Smoke-tested
standalone: `clasp-router --listen 127.0.0.1:<port> --auth-mode open` starts,
logs "Router ready, accepting connections", and answers a plain HTTP probe
with `200`.

Binary is tracked at `scripts/doot/clasp-router` (checked in, like other
compiled companion binaries this repo already carries under `scripts/`).

## Doot app build

Canonical, undocumented-patch build:

```sh
git clone https://github.com/virgilvox/doot-games.git
cd doot-games && git checkout 0f78d6d11e7a7863cc9aa575ab2cfa39c5f6bd9b
corepack enable
pnpm install --frozen-lockfile   # (repo ships no lockfile drift; resolves clean)
mkdir -p .data                   # local libSQL/SQLite file lives here — not in upstream docs, needed once
pnpm build                       # -> apps/web/.output (Nitro node-server build)
```

No source file in the Doot monorepo was modified. `.output/` is not
vendored/tracked (git-ignored, like OpenGlad's compiled `play.*`); it is
rebuilt from the pin and staged into the container's `/var/lib/doot/app`
directory.

## Runtime shape

```
 browser ── wss://binkterm.l33test.com/doot-relay ──────┐   (same origin; CLASP client
                                                          │    default is wss://relay.clasp.to,
 Cloudflare (pass-through, WebSockets on)                 │    overridden via CLASP_RELAY_URL)
                                                          ▼
 host Apache :443  ProxyPass /doot-relay ws://127.0.0.1:8090/doot-relay
                    ProxyPass /doot-app   http://127.0.0.1:8090/doot-app
   (vhost baseline, beside /ws, /dosdoor, /openglad-relay, /chessmata)
                                                          ▼
 container Caddy :80  handle_path /doot-relay/* { reverse_proxy 127.0.0.1:6036 }
                        handle_path /doot-app/*   { reverse_proxy 127.0.0.1:6037 }
   (image-baked Caddyfile; @compressible excludes /doot-relay)
                                                          ▼
 [program:doot-clasp-relay]  scripts/doot/clasp-router --listen 127.0.0.1:6036 --auth-mode open
 [program:doot-app]          node /var/lib/doot/app/apps/web/.output/server/index.mjs (PORT=6037)
   both 127.0.0.1-only, supervised, run as www-data
   • doot-app's durable state: local libSQL/SQLite file under /var/lib/doot/app/.data
   • doot-clasp-relay: no durable state (ephemeral room map only, matches canonical CLASP)

 WebDoor (BinkTerm core, unchanged):
   /games/doot → webdoor_play.twig (same-origin iframe) → /webdoors/doot/index.php
     • index.php: fail-closed 403 unless authenticated + enabled; on success,
       302-redirects the iframe to /doot-app/ (Doot's own SSR routing takes over
       from there — no BinkTerm route table duplicates Doot's pages)
```

**No BinkTermPHP source change and no canonical Doot source change.** The
relay binary, the Nuxt production build, the routing, the service management,
and the WebDoor gate are entirely L33TEST-owned deployment/integration, same
posture as `openglad-backend/`.

## Why a self-hosted relay, not the public one

Doot's canonical default (`CLASP_RELAY_URL=wss://relay.clasp.to`) is a public,
third-party-operated relay. Live room traffic — story contributions, drawing
strokes, chat, player names — would transit that service verbatim if left at
the default. L33TEST's product decision is to self-host: `CLASP_RELAY_URL` is
the sole config point Doot exposes for this, so pointing it at our own
loopback-bound `clasp-router` instance requires no Doot source change, only
one env var.

## AI / external services

`docker/docker-compose.yml`'s `app` env and `.env.example` were both
inspected; the only AI-related keys used by upstream are OpenRouter provider
keys for the optional end-of-game AI commentary personas, and **none of them
are present in this deployment's `.env`**. No `OPENROUTER_API_KEY` (or
equivalent) is configured, so that optional feature stays cleanly absent — it
is not disabled by patching source, it simply has no credential to activate
it. GoatCounter analytics likewise stay off (`NUXT_PUBLIC_GOATCOUNTER_URL`
unset). No caller content is sent to any third party by this deployment.

## Object storage

Doot's Spaces/MinIO-backed object storage (cover images, oversized ephemeral
blob offload) is **not** provisioned in this slice — `.env` points at a
`localhost:9000` MinIO endpoint that does not exist in this deployment. This
degrades non-fatally (`could not ensure ephemeral lifecycle rule` warning,
game hosting/joining/playing unaffected); cover-image upload and very large
drawing galleries would fail until MinIO (or DO Spaces) is wired. Documented
here as a disclosed gap, not silently patched around.

## L33TEST identity bridge

An authenticated L33TEST caller reaches Doot without a second signup/login
and keeps every account-scoped feature (decks, saved custom games, playlists,
bookmarks, profile). Additive on both sides — no existing Doot sign-up/sign-in
path or BinkTerm auth route was touched.

**PHP side** (`src/Crossroads/DootIdentityBridge.php`): mints a short-lived
(60s), single-use, HMAC-SHA256-signed launch assertion —
`{ sub: "l33test:<immutable user id>", name, iat, exp, jti }` — using
`DOOT_BRIDGE_SECRET` (BinkTermPHP `.env`). The WebDoor gate
(`public_html/webdoors/doot/index.php`) mints this only after its existing
fail-closed authentication check, then serves a tiny bootstrap page that
`fetch()`s the token to Doot same-origin before navigating into `/doot-app/`.
On any minting failure it falls back to a plain redirect — the caller still
reaches Doot as a full-featured guest (hosting/joining/playing never require
an account), just without persistent-account features until fixed.

**Doot side** (`apps/web/server/utils/l33test-bridge-plugin.ts`, wired into
`server/utils/auth.ts`'s `plugins` array only when `L33TEST_BRIDGE_SECRET` is
set): a better-auth plugin adding one endpoint,
`POST /api/auth/l33test/bridge`, built on the same primitives better-auth's
own shipped `admin` plugin uses for `impersonateUser`
(`internalAdapter.createSession` + `setSessionCookie` — see
`better-auth/dist/plugins/admin/routes.mjs`), not undocumented internals. It
verifies the signature + expiry + single-use `jti`, derives a synthetic email
`l33test+<id>@bridge.doot.invalid` (never the display name), and
auto-provisions/looks up the mapped user on first launch.

**Bounded session lifetime**: `BRIDGE_SESSION_TTL_SECONDS` (12h) is wired into
`authOptions.session.expiresIn` globally (only when the bridge is enabled) —
a per-call `expiresAt` override on `createSession` did not reliably stick
(observed directly: the session still came back at better-auth's 7-day
default even with `overrideAll=true`), so the bound lives at the supported
top-level config option instead. A caller who stops relaunching through
L33TEST (e.g. logged out) ages out of Doot on this clock rather than staying
signed in for better-auth's normal multi-day default.

**Both secrets must match exactly**: `DOOT_BRIDGE_SECRET` (BinkTermPHP `.env`)
and `L33TEST_BRIDGE_SECRET` (`data/doot/app/.env`) are the same value —
generated once (`openssl rand -hex 32`) and copied to both files. A mismatch
fails closed (401 from the bridge endpoint, guest fallback), never partial
trust.

**Verified** (production container, real HTTP, both directly and through the
Cloudflare→Apache→Caddy path): first launch auto-provisions; repeat launch by
the same caller maps to the same Doot user; a distinct L33TEST id maps to a
distinct Doot user; a forged-signature token and an expired token are both
rejected (401); a replayed already-consumed token is rejected (401); an
account-scoped route (`/api/me/bookmarks`) resolves under the bridged
session; a PHP-minted token (not a test harness token) is accepted by the
live Doot bridge endpoint, confirming real cross-system compatibility; the
unauthenticated gate still fails closed (403); the self-hosted CLASP relay
and the Curated catalog placement are both unaffected.

**Human acceptance (2026-09-12)**: PASSED. Matt (Skrawl) confirmed through the
real L33TEST Curated launch that Doot's own navbar correctly shows the
authenticated account state (Log in / Sign up gone; Your Games, Saved, and
the mapped avatar/account all present) — not just a server-side session, the
visible UI. All synthetic diagnostic bridge accounts created during
verification were removed; the two remaining `l33test+<id>@bridge.doot.invalid`
accounts are real mapped callers (Skrawl and a second real tester), not test
data.

**Follow-up fix required for human acceptance — client auth base path**: the
server-side bridge (mint → verify → `createSession` → `Set-Cookie`) worked
correctly from the first deploy, proven with real HTTP down to Skrawl's own
browser/cookie — but the visible navbar still showed Log in / Sign up. Root
cause: `apps/web/app/utils/auth-client.ts`'s `createAuthClient()` had no
`basePath` configured, so the client's own `useSession()` composable called
`/api/auth/get-session` at the site **root** instead of under `/doot-app/`,
404ing every time regardless of a perfectly valid session cookie. This is a
**general subpath-deployment bug in Doot's own client**, not bridge-specific —
it would affect native email/password sign-in identically under this
same-origin-subpath deployment. Fix: one line,
`basePath: '/doot-app/api/auth'`, kept in sync with `NUXT_APP_BASE_URL`. Not
vendored/committed here (canonical Doot source lives only in the built
artifact per this file's "not vendored" discipline above); recorded here as
the provenance/reasoning for that line in the deployed build.

## Terminal outlook (recorded, not built)

A future Telnet bridge would need, at minimum: (1) a CLASP client
implementation for the terminal daemon (the wire protocol is
`@clasp-to/core`'s pub/sub framing, not yet inspected at the byte level in
this integration), (2) read access to the same room/round state Doot's Vue
composables (`useDootRoom`) subscribe to, and (3) a way to submit a caller's
turn contribution as a publish to the same topic a phone client would use —
without re-implementing any block's scoring/turn logic, which must stay
engine-owned. Nothing here builds that bridge; this section exists so a future
slice does not have to re-derive it.
