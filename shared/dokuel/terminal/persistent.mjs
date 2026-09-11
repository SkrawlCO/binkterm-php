#!/usr/bin/env node
import { helperTransport } from '../persistence/node-transport.mjs';
import { connectTerminal } from '../persistence/terminal.mjs';
import { runTerminal } from './runtime.mjs';
if (process.argv.length !== 2 || !process.stdin.isTTY || !process.stdout.isTTY) {
    console.error('Trusted interactive launcher required; no caller arguments accepted.'); process.exit(2);
}
const helper = helperTransport();
try {
    const { adapter, lease } = await connectTerminal(helper.request);
    runTerminal(adapter, { onExit: async () => {
        try { await lease.release(); } finally { helper.close(); }
    } });
} catch (error) { helper.close(); console.error(error.message); process.exitCode = 1; }
