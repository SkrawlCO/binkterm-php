const { test } = require('node:test');
const assert = require('node:assert/strict');
const { TerminalAdapter, columnName } = require('./adapter.cjs');
const { OrdinaryPuzzlesSession, catalog } = require('../session.cjs');
const go = (t, row, col) => { while (t.cursor[0] !== row) t.key(t.cursor[0] < row ? 'down' : 'up'); while (t.cursor[1] !== col) t.key(t.cursor[1] < col ? 'right' : 'left'); };
const drag = (t, from, to) => { go(t, ...from); t.key('space'); go(t, ...to); t.key('space'); };
const line = t => t.state().lines.find(l => l.origin === '0:1');
test('coordinates and cursor navigation are presentation-only', () => {
    assert.equal(columnName(0), 'A'); assert.equal(columnName(26), 'AA');
    const t = new TerminalAdapter(), before = t.snapshot(); t.key('left'); t.key('w'); t.key('d');
    assert.deepEqual(t.cursor, [0, 1]); assert.deepEqual(t.snapshot(), before);
});
test('Small full board fits 80x24 with coordinates and instructions', () => {
    const t = new TerminalAdapter(), frame = t.render(); assert.equal(frame.length, 24);
    assert(frame.every(l => l.length === 80)); assert(frame.join('\n').includes('View rows 1-9/9'));
    assert(frame.join('\n').includes('Q quit')); assert.equal(t.state().puzzleId, 'e9c2882a25e2');
});
test('key-driven placement delegates exact begin/leave/enter/end actions', () => {
    const t = new TerminalAdapter(); drag(t, [0, 1], [0, 3]);
    assert.deepEqual(line(t).cells, ['0:1', '0:2', '0:3']);
    assert.deepEqual(t.snapshot().actions.map(a => a.type), ['begin','leave','enter','leave','enter','end']);
    assert(t.render().some(l => l.includes('-')));
});
test('canonical collision clips input request, no terminal filtering', () => {
    const t = new TerminalAdapter(); drag(t, [0, 1], [0, 5]);
    assert.deepEqual(line(t).cells, ['0:1', '0:2', '0:3']);
    assert(t.snapshot().actions.some(a => a.type === 'enter' && a.cell.join() === '0,5'));
    assert.equal(t.state().cells[0][5].lineId, null); assert.notEqual(t.state().cells[0][4].lineId, line(t).id);
});
test('R delegates tap/reset including unoccupied cells', () => {
    const t = new TerminalAdapter(); drag(t, [0, 1], [0, 3]); go(t, 0, 1); t.key('r');
    assert.deepEqual(line(t).cells, ['0:1']); go(t, 0, 0); t.key('r');
    assert.deepEqual(t.snapshot().actions.slice(-2), [{type:'begin',cell:[0,0]},{type:'end',cell:[0,0]}]);
});
test('solution fixture uses terminal keys and canonical completion renders', () => {
    const t = new TerminalAdapter(); for (const l of require('../test/quire-solution.json').solution) {
        for (const target of [l.coords[0], l.coords.at(-1)]) if (target.join() !== l.origin.join()) drag(t, l.origin, target);
    }
    assert(t.state().cleared); assert(t.state().lines.every(l => l.completed)); assert(t.render().join('\n').includes('PUZZLE COMPLETE!'));
    assert.deepEqual(OrdinaryPuzzlesSession.restore(t.snapshot()).state(), t.state());
});
test('tier menu loads Medium, supports navigation and actions', () => {
    const t = new TerminalAdapter(); t.key('n'); t.key('2'); t.key('return');
    assert.equal(t.state().puzzleId, '36aab10b5bd4'); go(t,9,6); t.key('space'); t.key('space');
    const frame = t.render(); assert.equal(frame.length,24); assert(frame.every(l => l.length === 80)); assert.equal(t.offset[0],1);
    assert(t.snapshot().actions.length >= 2);
});
test('stable ID, next, previous and random catalog browsing', () => {
    const t = new TerminalAdapter(); t.key('n'); t.key('right'); assert.equal(t.choice,1); t.key('left'); assert.equal(t.choice,0);
    t.key('x'); assert(t.choice >= 0 && t.choice < 300); t.key('i'); for (const c of '36aab10b5bd4') t.key(c); t.key('return'); assert.equal(t.state().tier,'medium');
});
test('larger real board pans and stays bounded; narrow viewport pans horizontally', () => {
    const p = catalog({tier:'large'})[0], t = new TerminalAdapter({puzzleId:p.puzzleId}); go(t,p.rows-1,p.columns-1);
    assert(t.render().every(l => l.length<=80)); assert(t.offset[0]>0); assert.equal(t.offset[1],0);
    assert(t.render(30,18).every(l=>l.length<=30)); assert(t.offset[1]>0); assert(t.render(30,18).length<=18);
});
test('unfinished-drag JSON restores into fresh adapter, then continues identically', () => {
    const a = new TerminalAdapter(); drag(a,[0,1],[0,3]); go(a,0,4); a.key('space');
    const snapshot = JSON.parse(JSON.stringify(a.snapshot())); a.key('q');
    const b = new TerminalAdapter({snapshot}); assert.deepEqual(b.state(),a.state()); assert(b.state().interaction.dragging);
    b.key('right'); b.key('space'); const core = OrdinaryPuzzlesSession.restore(snapshot); core.leave(0,4); core.enter(0,5); core.end(0,5);
    assert.deepEqual(b.state(),core.state());
});
test('menu cancellation and help preserve canonical state', () => {
    const t = new TerminalAdapter(), before = t.snapshot(); t.key('?'); assert(t.render().join('\n').includes('Cover every dot')); t.key('?'); t.key('n'); t.key('escape'); assert.deepEqual(t.snapshot(),before);
});
test('quit freezes adapter without discarding unfinished canonical state', () => {
    const t = new TerminalAdapter(); go(t,0,1); t.key('space'); const before = t.snapshot(); t.key('q'); t.key('right'); assert(t.closed); assert.deepEqual(t.snapshot(),before);
});
test('Esc sends canonical exit and ends active interaction', () => {
    const t = new TerminalAdapter(); go(t,0,1); t.key('space'); t.key('right'); t.key('escape'); assert(!t.state().interaction.dragging); assert.equal(t.snapshot().actions.at(-1).type,'exit');
});
