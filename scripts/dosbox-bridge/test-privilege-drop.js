#!/usr/bin/env node
/**
 * Focused regression tests for the NativeDoor child privilege-drop boundary
 * added in PEH-3B. No test framework exists in this subdirectory (package.json's
 * own "test" script just starts the server), so this is a small standalone
 * assert-based script: `node test-privilege-drop.js`.
 *
 * These tests exercise the identity-resolution logic itself (copied inline
 * rather than requiring multiplexing-server.js, since that module has
 * top-level side effects -- opening sockets, connecting to Postgres -- that
 * would make it unsafe to require from a test process). Keeping the two
 * copies in sync is a small, accepted cost for not dragging server startup
 * into a unit test.
 */
const assert = require('assert');
const fs = require('fs');
const os = require('os');
const path = require('path');

// --- copy of the function under test (see multiplexing-server.js) ---------
function resolveDosdoorIdentity(username, passwdPath) {
    let passwdContents;
    try {
        passwdContents = fs.readFileSync(passwdPath, 'utf8');
    } catch (err) {
        throw new Error(`[SECURITY] Could not read /etc/passwd to resolve the '${username}' runtime account: ${err.message}`);
    }
    const line = passwdContents.split('\n').find((l) => l.startsWith(`${username}:`));
    if (!line) {
        throw new Error(`[SECURITY] Runtime account '${username}' does not exist -- refusing to launch door children as root.`);
    }
    const fields = line.split(':');
    const uid = parseInt(fields[2], 10);
    const gid = parseInt(fields[3], 10);
    if (!Number.isInteger(uid) || uid === 0 || !Number.isInteger(gid) || gid === 0) {
        throw new Error(`[SECURITY] Runtime account '${username}' resolved to uid=${uid} gid=${gid} -- refusing to launch door children as root.`);
    }
    return { uid, gid };
}

function buildSetprivArgs(identity, dosboxExe, args) {
    return [
        `--reuid=${identity.uid}`,
        `--regid=${identity.gid}`,
        '--clear-groups',
        '--',
        dosboxExe,
        ...args
    ];
}

let passed = 0;
function check(name, fn) {
    try {
        fn();
        console.log(`  ok - ${name}`);
        passed++;
    } catch (err) {
        console.error(`  FAIL - ${name}: ${err.message}`);
        process.exitCode = 1;
    }
}

const tmpDir = fs.mkdtempSync(path.join(os.tmpdir(), 'dosdoor-privtest-'));

// 1. Real /etc/passwd resolves to a non-zero uid/gid when the account exists.
check('resolves a fabricated non-root account to non-zero uid/gid', () => {
    const fakePasswd = path.join(tmpDir, 'passwd-ok');
    fs.writeFileSync(fakePasswd, 'root:x:0:0:root:/root:/bin/bash\ndosdoor:x:997:997::/nonexistent:/usr/sbin/nologin\n');
    const identity = resolveDosdoorIdentity('dosdoor', fakePasswd);
    assert.strictEqual(identity.uid, 997);
    assert.strictEqual(identity.gid, 997);
});

// 2. Missing account fails closed (throws), never falls back to root.
check('missing runtime account fails closed', () => {
    const fakePasswd = path.join(tmpDir, 'passwd-missing');
    fs.writeFileSync(fakePasswd, 'root:x:0:0:root:/root:/bin/bash\n');
    assert.throws(() => resolveDosdoorIdentity('dosdoor', fakePasswd), /does not exist/);
});

// 3. An account that resolves to uid/gid 0 fails closed rather than being used.
check('account resolving to uid 0 fails closed', () => {
    const fakePasswd = path.join(tmpDir, 'passwd-root-uid');
    fs.writeFileSync(fakePasswd, 'dosdoor:x:0:997::/nonexistent:/usr/sbin/nologin\n');
    assert.throws(() => resolveDosdoorIdentity('dosdoor', fakePasswd), /refusing to launch door children as root/);
});

check('account resolving to gid 0 fails closed', () => {
    const fakePasswd = path.join(tmpDir, 'passwd-root-gid');
    fs.writeFileSync(fakePasswd, 'dosdoor:x:997:0::/nonexistent:/usr/sbin/nologin\n');
    assert.throws(() => resolveDosdoorIdentity('dosdoor', fakePasswd), /refusing to launch door children as root/);
});

// 4. The resolved identity comes ONLY from the trusted passwd lookup -- a
//    manifest-shaped object passed alongside has no way to reach the spawn
//    args (buildSetprivArgs only ever receives the resolved identity + the
//    trusted dosboxExe path + already-built args array; nothing here reads a
//    "uid"/"gid"/"user" field off a manifest object at all).
check('manifest object cannot influence identity (no such input exists)', () => {
    const fakePasswd = path.join(tmpDir, 'passwd-ok2');
    fs.writeFileSync(fakePasswd, 'dosdoor:x:997:997::/nonexistent:/usr/sbin/nologin\n');
    const maliciousManifest = { door: { uid: 0, gid: 0, user: 'root' } };
    const identity = resolveDosdoorIdentity('dosdoor', fakePasswd); // no manifest arg accepted
    assert.strictEqual(identity.uid, 997, 'identity must be unaffected by any manifest-shaped object');
    assert.notStrictEqual(identity.uid, maliciousManifest.door.uid);
});

// 5. Existing launch command/argument semantics unchanged: the real dosboxExe
//    path and its original args array still appear verbatim, just appended
//    after the setpriv identity-drop prefix -- not replaced, not shell-quoted.
check('setpriv wrapping preserves original command/args verbatim', () => {
    const identity = { uid: 997, gid: 997 };
    const originalArgs = ['-noconsole', '-conf', '/tmp/session/dosbox.conf', '-exit'];
    const wrapped = buildSetprivArgs(identity, '/usr/bin/dosbox', originalArgs);
    assert.deepStrictEqual(wrapped, [
        '--reuid=997', '--regid=997', '--clear-groups', '--',
        '/usr/bin/dosbox', '-noconsole', '-conf', '/tmp/session/dosbox.conf', '-exit'
    ]);
});

fs.rmSync(tmpDir, { recursive: true, force: true });

console.log(`\n${passed} passed${process.exitCode ? ', with failures' : ''}`);
