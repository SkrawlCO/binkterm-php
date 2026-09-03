# OpenGlad self-hosted relay

`openglad-relay-runtime.cjs` is the durable, **multi-room** rendezvous/relay for
the OpenGlad Web/WASM WebDoor's networked multiplayer (Crossroads Experience #3,
A-leg). It forwards **opaque binary game frames** between browser peers. It holds
no game state, no persistence, no database, and writes no secrets to its log.

- **Self-contained, dependency-free** Node (built-ins only). The RFC 6455 frame
  codec is ported from the pinned OpenGlad `tests/e2e/relay_stub.js` and the wire
  contract is implemented to `relay/README.md` + the two C++ transports
  (`src/platform/{emscripten,sdl}/net_transport_relay_ws.cpp`) of pinned OpenGlad
  `4565499825c25b0943ab0f6e1e5403af752e63ed` (GPL-2.0).
- **Loopback only** — the runtime refuses a non-loopback `OPENGLAD_RELAY_HOST`.
- **Authorization** defers entirely to BinkTerm: every `/api/*` call replays the
  caller's `Cookie` against `GET /api/webdoor/session?game_id=openglad` (the
  existing authenticated, admin-aware WebDoor session authority). 200 + a
  `session_id` + `game.id == "openglad"` == authorized; anything else is refused
  and **no room/peer state is created**. There is no second auth system and no
  bypass — the check runs in the relay, so hitting `127.0.0.1:<port>` directly is
  gated identically.
- **Limits** (from `relay/src/shared.ts`): 16 peers/room, 128 KiB frame,
  4 KiB TEXT, 2000 msg/s or 8 MiB/s per connection, 10 room-creates/IP/min,
  120 s empty-room TTL, 5 min owner-connect grace, 12 h room age, 256-room ceiling.

## Run

```
node scripts/openglad/openglad-relay-runtime.cjs      # 127.0.0.1:6035
```

Env (all optional; defaults are production values): `OPENGLAD_RELAY_HOST`,
`OPENGLAD_RELAY_PORT`, `OPENGLAD_RELAY_AUTH_URL`, `OPENGLAD_RELAY_AUTH_TIMEOUT_MS`,
`OPENGLAD_RELAY_AUTH_CACHE_MS`, `OPENGLAD_RELAY_EMPTY_ROOM_TTL_MS`,
`OPENGLAD_RELAY_MAX_ROOMS`, `OPENGLAD_RELAY_TRUST_XREALIP`. A few
`*_RATE_*` / `*_SWEEP_*` overrides exist only so the regression harness can drive
the limit/alarm paths quickly (the upstream relay overrides `EMPTY_ROOM_TTL_MS`
the same way); production leaves them at the defaults. See the file header.

## Deployment

Durable, supervised, baked into the L33TEST image — **not** a live
`supervisorctl` edit. See:

- `docs/Crossroads/OpenGladProduction.md` — the production deployment doc
- `docs/Crossroads/openglad-backend/runtime/` — the tracked reference fragments
  (`supervisord.openglad-relay.conf.fragment`, `caddy.openglad-relay.snippet`,
  `apache.openglad-relay.snippet`)
- `docs/Crossroads/openglad-backend/test/` — the black-box regression harness

## Regression

```
docs/Crossroads/openglad-backend/test/run-regression.sh
```
