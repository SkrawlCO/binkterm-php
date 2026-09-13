/**
 * Plain Node assertions for js/lastword/auto-solve.js — the core-game fix
 * for "board fully revealed but round/Final never recognized completion."
 * Exercises the pure module directly against real round.js/final.js state
 * shapes, cross-checking its bonus math against LastWordRound's own
 * computeSolveBonus()/decayPerActionFor() so this can never silently drift
 * from what an explicit SOLVE would have awarded at the same moment.
 * Run: node tests/js/lastword/auto-solve.test.js
 */
'use strict';

const assert = require('assert');
const AutoSolve = require('../../../public_html/webdoors/hangman/js/lastword/auto-solve.js');
const LastWordState = require('../../../public_html/webdoors/hangman/js/lastword/state.js');
const LastWordRound = require('../../../public_html/webdoors/hangman/js/lastword/round.js');
const LastWordFinal = require('../../../public_html/webdoors/hangman/js/lastword/final.js');

let passed = 0;
function check(name, fn) {
    fn();
    passed++;
    console.log('  ok - ' + name);
}

console.log('auto-solve.test.js');

function puzzle(answer) {
    return {
        id: 'p1', category: 'Test', answer: answer, difficulty: 'normal', finalEligible: true,
        guessableLetters: Array.from(new Set(answer.toUpperCase().replace(/[^A-Z]/g, '').split('')))
    };
}

const P = puzzle('GALILEO GALILEI'); // guessable: G,A,L,I,E,O — the exact reported repro's letters

function freshRound(roundNum) {
    return LastWordState.createRoundState(roundNum || 1, 'p1', 'Test');
}

function freshFinal() {
    return LastWordState.createFinalState('p1', 'Test');
}

// ---- isFullyRevealed --------------------------------------------------

check('isFullyRevealed is false with an empty reveal set and true once every guessable letter is present', () => {
    assert.strictEqual(AutoSolve.isFullyRevealed(P, []), false);
    assert.strictEqual(AutoSolve.isFullyRevealed(P, P.guessableLetters.slice(0, P.guessableLetters.length - 1)), false, 'one letter short');
    assert.strictEqual(AutoSolve.isFullyRevealed(P, P.guessableLetters), true);
    // Order/duplicates don't matter — only membership.
    assert.strictEqual(AutoSolve.isFullyRevealed(P, P.guessableLetters.slice().reverse().concat(P.guessableLetters[0])), true);
});

// ---- Round auto-solve ---------------------------------------------------

check('a partially revealed round does NOT auto-solve (no-op, same reference returned)', () => {
    var round = freshRound(1);
    round = LastWordRound.guessConsonant(round, P, 'G', LastWordState.ROUND_CONFIG[1]).roundState;
    const result = AutoSolve.applyRoundAutoSolve(round, P, LastWordState.ROUND_CONFIG[1]);
    assert.strictEqual(result, round, 'must return the exact same object reference, not a lookalike copy');
    assert.strictEqual(result.outcome, null);
});

check('normal round auto-completes after the final CONSONANT reveal', () => {
    var round = freshRound(1);
    var cfg = LastWordState.ROUND_CONFIG[1];
    const consonants = P.guessableLetters.filter((l) => 'AEIOU'.indexOf(l) === -1); // G, L
    const vowels = P.guessableLetters.filter((l) => 'AEIOU'.indexOf(l) !== -1); // A, I, E, O
    // Reveal every letter except the LAST consonant via vowels + all-but-one consonant.
    vowels.forEach((v) => { round = LastWordRound.purchaseVowel(round, P, v, 100000, cfg).roundState; });
    for (let i = 0; i < consonants.length - 1; i++) {
        round = LastWordRound.guessConsonant(round, P, consonants[i], cfg).roundState;
    }
    assert.strictEqual(AutoSolve.isFullyRevealed(P, round.revealedLetters), false, 'precondition: one consonant still hidden');
    assert.strictEqual(round.outcome, null);

    const lastConsonant = consonants[consonants.length - 1];
    round = LastWordRound.guessConsonant(round, P, lastConsonant, cfg).roundState;
    assert.strictEqual(round.outcome, null, 'precondition: guessConsonant() itself never auto-solves (that is the bug being fixed)');

    round = AutoSolve.applyRoundAutoSolve(round, P, cfg);
    assert.strictEqual(round.outcome, 'solved');
    assert.strictEqual(LastWordRound.isRoundOver(round), true);
});

check('normal round auto-completes after the final VOWEL reveal', () => {
    var round = freshRound(1);
    var cfg = LastWordState.ROUND_CONFIG[1];
    const consonants = P.guessableLetters.filter((l) => 'AEIOU'.indexOf(l) === -1);
    const vowels = P.guessableLetters.filter((l) => 'AEIOU'.indexOf(l) !== -1);
    consonants.forEach((c) => { round = LastWordRound.guessConsonant(round, P, c, cfg).roundState; });
    for (let i = 0; i < vowels.length - 1; i++) {
        round = LastWordRound.purchaseVowel(round, P, vowels[i], 100000, cfg).roundState;
    }
    assert.strictEqual(round.outcome, null, 'precondition: one vowel still hidden');

    const lastVowel = vowels[vowels.length - 1];
    round = LastWordRound.purchaseVowel(round, P, lastVowel, 100000, cfg).roundState;
    assert.strictEqual(round.outcome, null, 'precondition: purchaseVowel() itself never auto-solves');

    round = AutoSolve.applyRoundAutoSolve(round, P, cfg);
    assert.strictEqual(round.outcome, 'solved');
});

check('the auto-solve bonus matches LastWordRound.computeSolveBonus() exactly (byte-identical to an explicit SOLVE at that moment)', () => {
    var round = freshRound(1);
    var cfg = LastWordState.ROUND_CONFIG[1];
    P.guessableLetters.slice(0, P.guessableLetters.length - 1).forEach((l) => {
        round = 'AEIOU'.indexOf(l) === -1
            ? LastWordRound.guessConsonant(round, P, l, cfg).roundState
            : LastWordRound.purchaseVowel(round, P, l, 100000, cfg).roundState;
    });
    const last = P.guessableLetters[P.guessableLetters.length - 1];
    round = 'AEIOU'.indexOf(last) === -1
        ? LastWordRound.guessConsonant(round, P, last, cfg).roundState
        : LastWordRound.purchaseVowel(round, P, last, 100000, cfg).roundState;

    const pointsBeforeAutoSolve = round.pointsThisRound;
    const decayPerAction = LastWordRound.decayPerActionFor(cfg.maxSolveBonus);
    const expectedBonus = LastWordRound.computeSolveBonus(round, cfg.maxSolveBonus, decayPerAction);

    const solved = AutoSolve.applyRoundAutoSolve(round, P, cfg);
    assert.strictEqual(solved.pointsThisRound, pointsBeforeAutoSolve + expectedBonus);
    assert.ok(expectedBonus >= 0);
});

check('auto-solve reveals the full letter set and awards the bonus exactly once (idempotent on an already-solved round)', () => {
    var round = freshRound(1);
    var cfg = LastWordState.ROUND_CONFIG[1];
    P.guessableLetters.forEach((l) => {
        round = 'AEIOU'.indexOf(l) === -1
            ? LastWordRound.guessConsonant(round, P, l, cfg).roundState
            : LastWordRound.purchaseVowel(round, P, l, 100000, cfg).roundState;
    });
    const solvedOnce = AutoSolve.applyRoundAutoSolve(round, P, cfg);
    assert.strictEqual(solvedOnce.outcome, 'solved');
    assert.deepStrictEqual(solvedOnce.revealedLetters.slice().sort(), P.guessableLetters.slice().sort());

    // Calling it again on the now-solved state must be a true no-op — the
    // "reuse the canonical path exactly once" requirement.
    const solvedTwice = AutoSolve.applyRoundAutoSolve(solvedOnce, P, cfg);
    assert.strictEqual(solvedTwice, solvedOnce, 'must return the same reference — no double-award');
    assert.strictEqual(solvedTwice.pointsThisRound, solvedOnce.pointsThisRound);
});

check('auto-solve adds no strike and does not disturb strikes already on the board', () => {
    var round = freshRound(1);
    var cfg = LastWordState.ROUND_CONFIG[1];
    // One real wrong guess first (a letter absent from the puzzle).
    const absentConsonant = 'BCDFHJKMNPQRSTVWXYZ'.split('').find((l) => P.guessableLetters.indexOf(l) === -1);
    round = LastWordRound.guessConsonant(round, P, absentConsonant, cfg).roundState;
    assert.strictEqual(round.strikes, 1);

    P.guessableLetters.forEach((l) => {
        round = 'AEIOU'.indexOf(l) === -1
            ? LastWordRound.guessConsonant(round, P, l, cfg).roundState
            : LastWordRound.purchaseVowel(round, P, l, 100000, cfg).roundState;
    });
    const solved = AutoSolve.applyRoundAutoSolve(round, P, cfg);
    assert.strictEqual(solved.strikes, 1, 'the earlier real strike must survive unchanged; auto-solve must add none of its own');
});

check('applyRoundAutoSolve never mutates the roundState passed in', () => {
    var round = freshRound(1);
    var cfg = LastWordState.ROUND_CONFIG[1];
    P.guessableLetters.forEach((l) => {
        round = 'AEIOU'.indexOf(l) === -1
            ? LastWordRound.guessConsonant(round, P, l, cfg).roundState
            : LastWordRound.purchaseVowel(round, P, l, 100000, cfg).roundState;
    });
    const snapshot = JSON.parse(JSON.stringify(round));
    AutoSolve.applyRoundAutoSolve(round, P, cfg);
    assert.deepStrictEqual(round, snapshot);
});

check('a round already ended (struck-out) is left alone by auto-solve, even if somehow "fully revealed"', () => {
    var round = freshRound(1);
    var cfg = LastWordState.ROUND_CONFIG[1];
    // Force struck-out via 6 wrong guesses (letters guaranteed absent from P).
    const absent = 'BCDFHJKMNPQRSTVWXYZ'.split('').filter((l) => P.guessableLetters.indexOf(l) === -1);
    for (let i = 0; i < 6; i++) {
        round = LastWordRound.guessConsonant(round, P, absent[i], cfg).roundState;
    }
    assert.strictEqual(round.outcome, 'struck-out');
    const result = AutoSolve.applyRoundAutoSolve(round, P, cfg);
    assert.strictEqual(result, round, 'must be a true no-op once the round is already over');
});

// ---- Final auto-solve ----------------------------------------------------

check('a partially revealed Final does NOT auto-solve', () => {
    var fs = freshFinal();
    fs = LastWordFinal.purchaseAdditionalLetter(fs, P, 'G', 100000).finalState;
    const result = AutoSolve.applyFinalAutoSolve(fs, P);
    assert.strictEqual(result, fs);
    assert.strictEqual(result.outcome, null);
});

check('Final auto-completes after the final purchased-letter reveal', () => {
    var fs = freshFinal();
    for (let i = 0; i < P.guessableLetters.length - 1; i++) {
        fs = LastWordFinal.purchaseAdditionalLetter(fs, P, P.guessableLetters[i], 1000000).finalState;
    }
    assert.strictEqual(fs.outcome, null, 'precondition: one letter still hidden');

    const last = P.guessableLetters[P.guessableLetters.length - 1];
    fs = LastWordFinal.purchaseAdditionalLetter(fs, P, last, 1000000).finalState;
    assert.strictEqual(fs.outcome, null, 'precondition: purchaseAdditionalLetter() itself never auto-solves (the bug)');

    fs = AutoSolve.applyFinalAutoSolve(fs, P);
    assert.strictEqual(fs.outcome, 'solved');
    assert.strictEqual(LastWordFinal.isFinalOver(fs), true);
});

// Note: Final Hangman has NO hint/BUY-HINT-LETTER path of its own — that
// control is explicitly scoped to Rounds 1-4 only (see hint.js's header);
// Final's only letter-reveal purchase is purchaseAdditionalLetter(), already
// covered above, so there is no separate "Final HINT path" to test.

check('Final: the correct-solve bonus applies via computeFinalScore() exactly once, based on outcome alone (Final stores no bonus itself)', () => {
    var fs = freshFinal();
    P.guessableLetters.forEach((l) => { fs = LastWordFinal.purchaseAdditionalLetter(fs, P, l, 1000000).finalState; });
    const scoreEnteringFinal = 5000;
    const spent = fs.spentThisFinal;

    const notYetSolved = LastWordFinal.computeFinalScore(scoreEnteringFinal, fs);
    assert.strictEqual(notYetSolved, scoreEnteringFinal - spent, 'no bonus before outcome is set');

    fs = AutoSolve.applyFinalAutoSolve(fs, P);
    const solvedScore = LastWordFinal.computeFinalScore(scoreEnteringFinal, fs);
    assert.strictEqual(solvedScore, scoreEnteringFinal - spent + LastWordFinal.FINAL_CONFIG.correctSolveBonus);

    // Calling computeFinalScore again must not re-apply anything (it is a
    // pure projection of finalState, not a mutation) — bonus applied "exactly once".
    assert.strictEqual(LastWordFinal.computeFinalScore(scoreEnteringFinal, fs), solvedScore);
});

check('applyFinalAutoSolve is idempotent (a true no-op) once already solved', () => {
    var fs = freshFinal();
    P.guessableLetters.forEach((l) => { fs = LastWordFinal.purchaseAdditionalLetter(fs, P, l, 1000000).finalState; });
    const solvedOnce = AutoSolve.applyFinalAutoSolve(fs, P);
    const solvedTwice = AutoSolve.applyFinalAutoSolve(solvedOnce, P);
    assert.strictEqual(solvedTwice, solvedOnce);
});

check('applyFinalAutoSolve never mutates the finalState passed in', () => {
    var fs = freshFinal();
    P.guessableLetters.forEach((l) => { fs = LastWordFinal.purchaseAdditionalLetter(fs, P, l, 1000000).finalState; });
    const snapshot = JSON.parse(JSON.stringify(fs));
    AutoSolve.applyFinalAutoSolve(fs, P);
    assert.deepStrictEqual(fs, snapshot);
});

check('a Final already ended (struck-out) is left alone by auto-solve', () => {
    var fs = freshFinal();
    fs = LastWordFinal.attemptSolveFinal(fs, P, 'wrong').finalState; // +2 strikes
    fs = LastWordFinal.attemptSolveFinal(fs, P, 'wrong').finalState; // +2 -> 4
    fs = LastWordFinal.attemptSolveFinal(fs, P, 'wrong').finalState; // +2 -> 6, struck-out
    assert.strictEqual(fs.outcome, 'struck-out');
    const result = AutoSolve.applyFinalAutoSolve(fs, P);
    assert.strictEqual(result, fs);
});

console.log(passed + ' passed');
