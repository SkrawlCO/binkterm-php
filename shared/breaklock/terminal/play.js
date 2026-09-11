#!/usr/bin/env node
import { BreakLockSession } from '../persistence/session.js';
import { helperTransport } from '../persistence/node-transport.js';
import { emitKeypressEvents } from 'node:readline';
import { BreakLockTerminal } from './adapter.js';

const input = process.stdin, output = process.stdout;
if (!input.isTTY || !output.isTTY) {
    process.stderr.write('BreakLock requires an interactive terminal (80x24 or larger).\n');
    process.exitCode = 2;
} else {
    let persistence, transport;
    const wasRaw = Boolean(input.isRaw);
    let finished = false;
    const adequate = () => output.columns >= 80 && output.rows >= 24;
    const render = lines => {
        const content = adequate() ? lines : ['BreakLock needs 80 columns x 24 rows.', 'Resize to continue, or Q to quit.'];
        output.write('\x1b[H\x1b[2J' + content.map(line => line.slice(0, Math.max(0, output.columns - 1))).join('\r\n'));
    };
    const finish = (quiet = false) => {
        if (finished) return;
        transport?.close();
        finished = true;
        input.removeListener('keypress', keypress);
        input.removeListener('end', quit);
        output.removeListener('resize', resize);
        for (const signal of ['SIGINT', 'SIGTERM', 'SIGHUP']) process.removeListener(signal, quit);
        if (input.isTTY) input.setRawMode(wasRaw);
        input.pause();
        if (!quiet && !output.destroyed) output.write('\x1b[0m\x1b[?25h\x1b[?1049l' + (persistence ? 'BreakLock closed. Check save status above.' : 'BreakLock closed. No progress saved.') + '\r\n');
    };
    const adapter = new BreakLockTerminal({ onRender: render, onQuit: finish });
    if (process.env.BREAKLOCK_PERSISTENCE === '1') {
        transport = helperTransport();
        persistence = new BreakLockSession(adapter, transport.request, { onError: error => {
            adapter.message = 'Storage stopped: ' + error.message; adapter.draw();
        } });
        adapter.beforeQuit = async () => {
            try { await persistence.exit(); }
            catch (error) { process.exitCode = 1; process.stderr.write('BreakLock save failed: ' + error.message + '\n'); }
            adapter.beforeQuit = null; adapter.quit();
        };
    }
    const quit = () => adapter.quit();
    const resize = () => adapter.draw();
    const keypress = (text, key = {}) => {
        const command = key.ctrl && ['c', 'd'].includes(key.name) ? `ctrl-${key.name}`
            : ['return', 'enter'].includes(key.name) ? 'enter'
            : ['pageup', 'pagedown'].includes(key.name) ? key.name : text;
        if (command && (adequate() || ['q', 'Q', 'ctrl-c', 'ctrl-d'].includes(command))) adapter.handleKey(command);
    };
    output.on('error', error => {
        finish(true); adapter.quit();
        if (error.code !== 'EPIPE') process.exitCode = 1;
    });
    emitKeypressEvents(input);
    input.setRawMode(true);
    input.on('keypress', keypress);
    input.on('end', quit);
    output.on('resize', resize);
    for (const signal of ['SIGINT', 'SIGTERM', 'SIGHUP']) process.on(signal, quit);
    process.once('exit', () => finish());
    output.write('\x1b[?1049h\x1b[?25l');
    adapter.draw();
    input.resume();
    if (persistence) persistence.acquire().catch(error => { adapter.message = error.message; adapter.draw(); });
}
