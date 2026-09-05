'use strict';

/**
 * Ownership / identity guarantees for the managed door-runtime lifecycle.
 *
 * Proves:
 *  1. process-group termination: a runtime spawned as its own group leader is
 *     killed together with an ordinary child it spawned.
 *  2. identity validation: a tampered identity tuple FAILS CLOSED and sends no
 *     signal -- the live (unrelated-by-identity) process is untouched.
 *  3. restart-style reconciliation: a live runtime whose recorded identity
 *     still verifies is terminated before its "session" is finalised.
 *  4. bounded teardown: terminateOwnedRuntime is awaitable and confirms exit
 *     within its force budget even for a SIGTERM-ignoring runtime.
 *  5. idempotence: a second terminate call on a dead runtime is a harmless
 *     no-op ('already-gone'), never a throw and never a signal.
 *  6. BOUNDARY (documented, not a containment claim): a descendant that
 *     re-sessions itself (spawned detached => its own new process group)
 *     deliberately escapes the owned group and SURVIVES the group kill. This
 *     slice guarantees cleanup of the OWNED group, not containment of a
 *     runtime that actively escapes it.
 */

const assert = require('assert');
const { spawn } = require('child_process');
const {
    captureStableRuntimeIdentity,
    verifyRuntimeIdentity,
    terminateOwnedRuntime
} = require('../../scripts/dosbox-bridge/managed-runtime-lifecycle');

const alive = (pid) => {
    if (!pid) return false;
    try { process.kill(pid, 0); return true; }
    catch (err) { return err.code !== 'ESRCH'; }
};
const sleep = (ms) => new Promise(r => setTimeout(r, ms));

async function waitGone(pid, timeoutMs = 3000) {
    const deadline = Date.now() + timeoutMs;
    while (Date.now() < deadline) {
        if (!alive(pid)) return true;
        await sleep(20);
    }
    return !alive(pid);
}

// A runtime that is its own group/session leader (detached) and spawns one
// ordinary child in the SAME group. Prints "<parentPid> <childPid>".
const OWNED_GROUP_FIXTURE = `
    const { spawn } = require('child_process');
    const child = spawn(process.execPath, ['-e', 'setInterval(()=>{},1000)'], { stdio: 'ignore' });
    process.stdout.write(process.pid + ' ' + child.pid + '\\n');
    setInterval(() => {}, 1000);
`;

// A runtime that spawns a grandchild which RE-SESSIONS itself (detached), so
// the grandchild leaves the owned group. Prints "<parentPid> <escapedPid>".
const ESCAPING_FIXTURE = `
    const { spawn } = require('child_process');
    const esc = spawn(process.execPath, ['-e', 'setInterval(()=>{},1000)'], { detached: true, stdio: 'ignore' });
    esc.unref();
    process.stdout.write(process.pid + ' ' + esc.pid + '\\n');
    setInterval(() => {}, 1000);
`;

function launch(fixture, opts = {}) {
    const proc = spawn(process.execPath, ['-e', fixture], { detached: true, stdio: ['ignore', 'pipe', 'inherit'], ...opts });
    return new Promise((resolve) => {
        let buf = '';
        proc.stdout.on('data', (d) => {
            buf += d.toString();
            const nl = buf.indexOf('\n');
            if (nl !== -1) {
                const [a, b] = buf.slice(0, nl).trim().split(/\s+/).map(Number);
                resolve({ proc, primaryPid: a, secondaryPid: b });
            }
        });
    });
}

const silent = { log() {}, warn() {}, error() {} };

(async () => {
    // ---- 1. owned process-group termination -----------------------------
    {
        const { primaryPid, secondaryPid } = await launch(OWNED_GROUP_FIXTURE);
        const identity = await captureStableRuntimeIdentity(primaryPid);
        assert.ok(identity, 'identity captured');
        assert.strictEqual(identity.ownsGroup, true, 'detached runtime owns its process group');
        assert.strictEqual(identity.pgid, primaryPid, 'pgid == pid for the group leader');
        assert.ok(alive(secondaryPid), 'ordinary child is running before teardown');

        const result = await terminateOwnedRuntime(identity, {
            graceful: null, gracefulTimeoutMs: 50, forceTimeoutMs: 3000, logger: silent
        });
        assert.strictEqual(result.ok, true, 'teardown confirmed');
        assert.ok(await waitGone(primaryPid), 'group leader is gone');
        assert.ok(await waitGone(secondaryPid), 'ordinary child in the owned group is gone too');
    }

    // ---- 2. identity mismatch => FAIL CLOSED, no signal -----------------
    {
        const { primaryPid } = await launch(OWNED_GROUP_FIXTURE);
        const identity = await captureStableRuntimeIdentity(primaryPid);
        const tampered = Object.assign({}, identity, { starttime: String(Number(identity.starttime) + 987654) });

        assert.strictEqual(verifyRuntimeIdentity(tampered), 'mismatch', 'tampered start-time does not verify');

        const result = await terminateOwnedRuntime(tampered, {
            graceful: null, gracefulTimeoutMs: 50, forceTimeoutMs: 300, logger: silent
        });
        assert.strictEqual(result.ok, false, 'fail closed');
        assert.strictEqual(result.outcome, 'identity-mismatch', 'reported as identity mismatch');
        assert.ok(alive(primaryPid), 'the real process was NOT signalled');

        // real teardown with the correct identity still works
        const ok = await terminateOwnedRuntime(identity, { gracefulTimeoutMs: 50, forceTimeoutMs: 3000, logger: silent });
        assert.strictEqual(ok.ok, true);
        await waitGone(primaryPid);
    }

    // ---- 3. restart-style reconciliation of a still-live runtime --------
    {
        const { primaryPid, secondaryPid } = await launch(OWNED_GROUP_FIXTURE);
        // Simulate: fresh bridge reads a persisted identity from disk.
        const persisted = await captureStableRuntimeIdentity(primaryPid);
        const persistedCopy = JSON.parse(JSON.stringify(persisted));

        assert.strictEqual(verifyRuntimeIdentity(persistedCopy), 'match', 'persisted identity still verifies');
        const result = await terminateOwnedRuntime(persistedCopy, {
            gracefulTimeoutMs: 50, forceTimeoutMs: 3000, logger: silent
        });
        assert.strictEqual(result.ok, true, 'reconciliation terminated the orphaned runtime');
        assert.ok(await waitGone(primaryPid), 'orphan leader gone');
        assert.ok(await waitGone(secondaryPid), 'orphan child gone');
    }

    // ---- 4. bounded teardown of a SIGTERM-ignoring runtime -------------
    {
        const stubbornFixture = `
            process.on('SIGTERM', () => {});
            process.on('SIGHUP', () => {});
            process.stdout.write(process.pid + ' 0\\n');
            setInterval(() => {}, 1000);
        `;
        const { primaryPid } = await launch(stubbornFixture);
        const identity = await captureStableRuntimeIdentity(primaryPid);
        const started = Date.now();
        const result = await terminateOwnedRuntime(identity, {
            graceful: () => { try { process.kill(-identity.pgid, 'SIGTERM'); } catch (_) {} },
            gracefulTimeoutMs: 100,
            forceTimeoutMs: 3000,
            logger: silent
        });
        assert.strictEqual(result.ok, true, 'SIGKILL escalation confirmed exit');
        assert.ok(Date.now() - started < 5000, 'teardown stayed bounded');
        assert.ok(await waitGone(primaryPid), 'stubborn runtime killed');
    }

    // ---- 5. idempotence ----------------------------------------------
    {
        const { primaryPid } = await launch(OWNED_GROUP_FIXTURE);
        const identity = await captureStableRuntimeIdentity(primaryPid);
        const first = await terminateOwnedRuntime(identity, { gracefulTimeoutMs: 50, forceTimeoutMs: 3000, logger: silent });
        assert.strictEqual(first.ok, true);
        await waitGone(primaryPid);
        const second = await terminateOwnedRuntime(identity, { gracefulTimeoutMs: 50, forceTimeoutMs: 300, logger: silent });
        assert.strictEqual(second.ok, true, 'second call is harmless');
        assert.strictEqual(second.outcome, 'already-gone', 'second call sends nothing');
    }

    // ---- 6. BOUNDARY: a re-sessioned descendant escapes the owned group
    {
        const { primaryPid, secondaryPid: escapedPid } = await launch(ESCAPING_FIXTURE);
        const identity = await captureStableRuntimeIdentity(primaryPid);
        assert.ok(alive(escapedPid), 'escaped grandchild running');

        await terminateOwnedRuntime(identity, { gracefulTimeoutMs: 50, forceTimeoutMs: 3000, logger: silent });
        assert.ok(await waitGone(primaryPid), 'owned leader gone');
        await sleep(200);
        assert.ok(
            alive(escapedPid),
            'DOCUMENTED LIMITATION: a descendant that re-sessions itself leaves the owned ' +
            'process group and is NOT killed by the group signal. This slice guarantees ' +
            'owned-group cleanup, not containment of an actively escaping runtime.'
        );
        // clean up the intentionally-leaked process
        try { process.kill(escapedPid, 'SIGKILL'); } catch (_) {}
    }

    console.log('managed-runtime-lifecycle-ownership tests passed');
})().catch(err => {
    console.error(err);
    process.exitCode = 1;
});
