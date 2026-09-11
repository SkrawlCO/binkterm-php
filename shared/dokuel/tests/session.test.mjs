import test from 'node:test';
import assert from 'node:assert/strict';
import { DokuelSession as Session, REVISION } from '../dist/session.js';
import { generatePuzzleWithSolution, solvePuzzle, getConflicts, isBoardComplete } from '../dist/upstream/src/lib/sudoku.js';
import { countSolutions } from '../dist/upstream/src/lib/solver.js';
import { gradePuzzle } from '../dist/upstream/src/lib/grader.js';
import { getDailyPuzzle, hashCode, seededRandom } from '../dist/upstream/src/lib/daily.js';
import { initState, reducer, projectBoard } from '../dist/upstream/src/lib/board-engine.js';
import { findHint } from '../dist/upstream/src/lib/hint-engine.js';
const solved = '534678912672195348198342567859761423426853791713924856961537284287419635345286179';
const puzzle = '..'+solved.slice(2);
const fresh = () => Session.fromPuzzle(puzzle, 'easy');
function roundtrip(s) {
    const restored = Session.restore(s.export());
    assert.deepEqual(restored.view(), s.view());
    assert.deepEqual(restored.snapshot(), s.snapshot());
    return restored;
}

for (const level of ['easy', 'medium', 'hard', 'expert']) {
    test(`${level}: seeded canonical generation, uniqueness, metadata and fresh restore`, () => {
        const s = Session.startSolo(level, 'slice-one');
        const v = s.view();
        const canonical = generatePuzzleWithSolution(level, seededRandom(hashCode('sudoku-solo-slice-one')));
        assert.equal(v.puzzle, canonical.puzzle);
        assert.equal(v.state.solution, canonical.solution);
        assert.equal(countSolutions(v.puzzle), 1);
        assert.equal(getConflicts(v.state.board).size, 0);
        assert.equal(isBoardComplete(initState({puzzle: canonical.solution}).board), true);
        assert.equal(v.difficulty, level);
        assert.deepEqual(v.grade, gradePuzzle(v.puzzle));
        // Report canonical output honestly; bounded generation may fall back outside the target band.
        console.log(JSON.stringify({level, clues: v.puzzle.replaceAll('.', '').length, grade: v.grade}));
        roundtrip(s);
    });
}

test('digit, erase, notes and undo preserve canonical actions and givens', () => {
    const s = fresh();
    s.selectCell(0,0); s.toggleNotes(); s.enterDigit(5);
    assert.deepEqual([...s.view().state.board[0][0].notes], [5]);
    s.enterDigit(5); assert.equal(s.view().state.board[0][0].notes.size, 0);
    s.undo(); s.toggleNotes(); s.enterDigit(5);
    assert.equal(s.view().grid.values[0], '5');
    s.erase(); assert.equal(s.view().grid.values[0], '.');
    s.undo(); assert.equal(s.view().grid.values[0], '5');
    s.undo(); assert.deepEqual(s.view().grid.notes[0], [5]);
    s.selectCell(0,2); s.erase(); s.enterDigit(9);
    assert.equal(s.view().grid.values[2], solved[2]);
    roundtrip(s);
});

test('conflicts and wrong-entry hint are canonical; hints never place digits', () => {
    const s = fresh(); s.selectCell(0,0); s.enterDigit(3);
    assert.ok(s.view().projection.conflicts.has(0));
    assert.ok(s.view().projection.errors.has(0));
    const before = s.view().grid;
    s.requestHint();
    assert.equal(s.view().state.activeHint.technique, 'mistake');
    assert.deepEqual(s.view().grid, before);
    const r = roundtrip(s); r.erase();
    assert.equal(r.view().projection.conflicts.size, 0);
});

test('logical hint, active hint restore, dismissal and canonical continuation', () => {
    const s = fresh(); s.selectCell(0,0); s.requestHint();
    const v = s.view();
    assert.equal(v.state.activeHint.technique, 'naked-single');
    assert.equal(v.state.hintsUsed, 1);
    assert.deepEqual(v.state.activeHint, findHint(v.state.board, v.state.solution, {row:0,col:0}));
    const r = roundtrip(s); r.dismissHint(); s.dismissHint();
    assert.equal(r.view().state.activeHint, null);
    r.enterDigit(5); s.enterDigit(5);
    assert.deepEqual(r.view(), s.view());
});

test('explicit Reveal uses the canonical fallback on a unique hard fixture', () => {
    const p = '1....7.9..3..2...8..96..5....53..9...1..8...26....4...3......1..4......7..7...3..';
    const s = Session.fromPuzzle(p, 'expert');
    let found = false;
    for (let i=0;i<81;i++) {
        s.requestHint(); const h = s.view().state.activeHint;
        assert.ok(h);
        assert.equal(h.value, Number(solvePuzzle(p)[h.position.row*9+h.position.col]));
        if (h.technique === 'reveal') { found = true; roundtrip(s); break; }
        s.enterDigit(h.value);
    }
    assert.ok(found);
});

test('daily uses canonical local seeded identity and restores original givens', () => {
    const s = Session.startDaily('2026-09-11', 'medium');
    const d = getDailyPuzzle('2026-09-11', 'medium');
    assert.equal(s.view().puzzle, d.puzzle);
    assert.deepEqual(s.view().identity, {kind:'daily', date:d.date});
    s.requestHint(); roundtrip(s);
    // Restore deliberately does not regenerate from the descriptive identity.
    const saved = s.snapshot(); saved.identity.date = '2026-09-12';
    assert.equal(Session.restore(saved).view().puzzle, d.puzzle);
});

test('notes and peer-note elimination restore with working undo', () => {
    const s = fresh(); s.toggleNoteAt(0,1,5); s.toggleNoteAt(0,0,5);
    s.selectCell(0,0); s.enterDigit(5);
    assert.deepEqual(s.view().grid.notes[1], []);
    const r = roundtrip(s); r.undo(); s.undo();
    assert.deepEqual(r.view(), s.view());
    assert.deepEqual(r.view().grid.notes.slice(0,2), [[5],[5]]);
});

test('all batch history shapes and selection survive replay and undo', () => {
    const s = fresh();
    s.dispatch({type:'SET_SELECTED_CELLS', cells:[0,1], primary:{row:0,col:0}});
    s.enterDigit(5, true, true);
    const noted = roundtrip(s);
    noted.erase();
    const erased = roundtrip(noted); erased.undo();
    noted.undo();
    assert.deepEqual(erased.view(), noted.view());
    assert.deepEqual(erased.view().grid.notes.slice(0,2), [[5],[5]]);
    erased.undo(); assert.deepEqual(erased.view().grid.notes.slice(0,2), [[],[]]);
});

test('direct reducer parity after every action and after restore', () => {
    const s = fresh(); let state = initState({puzzle, solution:solved});
    const actions = [
        {type:'SELECT_CELL',row:0,col:0}, {type:'PLACE_NOTE_AT',row:0,col:1,value:5},
        {type:'PLACE_NUMBER',value:5,autoEliminateNotes:true}, {type:'UNDO'},
        {type:'TOGGLE_NOTES'}, {type:'PLACE_NUMBER',value:3,autoEliminateNotes:false},
        {type:'HINT'}, {type:'DISMISS_HINT'}, {type:'DESELECT_CELL'},
    ];
    for (const a of actions) {
        s.dispatch(a); state = reducer(state,a);
        assert.deepEqual(s.view().state,state);
        assert.deepEqual(s.view().projection,projectBoard(state.board,solved));
        roundtrip(s);
    }
});

test('upstream 100-move undo cap is preserved after longer journal restore', () => {
    const s = fresh();
    for(let i=0;i<105;i++) s.toggleNoteAt(0,0,5);
    assert.equal(s.view().state.history.length,100);
    const r = roundtrip(s);
    for(let i=0;i<101;i++) {s.undo();r.undo();}
    assert.deepEqual(r.view(),s.view());
    assert.deepEqual(r.view().grid.notes[0],[5]);
});

test('partial and completed restores preserve status and canonical completed guards', () => {
    const s = fresh(); s.selectCell(0,0);s.enterDigit(5);roundtrip(s);
    s.selectCell(0,1);s.enterDigit(3);
    assert.equal(s.view().state.status,'completed');
    assert.equal(s.view().grid.values,solved);
    const r = roundtrip(s);r.undo();r.erase();r.enterDigit(9);
    assert.equal(r.view().state.status,'completed');
    assert.equal(r.view().grid.values,solved);
});

test('active time, pause, assistance and completion time freeze survive restore', () => {
    const s = fresh();s.advanceTime(1234);s.pause();
    s.advanceTime(999);s.selectCell(0,0);s.enterDigit(5);
    assert.equal(s.view().state.selectedCell,null);
    s.setAssistLevel('full');const r = roundtrip(s);
    assert.equal(r.view().elapsedMs,1234);r.resume();r.advanceTime(66);
    r.selectCell(0,0);r.enterDigit(5);r.selectCell(0,1);r.enterDigit(3);r.advanceTime(999);
    assert.equal(r.view().elapsedMs,1300);assert.equal(r.view().assistLevel,'full');roundtrip(r);
});

test('views, snapshots and action inputs are detached from live state', () => {
    const s = fresh();const a={type:'SET_SELECTED_CELLS',cells:[0,1],primary:{row:0,col:0}};
    s.dispatch(a);a.cells.length=0;a.primary.row=8;
    const v=s.view();v.state.board[0][0].value=9;v.state.selectedCells.clear();
    const snap=s.snapshot();snap.actions.length=0;snap.identity.kind='daily';
    assert.equal(s.view().grid.values[0],'.');assert.deepEqual([...s.view().state.selectedCells],[0,1]);roundtrip(s);
});

test('invalid snapshots and unsafe action payloads are rejected without changing live state', () => {
    const s=fresh();const before=s.snapshot();
    for(const a of [{type:'SELECT_CELL',row:9,col:0},{type:'PLACE_NUMBER',value:0,autoEliminateNotes:true},
        {type:'PLACE_NOTE_AT',row:0,col:0,value:NaN},{type:'RESET',puzzle},
        {type:'SET_SELECTED_CELLS',cells:[0],primary:{row:0,col:1}}]) assert.throws(()=>s.dispatch(a));
    for(const patch of [{schema:2},{revision:'future'},{puzzle:'.'.repeat(81)},{puzzle:'55'+'.'.repeat(79)},
        {actions:[{type:'PLACE_NUMBER',value:10,autoEliminateNotes:true}]},{elapsedMs:-1},{paused:'yes'},
        {difficulty:'impossible'},{identity:{kind:'daily',date:'2026-02-30'}}]) assert.throws(()=>Session.restore({...before,...patch}));
    assert.throws(()=>s.advanceTime(-1));assert.throws(()=>Session.restore('{'));
    assert.deepEqual(s.snapshot(),before);assert.equal(before.revision,REVISION);
});
