#!/usr/bin/env node
const readline = require('node:readline');
const fs = require('node:fs');
const { TerminalAdapter } = require('./adapter.cjs');
function run({ input = process.stdin, output = process.stdout, adapter = new TerminalAdapter(), onQuit = () => {} } = {}) {
    if (!input.isTTY || !output.isTTY) throw Error('Use an interactive terminal');
    const wasRaw = Boolean(input.isRaw);
    readline.emitKeypressEvents(input);
    let closed = false;
    const draw = () => output.write('\x1b[H' + adapter.render(output.columns || 80, output.rows || 24).join('\r\n') + '\x1b[J');
    const cleanup = () => {
        if (closed) return; closed = true;
        input.off('keypress', keypress); input.off('end', cleanup); output.off('resize', draw);
        process.off('SIGTERM', cleanup); process.off('SIGHUP', cleanup);
        input.setRawMode(wasRaw); input.pause();
        output.write('\x1b[0m\x1b[?25h\x1b[?1049l');
        onQuit(adapter.snapshot());
    };
    const keypress = (text, key = {}) => {
        try {
            adapter.key(key.ctrl && key.name === 'c' ? 'ctrl-c' : text === '?' ? '?' : key.name || text);
            if (adapter.closed) cleanup(); else draw();
        } catch (error) { cleanup(); throw error; }
    };
    input.setRawMode(true); input.resume();
    input.on('keypress', keypress); input.on('end', cleanup); output.on('resize', draw);
    process.on('SIGTERM', cleanup); process.on('SIGHUP', cleanup);
    output.write('\x1b[?1049h\x1b[?25l'); draw();
    return cleanup;
}
if (require.main === module) {
    const args = process.argv.slice(2);
    const get = flag => { const index = args.indexOf(flag); if (index < 0) return undefined; if (!args[index + 1]) throw Error(`Missing ${flag} value`); return args[index + 1]; };
    const restore = get('--restore'), destination = get('--export');
    run({ adapter: new TerminalAdapter({ puzzleId: get('--puzzle'), snapshot: restore ? JSON.parse(fs.readFileSync(restore, 'utf8')) : undefined }),
        onQuit: snapshot => { if (destination) fs.writeFileSync(destination, JSON.stringify(snapshot)); } });
}
module.exports = { run };
