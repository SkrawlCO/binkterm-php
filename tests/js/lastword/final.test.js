/**
 * Plain Node assertions for js/lastword/final.js (M1D Final Hangman rules).
 * Run: node tests/js/lastword/final.test.js
 */
'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');

const LastWordContent = require('../../../public_html/webdoors/hangman/js/lastword/content.js');
const LastWordState = require('../../../public_html/webdoors/hangman/js/lastword/state.js');
const LastWordFinal = require('../../../public_html/webdoors/hangman/js/lastword/final.js');

const puzzlesPath = path.join(__dirname, '../../../public_html/webdoors/hangman/lastword/puzzles.json');
const puzzles = LastWordContent.loadPuzzleSet(JSON.parse(fs.readFileSync(puzzlesPath, 'utf8')));
const godfather = LastWordContent.byId(puzzles, 'movies-0002'); // "THE GODFATHER", finalEligible

let passed = 0;
function check(name, fn) {
    fn();
    passed++;
    console.log('  ok - ' + name);
}

console.log('final.test.js');

check('opening cost table matches the approved values', () => {
    assert.strictEqual(LastWordFinal.openingCostFor(0), 0);
    assert.strictEqual(LastWordFinal.openingCostFor(1), 500);
    assert.strictEqual(LastWordFinal.openingCostFor(2), 1250);
    assert.strictEqual(LastWordFinal.openingCostFor(3), 2250);
    assert.throws(() => LastWordFinal.openingCostFor(4), /invalid opening letter count/);
});

check('choosing 0 opening letters costs 0 and reveals nothing', () => {
    const fs0 = LastWordState.createFinalState(godfather.id, godfather.category);
    const result = LastWordFinal.purchaseOpening(fs0, godfather, [], 0);
    assert.strictEqual(result.changed, true);
    assert.strictEqual(result.cost, 0);
    assert.deepStrictEqual(result.finalState.revealedLetters, []);
    assert.strictEqual(result.finalState.spentThisFinal, 0);
});

check('choosing 2 opening letters reveals all occurrences of present letters', () => {
    const fs0 = LastWordState.createFinalState(godfather.id, godfather.category);
    // T and H both occur twice in "THE GODFATHER".
    const result = LastWordFinal.purchaseOpening(fs0, godfather, ['T', 'H'], 5000);
    assert.strictEqual(result.changed, true);
    assert.strictEqual(result.cost, 1250);
    assert.strictEqual(result.finalState.spentThisFinal, 1250);
    assert.deepStrictEqual(result.finalState.chosenOpeningLetters.slice().sort(), ['H', 'T']);
    assert.deepStrictEqual(result.finalState.revealedLetters.slice().sort(), ['H', 'T']);
});

check('an absent chosen opening letter still consumes the choice (no refund, no extra reveal)', () => {
    const fs0 = LastWordState.createFinalState(godfather.id, godfather.category);
    assert.strictEqual(LastWordContent.countOccurrences(godfather.answer, 'X'), 0);
    const result = LastWordFinal.purchaseOpening(fs0, godfather, ['X'], 5000);
    assert.strictEqual(result.changed, true);
    assert.strictEqual(result.cost, 500); // charged for choosing 1 letter, present or not
    assert.deepStrictEqual(result.finalState.chosenOpeningLetters, ['X']);
    assert.deepStrictEqual(result.finalState.revealedLetters, []); // not present, not revealed
});

check('opening choice is rejected if it would exceed available score', () => {
    const fs0 = LastWordState.createFinalState(godfather.id, godfather.category);
    const result = LastWordFinal.purchaseOpening(fs0, godfather, ['T', 'H', 'E'], 2000); // needs 2250
    assert.strictEqual(result.changed, false);
    assert.strictEqual(result.reason, 'insufficient-score');
    assert.strictEqual(result.finalState.spentThisFinal, 0); // nothing spent
});

check('opening choice is rejected on a duplicate letter', () => {
    const fs0 = LastWordState.createFinalState(godfather.id, godfather.category);
    const result = LastWordFinal.purchaseOpening(fs0, godfather, ['T', 'T'], 5000);
    assert.strictEqual(result.changed, false);
    assert.strictEqual(result.reason, 'duplicate-letter');
});

check('opening choice cannot be made twice', () => {
    let fs0 = LastWordState.createFinalState(godfather.id, godfather.category);
    fs0 = LastWordFinal.purchaseOpening(fs0, godfather, ['T'], 5000).finalState;
    const second = LastWordFinal.purchaseOpening(fs0, godfather, ['H'], 5000);
    assert.strictEqual(second.changed, false);
    assert.strictEqual(second.reason, 'opening-already-chosen');
});

check('an additional letter during Final costs a flat 250 regardless of consonant/vowel', () => {
    let fs0 = LastWordState.createFinalState(godfather.id, godfather.category);
    fs0 = LastWordFinal.purchaseOpening(fs0, godfather, [], 0).finalState;

    const consonant = LastWordFinal.purchaseAdditionalLetter(fs0, godfather, 'T', 5000);
    assert.strictEqual(consonant.changed, true);
    assert.strictEqual(consonant.finalState.spentThisFinal, 250);

    const vowel = LastWordFinal.purchaseAdditionalLetter(consonant.finalState, godfather, 'A', 5000);
    assert.strictEqual(vowel.changed, true);
    assert.strictEqual(vowel.finalState.spentThisFinal, 500); // 250 + 250, same rate
});

check('an absent additional letter still costs 250 and adds NO strike', () => {
    let fs0 = LastWordState.createFinalState(godfather.id, godfather.category);
    fs0 = LastWordFinal.purchaseOpening(fs0, godfather, [], 0).finalState;
    assert.strictEqual(LastWordContent.countOccurrences(godfather.answer, 'Z'), 0);

    const result = LastWordFinal.purchaseAdditionalLetter(fs0, godfather, 'Z', 5000);
    assert.strictEqual(result.present, false);
    assert.strictEqual(result.finalState.spentThisFinal, 250);
    assert.strictEqual(result.finalState.strikes, 0);
});

check('an already-used letter (opening or additional) cannot be repurchased', () => {
    let fs0 = LastWordState.createFinalState(godfather.id, godfather.category);
    fs0 = LastWordFinal.purchaseOpening(fs0, godfather, ['T'], 5000).finalState;

    const repurchaseOpening = LastWordFinal.purchaseAdditionalLetter(fs0, godfather, 'T', 5000);
    assert.strictEqual(repurchaseOpening.changed, false);
    assert.strictEqual(repurchaseOpening.reason, 'already-used');

    const bought = LastWordFinal.purchaseAdditionalLetter(fs0, godfather, 'H', 5000).finalState;
    const repurchaseAdditional = LastWordFinal.purchaseAdditionalLetter(bought, godfather, 'H', 5000);
    assert.strictEqual(repurchaseAdditional.changed, false);
    assert.strictEqual(repurchaseAdditional.reason, 'already-used');
});

check('an additional letter is rejected if it would exceed available score (never overspend)', () => {
    let fs0 = LastWordState.createFinalState(godfather.id, godfather.category);
    fs0 = LastWordFinal.purchaseOpening(fs0, godfather, [], 0).finalState;
    assert.strictEqual(LastWordFinal.canAffordAdditionalLetter(249), false);
    const result = LastWordFinal.purchaseAdditionalLetter(fs0, godfather, 'T', 249);
    assert.strictEqual(result.changed, false);
    assert.strictEqual(result.reason, 'insufficient-score');
});

check('a correct solve ends Final as solved; the +5000 bonus is applied by computeFinalScore, not stored in finalState', () => {
    let fs0 = LastWordState.createFinalState(godfather.id, godfather.category);
    fs0 = LastWordFinal.purchaseOpening(fs0, godfather, [], 0).finalState;
    const result = LastWordFinal.attemptSolveFinal(fs0, godfather, 'the godfather');
    assert.strictEqual(result.correct, true);
    assert.strictEqual(result.finalState.outcome, 'solved');
});

check('a wrong solve costs exactly 2 strikes and Final remains playable below 6', () => {
    let fs0 = LastWordState.createFinalState(godfather.id, godfather.category);
    fs0 = LastWordFinal.purchaseOpening(fs0, godfather, [], 0).finalState;
    const result = LastWordFinal.attemptSolveFinal(fs0, godfather, 'nope');
    assert.strictEqual(result.correct, false);
    assert.strictEqual(result.finalState.strikes, 2);
    assert.strictEqual(result.finalState.outcome, null);
});

check('six strikes from wrong solves ends Final as struck-out, revealing the answer via buildDisplayBoard', () => {
    let fs0 = LastWordState.createFinalState(godfather.id, godfather.category);
    fs0 = LastWordFinal.purchaseOpening(fs0, godfather, [], 0).finalState;
    fs0 = LastWordFinal.attemptSolveFinal(fs0, godfather, 'nope').finalState; // 2
    fs0 = LastWordFinal.attemptSolveFinal(fs0, godfather, 'nope').finalState; // 4
    const result = LastWordFinal.attemptSolveFinal(fs0, godfather, 'nope'); // 6
    assert.strictEqual(result.finalState.strikes, 6);
    assert.strictEqual(result.finalState.outcome, 'struck-out');

    // buildDisplayBoard is reused unchanged from round.js — prove it reveals everything on a loss.
    const LastWordRound = require('../../../public_html/webdoors/hangman/js/lastword/round.js');
    const board = LastWordRound.buildDisplayBoard(godfather, result.finalState);
    assert.ok(board.every((c) => c.revealed));
});

check('Final is over once decided — no double-processing of a second solve attempt', () => {
    let fs0 = LastWordState.createFinalState(godfather.id, godfather.category);
    fs0 = LastWordFinal.purchaseOpening(fs0, godfather, [], 0).finalState;
    fs0 = LastWordFinal.attemptSolveFinal(fs0, godfather, 'the godfather').finalState;
    const second = LastWordFinal.attemptSolveFinal(fs0, godfather, 'the godfather');
    assert.strictEqual(second.changed, false);
    assert.strictEqual(second.reason, 'final-over');
});

console.log('--- computeFinalScore ---');

check('computeFinalScore on success: scoreEnteringFinal - spent + 5000', () => {
    let fs0 = LastWordState.createFinalState(godfather.id, godfather.category);
    fs0 = LastWordFinal.purchaseOpening(fs0, godfather, ['T', 'H'], 6000).finalState; // cost 1250
    fs0 = LastWordFinal.purchaseAdditionalLetter(fs0, godfather, 'A', 6000).finalState; // cost 250, total spent 1500
    fs0 = LastWordFinal.attemptSolveFinal(fs0, godfather, 'the godfather').finalState;
    assert.strictEqual(LastWordFinal.computeFinalScore(6000, fs0), 6000 - 1500 + 5000);
});

check('computeFinalScore on failure: scoreEnteringFinal - spent, no bonus, never negative by construction', () => {
    let fs0 = LastWordState.createFinalState(godfather.id, godfather.category);
    fs0 = LastWordFinal.purchaseOpening(fs0, godfather, ['T'], 6000).finalState; // cost 500
    fs0 = LastWordFinal.attemptSolveFinal(fs0, godfather, 'nope').finalState; // 2
    fs0 = LastWordFinal.attemptSolveFinal(fs0, godfather, 'nope').finalState; // 4
    fs0 = LastWordFinal.attemptSolveFinal(fs0, godfather, 'nope').finalState; // 6, struck-out
    assert.strictEqual(fs0.outcome, 'struck-out');
    assert.strictEqual(LastWordFinal.computeFinalScore(6000, fs0), 6000 - 500);
});

check('computeFinalScore never goes negative: every purchase is already gated by availableScore', () => {
    // Spend everything available on opening, leaving 0 remaining.
    let fs0 = LastWordState.createFinalState(godfather.id, godfather.category);
    const result = LastWordFinal.purchaseOpening(fs0, godfather, ['T', 'H', 'E'], 2250); // costs exactly 2250
    assert.strictEqual(result.changed, true);
    const failed = LastWordFinal.attemptSolveFinal(result.finalState, godfather, 'nope1').finalState;
    const failed2 = LastWordFinal.attemptSolveFinal(failed, godfather, 'nope2').finalState;
    const failed3 = LastWordFinal.attemptSolveFinal(failed2, godfather, 'nope3').finalState;
    assert.strictEqual(failed3.outcome, 'struck-out');
    assert.strictEqual(LastWordFinal.computeFinalScore(2250, failed3), 0);
});

console.log(`final.test.js: ${passed} passed`);
