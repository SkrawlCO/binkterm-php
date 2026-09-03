/*
 * OpenGlad relay — black-box contract + authorization + limits regression.
 *
 *   node relay-contract.mjs [path/to/openglad-relay-runtime.cjs]
 *
 * Drives the EXACT tracked relay runtime over real HTTP/WebSockets against a
 * fake WebDoor-session authority (no BinkTerm, no DB, no network). Proves:
 *   - the create / rooms / join / frame-relay wire contract
 *   - BOTH SIDES of the authorization boundary: an authorized caller creates
 *     and joins; an unauthenticated caller, an authenticated-but-not-OpenGlad
 *     caller, and a stale session are each refused, leaving NO room/peer state
 *   - the bypass surface: hitting the listener directly is gated identically
 *     (the check lives in the relay, not the proxy)
 *   - multi-room isolation
 *   - peers/room cap, per-IP create-rate cap, oversized frame, empty-room TTL,
 *     owner-never-connects, host-leaves-with-guests, owner-reconnect supersede,
 *     positive auth cache
 *
 * Exit 0 = all assertions passed. See ./README.md for the list.
 */
import http from 'node:http';
import crypto from 'node:crypto';
import net from 'node:net';
import { fork } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const RUNTIME =
  process.argv[2] ||
  path.resolve(HERE, '../../../../scripts/openglad/openglad-relay-runtime.cjs');

const RELAY_PORT = 6135;
const AUTH_PORT = 6136;
const RELAY = `http://127.0.0.1:${RELAY_PORT}`;
const OK = 'authok=1';

let pass = 0;
let fail = 0;
const results = [];
function check(name, cond, detail) {
  (cond ? (pass++, results) : (fail++, results)).push(
    `  [${cond ? 'PASS' : 'FAIL'}] ${name}${detail ? ' — ' + detail : ''}`,
  );
}
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// --- fake WebDoor-session authority --------------------------------------
//   cookie has "authok=1"        -> 200 {session_id, game:{id:"openglad"}}
//   cookie has "notauthorized=1" -> 404 (authenticated, not OpenGlad-authorized)
//   anything else / no cookie    -> 401
let authHits = 0;
const authServer = http.createServer((req, res) => {
  authHits++;
  const cookie = req.headers['cookie'] || '';
  if (/(?:^|;\s*)authok=1(?:;|$)/.test(cookie)) {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(
      JSON.stringify({
        session_id: 'sess-' + crypto.randomBytes(6).toString('hex'),
        user: { display_name: 'tester' },
        game: { id: 'openglad', name: 'OpenGlad' },
      }),
    );
    return;
  }
  if (/notauthorized=1/.test(cookie)) {
    res.writeHead(404, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ success: false, error_code: 'errors.webdoor.game_unavailable' }));
    return;
  }
  res.writeHead(401, { 'Content-Type': 'application/json' });
  res.end(JSON.stringify({ success: false, error_code: 'errors.webdoor.auth_required' }));
});

// --- tiny HTTP client ---------------------------------------------------
function httpReq(method, urlStr, headers = {}) {
  return new Promise((resolve, reject) => {
    const u = new URL(urlStr);
    const req = http.request(
      { method, hostname: u.hostname, port: u.port, path: u.pathname + u.search, headers },
      (res) => {
        let data = '';
        res.on('data', (c) => (data += c));
        res.on('end', () => resolve({ status: res.statusCode, headers: res.headers, body: data }));
      },
    );
    req.on('error', reject);
    req.end();
  });
}
async function create(query = '', cookie = OK) {
  const r = await httpReq('POST', `${RELAY}/api/create${query}`, cookie ? { Cookie: cookie } : {});
  let json = null;
  try {
    json = JSON.parse(r.body);
  } catch {
    /* non-json (error text) */
  }
  return { status: r.status, json };
}
async function roomsList(cookie = OK) {
  const r = await httpReq('GET', `${RELAY}/api/rooms`, cookie ? { Cookie: cookie } : {});
  if (r.status !== 200) return null;
  try {
    return JSON.parse(r.body);
  } catch {
    return null;
  }
}

// --- minimal WebSocket client -----------------------------------------------
class WsClient {
  constructor() {
    this.sock = null;
    this.buf = Buffer.alloc(0);
    this.messages = [];
    this.control = [];
    this.closeInfo = null;
    this.httpStatus = null;
    this._waiters = [];
  }
  connect(code, cookie, ownerToken) {
    return new Promise((resolve) => {
      const key = crypto.randomBytes(16).toString('base64');
      const q = ownerToken ? `?owner_token=${ownerToken}` : '';
      const sock = net.connect(RELAY_PORT, '127.0.0.1', () => {
        const lines = [
          `GET /api/room/${code}${q} HTTP/1.1`,
          `Host: 127.0.0.1:${RELAY_PORT}`,
          'Upgrade: websocket',
          'Connection: Upgrade',
          `Sec-WebSocket-Key: ${key}`,
          'Sec-WebSocket-Version: 13',
        ];
        if (cookie) lines.push(`Cookie: ${cookie}`);
        sock.write(lines.join('\r\n') + '\r\n\r\n');
      });
      this.sock = sock;
      let done = false;
      sock.on('data', (chunk) => {
        if (!done) {
          this.buf = Buffer.concat([this.buf, chunk]);
          const idx = this.buf.indexOf('\r\n\r\n');
          if (idx === -1) return;
          this.httpStatus = parseInt(this.buf.subarray(0, idx).toString('utf8').split(' ')[1], 10);
          done = true;
          this.buf = this.buf.subarray(idx + 4);
          resolve(this);
          if (this.httpStatus !== 101) sock.end();
          else this._drain();
        } else {
          this.buf = Buffer.concat([this.buf, chunk]);
          this._drain();
        }
      });
      sock.on('error', () => {
        if (!done) {
          done = true;
          this.httpStatus = this.httpStatus || 0;
          resolve(this);
        }
      });
      sock.on('close', () => this._notify());
      setTimeout(() => {
        if (!done) {
          done = true;
          this.httpStatus = this.httpStatus || 0;
          resolve(this);
        }
      }, 3000);
    });
  }
  _drain() {
    for (;;) {
      if (this.buf.length < 2) return;
      const opcode = this.buf[0] & 0x0f;
      let len = this.buf[1] & 0x7f;
      let off = 2;
      if (len === 126) {
        if (this.buf.length < 4) return;
        len = this.buf.readUInt16BE(2);
        off = 4;
      } else if (len === 127) {
        if (this.buf.length < 10) return;
        len = Number(this.buf.readBigUInt64BE(2));
        off = 10;
      }
      if (this.buf.length < off + len) return;
      const payload = Buffer.from(this.buf.subarray(off, off + len));
      this.buf = this.buf.subarray(off + len);
      if (opcode === 0x8) {
        this.closeInfo = {
          code: payload.length >= 2 ? payload.readUInt16BE(0) : null,
          reason: payload.subarray(2).toString('utf8'),
        };
        this._notify();
        continue;
      }
      if (opcode === 0x9) {
        this._sendRaw(0xa, payload);
        continue;
      }
      if (opcode === 0x1) {
        try {
          this.control.push(JSON.parse(payload.toString('utf8')));
        } catch {
          /* ignore */
        }
      }
      this.messages.push({ opcode, payload });
      this._notify();
    }
  }
  _notify() {
    const w = this._waiters;
    this._waiters = [];
    w.forEach((fn) => fn());
  }
  _sendRaw(opcode, payload) {
    const mask = crypto.randomBytes(4);
    const masked = Buffer.from(payload);
    for (let i = 0; i < masked.length; i++) masked[i] ^= mask[i % 4];
    let header;
    if (masked.length < 126) header = Buffer.from([0x80 | opcode, 0x80 | masked.length]);
    else if (masked.length < 65536) {
      header = Buffer.alloc(4);
      header[0] = 0x80 | opcode;
      header[1] = 0x80 | 126;
      header.writeUInt16BE(masked.length, 2);
    } else {
      header = Buffer.alloc(10);
      header[0] = 0x80 | opcode;
      header[1] = 0x80 | 127;
      header.writeBigUInt64BE(BigInt(masked.length), 2);
    }
    this.sock.write(Buffer.concat([header, mask, masked]));
  }
  sendBinary(buf) {
    this._sendRaw(0x2, buf);
  }
  async waitFor(pred, ms = 2000) {
    const deadline = Date.now() + ms;
    while (Date.now() < deadline) {
      if (pred()) return true;
      await new Promise((r) => {
        this._waiters.push(r);
        setTimeout(r, 80);
      });
    }
    return pred();
  }
  close() {
    try {
      this._sendRaw(0x8, Buffer.from([0x03, 0xe8]));
      this.sock.end();
    } catch {
      /* ignore */
    }
  }
}

function binFrame(tag, target, body) {
  if (tag === 1) {
    const b = Buffer.alloc(5 + body.length);
    b[0] = 1;
    b.writeUInt32LE(target >>> 0, 1);
    body.copy(b, 5);
    return b;
  }
  return Buffer.concat([Buffer.from([3]), body]);
}

// --- relay process management -----------------------------------------------
let relayProc = null;
async function startRelay(extraEnv = {}) {
  await stopRelay();
  relayProc = fork(RUNTIME, [], {
    env: {
      ...process.env,
      OPENGLAD_RELAY_HOST: '127.0.0.1',
      OPENGLAD_RELAY_PORT: String(RELAY_PORT),
      OPENGLAD_RELAY_AUTH_URL: `http://127.0.0.1:${AUTH_PORT}/session`,
      OPENGLAD_RELAY_AUTH_CACHE_MS: '0',
      OPENGLAD_RELAY_EMPTY_ROOM_TTL_MS: '1500',
      OPENGLAD_RELAY_CREATE_RATE_MAX: '1000',
      OPENGLAD_RELAY_MAX_ROOMS: '64',
      OPENGLAD_RELAY_SWEEP_INTERVAL_MS: '400',
      ...extraEnv,
    },
    stdio: ['ignore', 'inherit', 'inherit', 'ipc'],
  });
  for (let i = 0; i < 60; i++) {
    try {
      if ((await httpReq('GET', `${RELAY}/healthz`)).status === 200) return;
    } catch {
      /* not up */
    }
    await sleep(100);
  }
  throw new Error('relay did not come up');
}
async function stopRelay() {
  if (!relayProc) return;
  const p = relayProc;
  relayProc = null;
  p.kill('SIGTERM');
  await sleep(300);
  try {
    p.kill('SIGKILL');
  } catch {
    /* already gone */
  }
}

// --- assertions ------------------------------------------------------------
async function phaseContract() {
  await startRelay();

  check('healthz_ungated_200', (await httpReq('GET', `${RELAY}/healthz`)).status === 200);
  const opt = await httpReq('OPTIONS', `${RELAY}/api/create`);
  check('cors_preflight_204', opt.status === 204 && opt.headers['access-control-allow-origin'] === '*');

  // --- authorization boundary: rejected sides create NO state ---
  {
    const before = (await roomsList())?.length ?? -1;
    const c1 = await create('', null);
    const c2 = await create('', 'notauthorized=1');
    const c3 = await create('', 'stale=1; foo=bar');
    const w = new WsClient();
    await w.connect('GLAD-ABCD', null);
    const after = (await roomsList())?.length ?? -1;
    check('create_unauthenticated_401', c1.status === 401, `status=${c1.status}`);
    check('create_not_openglad_authorized_401', c2.status === 401, `status=${c2.status}`);
    check('create_stale_session_401', c3.status === 401, `status=${c3.status}`);
    check('ws_upgrade_unauthenticated_401', w.httpStatus === 401, `httpStatus=${w.httpStatus}`);
    check('rejected_attempts_no_residual_state', before === 0 && after === 0, `rooms ${before}->${after}`);
  }

  // --- authorized create ---
  const a = await create('?campaign=abc&campaign_name=Test&host=HostA');
  check(
    'create_authorized_200',
    a.status === 200 &&
      /^GLAD-[A-Z0-9]{4}$/.test(a.json?.code || '') &&
      a.json.room_code === a.json.code &&
      /^[0-9a-f]{32}$/.test(a.json?.owner_token || ''),
    `code=${a.json?.code}`,
  );

  check('join_unknown_room_404', (await new WsClient().connect('GLAD-ZZZZ', OK)).httpStatus === 404);
  check('join_malformed_code_400', (await new WsClient().connect('nope', OK)).httpStatus === 400);
  check('rooms_hidden_until_owner_connects', (await roomsList()).length === 0);

  // owner + guest
  const owner = new WsClient();
  await owner.connect(a.json.code, OK, a.json.owner_token);
  await owner.waitFor(() => owner.control.some((m) => m.type === 'joined'));
  const oj = owner.control.find((m) => m.type === 'joined');
  check('owner_joined_peer1_host1', oj?.peer_id === 1 && oj?.host === 1, JSON.stringify(oj));

  const guest = new WsClient();
  await guest.connect(a.json.code, OK);
  await guest.waitFor(() => guest.control.some((m) => m.type === 'peer_list'));
  await owner.waitFor(() => owner.control.some((m) => m.type === 'peer_joined' && m.peer_id === 2));
  check('guest_joined_peer2', guest.control.find((m) => m.type === 'joined')?.peer_id === 2);
  check(
    'guest_peer_list_1_2',
    guest.control.find((m) => m.type === 'peer_list')?.peers.join(',') === '1,2',
  );
  check('owner_notified_peer_joined_2', owner.control.some((m) => m.type === 'peer_joined' && m.peer_id === 2));

  const row = (await roomsList()).find((r) => r.code === a.json.code);
  check(
    'rooms_lists_occupied_room',
    row?.player_count === 2 && row.host_name === 'HostA' && row.campaign_hash === 'abc',
    JSON.stringify(row),
  );

  // frame relay
  owner.messages.length = 0;
  guest.sendBinary(binFrame(3, 0, Buffer.from('bcast')));
  await owner.waitFor(() => owner.messages.some((m) => m.opcode === 0x2));
  let f = owner.messages.find((m) => m.opcode === 0x2);
  check(
    'broadcast_frame_relayed',
    f && f.payload[0] === 2 && f.payload.readUInt32LE(1) === 2 && f.payload.subarray(5).toString() === 'bcast',
  );
  owner.messages.length = 0;
  guest.sendBinary(binFrame(1, 1, Buffer.from('targeted')));
  await owner.waitFor(() => owner.messages.some((m) => m.opcode === 0x2));
  f = owner.messages.find((m) => m.opcode === 0x2);
  check('targeted_frame_relayed', f?.payload.subarray(5).toString() === 'targeted');

  // multi-room isolation
  const b = await create('?host=HostB');
  const ownerB = new WsClient();
  await ownerB.connect(b.json.code, OK, b.json.owner_token);
  await ownerB.waitFor(() => ownerB.control.some((m) => m.type === 'joined'));
  const guestB = new WsClient();
  await guestB.connect(b.json.code, OK);
  await guestB.waitFor(() => guestB.control.some((m) => m.type === 'peer_list'));
  ownerB.messages.length = 0;
  guestB.messages.length = 0;
  for (let i = 0; i < 6; i++) guest.sendBinary(binFrame(3, 0, Buffer.from('roomA-' + i)));
  await sleep(300);
  check(
    'multi_room_isolation',
    ownerB.messages.concat(guestB.messages).filter((m) => m.opcode === 0x2).length === 0,
  );
  ownerB.messages.length = 0;
  guestB.sendBinary(binFrame(3, 0, Buffer.from('roomB-internal')));
  await ownerB.waitFor(() => ownerB.messages.some((m) => m.opcode === 0x2));
  check(
    'room_b_internal_relay_still_works',
    ownerB.messages.some((m) => m.opcode === 0x2 && m.payload.subarray(5).toString() === 'roomB-internal'),
  );

  // oversized frame -> 1009
  const huge = Buffer.alloc(130 * 1024, 7);
  huge[0] = 3;
  guest.sendBinary(huge);
  await guest.waitFor(() => guest.closeInfo !== null, 3000);
  check('oversized_frame_close_1009', guest.closeInfo?.code === 1009, JSON.stringify(guest.closeInfo));

  [owner, guestB, ownerB].forEach((w) => w.close());
  await sleep(100);
}

async function phasePeerCap() {
  await startRelay();
  const r = await create('?host=Cap');
  const owner = new WsClient();
  await owner.connect(r.json.code, OK, r.json.owner_token);
  await owner.waitFor(() => owner.control.some((m) => m.type === 'joined'));
  const guests = [];
  for (let i = 0; i < 15; i++) {
    const g = new WsClient();
    await g.connect(r.json.code, OK);
    guests.push(g);
  }
  const okCount = guests.filter((g) => g.httpStatus === 101).length;
  const over = new WsClient();
  await over.connect(r.json.code, OK);
  check('peers_per_room_15_guests_accepted', okCount === 15, `accepted=${okCount}`);
  check('peer_over_cap_rejected_409', over.httpStatus === 409, `httpStatus=${over.httpStatus}`);
  owner.close();
  guests.forEach((g) => g.close());
  await sleep(150);
}

async function phaseCreateRate() {
  await startRelay({ OPENGLAD_RELAY_CREATE_RATE_MAX: '5', OPENGLAD_RELAY_CREATE_RATE_WINDOW_MS: '60000' });
  let ok = 0;
  let got429 = false;
  for (let i = 0; i < 8; i++) {
    const c = await create('?host=RL');
    if (c.status === 200) ok++;
    else if (c.status === 429) {
      got429 = true;
      break;
    }
  }
  check('create_rate_limit_429_after_budget', got429 && ok === 5, `ok=${ok} got429=${got429}`);
}

async function phaseRoomLifecycle() {
  await startRelay({ OPENGLAD_RELAY_EMPTY_ROOM_TTL_MS: '1200' });

  // empty-room TTL: owner alone leaves -> reconnectable, then expires
  const r = await create('?host=TTL');
  const o1 = new WsClient();
  await o1.connect(r.json.code, OK, r.json.owner_token);
  await o1.waitFor(() => o1.control.some((m) => m.type === 'joined'));
  o1.sock.destroy();
  await sleep(400);
  const o2 = new WsClient();
  await o2.connect(r.json.code, OK, r.json.owner_token);
  check('empty_room_reconnectable_within_ttl', o2.httpStatus === 101, `httpStatus=${o2.httpStatus}`);
  o2.sock.destroy();
  await sleep(2000);
  const o3 = new WsClient();
  await o3.connect(r.json.code, OK, r.json.owner_token);
  check('empty_room_deleted_after_ttl', o3.httpStatus === 404, `httpStatus=${o3.httpStatus}`);

  // host leaves with guests -> guests get peer_left{1} then close 1001, room gone
  const r2 = await create('?host=HL');
  const owner = new WsClient();
  await owner.connect(r2.json.code, OK, r2.json.owner_token);
  await owner.waitFor(() => owner.control.some((m) => m.type === 'joined'));
  const g = new WsClient();
  await g.connect(r2.json.code, OK);
  await g.waitFor(() => g.control.some((m) => m.type === 'peer_list'));
  owner.sock.resetAndDestroy();
  await g.waitFor(() => g.closeInfo !== null, 6000);
  check('host_leave_guest_gets_peer_left_1', g.control.some((m) => m.type === 'peer_left' && m.peer_id === 1));
  check('host_leave_guest_closed_1001', g.closeInfo?.code === 1001, JSON.stringify(g.closeInfo));
  let removed = false;
  for (let i = 0; i < 20 && !removed; i++) {
    removed = !(await roomsList()).some((x) => x.code === r2.json.code);
    if (!removed) await sleep(100);
  }
  check('host_leave_room_removed', removed);

  // owner reconnect supersede
  const r3 = await create('?host=RC');
  const oa = new WsClient();
  await oa.connect(r3.json.code, OK, r3.json.owner_token);
  await oa.waitFor(() => oa.control.some((m) => m.type === 'joined'));
  const ob = new WsClient();
  await ob.connect(r3.json.code, OK, r3.json.owner_token);
  await ob.waitFor(() => ob.control.some((m) => m.type === 'joined'));
  await oa.waitFor(() => oa.closeInfo !== null, 2000);
  check('owner_reconnect_supersede_1012', oa.closeInfo?.code === 1012, JSON.stringify(oa.closeInfo));
  check('owner_reconnect_replacement_ok', ob.httpStatus === 101 && ob.control.some((m) => m.type === 'joined' && m.peer_id === 1));
  ob.close();
  await sleep(100);
}

async function phaseOwnerNeverConnects() {
  // OWNER_CONNECT_GRACE_MS is a fixed 5 min in the runtime; too long for a
  // hermetic test. We assert the weaker, cheap property: a freshly-created
  // room the owner never connects to is not listed (player_count 0) and a
  // guest cannot join it before the owner (guests need a live room; an
  // ownerless room still 101s a guest per the contract, so instead assert the
  // room is simply absent from discovery until a peer connects).
  await startRelay();
  const r = await create('?host=NoOwner');
  const list = await roomsList();
  check('ownerless_room_not_discoverable', !list.some((x) => x.code === r.json.code));
}

async function phaseAuthCache() {
  await startRelay({ OPENGLAD_RELAY_AUTH_CACHE_MS: '5000' });
  authHits = 0;
  for (let i = 0; i < 6; i++) await httpReq('GET', `${RELAY}/api/rooms`, { Cookie: OK });
  check('auth_positive_cache_reduces_authority_hits', authHits < 6, `authorityHits=${authHits}/6`);
}

async function main() {
  await new Promise((r) => authServer.listen(AUTH_PORT, '127.0.0.1', r));
  try {
    await phaseContract();
    await phasePeerCap();
    await phaseCreateRate();
    await phaseRoomLifecycle();
    await phaseOwnerNeverConnects();
    await phaseAuthCache();
  } finally {
    await stopRelay();
    authServer.close();
  }
  console.log('\n' + results.join('\n'));
  console.log(`\n${pass}/${pass + fail} PASS`);
  process.exitCode = fail === 0 ? 0 : 1;
}

main().catch((e) => {
  console.error('FATAL', e);
  try {
    relayProc && relayProc.kill('SIGKILL');
  } catch {
    /* ignore */
  }
  process.exit(2);
});
