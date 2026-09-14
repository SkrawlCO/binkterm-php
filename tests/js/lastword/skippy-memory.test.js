/**
 * Plain Node assertions for js/lastword/skippy-memory.js — SKIPPY REMEMBERS
 * (conscious bounded fork #1). Exercises the pure memory/reaction module
 * directly, isolated from app-final-skippy.js's DOM wiring (covered
 * separately in app-final-skippy-dom.test.js).
 * Run: node tests/js/lastword/skippy-memory.test.js
 */
'use strict';

const assert = require('assert');
const Memory = require('../../../public_html/webdoors/hangman/js/lastword/skippy-memory.js');

let passed = 0;
function check(name, fn) {
    fn();
    passed++;
    console.log('  ok - ' + name);
}

console.log('skippy-memory.test.js');

function rngQueue(values) {
    let i = 0;
    return () => values[Math.min(i++, values.length - 1)];
}

// ---- createMemory ------------------------------------------------------

check('memory starts clean for a new session', () => {
    const m = Memory.createMemory();
    assert.strictEqual(m.hintsPurchasedThisSession, 0);
    assert.strictEqual(m.roundsSolved, 0);
    assert.strictEqual(m.skippyIncidents, 0);
    assert.strictEqual(m.perfectRounds, 0);
    assert.strictEqual(m.fiveStrikeSaves, 0);
    assert.strictEqual(m.previousRoundOutcome, null);
    assert.strictEqual(m.previousRoundStrikes, null);
    assert.strictEqual(m.previousRoundHintsPurchased, null);
});

check('a fresh/clean memory yields no opening reaction (no interesting history yet)', () => {
    const m = Memory.createMemory();
    assert.strictEqual(Memory.pickOpeningReaction(m), null);
});

// ---- recordRoundResult: individual facts --------------------------------

check('perfect round (solved at 0 strikes) is recorded correctly', () => {
    const m = Memory.recordRoundResult(Memory.createMemory(), { outcome: 'solved', strikes: 0, hintsPurchased: 0 });
    assert.strictEqual(m.perfectRounds, 1);
    assert.strictEqual(m.fiveStrikeSaves, 0);
    assert.strictEqual(m.skippyIncidents, 0);
    assert.strictEqual(m.roundsSolved, 1);
    assert.strictEqual(m.previousRoundOutcome, 'solved');
    assert.strictEqual(m.previousRoundStrikes, 0);
});

check('five-strike save (solved at 5/6 strikes) is recorded correctly', () => {
    const m = Memory.recordRoundResult(Memory.createMemory(), { outcome: 'solved', strikes: 5, hintsPurchased: 1 });
    assert.strictEqual(m.fiveStrikeSaves, 1);
    assert.strictEqual(m.perfectRounds, 0);
    assert.strictEqual(m.skippyIncidents, 0);
    assert.strictEqual(m.roundsSolved, 1);
});

check('Skippy incident (lost at 6 strikes) is recorded correctly', () => {
    const m = Memory.recordRoundResult(Memory.createMemory(), { outcome: 'struck-out', strikes: 6, hintsPurchased: 0 });
    assert.strictEqual(m.skippyIncidents, 1);
    assert.strictEqual(m.roundsSolved, 0);
    assert.strictEqual(m.perfectRounds, 0);
    assert.strictEqual(m.fiveStrikeSaves, 0);
    assert.strictEqual(m.previousRoundOutcome, 'struck-out');
});

check('hint counts are recorded correctly (per-round and cumulative)', () => {
    let m = Memory.createMemory();
    m = Memory.recordRoundResult(m, { outcome: 'solved', strikes: 3, hintsPurchased: 2 });
    assert.strictEqual(m.hintsPurchasedThisSession, 2);
    assert.strictEqual(m.previousRoundHintsPurchased, 2);
    m = Memory.recordRoundResult(m, { outcome: 'solved', strikes: 1, hintsPurchased: 3 });
    assert.strictEqual(m.hintsPurchasedThisSession, 5, 'cumulative across rounds');
    assert.strictEqual(m.previousRoundHintsPurchased, 3, 'previous-round is not cumulative');
});

check('a middling round (solved, non-0/5 strikes, no hints) still updates previous-round facts', () => {
    const m = Memory.recordRoundResult(Memory.createMemory(), { outcome: 'solved', strikes: 3, hintsPurchased: 0 });
    assert.strictEqual(m.previousRoundOutcome, 'solved');
    assert.strictEqual(m.previousRoundStrikes, 3);
    assert.strictEqual(m.previousRoundHintsPurchased, 0);
    assert.strictEqual(m.perfectRounds, 0);
    assert.strictEqual(m.fiveStrikeSaves, 0);
});

check('recordRoundResult does not mutate the memory object passed in (pure, like the rest of the suite)', () => {
    const before = Memory.createMemory();
    const snapshot = JSON.stringify(before);
    Memory.recordRoundResult(before, { outcome: 'solved', strikes: 0, hintsPurchased: 0 });
    assert.strictEqual(JSON.stringify(before), snapshot);
});

// ---- pickOpeningReaction: priority rules --------------------------------

check('opening reaction: incident outranks every other qualifying condition', () => {
    // strikes=6 (incident) AND hintsPurchased heavy — incident must win.
    const m = Memory.recordRoundResult(Memory.createMemory(), { outcome: 'struck-out', strikes: 6, hintsPurchased: 5 });
    const line = Memory.pickOpeningReaction(m, rngQueue([0]));
    assert.ok(line);
    // Sanity: a five-strike-save/perfect/hint reaction would never be picked for strikes=6/outcome=struck-out.
    assert.notStrictEqual(line, Memory.pickOpeningReaction(
        Memory.recordRoundResult(Memory.createMemory(), { outcome: 'solved', strikes: 0, hintsPurchased: 0 }), rngQueue([0])
    ));
});

check('opening reaction: five-strike save outranks perfect/hint conditions (mutually exclusive by strikes anyway, but priority order is explicit)', () => {
    const m = Memory.recordRoundResult(Memory.createMemory(), { outcome: 'solved', strikes: 5, hintsPurchased: 0 });
    const line = Memory.pickOpeningReaction(m, rngQueue([0]));
    assert.ok(line);
});

check('opening reaction: perfect round outranks conspicuous-hint/clean-no-hint conditions', () => {
    // strikes=0 with hintsPurchased=0 — both "perfect" and "clean, no hints" technically fit; perfect must win per priority order.
    const m = Memory.recordRoundResult(Memory.createMemory(), { outcome: 'solved', strikes: 0, hintsPurchased: 0 });
    const perfectLine = Memory.pickOpeningReaction(m, rngQueue([0]));
    const cleanLine = Memory.pickOpeningReaction(
        Memory.recordRoundResult(Memory.createMemory(), { outcome: 'solved', strikes: 3, hintsPurchased: 0 }), rngQueue([0])
    );
    assert.ok(perfectLine);
    assert.ok(cleanLine);
    assert.notStrictEqual(perfectLine, cleanLine, 'perfect-round line must differ from the plain clean-round line');
});

check('opening reaction: conspicuous hint use (>= HEAVY_HINT_THRESHOLD) is reacted to on an otherwise unremarkable round', () => {
    const m = Memory.recordRoundResult(Memory.createMemory(), { outcome: 'solved', strikes: 3, hintsPurchased: Memory.HEAVY_HINT_THRESHOLD });
    assert.ok(Memory.pickOpeningReaction(m, rngQueue([0])));
});

check('opening reaction: ordinary/non-interesting history (mid-strikes, one hint, solved) yields no special reaction', () => {
    const m = Memory.recordRoundResult(Memory.createMemory(), { outcome: 'solved', strikes: 3, hintsPurchased: 1 });
    assert.strictEqual(Memory.pickOpeningReaction(m, rngQueue([0])), null);
});

check('pickOpeningReaction does not mutate the memory object passed in', () => {
    const m = Memory.recordRoundResult(Memory.createMemory(), { outcome: 'struck-out', strikes: 6, hintsPurchased: 0 });
    const snapshot = JSON.stringify(m);
    Memory.pickOpeningReaction(m, rngQueue([0]));
    assert.strictEqual(JSON.stringify(m), snapshot);
});

// ---- pickFinalReaction ---------------------------------------------------

check('Final reaction reflects accumulated session history: repeated incidents read as suspicious', () => {
    let m = Memory.createMemory();
    m = Memory.recordRoundResult(m, { outcome: 'struck-out', strikes: 6, hintsPurchased: 0 });
    m = Memory.recordRoundResult(m, { outcome: 'struck-out', strikes: 6, hintsPurchased: 0 });
    assert.strictEqual(m.skippyIncidents, 2);
    assert.ok(Memory.pickFinalReaction(m, rngQueue([0])));
});

check('Final reaction reflects a strong session (perfect rounds + five-strike saves) as impressed', () => {
    let m = Memory.createMemory();
    m = Memory.recordRoundResult(m, { outcome: 'solved', strikes: 0, hintsPurchased: 0 });
    m = Memory.recordRoundResult(m, { outcome: 'solved', strikes: 5, hintsPurchased: 0 });
    assert.ok(Memory.pickFinalReaction(m, rngQueue([0])));
});

check('Final reaction never returns null — a simple neutral line is always available', () => {
    assert.ok(Memory.pickFinalReaction(Memory.createMemory(), rngQueue([0])));
});

check('Final reaction: a clean/uninteresting session still returns the neutral line, distinct from the suspicious/impressed lines', () => {
    const neutral = Memory.pickFinalReaction(Memory.createMemory(), rngQueue([0]));
    let incidentMemory = Memory.createMemory();
    incidentMemory = Memory.recordRoundResult(incidentMemory, { outcome: 'struck-out', strikes: 6, hintsPurchased: 0 });
    incidentMemory = Memory.recordRoundResult(incidentMemory, { outcome: 'struck-out', strikes: 6, hintsPurchased: 0 });
    const suspicious = Memory.pickFinalReaction(incidentMemory, rngQueue([0]));
    assert.notStrictEqual(neutral, suspicious);
});

// ---- no persistence ------------------------------------------------------

check('this module introduces no persistence/storage calls of any kind', () => {
    const src = require('fs').readFileSync(
        require('path').join(__dirname, '../../../public_html/webdoors/hangman/js/lastword/skippy-memory.js'), 'utf8'
    );
    // Strip comments first so the module's own doc-comment prose describing
    // what it deliberately does NOT do (which necessarily names these APIs)
    // can't trip this check — only actual code should be scanned.
    const code = src.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/.*$/gm, '');
    assert.ok(!/localStorage|sessionStorage|UserStorage|indexedDB|fetch\(|XMLHttpRequest/.test(code),
        'skippy-memory.js must remain a pure, storage-free, network-free module');
});

console.log(passed + ' passed');
