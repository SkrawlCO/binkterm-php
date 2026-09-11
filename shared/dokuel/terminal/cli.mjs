#!/usr/bin/env node
import { runTerminal } from './runtime.mjs';
import { readFileSync, writeFileSync } from 'node:fs';
import { TerminalAdapter } from './adapter.mjs';

const args = process.argv.slice(2);
let restoreFile, snapshotFile;
for (let i = 0; i < args.length; i++) {
    if (['--restore', '--snapshot'].includes(args[i]) && args[i + 1] && !args[i + 1].startsWith('--')) {
        if (args[i] === '--restore') restoreFile = args[++i]; else snapshotFile = args[++i];
    } else {
        console.error('Usage: node cli.mjs [--restore FILE] [--snapshot FILE]'); process.exit(2);
    }
}
if (!process.stdin.isTTY || !process.stdout.isTTY) {
    console.error('Dokuel requires an interactive terminal (80x24 minimum).'); process.exit(2);
}
const app = new TerminalAdapter(null, {
    exportSnapshot: snapshotFile ? text => writeFileSync(snapshotFile, text + '\n', { mode: 0o600 }) : null,
});
try { if (restoreFile) app.restore(readFileSync(restoreFile, 'utf8')); }
catch (error) { console.error(`Cannot restore snapshot: ${error.message}`); process.exit(2); }
runTerminal(app);
