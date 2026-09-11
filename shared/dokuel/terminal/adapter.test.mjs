import test from 'node:test';
import assert from 'node:assert/strict';
import { TerminalAdapter, coordinate, move, wrap, ascii, elapsed } from './adapter.mjs';
import { DokuelSession as Session } from '../dist/session.js';
const solution = '534678912672195348198342567859761423426853791713924856961537284287419635345286179';
const fresh = () => new TerminalAdapter(Session.fromPuzzle('..' + solution.slice(2), 'easy'));
const text = a => a.render().join('\n');

test('all coordinate mappings and clamped navigation', () => {
    for (let r = 0; r < 9; r++) for (let c = 0; c < 9; c++) assert.equal(coordinate(r,c), 'ABCDEFGHI'[r] + (c+1));
    assert.deepEqual(move({row:0,col:0},'up'),{row:0,col:0});
    assert.deepEqual(move({row:8,col:8},'right'),{row:8,col:8});
    const a=fresh();a.key('d');a.key('s');assert.deepEqual(a.session.view().state.selectedCell,{row:1,col:1});
    a.key('left');a.key('up');assert.deepEqual(a.session.view().state.selectedCell,{row:0,col:0});
});
for (const [key,mode] of Object.entries({'1':'easy','2':'medium','3':'hard','4':'expert','5':'daily'})) {
    test(`menu ${mode} delegates generation and canonical metadata`, () => {
        const a=new TerminalAdapter();a.key(key);const s=a.session.snapshot();
        assert.equal(s.difficulty,mode==='daily'?'medium':mode);
        assert.equal(s.identity.kind,mode==='daily'?'daily':'solo');
        const same=mode==='daily'?Session.startDaily(s.identity.date):Session.startSolo(mode,s.identity.key);
        assert.equal(same.snapshot().puzzle,s.puzzle);
        for(const line of a.render())assert.ok(line.length<=79);
    });
}
test('value entry, all erase keys and undo use shared actions',()=>{
    for(const erase of ['0','backspace','delete']){
        const a=fresh();a.key('5');assert.equal(a.session.view().grid.values[0],'5');
        a.key(erase);assert.equal(a.session.view().grid.values[0],'.');a.key('u');assert.equal(a.session.view().grid.values[0],'5');
    }
});
test('notes mode toggles safely and peer-note elimination/undo are canonical',()=>{
    const a=fresh();a.key('d');a.key('n');a.key('5');assert.deepEqual(a.session.view().grid.notes[1],[5]);
    assert.match(text(a),/Notes: 5/);assert.match(text(a),/NOTES/);assert.match(text(a),/\[:\]/);
    a.key('5');assert.deepEqual(a.session.view().grid.notes[1],[]);a.key('5');a.key('n');a.key('a');a.key('5');
    assert.deepEqual(a.session.view().grid.notes[1],[]);a.key('u');assert.deepEqual(a.session.view().grid.notes[1],[5]);
    a.key('e');a.key('q');assert.equal(a.quit,false); // menu-only commands never collide
});
test('new puzzle confirmation and cancellation preserve existing game',()=>{
    const a=fresh();a.key('5');const puzzle=a.session.snapshot().puzzle;a.key('escape');a.key('2');a.key('n');
    assert.equal(a.session.snapshot().puzzle,puzzle);a.key('2');a.key('y');assert.equal(a.session.snapshot().difficulty,'medium');
});
test('monochrome conflicts, entered/given distinction and selected status',()=>{
    const a=fresh();a.key('3');assert.match(text(a),/CONFLICT/);assert.match(text(a),/!3!/);
    a.key('0');a.key('5');a.key('d');assert.match(text(a),/5\+/);assert.match(text(a),/5 given/);
});
test('canonical logical and mistake hints; reopening does not consume another',()=>{
    const a=fresh();a.key('h');assert.match(text(a),/Naked Single/);const h=a.session.view().state.activeHint;
    assert.ok(text(a).replace(/\s+/g,' ').includes(ascii(h.explanation)));a.key('enter');a.key('h');assert.equal(a.session.view().state.hintsUsed,1);
    a.key('x');assert.equal(a.session.view().state.activeHint,null);
    a.key('3');a.key('h');assert.match(text(a),/Mistake/);
});
test('canonical Reveal explanation remains explicitly labeled',()=>{
    const s=Session.fromPuzzle('1....7.9..3..2...8..96..5....53..9...1..8...26....4...3......1..4......7..7...3..','expert');
    for(let i=0;i<81;i++){s.requestHint();const h=s.view().state.activeHint;if(h.technique==='reveal')break;s.enterDigit(h.value);}
    const a=new TerminalAdapter(s);a.key('h');assert.match(text(a),/Reveal/);assert.equal(a.session.view().state.activeHint.technique,'reveal');
});
test('help/hint wrapping preserves content with bounded pagination',()=>{
    const input='A very long explanation '.repeat(120);const lines=wrap(input);assert.ok(lines.every(l=>l.length<=74));
    assert.equal(lines.join(' ').trim(),input.trim());assert.ok(wrap('x'.repeat(200)).every(l=>l.length<=74));
    const a=fresh();a.key('?');const first=text(a);a.key('right');const second=text(a);assert.notEqual(first,second);
    assert.match(second,/Page 2\//);a.key('left');assert.equal(text(a),first);
    for(let i=0;i<100;i++)a.key('right');assert.ok(a.render().length<=23);
});
test('pause hides values/notes, blocks actions, freezes timer; resize requires resume',()=>{
    const a=fresh();a.key('5');a.advanceTime(65000);a.key('p');assert.match(text(a),/01:05/);assert.match(text(a),/Puzzle hidden/);
    assert.match(text(a),/\? \? \?/);const before=a.export();a.key('3');a.advanceTime(5000);assert.equal(a.export(),before);
    a.key('p');a.advanceTime(1000);assert.match(text(a),/01:06/);a.resize(60,18);assert.equal(a.session.snapshot().paused,true);
    for(const l of a.render(60,18))assert.ok(l.length<60);a.resize(80,24);assert.match(text(a),/PAUSED/);
    assert.equal(elapsed(3600000),'60:00');
});
test('snapshot export/restore preserves canonical state, selection, notes, hints and history',()=>{
    let exported;const a=fresh();a.exportSnapshot=s=>{exported=s;};a.key('d');a.key('n');a.key('5');a.key('n');a.key('h');a.key('escape');a.key('e');
    const b=new TerminalAdapter();b.restore(exported);assert.deepEqual(b.session.view(),a.session.view());
    b.key('p');b.key('u');a.key('r');a.key('p');a.key('u');assert.deepEqual(b.session.view(),a.session.view());
});
test('completion is canonical, visible, restored, and timer cannot advance',()=>{
    const a=fresh();a.key('5');a.key('d');a.key('3');assert.match(text(a),/PUZZLE COMPLETE!/);
    const before=a.export();a.advanceTime(1000);assert.equal(a.export(),before);
    const b=new TerminalAdapter();b.restore(before);assert.equal(text(b),text(a));
});
test('all views fit 80x24 without controls or non-ASCII display bytes',()=>{
    const a=fresh();for(const key of ['','h','escape','1','n','r','p','?','right']){
        if(key)a.key(key);const lines=a.render();assert.ok(lines.length<=23);assert.ok(lines.every(l=>l.length<=79&&/^[\x20-\x7e]*$/.test(l)));
    }
    a.key('ctrl-c');assert.equal(a.quit,true);
});
