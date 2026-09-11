import { emitKeypressEvents } from 'node:readline';
export function runTerminal(app, { onExit = async () => {} } = {}) {
const input = process.stdin, output = process.stdout;
const priorRaw = input.isRaw ?? false;
let stopped = false, timer, previous = '', last = performance.now();
const tick = () => {
    const now = performance.now(), delta = Math.floor(now - last);
    last += delta; app.advanceTime(delta);
};
function draw() {
    const lines = app.render(output.columns, output.rows);
    const frame = lines.join('\n');
    if (frame === previous) return;
    previous = frame;
    // Explicit row positioning avoids newline/autowrap at the bottom-right cell.
    const colour = !('NO_COLOR' in process.env) && process.env.TERM !== 'dumb';
    const paint = line => colour ? line.replace(/![1-9.]!/g, token => `\x1b[31m${token}\x1b[0m`).replace(/\[[1-9.:]\]/g, token => `\x1b[7m${token}\x1b[0m`) : line;
    output.write('\x1b[H\x1b[2J' + lines.map((line, i) => `\x1b[${i + 1};1H${paint(line)}`).join(''));
}
async function cleanup(code = 0) {
    if (stopped) return;
    stopped = true;
    clearInterval(timer);
    input.off('keypress', onKey); output.off('resize', onResize);
    input.setRawMode(priorRaw); input.pause();
    try { await onExit(); } catch (error) { code = 1; process.stderr.write(`Save stopped: ${error.message}\n`); }
    output.write('\x1b[0m\x1b[?25h\x1b[?1049l', () => { process.exitCode = code; });
}
function onKey(text, key = {}) {
    if (stopped) return;
    tick();
    if (key.ctrl && key.name === 'c') { cleanup(130); return; }
    // Ignore modified shortcuts and unknown escape sequences; no pasted commands.
    if (key.ctrl || (key.meta && key.name !== 'escape') || (text?.startsWith('\x1b') && !['escape', 'up', 'down', 'left', 'right', 'delete', 'pageup', 'pagedown'].includes(key.name))) return;
    const name = key.name === 'return' ? 'enter' : key.name === 'space' ? 'space' : text === '?' ? '?' : key.name ?? text;
    if (!name) return;
    try { app.key(name); if (app.quit) cleanup(); else draw(); }
    catch (error) { app.message = `Operation failed: ${error.message}`; app.menu(); draw(); }
}
function onResize() { tick(); app.resize(output.columns, output.rows); previous = ''; draw(); }
process.on('SIGTERM', () => cleanup(143));
process.on('SIGINT', () => cleanup(130));
process.on('SIGHUP', () => cleanup(129));
process.on('uncaughtException', () => cleanup(1));
input.on('end', () => cleanup());
input.on('error', () => cleanup(1));
output.on('error', () => { cleanup(1); });
emitKeypressEvents(input);
input.setRawMode(true); input.resume();
input.on('keypress', onKey); output.on('resize', onResize);
output.write('\x1b[?1049h\x1b[?25l');
app.resize(output.columns, output.rows); draw();
timer = setInterval(() => { if (!stopped) { tick(); draw(); } }, 250);

return cleanup;
}
