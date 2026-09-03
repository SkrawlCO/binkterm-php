#!/usr/bin/env node
'use strict';

/*
 * OpenGlad self-hosted relay — L33TEST Crossroads Experience #3 (M4 Slice 1F).
 *
 * A durable, multi-room rendezvous/relay for the OpenGlad Web/WASM client's
 * networked multiplayer. Browsers cannot listen for connections, so peers meet
 * in a named room on this relay, which forwards OPAQUE binary game frames
 * between them. The relay never parses or stores game state, holds no
 * persistence, keeps no database, and writes no secrets to its log.
 *
 * WIRE CONTRACT (not designed here — implemented to the existing spec):
 *   - the two C++ transports:
 *       src/platform/emscripten/net_transport_relay_ws.cpp
 *       src/platform/sdl/net_transport_relay_ws.cpp
 *   - the upstream reference relay:  relay/README.md, relay/src/*.ts
 *   - cross-checked against the pinned test double  tests/e2e/relay_stub.js
 *   all from pinned OpenGlad 4565499825c25b0943ab0f6e1e5403af752e63ed (GPL-2.0).
 *
 *   POST /api/create?campaign=<hash>&campaign_name=<name>&host=<host name>
 *        -> 200 {"room_code":"GLAD-XXXX","code":"GLAD-XXXX","owner_token":"<hex>"}
 *   GET  /api/rooms[?campaign=<hash>]
 *        -> 200 [{code,campaign_hash,campaign_name,host_name,player_count,created_at}]
 *           (only rooms that currently have a connected peer)
 *   WS   /api/room/<CODE>[?owner_token=<hex>]
 *        -> 101; unknown room 404, malformed code 400, full room 409
 *   Control (TEXT JSON, relay->client):
 *        {"type":"joined","peer_id":N,"host":H}
 *        {"type":"peer_list","peers":[...],"host":H}
 *        {"type":"peer_joined","peer_id":N,"is_host":B}
 *        {"type":"peer_left","peer_id":N}
 *   Binary frames (opaque):
 *        client->relay  [0x01][target peer u32 LE][body]   send to one peer
 *        client->relay  [0x03][body]                       broadcast
 *        relay->client  [0x02][sender peer u32 LE][body]
 *
 * LIMITS (from relay/src/shared.ts — the source of truth; relay/README.md's
 * "8 peers" line is stale, the client seats 16):
 *   peers/room .......... 16 (1 reserved for the owner) -> HTTP 409
 *   inbound binary frame  128 KiB; broadcast body 128 KiB-4 -> WS 1009
 *   inbound TEXT ........  4 KiB -> WS 1009
 *   per-connection rate .  2000 msgs OR 8 MiB per 1000 ms -> WS 1008
 *   room creates per IP .  10 per 60 s -> HTTP 429
 *   empty-room TTL ......  120 s   (OPENGLAD_RELAY_EMPTY_ROOM_TTL_MS)
 *   owner-never-connects   300 s
 *   absolute room age ...  12 h
 *   global room ceiling .  256     (OPENGLAD_RELAY_MAX_ROOMS)  -> HTTP 503
 *
 * AUTHORIZATION (L33TEST):
 *   Every /api/* request (except OPTIONS preflight and /healthz) is gated by
 *   replaying the caller's Cookie against the EXISTING authenticated,
 *   admin-aware WebDoor session authority:
 *       GET <OPENGLAD_RELAY_AUTH_URL>   (default
 *          http://127.0.0.1/api/webdoor/session?game_id=openglad)
 *   Authorized  == HTTP 200 AND JSON body has a non-empty session_id AND
 *                  game.id === "openglad".
 *   Anything else (401 no session, 404 not authorized / disabled, 5xx, timeout)
 *   -> the relay refuses: POST/GET 401, WS upgrade refused with HTTP 401,
 *      and NO room/peer state is created. This is not a second auth system;
 *      it defers entirely to BinkTerm. There is no bypass: the check runs in
 *      the relay itself, so a caller hitting 127.0.0.1:<port> directly (past
 *      the reverse proxy) is gated identically.
 *
 * Env (all optional; defaults are the production values):
 *   OPENGLAD_RELAY_HOST                127.0.0.1  (loopback only; refuses else)
 *   OPENGLAD_RELAY_PORT                6035
 *   OPENGLAD_RELAY_AUTH_URL           http://127.0.0.1/api/webdoor/session?game_id=openglad
 *   OPENGLAD_RELAY_AUTH_TIMEOUT_MS    2000
 *   OPENGLAD_RELAY_AUTH_CACHE_MS      15000   (positive results only)
 *   OPENGLAD_RELAY_EMPTY_ROOM_TTL_MS  120000
 *   OPENGLAD_RELAY_MAX_ROOMS          256
 *   OPENGLAD_RELAY_TRUST_XREALIP      1       (read X-Real-IP for the per-IP cap)
 *
 * Deployment: supervised as [program:openglad-relay] inside the L33TEST image
 * (loopback:6035); container Caddy reverse-proxies /openglad-relay -> it and
 * strips the prefix; host Apache proxies wss://<host>/openglad-relay to the
 * container. See docs/Crossroads/OpenGladProduction.md.
 */

const http = require('http');
const crypto = require('crypto');

// --------------------------------------------------------------------------
// Config
// --------------------------------------------------------------------------

const HOST = process.env.OPENGLAD_RELAY_HOST || '127.0.0.1';
const PORT = Number(process.env.OPENGLAD_RELAY_PORT || 6035);
const AUTH_URL =
  process.env.OPENGLAD_RELAY_AUTH_URL ||
  'http://127.0.0.1/api/webdoor/session?game_id=openglad';
const AUTH_TIMEOUT_MS = Number(process.env.OPENGLAD_RELAY_AUTH_TIMEOUT_MS || 2000);
const AUTH_CACHE_MS = Number(process.env.OPENGLAD_RELAY_AUTH_CACHE_MS || 15000);
const EMPTY_ROOM_TTL_MS = Number(
  process.env.OPENGLAD_RELAY_EMPTY_ROOM_TTL_MS || 120000,
);
const MAX_ROOMS = Number(process.env.OPENGLAD_RELAY_MAX_ROOMS || 256);
const TRUST_XREALIP = process.env.OPENGLAD_RELAY_TRUST_XREALIP !== '0';

const OWNER_CONNECT_GRACE_MS = 5 * 60 * 1000;
const ROOM_MAX_AGE_MS = 12 * 60 * 60 * 1000;
const MAX_ROOM_PEERS = 16;
const MAX_INBOUND_BINARY_FRAME_BYTES = 128 * 1024;
const MAX_BROADCAST_FRAME_BYTES = MAX_INBOUND_BINARY_FRAME_BYTES - 4;
const MAX_INBOUND_TEXT_MESSAGE_BYTES = 4 * 1024;
const MSG_RATE_WINDOW_MS = 1000;
const MSG_RATE_MAX_MESSAGES = 2000;
const MSG_RATE_MAX_BYTES = 8 * 1024 * 1024;
// The per-IP create budget. Overridable only to let the regression harness
// exercise the 429 path quickly (the upstream relay overrides EMPTY_ROOM_TTL_MS
// the same way); production leaves both at the defaults.
const CREATE_RATE_MAX = Number(process.env.OPENGLAD_RELAY_CREATE_RATE_MAX || 10);
const CREATE_RATE_WINDOW_MS = Number(
  process.env.OPENGLAD_RELAY_CREATE_RATE_WINDOW_MS || 60 * 1000,
);
const MAX_CAMPAIGN_HASH_LENGTH = 128;
const MAX_CAMPAIGN_NAME_LENGTH = 128;
const MAX_HOST_NAME_LENGTH = 64;
// Overridable only so the regression harness can drive the room-lifecycle
// alarms without multi-second waits; production leaves it at 5 s.
const SWEEP_INTERVAL_MS = Number(process.env.OPENGLAD_RELAY_SWEEP_INTERVAL_MS || 5 * 1000);
const PEER_PING_INTERVAL_MS = 30 * 1000;
const PEER_PONG_TIMEOUT_MS = 90 * 1000;

const ROOM_CODE_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
const ROOM_CODE_PATTERN = /^GLAD-[A-Z0-9]{4}$/;

if (HOST !== '127.0.0.1' && HOST !== '::1' && HOST !== 'localhost') {
  console.error(`openglad-relay: refusing to bind non-loopback host: ${HOST}`);
  process.exit(2);
}

// --------------------------------------------------------------------------
// Small logging helper — never logs cookies, owner tokens, or frame bodies.
// --------------------------------------------------------------------------

function log(msg, fields) {
  const ts = new Date().toISOString();
  const extra = fields
    ? ' ' +
      Object.entries(fields)
        .map(([k, v]) => `${k}=${typeof v === 'string' ? v : JSON.stringify(v)}`)
        .join(' ')
    : '';
  console.log(`${ts} openglad-relay: ${msg}${extra}`);
}

// --------------------------------------------------------------------------
// WebSocket frame codec (RFC 6455), dependency-free. Ported from the pinned
// OpenGlad tests/e2e/relay_stub.js and extended with continuation-frame
// reassembly and close codes.
// --------------------------------------------------------------------------

const WS_GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
const OP_CONT = 0x0;
const OP_TEXT = 0x1;
const OP_BIN = 0x2;
const OP_CLOSE = 0x8;
const OP_PING = 0x9;
const OP_PONG = 0xa;

const TAG_SEND = 1;
const TAG_RECV = 2;
const TAG_BROADCAST = 3;
const PEER_HEADER_SIZE = 5;

function encodeFrame(opcode, payload) {
  let header;
  if (payload.length < 126) {
    header = Buffer.from([0x80 | opcode, payload.length]);
  } else if (payload.length < 65536) {
    header = Buffer.alloc(4);
    header[0] = 0x80 | opcode;
    header[1] = 126;
    header.writeUInt16BE(payload.length, 2);
  } else {
    header = Buffer.alloc(10);
    header[0] = 0x80 | opcode;
    header[1] = 127;
    header.writeBigUInt64BE(BigInt(payload.length), 2);
  }
  return Buffer.concat([header, payload]);
}

function encodeText(obj) {
  return encodeFrame(OP_TEXT, Buffer.from(JSON.stringify(obj), 'utf8'));
}

function encodeClose(code, reason) {
  const r = Buffer.from(String(reason || ''), 'utf8');
  const payload = Buffer.alloc(2 + r.length);
  payload.writeUInt16BE(code, 0);
  r.copy(payload, 2);
  return encodeFrame(OP_CLOSE, payload);
}

function encodeU32LE(value) {
  const b = Buffer.alloc(4);
  b.writeUInt32LE(value >>> 0, 0);
  return b;
}

function makeRecvFrame(fromPeerId, body) {
  return Buffer.concat([Buffer.from([TAG_RECV]), encodeU32LE(fromPeerId), body]);
}

/** Decode one frame from `buffer`. Returns null while incomplete. */
function decodeOneFrame(buffer) {
  if (buffer.length < 2) return null;
  const fin = (buffer[0] & 0x80) !== 0;
  const opcode = buffer[0] & 0x0f;
  const masked = (buffer[1] & 0x80) !== 0;
  let len = buffer[1] & 0x7f;
  let offset = 2;
  if (len === 126) {
    if (buffer.length < 4) return null;
    len = buffer.readUInt16BE(2);
    offset = 4;
  } else if (len === 127) {
    if (buffer.length < 10) return null;
    const big = buffer.readBigUInt64BE(2);
    // Hard abort well above the policy limit: a frame this large is a
    // non-conformant / abusive client, and we must not buffer toward it.
    // Frames between the policy limit and this ceiling are decoded and then
    // rejected with a clean 1009 by handleWsFrame().
    if (big > BigInt(4 * MAX_INBOUND_BINARY_FRAME_BYTES)) {
      throw new Error('frame length exceeds hard ceiling');
    }
    len = Number(big);
    offset = 10;
  }
  let mask = null;
  if (masked) {
    if (buffer.length < offset + 4) return null;
    mask = buffer.subarray(offset, offset + 4);
    offset += 4;
  }
  if (buffer.length < offset + len) return null;
  const payload = Buffer.from(buffer.subarray(offset, offset + len));
  if (mask) {
    for (let i = 0; i < payload.length; ++i) payload[i] ^= mask[i % 4];
  }
  return { fin, opcode, payload, rest: buffer.subarray(offset + len) };
}

// --------------------------------------------------------------------------
// Authorization — defers entirely to the existing WebDoor session authority.
// --------------------------------------------------------------------------

/** cookieHash -> {ok:true, until:ms}. Only positive results are cached. */
const authCache = new Map();

function cookieKey(cookieHeader) {
  return crypto
    .createHash('sha256')
    .update(cookieHeader || '')
    .digest('hex')
    .slice(0, 32);
}

async function authorize(cookieHeader) {
  if (!cookieHeader || typeof cookieHeader !== 'string' || cookieHeader.length > 8192) {
    return { ok: false, reason: 'no-cookie' };
  }
  const key = cookieKey(cookieHeader);
  const cached = authCache.get(key);
  const now = Date.now();
  if (cached && cached.until > now) {
    return { ok: true, reason: 'cache' };
  }
  if (cached) authCache.delete(key);

  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), AUTH_TIMEOUT_MS);
  try {
    const res = await fetch(AUTH_URL, {
      method: 'GET',
      redirect: 'manual',
      signal: controller.signal,
      headers: {
        Cookie: cookieHeader,
        Accept: 'application/json',
        'User-Agent': 'openglad-relay/1.0 (auth-check)',
        'X-Openglad-Relay-Auth': '1',
      },
    });
    if (res.status !== 200) {
      return { ok: false, reason: `authority-${res.status}` };
    }
    let body;
    try {
      body = await res.json();
    } catch {
      return { ok: false, reason: 'authority-nonjson' };
    }
    const sessionId = body && typeof body.session_id === 'string' ? body.session_id : '';
    const gameId = body && body.game && typeof body.game.id === 'string' ? body.game.id : '';
    if (!sessionId || gameId !== 'openglad') {
      return { ok: false, reason: 'authority-unauthorized' };
    }
    if (AUTH_CACHE_MS > 0) {
      authCache.set(key, { ok: true, until: now + AUTH_CACHE_MS });
    }
    return { ok: true, reason: 'authority' };
  } catch (e) {
    return { ok: false, reason: e && e.name === 'AbortError' ? 'authority-timeout' : 'authority-error' };
  } finally {
    clearTimeout(timer);
  }
}

function pruneAuthCache() {
  const now = Date.now();
  for (const [k, v] of authCache) {
    if (v.until <= now) authCache.delete(k);
  }
}

// --------------------------------------------------------------------------
// Per-IP room-create rate limit
// --------------------------------------------------------------------------

/** ip -> number[] (create timestamps within the window) */
const createHistory = new Map();

function clientIp(req) {
  if (TRUST_XREALIP) {
    const xr = req.headers['x-real-ip'];
    if (typeof xr === 'string' && xr.trim()) return xr.trim().split(',')[0].trim();
    const xff = req.headers['x-forwarded-for'];
    if (typeof xff === 'string' && xff.trim()) return xff.trim().split(',')[0].trim();
  }
  return req.socket.remoteAddress || 'unknown';
}

function createRateLimited(ip) {
  const now = Date.now();
  const hist = (createHistory.get(ip) || []).filter((t) => now - t < CREATE_RATE_WINDOW_MS);
  if (hist.length >= CREATE_RATE_MAX) {
    createHistory.set(ip, hist);
    return true;
  }
  hist.push(now);
  createHistory.set(ip, hist);
  return false;
}

// --------------------------------------------------------------------------
// Room model
// --------------------------------------------------------------------------

/** code -> Room */
const rooms = new Map();

function boundedString(value, max) {
  return String(value == null ? '' : value).slice(0, max);
}

function generateRoomCode() {
  let suffix = '';
  const bytes = crypto.randomBytes(4);
  for (let i = 0; i < 4; ++i) {
    suffix += ROOM_CODE_ALPHABET[bytes[i] % ROOM_CODE_ALPHABET.length];
  }
  return `GLAD-${suffix}`;
}

function normalizeRoomCode(raw) {
  return String(raw || '').toUpperCase();
}

function createRoom(params) {
  let code = null;
  for (let attempt = 0; attempt < 8; ++attempt) {
    const candidate = generateRoomCode();
    if (!rooms.has(candidate)) {
      code = candidate;
      break;
    }
  }
  if (!code) return null;

  const now = Date.now();
  const room = {
    code,
    campaignHash: boundedString(params.campaignHash, MAX_CAMPAIGN_HASH_LENGTH),
    campaignName: boundedString(params.campaignName, MAX_CAMPAIGN_NAME_LENGTH),
    hostName: boundedString(params.hostName, MAX_HOST_NAME_LENGTH),
    ownerToken: crypto.randomBytes(16).toString('hex'),
    createdAt: now,
    hostEverConnected: false,
    hostPeerId: null,
    emptySince: now,
    nextPeerId: 2, // peer 1 is reserved for the owner
    peers: new Map(), // peerId -> Peer
  };
  rooms.set(code, room);
  return room;
}

function destroyRoom(room, closeCode, closeReason, leavingPeerId) {
  const survivors = [...room.peers.values()];
  room.peers.clear();
  for (const peer of survivors) {
    if (leavingPeerId != null) {
      safeSend(peer, encodeText({ type: 'peer_left', peer_id: leavingPeerId }));
    }
    closePeer(peer, closeCode, closeReason);
  }
  rooms.delete(room.code);
  log('room closed', { code: room.code, reason: closeReason });
}

function roomPlayerCount(room) {
  return room.peers.size;
}

function broadcastControl(room, obj, exceptPeerId) {
  for (const [peerId, peer] of room.peers) {
    if (peerId === exceptPeerId) continue;
    safeSend(peer, encodeText(obj));
  }
}

// --------------------------------------------------------------------------
// Peer model + socket lifecycle
// --------------------------------------------------------------------------

function safeSend(peer, frameBuffer) {
  if (!peer || peer.closed || peer.socket.destroyed || peer.socket.writableEnded) {
    return false;
  }
  try {
    peer.socket.write(frameBuffer);
    return true;
  } catch {
    return false;
  }
}

function closePeer(peer, code, reason) {
  if (peer.closed) return;
  peer.closed = true;
  const s = peer.socket;
  try {
    if (!s.destroyed && !s.writableEnded) {
      // write() then end() so the CLOSE frame (and any control frame queued
      // just before it, e.g. peer_left) flushes before FIN. Do NOT destroy()
      // synchronously — that can discard the unsent frame.
      s.write(encodeClose(code || 1000, reason || ''));
      s.end();
    }
  } catch {
    /* ignore */
  }
  setTimeout(() => {
    try {
      s.destroy();
    } catch {
      /* ignore */
    }
  }, 1500).unref();
}

/** A peer left (socket closed or was dropped). Mirror upstream game-room.ts. */
function removePeer(room, peerId) {
  const peer = room.peers.get(peerId);
  if (!peer) return;
  room.peers.delete(peerId);
  peer.closed = true;

  if (peerId === room.hostPeerId) {
    room.hostPeerId = null;
    if (room.peers.size > 0) {
      // The game server runs inside the host's client; the room dies with it.
      destroyRoom(room, 1001, 'host-left', peerId);
      return;
    }
    room.emptySince = Date.now();
    return;
  }

  if (room.peers.size === 0) {
    room.emptySince = Date.now();
  }
  broadcastControl(room, { type: 'peer_left', peer_id: peerId });
}

// --------------------------------------------------------------------------
// HTTP request handling (POST /api/create, GET /api/rooms, GET /healthz)
// --------------------------------------------------------------------------

const CORS = {
  'Access-Control-Allow-Origin': '*',
  'Access-Control-Allow-Methods': 'GET, POST, OPTIONS',
  'Access-Control-Allow-Headers': '*',
};

function sendJson(res, status, obj) {
  res.writeHead(status, { ...CORS, 'Content-Type': 'application/json; charset=utf-8' });
  res.end(JSON.stringify(obj));
}

function sendText(res, status, text) {
  res.writeHead(status, { ...CORS, 'Content-Type': 'text/plain; charset=utf-8' });
  res.end(text + '\n');
}

const server = http.createServer((req, res) => {
  const url = new URL(req.url || '/', `http://${HOST}:${PORT}`);

  if (req.method === 'OPTIONS') {
    res.writeHead(204, CORS);
    res.end();
    return;
  }

  if (req.method === 'GET' && url.pathname === '/healthz') {
    sendText(res, 200, 'ok');
    return;
  }

  if (req.method === 'POST' && url.pathname === '/api/create') {
    handleCreate(req, res, url).catch((e) => {
      log('create handler error', { err: String(e && e.message) });
      if (!res.headersSent) sendText(res, 500, 'internal error');
    });
    return;
  }

  if (req.method === 'GET' && url.pathname === '/api/rooms') {
    handleRooms(req, res, url).catch((e) => {
      log('rooms handler error', { err: String(e && e.message) });
      if (!res.headersSent) sendText(res, 500, 'internal error');
    });
    return;
  }

  sendText(res, 404, 'not found');
});

async function handleCreate(req, res, url) {
  // Drain and discard the body (the contract carries params in the query).
  req.resume();

  const auth = await authorize(req.headers['cookie']);
  if (!auth.ok) {
    log('create rejected', { reason: auth.reason, ip: clientIp(req) });
    sendText(res, 401, 'unauthorized');
    return;
  }

  const ip = clientIp(req);
  if (createRateLimited(ip)) {
    sendText(res, 429, 'rate limit exceeded');
    return;
  }

  if (rooms.size >= MAX_ROOMS) {
    sendText(res, 503, 'room capacity reached');
    return;
  }

  const room = createRoom({
    campaignHash: url.searchParams.get('campaign') || '',
    campaignName:
      url.searchParams.get('campaign_name') || url.searchParams.get('campaign') || '',
    hostName: url.searchParams.get('host') || '',
  });
  if (!room) {
    sendText(res, 503, 'unable to allocate a room code');
    return;
  }

  log('room created', { code: room.code, rooms: rooms.size });
  sendJson(res, 200, {
    room_code: room.code,
    code: room.code,
    owner_token: room.ownerToken,
  });
}

async function handleRooms(req, res, url) {
  const auth = await authorize(req.headers['cookie']);
  if (!auth.ok) {
    sendText(res, 401, 'unauthorized');
    return;
  }

  const campaignFilter = url.searchParams.get('campaign');
  const list = [];
  for (const room of rooms.values()) {
    if (roomPlayerCount(room) < 1) continue; // only joinable/occupied rooms
    if (campaignFilter && room.campaignHash !== campaignFilter) continue;
    list.push({
      code: room.code,
      campaign_hash: room.campaignHash,
      campaign_name: room.campaignName,
      host_name: room.hostName,
      player_count: roomPlayerCount(room),
      created_at: room.createdAt,
    });
  }
  sendJson(res, 200, list);
}

// --------------------------------------------------------------------------
// WebSocket upgrade handling (join a room)
// --------------------------------------------------------------------------

server.on('upgrade', (req, socket) => {
  handleUpgrade(req, socket).catch((e) => {
    log('upgrade handler error', { err: String(e && e.message) });
    try {
      socket.write('HTTP/1.1 500 Internal Server Error\r\nConnection: close\r\n\r\n');
      socket.destroy();
    } catch {
      /* ignore */
    }
  });
});

function refuseUpgrade(socket, statusLine) {
  try {
    socket.write(`HTTP/1.1 ${statusLine}\r\nConnection: close\r\n\r\n`);
    socket.destroy();
  } catch {
    /* ignore */
  }
}

async function handleUpgrade(req, socket) {
  const url = new URL(req.url || '/', `http://${HOST}:${PORT}`);
  const key = req.headers['sec-websocket-key'];
  const m = url.pathname.match(/^\/api\/room\/([^/]+)$/);
  if (!m || typeof key !== 'string') {
    refuseUpgrade(socket, '400 Bad Request');
    return;
  }

  let rawCode = m[1];
  try {
    rawCode = decodeURIComponent(rawCode);
  } catch {
    refuseUpgrade(socket, '400 Bad Request');
    return;
  }
  const code = normalizeRoomCode(rawCode);
  if (!ROOM_CODE_PATTERN.test(code)) {
    refuseUpgrade(socket, '400 Bad Request');
    return;
  }

  // Authorization BEFORE any room lookup or state change. A rejected upgrade
  // never creates a peer or touches a room.
  const auth = await authorize(req.headers['cookie']);
  if (!auth.ok) {
    log('join rejected', { code, reason: auth.reason, ip: clientIp(req) });
    refuseUpgrade(socket, '401 Unauthorized');
    return;
  }

  const room = rooms.get(code);
  if (!room) {
    refuseUpgrade(socket, '404 Not Found');
    return;
  }
  if (Date.now() >= room.createdAt + ROOM_MAX_AGE_MS) {
    destroyRoom(room, 1001, 'room-max-age');
    refuseUpgrade(socket, '404 Not Found');
    return;
  }

  const presentedToken = url.searchParams.get('owner_token') || '';
  const isOwner = room.ownerToken.length > 0 && presentedToken === room.ownerToken;

  let supersededOwner = false;
  if (isOwner) {
    const existing = room.peers.get(1);
    if (existing) {
      room.peers.delete(1);
      closePeer(existing, 1012, 'superseded by owner reconnect');
      supersededOwner = true;
    }
  } else {
    // Guests may fill every slot except the one reserved for the owner.
    let guests = 0;
    for (const pid of room.peers.keys()) if (pid !== 1) guests++;
    if (guests >= MAX_ROOM_PEERS - 1) {
      refuseUpgrade(socket, '409 Conflict');
      return;
    }
  }

  // Complete the RFC 6455 handshake.
  const accept = crypto.createHash('sha1').update(key + WS_GUID).digest('base64');
  socket.write(
    'HTTP/1.1 101 Switching Protocols\r\n' +
      'Upgrade: websocket\r\n' +
      'Connection: Upgrade\r\n' +
      `Sec-WebSocket-Accept: ${accept}\r\n\r\n`,
  );
  socket.setNoDelay(true);

  const peerId = isOwner ? 1 : room.nextPeerId++;
  const peer = {
    peerId,
    socket,
    isOwner,
    closed: false,
    inbound: Buffer.alloc(0),
    fragOpcode: null,
    fragChunks: [],
    fragBytes: 0,
    budget: { count: 0, bytes: 0, windowStartedAt: Date.now() },
    lastPongAt: Date.now(),
  };
  room.peers.set(peerId, peer);
  if (isOwner) {
    room.hostPeerId = 1;
    room.hostEverConnected = true;
  }
  room.emptySince = null;

  const hostPeerId = room.hostPeerId || 0;
  safeSend(peer, encodeText({ type: 'joined', peer_id: peerId, host: hostPeerId }));
  safeSend(
    peer,
    encodeText({
      type: 'peer_list',
      peers: [...room.peers.keys()].sort((a, b) => a - b),
      host: hostPeerId,
    }),
  );
  if (!supersededOwner) {
    broadcastControl(
      room,
      { type: 'peer_joined', peer_id: peerId, is_host: peerId === room.hostPeerId },
      peerId,
    );
  }
  log('peer joined', { code, peer: peerId, owner: isOwner, players: room.peers.size });

  const teardown = () => {
    if (peer.closed) {
      // still ensure it is removed from the room map
      if (room.peers.get(peerId) === peer) removePeer(room, peerId);
      return;
    }
    peer.closed = true;
    if (room.peers.get(peerId) === peer) {
      removePeer(room, peerId);
    }
  };
  socket.on('close', teardown);
  socket.on('error', teardown);

  socket.on('data', (chunk) => {
    peer.inbound = Buffer.concat([peer.inbound, chunk]);
    try {
      for (;;) {
        const frame = decodeOneFrame(peer.inbound);
        if (!frame) break;
        peer.inbound = frame.rest;
        handleWsFrame(room, peer, frame);
        if (peer.closed) return;
      }
    } catch (e) {
      log('peer frame error', { code, peer: peerId, err: String(e && e.message) });
      closePeer(peer, 1002, 'protocol error');
      teardown();
    }
  });
}

function consumeBudget(peer, bytes) {
  const now = Date.now();
  const b = peer.budget;
  if (now - b.windowStartedAt >= MSG_RATE_WINDOW_MS) {
    b.count = 1;
    b.bytes = bytes;
    b.windowStartedAt = now;
    return true;
  }
  if (b.count >= MSG_RATE_MAX_MESSAGES || b.bytes + bytes > MSG_RATE_MAX_BYTES) {
    return false;
  }
  b.count += 1;
  b.bytes += bytes;
  return true;
}

function handleWsFrame(room, peer, frame) {
  // Control opcodes first.
  if (frame.opcode === OP_CLOSE) {
    closePeer(peer, 1000, '');
    if (room.peers.get(peer.peerId) === peer) removePeer(room, peer.peerId);
    return;
  }
  if (frame.opcode === OP_PING) {
    safeSend(peer, encodeFrame(OP_PONG, frame.payload));
    return;
  }
  if (frame.opcode === OP_PONG) {
    peer.lastPongAt = Date.now();
    return;
  }

  // Data opcodes (with continuation reassembly).
  let opcode = frame.opcode;
  let payload = frame.payload;
  if (opcode === OP_CONT) {
    if (peer.fragOpcode === null) {
      closePeer(peer, 1002, 'unexpected continuation');
      return;
    }
    peer.fragChunks.push(payload);
    peer.fragBytes += payload.length;
    if (peer.fragBytes > MAX_INBOUND_BINARY_FRAME_BYTES) {
      closePeer(peer, 1009, 'relay frame too large');
      return;
    }
    if (!frame.fin) return;
    opcode = peer.fragOpcode;
    payload = Buffer.concat(peer.fragChunks);
    peer.fragOpcode = null;
    peer.fragChunks = [];
    peer.fragBytes = 0;
  } else if (!frame.fin) {
    peer.fragOpcode = opcode;
    peer.fragChunks = [payload];
    peer.fragBytes = payload.length;
    if (peer.fragBytes > MAX_INBOUND_BINARY_FRAME_BYTES) {
      closePeer(peer, 1009, 'relay frame too large');
    }
    return;
  }

  const msgBytes = payload.length;
  if (!consumeBudget(peer, msgBytes)) {
    closePeer(peer, 1008, 'message rate limit exceeded');
    if (room.peers.get(peer.peerId) === peer) removePeer(room, peer.peerId);
    return;
  }

  if (opcode === OP_TEXT) {
    if (msgBytes > MAX_INBOUND_TEXT_MESSAGE_BYTES) {
      closePeer(peer, 1009, 'control message too large');
      return;
    }
    // The C++ clients never send TEXT; tolerate a couple of debug verbs.
    let parsed;
    try {
      parsed = JSON.parse(payload.toString('utf8'));
    } catch {
      closePeer(peer, 1003, 'malformed json');
      return;
    }
    if (parsed && parsed.type === 'leave_room') {
      closePeer(peer, 1000, 'leaving room');
      if (room.peers.get(peer.peerId) === peer) removePeer(room, peer.peerId);
    } else if (parsed && parsed.type === 'list_peers') {
      safeSend(
        peer,
        encodeText({
          type: 'peer_list',
          peers: [...room.peers.keys()].sort((a, b) => a - b),
          host: room.hostPeerId || 0,
        }),
      );
    }
    return;
  }

  if (opcode !== OP_BIN) return;
  if (msgBytes === 0) return;
  if (msgBytes > MAX_INBOUND_BINARY_FRAME_BYTES) {
    closePeer(peer, 1009, 'relay frame too large');
    return;
  }

  const tag = payload[0];
  if (tag === TAG_SEND) {
    if (msgBytes < PEER_HEADER_SIZE) {
      closePeer(peer, 1003, 'malformed relay frame');
      return;
    }
    const target = payload.readUInt32LE(1);
    forwardFrame(room, peer.peerId, payload.subarray(PEER_HEADER_SIZE), target);
    return;
  }
  if (tag === TAG_BROADCAST) {
    if (msgBytes > MAX_BROADCAST_FRAME_BYTES) {
      closePeer(peer, 1009, 'relay frame too large');
      return;
    }
    forwardFrame(room, peer.peerId, payload.subarray(1), undefined);
    return;
  }
  // Unknown tag: drop silently (tolerant, matches the reference stub).
}

function forwardFrame(room, fromPeerId, body, targetPeerId) {
  const frame = makeRecvFrame(fromPeerId, body);
  const failed = [];
  if (targetPeerId !== undefined) {
    if (targetPeerId === fromPeerId) return;
    const target = room.peers.get(targetPeerId);
    if (target && !safeSend(target, encodeFrame(OP_BIN, frame))) failed.push(targetPeerId);
  } else {
    for (const [pid, peer] of room.peers) {
      if (pid === fromPeerId) continue;
      if (!safeSend(peer, encodeFrame(OP_BIN, frame))) failed.push(pid);
    }
  }
  for (const pid of failed) removePeer(room, pid);
}

// --------------------------------------------------------------------------
// Periodic sweep: room lifecycle alarms + dead-peer reaping + counters.
// --------------------------------------------------------------------------

let statsSnapshot = '';

const sweep = setInterval(() => {
  const now = Date.now();

  for (const room of [...rooms.values()]) {
    if (now >= room.createdAt + ROOM_MAX_AGE_MS) {
      destroyRoom(room, 1001, 'room-max-age');
      continue;
    }
    if (
      room.peers.size === 0 &&
      room.emptySince !== null &&
      now >= room.emptySince + EMPTY_ROOM_TTL_MS
    ) {
      destroyRoom(room, 1001, 'empty-room-ttl');
      continue;
    }
    if (!room.hostEverConnected && now >= room.createdAt + OWNER_CONNECT_GRACE_MS) {
      destroyRoom(room, 1001, 'owner-never-connected');
      continue;
    }

    for (const [pid, peer] of [...room.peers]) {
      if (peer.closed) {
        removePeer(room, pid);
        continue;
      }
      if (now - peer.lastPongAt > PEER_PONG_TIMEOUT_MS) {
        log('peer timed out', { code: room.code, peer: pid });
        closePeer(peer, 1001, 'ping timeout');
        removePeer(room, pid);
        continue;
      }
      if (now - peer.lastPongAt > PEER_PING_INTERVAL_MS) {
        safeSend(peer, encodeFrame(OP_PING, Buffer.alloc(0)));
      }
    }
  }

  for (const [ip, hist] of createHistory) {
    const kept = hist.filter((t) => now - t < CREATE_RATE_WINDOW_MS);
    if (kept.length === 0) createHistory.delete(ip);
    else createHistory.set(ip, kept);
  }
  pruneAuthCache();

  let peerTotal = 0;
  for (const room of rooms.values()) peerTotal += room.peers.size;
  const snap = `rooms=${rooms.size} peers=${peerTotal}`;
  if (snap !== statsSnapshot) {
    log('stats', { rooms: rooms.size, peers: peerTotal });
    statsSnapshot = snap;
  }
}, SWEEP_INTERVAL_MS);
sweep.unref();

setInterval(() => {
  let peerTotal = 0;
  for (const room of rooms.values()) peerTotal += room.peers.size;
  log('alive', { rooms: rooms.size, peers: peerTotal });
}, 60000).unref();

// --------------------------------------------------------------------------
// Lifecycle
// --------------------------------------------------------------------------

server.on('error', (err) => {
  log('listen error', { err: String(err && err.message) });
  process.exit(1);
});

server.listen(PORT, HOST, () => {
  log('listening', {
    host: HOST,
    port: PORT,
    auth_url: AUTH_URL,
    max_rooms: MAX_ROOMS,
    empty_room_ttl_ms: EMPTY_ROOM_TTL_MS,
  });
});

let shuttingDown = false;
function shutdown(signal) {
  if (shuttingDown) return;
  shuttingDown = true;
  log('shutting down', { signal });
  for (const room of [...rooms.values()]) {
    destroyRoom(room, 1001, 'relay shutdown');
  }
  server.close(() => process.exit(0));
  setTimeout(() => process.exit(0), 3000).unref();
}
process.on('SIGTERM', () => shutdown('SIGTERM'));
process.on('SIGINT', () => shutdown('SIGINT'));
