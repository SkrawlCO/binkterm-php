'use strict';

const assert = require('assert');
const net = require('net');
const { spawn } = require('child_process');
const { waitForProcessExit } = require('../../scripts/dosbox-bridge/managed-runtime-lifecycle');

async function listen(server) {
    await new Promise((resolve, reject) => {
        server.once('error', reject);
        server.listen(0, '127.0.0.1', resolve);
    });
    return server.address().port;
}

(async () => {
    const sockets = new Set();
    const server = net.createServer(socket => {
        sockets.add(socket);
        socket.once('close', () => sockets.delete(socket));
    });
    const port = await listen(server);
    const child = spawn(process.execPath, ['-e', `
        const net = require('net');
        const socket = net.connect(${port}, '127.0.0.1');
        socket.on('connect', () => process.stdout.write('ready\\n'));
        setInterval(() => {}, 1000);
    `], { stdio: ['ignore', 'pipe', 'inherit'] });
    await new Promise(resolve => child.stdout.once('data', resolve));
    assert.strictEqual(sockets.size, 1, 'runtime must own one backend socket');

    child.kill('SIGTERM');
    const confirmed = await waitForProcessExit(child.pid, 1000, 500);
    await new Promise(resolve => setTimeout(resolve, 25));
    assert.strictEqual(confirmed, true, 'termination must be confirmed');
    assert.strictEqual(sockets.size, 0, 'backend socket must close before confirmation');
    server.close();

    const stubborn = spawn(process.execPath, ['-e', `
        process.on('SIGTERM', () => {});
        process.stdout.write('ready\\n');
        setInterval(() => {}, 1000);
    `], { stdio: ['ignore', 'pipe', 'inherit'] });
    await new Promise(resolve => stubborn.stdout.once('data', resolve));
    stubborn.kill('SIGTERM');
    const forced = await waitForProcessExit(
        stubborn.pid,
        50,
        1000,
        { warn() {}, error() {} }
    );
    assert.strictEqual(forced, true, 'bounded SIGKILL fallback must confirm exit');

    console.log('managed-runtime-lifecycle tests passed');
})().catch(err => {
    console.error(err);
    process.exitCode = 1;
});
