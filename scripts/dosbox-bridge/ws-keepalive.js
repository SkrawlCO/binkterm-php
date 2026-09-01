'use strict';

/**
 * /dosdoor WebSocket keepalive.
 *
 * The public path for the door bridge is:
 *
 *   browser -> Cloudflare -> Apache (mod_proxy_wstunnel) -> docker-proxy
 *           -> Caddy (reverse_proxy) -> scripts/dosbox-bridge/multiplexing-server.js
 *
 * Cloudflare closes a WebSocket connection that carries no frames in either
 * direction for ~100 seconds (a fixed inactivity timeout). A door sitting at an
 * idle menu or lobby produces no terminal output, and the browser client sends
 * nothing unless the user types, so nothing keeps the connection warm. The
 * browser eventually observes a synthetic 1006 close and shows "[Connection
 * closed]"; the bridge only reacts to the transport loss, it never initiates it.
 *
 * BinkTerm's other WebSocket server already solved this for its realtime feed
 * (src/Realtime/WebSocketServer.php, PING_INTERVAL_SECONDS = 20, opcode 0x9).
 * This module is the generic bridge-level equivalent: a single interval that
 * sends a protocol-level PING frame to every OPEN client every 20 seconds.
 *
 * Design notes:
 *  - Protocol PING/PONG only. No application/terminal bytes are written, so
 *    terminal data framing, session replay, Telnet behaviour, authentication,
 *    tokens, the 30s reconnect window and the 5-minute disconnected-session
 *    grace are all untouched.
 *  - Compliant WebSocket clients (every browser) answer PING with PONG at the
 *    protocol layer, invisible to page JavaScript.
 *  - One bridge-level interval over wsServer.clients, not a timer per socket.
 *  - Keepalive only. No pong-timeout / dead-peer reaping (matches BinkStream).
 *  - Generic: this operates purely on a ws clients collection and the OPEN
 *    constant. It has no knowledge of any specific door.
 */

const KEEPALIVE_PING_INTERVAL_SECONDS = 20;

/**
 * Send one PING to every OPEN client. Non-OPEN sockets are skipped. A ping()
 * that throws (socket torn down mid-sweep) is swallowed so a single bad socket
 * can neither abort the sweep nor crash the shared bridge.
 *
 * @param {Iterable<{readyState: number, ping: Function}>} clients  wsServer.clients
 * @param {number} openState   WebSocket.OPEN
 * @param {(err: Error, ws: object) => void} [onError]  optional per-failure hook
 * @returns {{pinged: number, skipped: number, failed: number}}
 */
function keepalivePingSweep(clients, openState, onError) {
    let pinged = 0;
    let skipped = 0;
    let failed = 0;

    for (const ws of clients || []) {
        if (!ws || ws.readyState !== openState) {
            skipped++;
            continue;
        }
        try {
            ws.ping();
            pinged++;
        } catch (err) {
            failed++;
            if (typeof onError === 'function') {
                try {
                    onError(err, ws);
                } catch (_) {
                    /* a broken hook must never break the sweep */
                }
            }
        }
    }

    return { pinged, skipped, failed };
}

/**
 * Start the keepalive interval for a ws WebSocket.Server.
 *
 * @param {{clients: Iterable<object>}} wsServer   ws server with clientTracking
 * @param {{OPEN: number}} WebSocket               the ws module (for WebSocket.OPEN)
 * @param {object} [opts]
 * @param {number} [opts.intervalSeconds]          default 20
 * @param {(msg: string) => void} [opts.log]       start line + first failure only
 * @returns {{stop: () => void, sweepNow: () => object, intervalSeconds: number}}
 */
function startKeepalive(wsServer, WebSocket, opts) {
    opts = opts || {};
    const intervalSeconds = opts.intervalSeconds || KEEPALIVE_PING_INTERVAL_SECONDS;
    const log = typeof opts.log === 'function' ? opts.log : function () {};

    let warnedOnce = false;
    const onError = function (err) {
        if (warnedOnce) {
            return;
        }
        warnedOnce = true;
        const detail = err && err.message ? err.message : String(err);
        log(`[WS] keepalive: a client ping failed (${detail}); sweep continues`);
    };

    const sweepNow = function () {
        return keepalivePingSweep(wsServer.clients, WebSocket.OPEN, onError);
    };

    const handle = setInterval(sweepNow, intervalSeconds * 1000);
    // Never let the keepalive timer hold the process open during shutdown.
    if (handle && typeof handle.unref === 'function') {
        handle.unref();
    }

    log(`[WS] keepalive: PING every ${intervalSeconds}s to OPEN clients (proxy idle-timeout guard)`);

    return {
        stop: function () {
            clearInterval(handle);
        },
        sweepNow,
        intervalSeconds,
    };
}

module.exports = {
    KEEPALIVE_PING_INTERVAL_SECONDS,
    keepalivePingSweep,
    startKeepalive,
};
