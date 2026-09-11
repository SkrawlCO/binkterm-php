import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { BreakLockTerminal, WIDTH, HEIGHT } from './adapter.js';

function fakeClock() {
    let serial = 0;
    const intervals = new Map(), timeouts = new Map();
    return {
        intervals, timeouts,
        setInterval(fn, delay) { assert.equal(delay, 1000); intervals.set(++serial, fn); return serial; },
        clearInterval(id) { intervals.delete(id); },
        setTimeout(fn, delay) { assert.equal(delay, 1000); timeouts.set(++serial, fn); return serial; },
        clearTimeout(id) { timeouts.delete(id); },
        tick() { for (const fn of [...intervals.values()]) fn(); },
        reset() { const pending = [...timeouts.values()]; timeouts.clear(); for (const fn of pending) fn(); },
    };
}
const evidence = [];
function capture(adapter, label) {
    const lines = adapter.lines();
    assert.equal(lines.length, HEIGHT);
    assert(lines.every(line => line.length <= WIDTH && /^[\x20-\x7e]*$/.test(line)));
    evidence.push({ label, rows: lines.length, maxWidth: Math.max(...lines.map(l => l.length)), screen: lines.join('\n') });
    return lines.join('\n');
}
function create(mode = '1', length = '4') {
    const clock = fakeClock(); let quits = 0;
    const a = new BreakLockTerminal({ clock, onQuit: () => quits++ });
    a.handleKey(mode); a.handleKey(length);
    const previous = Math.random, draws = [0, 1, 2, 5, 4, 3]; let i = 0;
    Math.random = () => (draws[i++ % draws.length] + 0.1) / 9;
    try { a.handleKey('enter'); } finally { Math.random = previous; }
    return { a, clock, quits: () => quits };
}
function enter(a, sequence) { for (const key of sequence) a.handleKey(String(key)); }
const snapshot = a => a.round.snapshot();
const wrong = [1, 2, 5, 3];
function attempt(a, sequence = wrong) { a.handleKey('c'); enter(a, sequence); }

test('all three menu modes and difficulties 4/5/6 select shared start state', () => {
    for (const [modeKey, mode] of [['1', 'practice'], ['2', 'challenge'], ['3', 'countdown']]) {
        for (const length of ['4', '5', '6']) {
            const { a } = create(modeKey, length);
            assert.equal(snapshot(a).mode, mode); assert.equal(snapshot(a).dotLength, Number(length));
            capture(a, `${mode}-${length}`); a.quit();
        }
    }
});
test('normal keys map to canonical positions with no model mutation', () => {
    const { a } = create(); const selected = [], select = a.round.select.bind(a.round);
    a.round.select = position => { selected.push(position); return select(position); };
    enter(a, [1, 2]);
    assert.deepEqual(selected, [0, 1]); assert.deepEqual(snapshot(a).draft, [0, 1]);
    assert.match(capture(a, 'normal selections'), /Current: 1-2/);
});
test('canonical midpoint is visible in Current and Added message', () => {
    const { a } = create(); enter(a, [1, 3]);
    assert.deepEqual(snapshot(a).draft, [0, 1, 2]);
    const frame = capture(a, 'midpoint insertion');
    assert.match(frame, /Current: 1-2-3/); assert.match(frame, /Added: 2-3/);
});
test('duplicate and pending-full intents reach shared select without rule filtering', () => {
    const { a } = create(); const calls = [], select = a.round.select.bind(a.round);
    a.round.select = p => { calls.push(p); return select(p); };
    enter(a, [1, 1]); assert.deepEqual(calls, [0, 0]); assert.deepEqual(snapshot(a).draft, [0]);
    assert.match(capture(a, 'duplicate rejection'), /Selection unchanged/);
    attempt(a); const before = snapshot(a); a.handleKey('9');
    assert.equal(calls.at(-1), 8); assert.deepEqual(snapshot(a), before);
    assert.match(capture(a, 'full draft held by shared pending reset'), /Guess displayed briefly/);
});
test('shared feedback renders; Practice win shows unchanged counter and summary', () => {
    const { a } = create(); attempt(a);
    const feedback = a.round.history()[0].feedback;
    assert.deepEqual(feedback, [2, 1, 1]);
    const frame = capture(a, 'Practice feedback');
    assert.match(frame, /1-2-5-3\s+2\s+1\s+1/);
    attempt(a, [1, 2, 3, 6]);
    assert.equal(snapshot(a).counterValue, 1);
    assert.match(capture(a, 'Practice win'), /LOCK OPENED/);
});
test('Challenge counter reaches failure through keypad; reveal and continued win retain loss', () => {
    const { a } = create('2'); attempt(a); assert.equal(snapshot(a).counterValue, 9);
    capture(a, 'Challenge counter nine');
    for (let i = 1; i < 10; i++) attempt(a);
    assert.match(capture(a, 'Challenge exhausted'), /ROUND LOST/);
    a.handleKey('s');
    assert.equal(a.round.attempts, 10); assert.equal(snapshot(a).counterValue, 10);
    assert.match(capture(a, 'solution'), /SOL\s+1-2-3-6/);
    attempt(a, [1, 2, 3, 6]); assert.equal(snapshot(a).ended, 'lost');
    assert.match(capture(a, 'continued after loss'), /Continuing after recorded loss/);
});
test('winning summary continuation adds no solution entry', () => {
    const { a } = create(); attempt(a, [1, 2, 3, 6]); a.handleKey('s');
    assert.equal(snapshot(a).history.length, 1); assert.equal(snapshot(a).summary.visible, false);
    attempt(a); assert.equal(a.round.attempts, 2); assert.equal(snapshot(a).ended, 'won');
});
test('scheduler delivers one shared tick; redraw shows remaining and timeout stops scheduling', () => {
    const { a, clock } = create('3');
    let ticks = 0; const tick = a.round.tick.bind(a.round);
    a.round.tick = () => { ticks++; return tick(); };
    a.update(); a.update(); assert.equal(clock.intervals.size, 1);
    clock.tick(); assert.equal(ticks, 1);
    assert.match(capture(a, 'Countdown 59'), /Countdown: 59/);
    for (let i = 1; i < 60; i++) clock.tick();
    assert.equal(ticks, 60); assert.equal(clock.intervals.size, 0);
    assert.match(capture(a, 'Countdown timeout'), /ROUND LOST/);
    a.handleKey('s'); attempt(a, [1, 2, 3, 6]); assert.equal(snapshot(a).ended, 'lost');
});
test('win cancels Countdown schedule without waiting for another callback', () => {
    const { a, clock } = create('3'); attempt(a, [1, 2, 3, 6]);
    assert.equal(clock.intervals.size, 0); assert.equal(snapshot(a).timer.remainingTicks, 60);
});
test('clear cancels one-shot reset; delivered reset clears submitted draft', () => {
    const { a, clock } = create(); attempt(a); assert.equal(clock.timeouts.size, 1);
    a.handleKey('c'); assert.equal(clock.timeouts.size, 0); assert.deepEqual(snapshot(a).draft, []);
    attempt(a); clock.reset(); assert.deepEqual(snapshot(a).draft, []); assert.equal(clock.timeouts.size, 0);
});
test('new game from overlay and active round delegates correct shared start action', () => {
    const { a } = create('2'); attempt(a, [1, 2, 3, 6]); a.handleKey('n');
    assert.equal(snapshot(a).summary.visible, false); assert.equal(a.round.attempts, 0);
    assert.equal(snapshot(a).counterValue, 10); assert.equal(snapshot(a).ended, 'active');
    a.handleKey('n'); assert.equal(snapshot(a).summary.visible, false);
    capture(a, 'new game');
});
test('history pages retain old guesses and return to newest after another attempt', () => {
    const { a } = create(); for (let i = 0; i < 25; i++) attempt(a);
    assert.match(capture(a, 'recent history'), /History 18-25 of 25/);
    a.handleKey('['); assert.match(capture(a, 'older history'), /History 10-17 of 25/);
    a.handleKey('pageup'); a.handleKey('pageup'); a.handleKey('pageup');
    assert.match(capture(a, 'oldest history'), /History 1-1 of 25/);
    a.handleKey(']'); assert.equal(a.page, 2);
    attempt(a); assert.equal(a.page, 0); assert.match(capture(a, 'newest after input'), /History 19-26 of 26/);
});
test('help/menu do not pause inherited Countdown; overlay consumes gameplay keys', () => {
    const { a, clock } = create('3'); a.handleKey('?'); capture(a, 'help'); clock.tick();
    a.handleKey('1'); assert.deepEqual(snapshot(a).draft, []);
    a.handleKey('m'); capture(a, 'menu with running clock');
    const handle = [...clock.intervals.keys()][0]; a.handleKey('1'); a.handleKey('enter');
    assert.equal([...clock.intervals.keys()][0], handle);
    for (let i = 1; i < 60; i++) clock.tick();
    assert.equal(snapshot(a).mode, 'practice'); assert.equal(snapshot(a).ended, 'lost');
    a.handleKey('1'); assert.deepEqual(snapshot(a).draft, []);
});
test('quit cancels callbacks once and later input/callbacks cannot mutate the round', () => {
    const { a, clock, quits } = create('3'); attempt(a);
    const tick = [...clock.intervals.values()][0], reset = [...clock.timeouts.values()][0];
    const before = snapshot(a); a.handleKey('q'); a.quit(); a.handleKey('1'); tick(); reset();
    assert.equal(quits(), 1); assert.equal(clock.intervals.size, 0); assert.equal(clock.timeouts.size, 0);
    assert.deepEqual(snapshot(a), before);
});
test('every captured menu/game/help/result frame fits 80x24 and secrets remain hidden', () => {
    const { a } = create(); const frame = capture(a, 'unrevealed initial board');
    assert(!frame.includes('Secret:')); assert(!frame.includes('1-2-3-6'));
    assert(evidence.length > 20);
    if (process.env.BREAKLOCK_TERMINAL_EVIDENCE) {
        fs.mkdirSync(process.env.BREAKLOCK_TERMINAL_EVIDENCE, { recursive: true });
        fs.writeFileSync(path.join(process.env.BREAKLOCK_TERMINAL_EVIDENCE, 'screens.json'), JSON.stringify(evidence, null, 2));
        fs.writeFileSync(path.join(process.env.BREAKLOCK_TERMINAL_EVIDENCE, 'screens.txt'), evidence.map(e => `${e.label}\n${e.screen}`).join('\n\n'));
    }
});
