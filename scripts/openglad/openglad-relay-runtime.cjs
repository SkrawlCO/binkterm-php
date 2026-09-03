#!/usr/bin/env node
'use strict';

/*
 * OpenGlad self-hosted relay — M4 Slice 1A assay runtime.
 *
 * A thin, long-lived wrapper around the UNMODIFIED OpenGlad project relay
 * contract implementation (`relay_stub.js`, staged from the pinned OpenGlad
 * revision 4565499825c2, tests/e2e/relay_stub.js, GPL-2.0, byte-identical —
 * sha256 51127d6d...5e290d). M3 runtime-proved this exact wire contract against
 * the real WASM client (OPENGLAD_M3_REPORT.md).
 *
 * It is a rendezvous only: it forwards OPAQUE binary frames between browser
 * peers in a single shared room. It never parses or holds game state, binds
 * LOOPBACK ONLY, and speaks the same protocol the browser client's
 * RelayWebSocketTransport expects (POST /api/create, GET /api/rooms,
 * WS /api/room/<CODE>, tag framing 0x01/0x02/0x03).
 *
 * Deployment: run inside the binkterm-app container as the supervised companion
 * [program:openglad-relay]; the container Caddyfile reverse-proxies
 * /openglad-relay -> 127.0.0.1:<port>, and host Apache proxies the public
 * wss://binkterm.l33test.com/openglad-relay to the container. TEMPORARY / ASSAY
 * (writable-layer) state — see public_html/webdoors/openglad/README.md.
 *
 * Env:
 *   OPENGLAD_RELAY_HOST  (default 127.0.0.1 — do not change to a routable addr)
 *   OPENGLAD_RELAY_PORT  (default 6035)
 *   OPENGLAD_RELAY_ROOM  (default GLAD-XR1A — the single shared room code)
 */

const crypto = require('crypto');
const { RelayStub } = require('./relay_stub.js');

const HOST = process.env.OPENGLAD_RELAY_HOST || '127.0.0.1';
const PORT = Number(process.env.OPENGLAD_RELAY_PORT || 6035);
const ROOM = process.env.OPENGLAD_RELAY_ROOM || 'GLAD-XR1A';

if (HOST !== '127.0.0.1' && HOST !== '::1' && HOST !== 'localhost') {
  console.error(`refusing to bind a non-loopback host: ${HOST}`);
  process.exit(2);
}

// A fresh owner token per process start; never logged.
const ownerToken = crypto.randomBytes(24).toString('hex');
const stub = new RelayStub({ roomCode: ROOM, ownerToken });

// RelayStub.start() binds an ephemeral port; the assay needs a fixed one behind
// the reverse proxy, so drive its own http.Server to a fixed loopback port and
// set the port field the class uses to resolve request URLs.
stub.port = PORT;
stub._server.once('error', (err) => {
  console.error(`openglad-relay listen error: ${err && err.message}`);
  process.exit(1);
});
stub._server.listen(PORT, HOST, () => {
  console.log(`openglad-relay: listening ${HOST}:${PORT} room=${ROOM} (loopback only)`);
});

// Assay observability: log peer join/leave and a periodic forwarded-frame
// count so the acceptance harness can confirm two browsers actually met and
// exchanged lobby/gameplay traffic through THIS relay (no game state, no
// secrets — peer ids and byte counts only).
let lastForwarded = 0;
let lastPeerCount = 0;
setInterval(() => {
  const peers = stub._peers ? stub._peers.size : 0;
  const fwd = stub.forwardedFrames.length;
  if (peers !== lastPeerCount || fwd !== lastForwarded) {
    const byDir = {};
    for (const f of stub.forwardedFrames) {
      const k = `${f.from}->${f.to}`;
      byDir[k] = (byDir[k] || 0) + 1;
    }
    console.log(`openglad-relay: peers=${peers} forwarded=${fwd} ${JSON.stringify(byDir)}`);
    lastForwarded = fwd;
    lastPeerCount = peers;
  }
}, 1000).unref();

let shuttingDown = false;
async function shutdown(signal) {
  if (shuttingDown) return;
  shuttingDown = true;
  console.log(`openglad-relay: ${signal}, closing`);
  try {
    await stub.stop();
  } catch (e) {
    /* ignore */
  }
  process.exit(0);
}
process.on('SIGTERM', () => shutdown('SIGTERM'));
process.on('SIGINT', () => shutdown('SIGINT'));

// Lightweight liveness heartbeat so supervisor tail shows the process is alive
// and how many peers are currently connected (no room/game state, no secrets).
setInterval(() => {
  const peers = stub.roomConnections.filter((c) => !c.closed).length;
  console.log(`openglad-relay: alive peers=${peers} forwarded=${stub.forwardedFrames.length}`);
}, 60000).unref();
