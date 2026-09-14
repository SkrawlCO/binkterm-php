/**
 * Plain Node assertions for js/lastword/situational-awareness.js — SKIPPY
 * IS WATCHING (conscious bounded fork #2). Exercises the pure round-scoped
 * awareness/reaction module directly, isolated from app-final-skippy.js's
 * DOM wiring (covered separately in app-final-skippy-dom.test.js) and from
 * skippy-memory.js (Fork #1, session-scoped — separate module, separate
 * test file, unaffected by this fork).
 * Run: node tests/js/lastword/situational-awareness.test.js
 */
'use strict';

const assert = require('assert');
const Situational = require('../../../public_html/webdoors/hangman/js/lastword/situational-awareness.js');

let passed = 0;
function check(name, fn) {
    fn();
    passed++;
    console.log('  ok - ' + name);
}

console.log('situational-awareness.test.js');

function rngQueue(values) {
    let i = 0;
    return () => values[Math.min(i++, values.length - 1)];
}

function otherAction(overrides) {
    return Object.assign({ kind: 'other', strikes: 2, isRoundOver: false, canBuyHint: false, hintCost: 300 }, overrides || {});
}

// ---- createRoundAwareness ------------------------------------------------

check('a fresh round awareness starts clean', () => {
    const a = Situational.createRoundAwareness();
    assert.strictEqual(a.hintsPurchasedThisRound, 0);
    assert.strictEqual(a.wrongSolveAttemptsThisRound, 0);
    assert.strictEqual(a.fiveStrikeDangerFired, false);
    assert.strictEqual(a.hintDependenceFired, false);
    assert.strictEqual(a.repeatedWrongSolveFired, false);
});

// ---- situation 1: five strikes + affordable hint -------------------------

check('five-strike danger fires when strikes=5, round not over, and a hint is affordable', () => {
    const r = Situational.onAction(Situational.createRoundAwareness(),
        otherAction({ strikes: 5, canBuyHint: true, hintCost: 750 }), rngQueue([0]));
    assert.ok(r.reaction, 'should produce a reaction');
    assert.strictEqual(r.reaction.indexOf('{cost}'), -1, 'the {cost} token must always be substituted, never left literal: ' + r.reaction);
    assert.strictEqual(r.awareness.fiveStrikeDangerFired, true);
});

check('five-strike danger substitutes the LIVE round-specific hint cost into the {cost} line, not a hardcoded price', () => {
    // Force the {cost}-bearing line via rng, and prove two different rounds' costs both come through correctly.
    const r1 = Situational.onAction(Situational.createRoundAwareness(),
        otherAction({ strikes: 5, canBuyHint: true, hintCost: 300 }), rngQueue([0.99]));
    const r2 = Situational.onAction(Situational.createRoundAwareness(),
        otherAction({ strikes: 5, canBuyHint: true, hintCost: 750 }), rngQueue([0.99]));
    assert.ok(r1.reaction.indexOf('300') !== -1, 'Round 1 cost should appear: ' + r1.reaction);
    assert.ok(r2.reaction.indexOf('750') !== -1, 'Round 4 cost should appear: ' + r2.reaction);
});

check('five-strike danger does NOT fire when the caller cannot afford a hint', () => {
    const r = Situational.onAction(Situational.createRoundAwareness(),
        otherAction({ strikes: 5, canBuyHint: false }), rngQueue([0]));
    assert.strictEqual(r.reaction, null);
    assert.strictEqual(r.awareness.fiveStrikeDangerFired, false);
});

check('five-strike danger does NOT fire at other strike counts even if a hint is affordable', () => {
    [0, 1, 2, 3, 4, 6].forEach((strikes) => {
        const r = Situational.onAction(Situational.createRoundAwareness(),
            otherAction({ strikes: strikes, canBuyHint: true }), rngQueue([0]));
        assert.strictEqual(r.reaction, null, 'should not fire at strikes=' + strikes);
    });
});

check('five-strike danger does NOT fire once the round is already over', () => {
    const r = Situational.onAction(Situational.createRoundAwareness(),
        otherAction({ strikes: 5, canBuyHint: true, isRoundOver: true }), rngQueue([0]));
    assert.strictEqual(r.reaction, null);
});

check('five-strike danger fires only ONCE per round even if the condition holds again', () => {
    let awareness = Situational.createRoundAwareness();
    const first = Situational.onAction(awareness, otherAction({ strikes: 5, canBuyHint: true }), rngQueue([0]));
    assert.ok(first.reaction);
    awareness = first.awareness;
    const second = Situational.onAction(awareness, otherAction({ strikes: 5, canBuyHint: true }), rngQueue([0]));
    assert.strictEqual(second.reaction, null, 'must not repeat within the same round');
});

// ---- situation 2: repeated hint use ---------------------------------------

check('hint dependence does NOT fire on the first hint of a round', () => {
    const r = Situational.onAction(Situational.createRoundAwareness(),
        otherAction({ kind: 'hintPurchased', strikes: 1 }), rngQueue([0]));
    assert.strictEqual(r.reaction, null);
    assert.strictEqual(r.awareness.hintsPurchasedThisRound, 1);
});

check('hint dependence fires at the intended threshold (HINT_DEPENDENCE_THRESHOLD-th hint)', () => {
    let awareness = Situational.createRoundAwareness();
    let last;
    for (let i = 0; i < Situational.HINT_DEPENDENCE_THRESHOLD; i++) {
        last = Situational.onAction(awareness, otherAction({ kind: 'hintPurchased', strikes: 1 }), rngQueue([0]));
        awareness = last.awareness;
    }
    assert.ok(last.reaction, 'should fire on the ' + Situational.HINT_DEPENDENCE_THRESHOLD + 'th hint');
    assert.strictEqual(awareness.hintsPurchasedThisRound, Situational.HINT_DEPENDENCE_THRESHOLD);
});

check('hint dependence fires only once even with further hints in the same round', () => {
    let awareness = Situational.createRoundAwareness();
    let fires = 0;
    for (let i = 0; i < Situational.HINT_DEPENDENCE_THRESHOLD + 3; i++) {
        const r = Situational.onAction(awareness, otherAction({ kind: 'hintPurchased', strikes: 1 }), rngQueue([0]));
        awareness = r.awareness;
        if (r.reaction) fires++;
    }
    assert.strictEqual(fires, 1, 'hint dependence must fire exactly once per round, not on every hint');
});

// ---- situation 3: repeated wrong solves -----------------------------------

check('repeated wrong solve does NOT fire on the first wrong solve of a round', () => {
    const r = Situational.onAction(Situational.createRoundAwareness(),
        otherAction({ kind: 'wrongSolveAttempt', strikes: 2 }), rngQueue([0]));
    assert.strictEqual(r.reaction, null);
    assert.strictEqual(r.awareness.wrongSolveAttemptsThisRound, 1);
});

check('repeated wrong solve fires at the intended threshold (WRONG_SOLVE_THRESHOLD-th wrong solve)', () => {
    let awareness = Situational.createRoundAwareness();
    let last;
    for (let i = 0; i < Situational.WRONG_SOLVE_THRESHOLD; i++) {
        last = Situational.onAction(awareness, otherAction({ kind: 'wrongSolveAttempt', strikes: 2 }), rngQueue([0]));
        awareness = last.awareness;
    }
    assert.ok(last.reaction);
    assert.strictEqual(awareness.wrongSolveAttemptsThisRound, Situational.WRONG_SOLVE_THRESHOLD);
});

check('repeated wrong solve fires only once even with further wrong solves in the same round', () => {
    let awareness = Situational.createRoundAwareness();
    let fires = 0;
    for (let i = 0; i < Situational.WRONG_SOLVE_THRESHOLD + 2; i++) {
        const r = Situational.onAction(awareness, otherAction({ kind: 'wrongSolveAttempt', strikes: 2 }), rngQueue([0]));
        awareness = r.awareness;
        if (r.reaction) fires++;
    }
    assert.strictEqual(fires, 1);
});

// ---- priority when multiple situations qualify at once -------------------

check('priority: five-strike danger outranks repeated wrong solve when both qualify from the same action', () => {
    let awareness = Situational.createRoundAwareness();
    // One prior wrong solve so this next one crosses WRONG_SOLVE_THRESHOLD...
    awareness = Situational.onAction(awareness, otherAction({ kind: 'wrongSolveAttempt', strikes: 3 }), rngQueue([0])).awareness;
    // ...and this wrong solve ALSO happens to land strikes on 5 with an affordable hint.
    const r = Situational.onAction(awareness, { kind: 'wrongSolveAttempt', strikes: 5, isRoundOver: false, canBuyHint: true, hintCost: 300 }, rngQueue([0]));
    assert.ok(r.reaction);
    assert.strictEqual(r.awareness.fiveStrikeDangerFired, true, 'danger should have fired');
    assert.strictEqual(r.awareness.repeatedWrongSolveFired, false, 'repeated-wrong-solve should NOT also fire in the same call — only one reaction per action');
});

check('priority: five-strike danger outranks hint dependence when both qualify from the same action', () => {
    let awareness = Situational.createRoundAwareness();
    awareness = Situational.onAction(awareness, otherAction({ kind: 'hintPurchased', strikes: 3 }), rngQueue([0])).awareness;
    const r = Situational.onAction(awareness, { kind: 'hintPurchased', strikes: 5, isRoundOver: false, canBuyHint: true, hintCost: 300 }, rngQueue([0]));
    assert.ok(r.reaction);
    assert.strictEqual(r.awareness.fiveStrikeDangerFired, true);
    assert.strictEqual(r.awareness.hintDependenceFired, false, 'only one reaction per action — danger wins');
});

check('once danger has already fired, a later qualifying hint-dependence/wrong-solve event can still fire on its own', () => {
    let awareness = Situational.createRoundAwareness();
    let r = Situational.onAction(awareness, otherAction({ strikes: 5, canBuyHint: true }), rngQueue([0]));
    assert.ok(r.reaction);
    awareness = r.awareness;
    // Now two hint purchases, no longer coinciding with the danger condition (strikes changed / hint no longer affordable).
    awareness = Situational.onAction(awareness, otherAction({ kind: 'hintPurchased', strikes: 5, canBuyHint: false }), rngQueue([0])).awareness;
    r = Situational.onAction(awareness, otherAction({ kind: 'hintPurchased', strikes: 5, canBuyHint: false }), rngQueue([0]));
    assert.ok(r.reaction, 'hint dependence should still be able to fire once danger is no longer re-triggering');
});

// ---- ordinary/uninteresting actions produce no reaction -------------------

check('an ordinary action (normal strikes, no hint spree, no repeated wrong solve) yields no reaction', () => {
    const r = Situational.onAction(Situational.createRoundAwareness(), otherAction({ strikes: 2 }), rngQueue([0]));
    assert.strictEqual(r.reaction, null);
});

// ---- purity -----------------------------------------------------------

check('onAction does not mutate the awareness object passed in', () => {
    const before = Situational.createRoundAwareness();
    const snapshot = JSON.stringify(before);
    Situational.onAction(before, otherAction({ strikes: 5, canBuyHint: true }), rngQueue([0]));
    assert.strictEqual(JSON.stringify(before), snapshot);
});

// ---- no persistence ------------------------------------------------------

check('this module introduces no persistence/storage calls of any kind', () => {
    const src = require('fs').readFileSync(
        require('path').join(__dirname, '../../../public_html/webdoors/hangman/js/lastword/situational-awareness.js'), 'utf8'
    );
    const code = src.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/.*$/gm, '');
    assert.ok(!/localStorage|sessionStorage|UserStorage|indexedDB|fetch\(|XMLHttpRequest/.test(code),
        'situational-awareness.js must remain a pure, storage-free, network-free module');
});

console.log(passed + ' passed');
