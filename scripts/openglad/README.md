# OpenGlad self-hosted relay (M4 Slice 1A assay)

`openglad-relay-runtime.cjs` runs the OpenGlad multiplayer **relay** (rendezvous)
as a supervised, loopback-only companion process for the admin-only OpenGlad
WebDoor (`public_html/webdoors/openglad/`).

- `relay_stub.js` — **git-ignored**, staged byte-identical from the pinned
  OpenGlad revision `4565499825c25b0943ab0f6e1e5403af752e63ed`
  (`tests/e2e/relay_stub.js`, GPL-2.0, sha256 `51127d6d…5e290d`). M3 runtime-proved
  this exact wire contract against the real WASM client.
- `openglad-relay-runtime.cjs` — **tracked**, ~50 lines, a long-lived wrapper:
  fixed loopback port, fresh per-process owner token, clean SIGTERM shutdown,
  a 60 s liveness heartbeat. No game state, no secrets in logs.

## Run (inside the `binkterm-app` container)

```
OPENGLAD_RELAY_PORT=6035 node /var/www/html/scripts/openglad/openglad-relay-runtime.cjs
```

Supervised as `[program:openglad-relay]` — a live edit to the container's
`/etc/supervisor/conf.d/supervisord.conf` (the `[program:multizorkd]` /
`[program:ascii-royale-arena]` precedent). **TEMPORARY / ASSAY** — lives on the
container writable layer, lost on `docker compose up --force-recreate`.

## Reverse proxy

```
browser  wss://binkterm.l33test.com/openglad-relay
  -> host Apache  ProxyPass /openglad-relay ws://127.0.0.1:8090/openglad-relay   (live public vhost edit)
  -> container Caddy  reverse_proxy /openglad-relay 127.0.0.1:6035               (Caddyfile edit, baked image)
  -> this process (127.0.0.1:6035, loopback only)
```

The relay is a single shared room (`GLAD-XR1A`): the first browser to HOST owns
it, others JOIN. Restart the program to reset the room.

## Not for production as-is

Durable production needs the relay built into the image with a supervised block,
the Caddyfile + proxy routes as managed config, and a decision on running the
upstream relay Worker (`workerd`) vs this contract implementation. See
`/root/openglad-assay/OPENGLAD_M4_SLICE1A_REPORT.md`.
