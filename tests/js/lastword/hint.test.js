/**
 * Plain Node assertions for js/lastword/hint.js — BUY HINT LETTER
 * (SKIPPY playtest iteration #2). Isolated from round.js/state.js's own
 * test suites; exercises the pure hint module directly, plus cross-checks
 * against LastWordRound's own actionsTaken()/computeSolveBonus() to prove
 * a hint purchase decays the solve bonus by exactly one action, the same
 * way round.js already guarantees for a real guess/vowel purchase.
 * Run: node tests/js/lastword/hint.test.js
 */
'use strict';

const assert = require('assert');
const Hint = require('../../../public_html/webdoors/hangman/js/lastword/hint.js');
const LastWordState = require('../../../public_html/webdoors/hangman/js/lastword/state.js');
const LastWordRound = require('../../../public_html/webdoors/hangman/js/lastword/round.js');

let passed = 0;
function check(name, fn) {
    fn();
    passed++;
    console.log('  ok - ' + name);
}

console.log('hint.test.js');

function puzzle(answer) {
    return { id: 'p1', category: 'Test', answer: answer, difficulty: 'normal', finalEligible: false,
        guessableLetters: Array.from(new Set(answer.toUpperCase().replace(/[^A-Z]/g, '').split(''))) };
}

function freshRound(roundNum) {
    return LastWordState.createRoundState(roundNum || 1, 'p1', 'Test');
}

const P = puzzle('MOVIE NIGHT'); // guessable: M,O,V,I,E,N,G,H,T

check('HINT_COST_MULTIPLIER is 3 and hintCostFor is exactly 3x that round\'s vowel cost', () => {
    assert.strictEqual(Hint.HINT_COST_MULTIPLIER, 3);
    [1, 2, 3, 4].forEach((r) => {
        const cfg = LastWordState.ROUND_CONFIG[r];
        assert.strictEqual(Hint.hintCostFor(cfg), cfg.vowelCost * 3);
    });
    // Matches the approved initial human-test pricing exactly.
    assert.strictEqual(Hint.hintCostFor(LastWordState.ROUND_CONFIG[1]), 300);
    assert.strictEqual(Hint.hintCostFor(LastWordState.ROUND_CONFIG[2]), 450);
    assert.strictEqual(Hint.hintCostFor(LastWordState.ROUND_CONFIG[3]), 600);
    assert.strictEqual(Hint.hintCostFor(LastWordState.ROUND_CONFIG[4]), 750);
});

check('hintCandidates only offers letters actually present in the answer and not yet revealed', () => {
    const round = freshRound(1);
    const candidates = Hint.hintCandidates(round, P);
    assert.deepStrictEqual(candidates.slice().sort(), P.guessableLetters.slice().sort());
    // Never offers a letter absent from the answer.
    'QXZJKWFY'.split('').forEach((absent) => {
        if (P.guessableLetters.indexOf(absent) === -1) {
            assert.ok(candidates.indexOf(absent) === -1);
        }
    });
});

check('purchaseHintLetter selects only an unrevealed letter actually in the answer', () => {
    const round = freshRound(1);
    for (let i = 0; i < 30; i++) {
        const result = Hint.purchaseHintLetter(round, P, 10000, LastWordState.ROUND_CONFIG[1], () => i / 30);
        assert.ok(result.changed);
        assert.ok(P.guessableLetters.indexOf(result.letter) !== -1, result.letter + ' must be an answer letter');
    }
});

check('purchaseHintLetter reveals ALL occurrences of the selected letter', () => {
    const round = freshRound(1);
    // Force selection of 'I' (occurs twice in "MOVIE NIGHT").
    const idx = P.guessableLetters.indexOf('I');
    const rng = () => idx / P.guessableLetters.length + 0.001; // lands on 'I' via floor()
    const result = Hint.purchaseHintLetter(round, P, 10000, LastWordState.ROUND_CONFIG[1], rng);
    assert.strictEqual(result.letter, 'I');
    const board = LastWordRound.buildDisplayBoard(P, result.roundState);
    const revealedIs = board.filter((c) => c.isLetter && c.revealed && c.char === 'I');
    const totalIs = P.answer.split('').filter((c) => c === 'I').length;
    assert.strictEqual(revealedIs.length, totalIs, 'every occurrence of I should be revealed');
    assert.ok(totalIs >= 2, 'test setup sanity: I should occur more than once');
});

check('a hinted consonant is tracked in consonantsGuessed; a hinted vowel is tracked in purchasedVowels', () => {
    const round = freshRound(1);
    const consonantIdx = P.guessableLetters.indexOf('M'); // consonant
    const r1 = Hint.purchaseHintLetter(round, P, 10000, LastWordState.ROUND_CONFIG[1], () => consonantIdx / P.guessableLetters.length);
    assert.strictEqual(r1.letter, 'M');
    assert.ok(r1.roundState.consonantsGuessed.indexOf('M') !== -1);
    assert.ok(r1.roundState.purchasedVowels.indexOf('M') === -1);

    const round2 = freshRound(1);
    const vowelIdx = P.guessableLetters.indexOf('O'); // vowel
    const r2 = Hint.purchaseHintLetter(round2, P, 10000, LastWordState.ROUND_CONFIG[1], () => vowelIdx / P.guessableLetters.length);
    assert.strictEqual(r2.letter, 'O');
    assert.ok(r2.roundState.purchasedVowels.indexOf('O') !== -1);
    assert.ok(r2.roundState.consonantsGuessed.indexOf('O') === -1);
});

check('both consonant and vowel letters are eligible hint targets (not restricted to one type)', () => {
    const round = freshRound(1);
    const seenTypes = { consonant: false, vowel: false };
    for (let i = 0; i < P.guessableLetters.length; i++) {
        const r = Hint.purchaseHintLetter(round, P, 10000, LastWordState.ROUND_CONFIG[1], () => i / P.guessableLetters.length);
        if (LastWordRound && require('../../../public_html/webdoors/hangman/js/lastword/content.js').isVowel(r.letter)) {
            seenTypes.vowel = true;
        } else {
            seenTypes.consonant = true;
        }
    }
    assert.ok(seenTypes.consonant && seenTypes.vowel, 'sweeping every candidate index should hit both a consonant and a vowel');
});

check('exactly one deduction from pointsThisRound per purchase, at the correct round-specific cost', () => {
    [1, 2, 3, 4].forEach((roundNum) => {
        const round = freshRound(roundNum);
        const cfg = LastWordState.ROUND_CONFIG[roundNum];
        const result = Hint.purchaseHintLetter(round, P, 10000, cfg, () => 0);
        assert.strictEqual(result.roundState.pointsThisRound, -Hint.hintCostFor(cfg));
        assert.strictEqual(result.cost, Hint.hintCostFor(cfg));
    });
});

check('no points are awarded for the hint reveal itself, even for a multi-occurrence letter', () => {
    const round = freshRound(1);
    const idx = P.guessableLetters.indexOf('I'); // occurs twice
    const result = Hint.purchaseHintLetter(round, P, 10000, LastWordState.ROUND_CONFIG[1], () => idx / P.guessableLetters.length + 0.001);
    // pointsThisRound only reflects the negative cost — no positive
    // per-occurrence award was added on top, unlike guessConsonant().
    assert.strictEqual(result.roundState.pointsThisRound, -Hint.hintCostFor(LastWordState.ROUND_CONFIG[1]));
});

check('no strike is ever added by a hint purchase', () => {
    const round = freshRound(1);
    const result = Hint.purchaseHintLetter(round, P, 10000, LastWordState.ROUND_CONFIG[1], () => 0);
    assert.strictEqual(result.roundState.strikes, 0);
});

check('a hint purchase decays the solve bonus by exactly one action, via round.js\'s own actionsTaken/computeSolveBonus', () => {
    const cfg = LastWordState.ROUND_CONFIG[1];
    const decay = LastWordRound.decayPerActionFor(cfg.maxSolveBonus);
    const before = freshRound(1);
    assert.strictEqual(LastWordRound.actionsTaken(before), 0);
    const bonusBefore = LastWordRound.computeSolveBonus(before, cfg.maxSolveBonus, decay);
    assert.strictEqual(bonusBefore, cfg.maxSolveBonus);

    const result = Hint.purchaseHintLetter(before, P, 10000, cfg, () => 0);
    assert.strictEqual(LastWordRound.actionsTaken(result.roundState), 1, 'hint purchase should count as exactly one action');
    const bonusAfter = LastWordRound.computeSolveBonus(result.roundState, cfg.maxSolveBonus, decay);
    assert.strictEqual(bonusAfter, cfg.maxSolveBonus - decay, 'solve bonus should decay by exactly one action\'s worth');
});

check('insufficient score blocks the purchase and changes nothing', () => {
    const round = freshRound(1);
    const cost = Hint.hintCostFor(LastWordState.ROUND_CONFIG[1]);
    const result = Hint.purchaseHintLetter(round, P, cost - 1, LastWordState.ROUND_CONFIG[1], () => 0);
    assert.strictEqual(result.changed, false);
    assert.strictEqual(result.reason, 'insufficient-score');
    assert.strictEqual(result.roundState, round, 'unchanged: same reference, not just equal');
});

check('exactly enough score (== cost) is sufficient — the boundary is inclusive', () => {
    const round = freshRound(1);
    const cost = Hint.hintCostFor(LastWordState.ROUND_CONFIG[1]);
    const result = Hint.purchaseHintLetter(round, P, cost, LastWordState.ROUND_CONFIG[1], () => 0);
    assert.strictEqual(result.changed, true);
});

check('no unrevealed guessable letters remaining blocks the purchase', () => {
    let round = freshRound(1);
    // Reveal every guessable letter via repeated hints.
    for (let i = 0; i < P.guessableLetters.length; i++) {
        const r = Hint.purchaseHintLetter(round, P, 100000, LastWordState.ROUND_CONFIG[1], () => 0);
        assert.ok(r.changed);
        round = r.roundState;
    }
    assert.strictEqual(Hint.hintCandidates(round, P).length, 0);
    const result = Hint.purchaseHintLetter(round, P, 100000, LastWordState.ROUND_CONFIG[1], () => 0);
    assert.strictEqual(result.changed, false);
    assert.strictEqual(result.reason, 'no-letters-remaining');
});

check('never drives score negative: the affordability check runs before any deduction', () => {
    const round = freshRound(1);
    const cost = Hint.hintCostFor(LastWordState.ROUND_CONFIG[1]);
    // availableScore just under cost is rejected outright.
    const blocked = Hint.purchaseHintLetter(round, P, cost - 1, LastWordState.ROUND_CONFIG[1], () => 0);
    assert.strictEqual(blocked.changed, false);
    // availableScore exactly at cost succeeds and leaves pointsThisRound at
    // exactly -cost (i.e. availableScore-after == 0, never negative).
    const allowed = Hint.purchaseHintLetter(round, P, cost, LastWordState.ROUND_CONFIG[1], () => 0);
    assert.strictEqual(allowed.changed, true);
    const availableAfter = 0 /* cumulativeScore */ + allowed.roundState.pointsThisRound + cost /* + the score we started with */;
    assert.ok(availableAfter >= 0);
});

check('a hint purchase is rejected once the round is already over', () => {
    let round = freshRound(1);
    round = Object.assign({}, round, { outcome: 'solved' });
    const result = Hint.purchaseHintLetter(round, P, 100000, LastWordState.ROUND_CONFIG[1], () => 0);
    assert.strictEqual(result.changed, false);
    assert.strictEqual(result.reason, 'round-over');
});

check('purchaseHintLetter does not mutate the roundState passed in', () => {
    const round = freshRound(1);
    const snapshot = JSON.parse(JSON.stringify(round));
    Hint.purchaseHintLetter(round, P, 100000, LastWordState.ROUND_CONFIG[1], () => 0);
    assert.deepStrictEqual(round, snapshot);
});

console.log(passed + ' passed');
