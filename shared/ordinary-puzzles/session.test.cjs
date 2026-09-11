const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { createHash } = require('node:crypto');
const { OrdinaryPuzzlesSession: Session, catalog, upstreamRevision, packId } = require('./session.cjs');
const { RootStore } = require('./runtime/board.cjs');
const { getPackRecords } = require('./runtime/pack.cjs');
const fixture = require('./test/quire-solution.json');
const id = 'e9c2882a25e2';
const make = () => new Session(id);
function drag(s, from, to) { s.begin(...from); s.leave(...from); s.enter(...to); s.end(...to); }
const line = (s, origin = '0:1') => s.state().lines.find(l => l.id === origin);
function solve(s) {
    for (const { origin, coords } of fixture.solution) {
        for (const endpoint of [coords[0], coords.at(-1)]) {
            if (String(endpoint) !== String(origin)) drag(s, origin, endpoint);
        }
    }
}

test('pinned revision, license and every canonical source hash match provenance', () => {
    assert.equal(upstreamRevision, 'ee4c9d169ec65a2aad34a3856529a542ff583508');
    const provenance = require('./upstream.json');
    for (const file of provenance.files) {
        const bytes = fs.readFileSync(path.join(__dirname, file.localPath));
        assert.equal(createHash('sha256').update(bytes).digest('hex'), file.sha256, file.localPath);
    }
    assert.match(fs.readFileSync(path.join(__dirname, 'LICENSE.upstream'), 'utf8'), /Permission is hereby granted/);
    assert(!fs.readFileSync(path.join(__dirname, 'runtime/board.cjs'), 'utf8').includes('require("react-native")'));
});
test('whole committed catalog: 1200 unique IDs, 300 in each canonical tier', () => {
    const all = catalog({ includeRetired: true });
    assert.equal(all.length, 1200); assert.equal(new Set(all.map(p => p.puzzleId)).size, 1200);
    for (const tier of ['small','medium','large','extraordinary']) assert.equal(catalog({ tier }).length, 300);
    assert.match(packId, /^sha256:/); assert.throws(() => catalog({ tier: 'invented' }), RangeError);
});
test('stable proof ID resolves to quire / Small, 9 rows x 6 columns, seven origins', () => {
    const s = make().state();
    assert.equal(s.puzzleId, id); assert.equal(s.name, 'quire'); assert.equal(s.type, 'puzzle');
    assert.equal(s.tier, 'small'); assert.equal(s.rows, 9); assert.equal(s.columns, 6);
    assert.equal(s.lines.length, 7); assert.equal(s.cleared, false);
    assert.equal(s.cells[0][1].value, '4'); assert.deepEqual(s.lines[0].cells, ['0:1']);
});
test('valid B1 -> D1 uses canonical line placement', () => {
    const s = make(); drag(s, [0,1], [0,3]);
    assert.deepEqual(line(s).cells, ['0:1','0:2','0:3']);
    assert.equal(s.state().cells[0][3].lineId, '0:1');
});
test('B1 -> F1 reaches canonical clipping at occupied E1', () => {
    const s = make(); drag(s, [0,1], [0,3]); const origin = s.state().cells[0][4];
    drag(s, [0,1], [0,5]);
    assert.deepEqual(line(s).cells, ['0:1','0:2','0:3']);
    assert.deepEqual(s.state().cells[0][4], origin); assert.equal(s.state().cells[0][5].lineId, null);
    assert.deepEqual(s.export().actions.at(-2), { type: 'enter', cell: [0,5] });
});
test('tap B1 resets canonically and releases C1/D1', () => {
    const s = make(); drag(s, [0,1], [0,3]); s.tap(0,1);
    assert.deepEqual(line(s).cells, ['0:1']);
    assert.equal(s.state().cells[0][2].filled, false); assert.equal(s.state().cells[0][3].lineId, null);
});
test('input translation invokes canonical handlers, including an empty-cell intent', () => {
    const probe = new RootStore(), proto = Object.getPrototypeOf(probe.interactions), calls = [];
    const names = ['onCellTouch','onCellEnter','onCellLeave','onCellTouchEnd','onGridTouchExit'];
    const originals = Object.fromEntries(names.map(n => [n, proto[n]]));
    try {
        for (const name of names) proto[name] = function(...args) { calls.push([name,args[0]?.id]); return originals[name].apply(this,args); };
        const s = make(); s.begin(2,0); s.begin(0,1); s.leave(0,1); s.enter(0,5); s.end(0,5); s.exit();
        assert.deepEqual(calls, [['onCellTouch','2:0'],['onCellTouch','0:1'],['onCellLeave','0:1'],['onCellEnter','0:5'],['onCellTouchEnd','0:5'],['onGridTouchExit',undefined]]);
    } finally { for (const name of names) proto[name] = originals[name]; }
});
test('neutral state directly reflects canonical board/cell/line getters', () => {
    const s = make(), core = new RootStore();
    core.board.initialize(id, getPackRecords('small').find(p => p.id === id).rows);
    s.begin(0,1); s.enter(0,3);
    core.interactions.onCellTouch(core.board.at(0,1)); core.interactions.onCellEnter(core.board.at(0,3));
    const state = s.state();
    assert.equal(state.cleared, core.board.cleared);
    assert.deepEqual(state.lines.map(l => l.cells), core.board.lines.map(l => l.cells.map(c => c.id)));
    assert.deepEqual(state.cells.map(row => row.map(c => [c.lineId,c.orientation,c.valid,c.completed])),
        core.board.grid.map(row => row.map(c => [c.line?.id ?? null,c.orientation,c.valid,c.completed])));
});
test('known canonical solution completes all seven lines and triggers canonical interaction cleanup', () => {
    const s = make(); solve(s); const state = s.state();
    assert.equal(state.cleared, true); assert(state.lines.every(l => l.completed));
    assert.equal(state.interaction.enabled, false); assert.equal(state.interaction.dragging, false);
});
test('export is detached JSON, with action journal and replay verification', () => {
    const s = make(); s.begin(0,1); s.enter(0,3);
    const snap = s.export(); assert.deepEqual(JSON.parse(JSON.stringify(snap)), snap);
    assert.equal(snap.schemaVersion,1); assert.equal(snap.puzzleId,id); assert.equal(snap.actions.length,2);
    snap.actions[0].cell[0]=8; snap.verification.lines[0].cells.length=0;
    assert.equal(s.export().actions[0].cell[0],0); assert.equal(line(s).cells.length,3);
});
test('restore replays an unfinished drag and both instances can continue identically', () => {
    const a = make(); a.begin(0,1); a.leave(0,1); a.enter(0,3);
    const b = Session.restore(JSON.parse(JSON.stringify(a.export())));
    assert.deepEqual(b.state(),a.state()); assert.equal(b.state().interaction.dragging,true);
    for (const s of [a,b]) { s.leave(0,3); s.enter(0,5); s.end(0,5); s.tap(0,1); }
    assert.deepEqual(b.export(),a.export());
});
test('solved state restores including pending/committed and disabled-interaction quirks', () => {
    const a = make(); solve(a); const b = Session.restore(a.export());
    assert.deepEqual(b.state(),a.state()); assert.equal(b.state().cleared,true);
});
test('restore accepts reordered object keys but rejects tampered results and incompatible revisions', () => {
    const a = make(); drag(a,[0,1],[0,3]);
    const reorder = v => Array.isArray(v) ? v.map(reorder) : v && typeof v === 'object'
        ? Object.fromEntries(Object.keys(v).reverse().map(k=>[k,reorder(v[k])])) : v;
    assert.deepEqual(Session.restore(reorder(a.export())).state(),a.state());
    const bad = a.export(); bad.verification.cleared=true; assert.throws(()=>Session.restore(bad),/verification/);
    assert.throws(()=>Session.restore({...a.export(),upstreamRevision:'other'}),/Unsupported/);
    assert.throws(()=>Session.restore({...a.export(),actions:[{type:'mutate',cell:[0,0]}]}),/Invalid action/);
});
test('loading another tier resets previous actions, cells and drag state', () => {
    const s = make(); s.begin(0,1); s.enter(0,3);
    const other = catalog({tier:'medium'})[0], record = getPackRecords('medium')[0];
    s.load(other.puzzleId); const state = s.state();
    assert.notEqual(state.puzzleId,id); assert.equal(state.rows,record.rows.length); assert.equal(state.columns,record.rows[0].length);
    assert.equal(state.tier,'medium'); assert.equal(state.interaction.dragging,false); assert.deepEqual(s.export().actions,[]);
    assert.deepEqual(state.cells.map(row=>row.map(c=>c.value).join('')),record.rows);
    assert.deepEqual(Session.restore(s.export()).state(),state);
});
test('coordinate and ID validation do not mutate a live session', () => {
    const s=make(), before=s.export();
    for (const args of [[-1,0],[0,6],[NaN,0],[0.5,0]]) assert.throws(()=>s.begin(...args),RangeError);
    assert.throws(()=>s.load('missing'),RangeError); assert.deepEqual(s.export(),before);
});
