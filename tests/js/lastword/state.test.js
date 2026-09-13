/**
 * Plain Node assertions for js/lastword/state.js (M1A foundation).
 * Run: node tests/js/lastword/state.test.js
 */
'use strict';

const assert = require('assert');
const LastWordState = require('../../../public_html/webdoors/hangman/js/lastword/state.js');

let passed = 0;
function check(name, fn) {
    fn();
    passed++;
    console.log('  ok - ' + name);
}

console.log('state.test.js');

check('createSession produces a fresh round-1 session', () => {
    const session = LastWordState.createSession();
    assert.strictEqual(session.round, 1);
    assert.strictEqual(session.cumulativeScore, 0);
    assert.deepStrictEqual(session.categoriesUsed, []);
    assert.deepStrictEqual(session.rounds, []);
    assert.strictEqual(session.currentRound, null);
    assert.strictEqual(session.finalState, null);
    assert.strictEqual(session.finished, null);
});

check('createRoundState rejects an out-of-range round number', () => {
    assert.throws(() => LastWordState.createRoundState(5, 'movies-0001', 'Movies & TV'), /invalid round/);
    assert.throws(() => LastWordState.createRoundState(0, 'movies-0001', 'Movies & TV'), /invalid round/);
});

check('a full session (JSON round-trip) survives JSON.stringify/parse without structural loss', () => {
    const session = LastWordState.createSession();
    session.currentRound = LastWordState.createRoundState(1, 'movies-0001', 'Movies & TV');
    session.currentRound.consonantsGuessed.push('B', 'K');
    session.currentRound.strikes = 2;
    session.finalState = LastWordState.createFinalState('history-0001', 'History');

    const json = JSON.stringify(session);
    const restored = JSON.parse(json);

    assert.deepStrictEqual(restored, session);
    LastWordState.validateSession(restored); // throws on structural mismatch
});

check('completeRound carries cumulative score forward and clears round-scoped state', () => {
    let session = LastWordState.createSession();
    const round1 = LastWordState.createRoundState(1, 'movies-0001', 'Movies & TV');
    round1.consonantsGuessed.push('B', 'K', 'T', 'F');
    round1.pointsThisRound = 700;
    round1.outcome = 'solved';

    session = LastWordState.completeRound(session, round1, 700);

    assert.strictEqual(session.cumulativeScore, 700);
    assert.strictEqual(session.round, 2);
    assert.strictEqual(session.currentRound, null);
    assert.strictEqual(session.rounds.length, 1);
    assert.strictEqual(session.rounds[0].outcome, 'solved');
    assert.deepStrictEqual(session.categoriesUsed, ['Movies & TV']);

    // Starting round 2 must not see round 1's guesses.
    const round2 = LastWordState.createRoundState(session.round, 'music-0001', 'Music');
    assert.deepStrictEqual(round2.consonantsGuessed, []);
    assert.strictEqual(round2.strikes, 0);
});

check('completeRound is non-mutating (returns a new session object)', () => {
    const session = LastWordState.createSession();
    const round1 = LastWordState.createRoundState(1, 'movies-0001', 'Movies & TV');
    const next = LastWordState.completeRound(session, round1, 500);

    assert.strictEqual(session.cumulativeScore, 0); // original untouched
    assert.strictEqual(next.cumulativeScore, 500);
    assert.notStrictEqual(session, next);
});

check('four rounds accumulate score independently and reach round 4', () => {
    let session = LastWordState.createSession();
    const categories = ['Movies & TV', 'Music', 'Games', 'Places'];
    const pointsPerRound = [500, 800, 1200, 900];

    for (let i = 0; i < 4; i++) {
        const round = LastWordState.createRoundState(session.round, `puzzle-${i}`, categories[i]);
        session = LastWordState.completeRound(session, round, pointsPerRound[i]);
    }

    assert.strictEqual(session.cumulativeScore, 500 + 800 + 1200 + 900);
    assert.strictEqual(session.rounds.length, 4);
    // Round stays at 4 (does not roll over) once the last normal round completes.
    assert.strictEqual(session.round, 4);
    assert.deepStrictEqual(session.categoriesUsed, categories);
});

check('ROUND_CONFIG carries the approved per-round values as plain data (no formula)', () => {
    assert.strictEqual(LastWordState.ROUND_CONFIG[1].consonantValue, 100);
    assert.strictEqual(LastWordState.ROUND_CONFIG[1].vowelCost, 100);
    assert.strictEqual(LastWordState.ROUND_CONFIG[1].maxSolveBonus, 1500);
    assert.strictEqual(LastWordState.ROUND_CONFIG[4].consonantValue, 250);
    assert.strictEqual(LastWordState.ROUND_CONFIG[4].maxSolveBonus, 3000);
    // No solve-bonus decay function is exported — the formula is intentionally unresolved.
    assert.strictEqual(typeof LastWordState.computeSolveBonus, 'undefined');
});

check('FINAL_CONFIG carries the approved opening-letter costs as plain data', () => {
    assert.strictEqual(LastWordState.FINAL_CONFIG.openingLetterCost[0], 0);
    assert.strictEqual(LastWordState.FINAL_CONFIG.openingLetterCost[3], 2250);
    assert.strictEqual(LastWordState.FINAL_CONFIG.correctSolveBonus, 5000);
    assert.strictEqual(LastWordState.FINAL_CONFIG.wrongSolveStrikes, 2);
});

check('validateSession rejects a session with a bad stateVersion', () => {
    const session = LastWordState.createSession();
    session.stateVersion = 999;
    assert.throws(() => LastWordState.validateSession(session), /unsupported stateVersion/);
});

console.log(`state.test.js: ${passed} passed`);
