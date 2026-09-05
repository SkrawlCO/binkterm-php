'use strict';

/**
 * Managed door-runtime lifecycle helper.
 *
 * Centralises how the multiplexing bridge proves it still owns a launched
 * door runtime and how it tears that runtime down. Two hard rules:
 *
 *   1. A raw PID is never trusted on its own. The kernel recycles PIDs, so a
 *      bare `process.kill(pid, ...)` after any delay can hit an unrelated
 *      process. Every signal in this module is gated on an immutable identity
 *      tuple (pgid + /proc start-time + boot id) captured at launch.
 *
 *   2. A POSIX process group is signalled ONLY when this bridge created it and
 *      owns its leader (pgid === pid, i.e. the runtime was spawned
 *      `detached: true` / via forkpty's setsid). A door that deliberately
 *      double-forks / calls setsid() leaves that group and is intentionally
 *      out of scope -- this module guarantees cleanup of the *owned* group,
 *      not containment of a runtime that actively escapes it. (cgroups /
 *      systemd scopes would be needed for that and are out of scope here.)
 */

const fs = require('fs');

const CLOCK_TICK_HZ = 100; // Linux USER_HZ; only used for human-readable logging

let cachedBootId = null;

/**
 * Host boot identifier. Immutable for the life of a boot; changes on reboot.
 * Used so a persisted identity tuple from before a reboot can never be
 * mistaken for a live process after the reboot (start-time is measured in
 * ticks-since-boot and would otherwise be ambiguous across boots).
 *
 * @returns {string|null}
 */
function readBootId() {
    if (cachedBootId !== null) {
        return cachedBootId || null;
    }
    try {
        cachedBootId = fs.readFileSync('/proc/sys/kernel/random/boot_id', 'utf8').trim();
    } catch (_) {
        cachedBootId = '';
    }
    return cachedBootId || null;
}

/**
 * Parse /proc/<pid>/stat for the fields we need. Robust against a comm field
 * that contains spaces or parentheses: everything up to the last ')' is the
 * "(comm)" field, and the space-separated fields after it start at field 3
 * (state).
 *
 * @param {number} pid
 * @returns {{state:string, pgid:number, starttime:string}|null} null when the
 *          pid is gone or unreadable.
 */
function readProcStat(pid) {
    if (!pid || pid < 1) {
        return null;
    }
    let raw;
    try {
        raw = fs.readFileSync(`/proc/${pid}/stat`, 'utf8');
    } catch (_) {
        return null; // ESRCH / gone / not Linux
    }
    const rparen = raw.lastIndexOf(')');
    if (rparen === -1) {
        return null;
    }
    // Fields after "(comm)" -- index 0 here == stat field 3 (state).
    const rest = raw.slice(rparen + 2).trim().split(/\s+/);
    const state = rest[0] || '';
    // field 5  (pgrp)      -> rest[2]
    // field 22 (starttime) -> rest[19]
    const pgid = Number.parseInt(rest[2], 10);
    const starttime = rest[19];
    if (!Number.isFinite(pgid) || typeof starttime !== 'string' || starttime.length === 0) {
        return null;
    }
    return { state, pgid, starttime };
}

/**
 * Capture the immutable identity of a just-launched runtime. Call this
 * immediately after spawn, while the PID is guaranteed to still be the one we
 * created.
 *
 * @param {number} pid
 * @returns {{pid:number, pgid:number, starttime:string, bootId:string|null, ownsGroup:boolean, launchedAt:number}|null}
 */
function captureRuntimeIdentity(pid) {
    if (!pid || pid < 1) {
        return null;
    }
    const stat = readProcStat(pid);
    if (!stat) {
        return null;
    }
    return {
        pid,
        pgid: stat.pgid,
        starttime: stat.starttime,
        bootId: readBootId(),
        // We only own (and may signal) the process group when the runtime is
        // its own group leader -- true for detached spawns and forkpty setsid.
        ownsGroup: stat.pgid === pid,
        launchedAt: Date.now()
    };
}

const delay = (ms) => new Promise(resolve => setTimeout(resolve, ms));

/**
 * Capture identity, waiting briefly for the runtime to become its own process
 * group leader. `detached: true` spawns (and forkpty) make the child call
 * setsid(), but that happens in the child after the parent's spawn() returns,
 * so an immediate /proc read can still show the inherited pgid. We must record
 * the post-setsid pgid or a later verify would see a "mismatch" against our
 * own door and refuse to kill it.
 *
 * @param {number} pid
 * @param {{requireGroupLeader?:boolean, attempts?:number, intervalMs?:number}} [opts]
 * @returns {Promise<ReturnType<typeof captureRuntimeIdentity>>}
 */
async function captureStableRuntimeIdentity(pid, opts = {}) {
    const { requireGroupLeader = true, attempts = 25, intervalMs = 10 } = opts;
    let last = null;
    for (let i = 0; i < Math.max(1, attempts); i++) {
        last = captureRuntimeIdentity(pid);
        if (!last) {
            return null; // process already gone
        }
        if (!requireGroupLeader || last.ownsGroup) {
            return last;
        }
        await delay(intervalMs);
    }
    return last; // best effort; ownsGroup may be false -> group kill is skipped
}

/**
 * Verify that the process at recorded.pid is still the exact runtime we
 * launched.
 *
 * @param {object|null} recorded identity from captureRuntimeIdentity()
 * @returns {'gone'|'match'|'mismatch'}
 *   'gone'     - nothing owned is alive (safe: nothing to do / nothing to kill)
 *   'match'    - the recorded runtime is still alive and is ours
 *   'mismatch' - a process exists at that pid but it is NOT ours -> FAIL CLOSED
 */
function verifyRuntimeIdentity(recorded) {
    if (!recorded || !recorded.pid) {
        return 'gone';
    }
    // A recorded identity from a previous boot can never be re-validated
    // (start-time is ticks-since-boot). Refuse to match it.
    const bootId = readBootId();
    if (recorded.bootId && bootId && recorded.bootId !== bootId) {
        return 'mismatch';
    }
    const stat = readProcStat(recorded.pid);
    if (!stat) {
        return 'gone';
    }
    // A zombie/dead process has already exited -- nothing left to signal.
    if (stat.state === 'Z' || stat.state === 'X' || stat.state === 'x') {
        return 'gone';
    }
    if (String(stat.starttime) === String(recorded.starttime) &&
        Number(stat.pgid) === Number(recorded.pgid)) {
        return 'match';
    }
    return 'mismatch';
}

/**
 * Send a signal to the runtime, scoped as narrowly as ownership allows.
 * Group signal (negative pgid) only when recorded.ownsGroup; otherwise a
 * single-PID signal. Never throws.
 *
 * @returns {boolean} true if the signal call did not raise ESRCH-style errors
 */
function signalOwnedRuntime(recorded, signal, logger) {
    const log = logger || console;
    try {
        if (recorded.ownsGroup && recorded.pgid === recorded.pid) {
            process.kill(-recorded.pgid, signal);
        } else {
            process.kill(recorded.pid, signal);
        }
        return true;
    } catch (err) {
        if (err.code === 'ESRCH') {
            return true; // already gone
        }
        if (typeof log.error === 'function') {
            log.error(`[lifecycle] signal ${signal} to runtime pid=${recorded.pid} failed: ${err.message}`);
        }
        return false;
    }
}

/**
 * Terminate a runtime this bridge owns, identity-gated at every step.
 *
 * Sequence:
 *   - verify identity; 'gone' -> success (already-gone); 'mismatch' -> FAIL
 *     CLOSED, send nothing.
 *   - run the optional caller-supplied graceful step (carrier-loss close /
 *     SIGHUP) exactly once.
 *   - poll for exit up to gracefulTimeoutMs, re-verifying identity each loop.
 *   - if still 'match', SIGKILL the owned group (or PID); poll up to
 *     forceTimeoutMs.
 *   - if at any point identity becomes 'mismatch', stop and FAIL CLOSED.
 *
 * Idempotent: a second call on an already-dead runtime returns
 * { ok: true, outcome: 'already-gone' } without signalling.
 *
 * @param {object|null} recorded
 * @param {{graceful?:(()=>void)|null, gracefulTimeoutMs?:number, forceTimeoutMs?:number, logger?:object}} [opts]
 * @returns {Promise<{ok:boolean, outcome:string}>}
 */
async function terminateOwnedRuntime(recorded, opts = {}) {
    const {
        graceful = null,
        gracefulTimeoutMs = 5000,
        forceTimeoutMs = 1000,
        logger = console
    } = opts;
    const log = logger || console;
    const info = (msg) => {
        if (log && typeof log.log === 'function') { log.log(msg); }
        else if (log && typeof log.warn === 'function') { log.warn(msg); }
        else { console.log(msg); }
    };

    const initial = verifyRuntimeIdentity(recorded);
    if (initial === 'gone') {
        return { ok: true, outcome: 'already-gone' };
    }
    if (initial === 'mismatch') {
        info(`[lifecycle] runtime identity mismatch for pid=${recorded && recorded.pid} -- not signalling (fail closed)`);
        return { ok: false, outcome: 'identity-mismatch' };
    }

    if (typeof graceful === 'function') {
        try { graceful(); } catch (err) {
            if (typeof log.error === 'function') {
                log.error(`[lifecycle] graceful step threw: ${err.message}`);
            }
        }
    }

    const gracefulDeadline = Date.now() + Math.max(0, gracefulTimeoutMs);
    while (Date.now() < gracefulDeadline) {
        const state = verifyRuntimeIdentity(recorded);
        if (state === 'gone') {
            return { ok: true, outcome: 'exited-graceful' };
        }
        if (state === 'mismatch') {
            return { ok: false, outcome: 'identity-mismatch' };
        }
        await delay(25);
    }

    // Still alive and still ours -> force.
    const preForce = verifyRuntimeIdentity(recorded);
    if (preForce === 'gone') {
        return { ok: true, outcome: 'exited-graceful' };
    }
    if (preForce === 'mismatch') {
        return { ok: false, outcome: 'identity-mismatch' };
    }

    info(`[lifecycle] runtime pid=${recorded.pid} did not exit; SIGKILL ${recorded.ownsGroup ? `process group ${recorded.pgid}` : `pid ${recorded.pid}`}`);
    signalOwnedRuntime(recorded, 'SIGKILL', log);

    const forceDeadline = Date.now() + Math.max(0, forceTimeoutMs);
    while (Date.now() < forceDeadline) {
        const state = verifyRuntimeIdentity(recorded);
        if (state === 'gone') {
            return { ok: true, outcome: 'killed' };
        }
        if (state === 'mismatch') {
            return { ok: false, outcome: 'identity-mismatch' };
        }
        await delay(25);
    }

    return { ok: verifyRuntimeIdentity(recorded) === 'gone', outcome: 'kill-unconfirmed' };
}

/* --------------------------------------------------------------------------
 * Backwards-compatible shims. Existing call sites and tests use these; they
 * now route through the identity-gated primitive above. A bare pid carries no
 * launch-time identity, so these capture it lazily -- callers that need
 * PID-reuse protection must pass a recorded identity to terminateOwnedRuntime
 * directly.
 * ------------------------------------------------------------------------ */

function isProcessRunning(pid) {
    return readProcStat(pid) !== null;
}

/**
 * @deprecated prefer terminateOwnedRuntime() with a captured identity.
 * @param {number} pid
 * @param {number} gracefulTimeoutMs
 * @param {number} forceTimeoutMs
 * @param {object} [logger]
 * @returns {Promise<boolean>}
 */
async function waitForProcessExit(pid, gracefulTimeoutMs, forceTimeoutMs, logger = console) {
    if (!pid || !isProcessRunning(pid)) {
        return true;
    }
    const recorded = captureRuntimeIdentity(pid);
    if (!recorded) {
        return true;
    }
    const result = await terminateOwnedRuntime(recorded, {
        graceful: null,
        gracefulTimeoutMs,
        forceTimeoutMs,
        logger
    });
    return result.ok;
}

module.exports = {
    readBootId,
    readProcStat,
    captureRuntimeIdentity,
    captureStableRuntimeIdentity,
    verifyRuntimeIdentity,
    terminateOwnedRuntime,
    isProcessRunning,
    waitForProcessExit,
    CLOCK_TICK_HZ
};
