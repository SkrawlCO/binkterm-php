/**
 * Plain Node assertions for js/lastword/round.js (M1B Round 1 rules engine).
 * Run: node tests/js/lastword/round.test.js
 */
'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');

const LastWordContent = require('../../../public_html/webdoors/hangman/js/lastword/content.js');
const LastWordState = require('../../../public_html/webdoors/hangman/js/lastword/state.js');
const LastWordRound = require('../../../public_html/webdoors/hangman/js/lastword/round.js');
const LastWordAutoSolve = require('../../../public_html/webdoors/hangman/js/lastword/auto-solve.js');

const puzzlesPath = path.join(
    __dirname, '../../../public_html/webdoors/hangman/lastword/puzzles.json'
);
const puzzles = LastWordContent.loadPuzzleSet(JSON.parse(fs.readFileSync(puzzlesPath, 'utf8')));

let passed = 0;
function check(name, fn) {
    fn();
    passed++;
    console.log('  ok - ' + name);
}

console.log('round.test.js');

const backToTheFuture = LastWordContent.byId(puzzles, 'movies-0001'); // "BACK TO THE FUTURE"
const sayings0001 = LastWordContent.byId(puzzles, 'sayings-0001'); // "DON'T CRY OVER SPILLED MILK"

check('display board preserves spaces and masks letters', () => {
    const round = LastWordState.createRoundState(1, backToTheFuture.id, backToTheFuture.category);
    const board = LastWordRound.buildDisplayBoard(backToTheFuture, round);

    assert.strictEqual(board.length, backToTheFuture.answer.length);
    const spaceIndexes = [4, 7, 11]; // "BACK TO THE FUTURE" space positions
    spaceIndexes.forEach((i) => {
        assert.strictEqual(board[i].char, ' ');
        assert.strictEqual(board[i].isLetter, false);
    });
    board.filter((c) => c.isLetter).forEach((c) => {
        assert.strictEqual(c.char, null);
        assert.strictEqual(c.revealed, false);
    });
});

check('punctuation is always shown, never masked, and is not a guessable letter', () => {
    let round = LastWordState.createRoundState(1, sayings0001.id, sayings0001.category);
    const board = LastWordRound.buildDisplayBoard(sayings0001, round);
    const apostropheIndex = sayings0001.answer.indexOf("'");
    assert.ok(apostropheIndex > -1);
    assert.strictEqual(board[apostropheIndex].char, "'");
    assert.strictEqual(board[apostropheIndex].isLetter, false);
    assert.ok(!sayings0001.guessableLetters.includes("'"));
});

check('correct consonant reveals ALL occurrences and awards 100 x occurrences', () => {
    const round = LastWordState.createRoundState(1, backToTheFuture.id, backToTheFuture.category);
    // "BACK TO THE FUTURE" has T at positions... let's just trust countOccurrences.
    const occurrences = LastWordContent.countOccurrences(backToTheFuture.answer, 'T');
    assert.ok(occurrences >= 2, 'expected T to repeat for this test to be meaningful');

    const result = LastWordRound.guessConsonant(round, backToTheFuture, 'T');
    assert.strictEqual(result.correct, true);
    assert.strictEqual(result.occurrences, occurrences);
    assert.strictEqual(result.roundState.pointsThisRound, occurrences * 100);
    assert.strictEqual(result.roundState.strikes, 0);

    const board = LastWordRound.buildDisplayBoard(backToTheFuture, result.roundState);
    const tPositions = [...backToTheFuture.answer].map((c, i) => (c === 'T' ? i : -1)).filter((i) => i !== -1);
    tPositions.forEach((i) => assert.strictEqual(board[i].char, 'T'));
});

check('incorrect consonant costs exactly one strike and reveals nothing', () => {
    const round = LastWordState.createRoundState(1, backToTheFuture.id, backToTheFuture.category);
    assert.strictEqual(LastWordContent.countOccurrences(backToTheFuture.answer, 'Z'), 0);

    const result = LastWordRound.guessConsonant(round, backToTheFuture, 'Z');
    assert.strictEqual(result.correct, false);
    assert.strictEqual(result.roundState.strikes, 1);
    assert.strictEqual(result.roundState.pointsThisRound, 0);
    assert.deepStrictEqual(result.roundState.revealedLetters, []);
});

check('an already-guessed consonant is a no-op, not double-processed', () => {
    let round = LastWordState.createRoundState(1, backToTheFuture.id, backToTheFuture.category);
    round = LastWordRound.guessConsonant(round, backToTheFuture, 'T').roundState;
    const pointsAfterFirst = round.pointsThisRound;

    const repeat = LastWordRound.guessConsonant(round, backToTheFuture, 't'); // lowercase too
    assert.strictEqual(repeat.changed, false);
    assert.strictEqual(repeat.reason, 'already-guessed');
    assert.strictEqual(repeat.roundState.pointsThisRound, pointsAfterFirst);
    assert.strictEqual(repeat.roundState.consonantsGuessed.filter((l) => l === 'T').length, 1);
});

check('a vowel cannot be guessed as a consonant', () => {
    const round = LastWordState.createRoundState(1, backToTheFuture.id, backToTheFuture.category);
    const result = LastWordRound.guessConsonant(round, backToTheFuture, 'A');
    assert.strictEqual(result.changed, false);
    assert.strictEqual(result.reason, 'not-a-consonant');
});

check('vowel purchase costs 100, reveals all occurrences, and adds no strike on a miss', () => {
    const round = LastWordState.createRoundState(1, backToTheFuture.id, backToTheFuture.category);
    assert.strictEqual(LastWordContent.countOccurrences(backToTheFuture.answer, 'I'), 0); // no I in this answer

    const result = LastWordRound.purchaseVowel(round, backToTheFuture, 'I', 500);
    assert.strictEqual(result.changed, true);
    assert.strictEqual(result.present, false);
    assert.strictEqual(result.roundState.pointsThisRound, -100); // charged regardless of the miss
    assert.strictEqual(result.roundState.strikes, 0); // no strike for a vowel miss
});

check('vowel purchase reveals every occurrence on a hit', () => {
    const round = LastWordState.createRoundState(1, backToTheFuture.id, backToTheFuture.category);
    const occurrences = LastWordContent.countOccurrences(backToTheFuture.answer, 'E');
    assert.ok(occurrences >= 2);

    const result = LastWordRound.purchaseVowel(round, backToTheFuture, 'E', 500);
    assert.strictEqual(result.present, true);
    assert.strictEqual(result.roundState.pointsThisRound, -100); // cost only; vowels award no per-occurrence points
    const board = LastWordRound.buildDisplayBoard(backToTheFuture, result.roundState);
    const ePositions = [...backToTheFuture.answer].map((c, i) => (c === 'E' ? i : -1)).filter((i) => i !== -1);
    ePositions.forEach((i) => assert.strictEqual(board[i].char, 'E'));
});

check('an unaffordable vowel purchase is cleanly rejected without charging or revealing', () => {
    const round = LastWordState.createRoundState(1, backToTheFuture.id, backToTheFuture.category);
    assert.strictEqual(LastWordRound.canAffordVowel(99), false);

    const result = LastWordRound.purchaseVowel(round, backToTheFuture, 'A', 99);
    assert.strictEqual(result.changed, false);
    assert.strictEqual(result.reason, 'insufficient-score');
    assert.strictEqual(result.roundState.pointsThisRound, 0);
    assert.deepStrictEqual(result.roundState.purchasedVowels, []);
});

check('an already-purchased vowel is a no-op', () => {
    let round = LastWordState.createRoundState(1, backToTheFuture.id, backToTheFuture.category);
    round = LastWordRound.purchaseVowel(round, backToTheFuture, 'A', 500).roundState;
    const pointsAfterFirst = round.pointsThisRound;

    const repeat = LastWordRound.purchaseVowel(round, backToTheFuture, 'a', 500);
    assert.strictEqual(repeat.changed, false);
    assert.strictEqual(repeat.reason, 'already-purchased');
    assert.strictEqual(repeat.roundState.pointsThisRound, pointsAfterFirst);
});

check('six strikes from wrong consonants ends the round as struck-out', () => {
    let round = LastWordState.createRoundState(1, backToTheFuture.id, backToTheFuture.category);
    const wrongLetters = ['Q', 'X', 'Z', 'J', 'V', 'W']; // none present in "BACK TO THE FUTURE"
    wrongLetters.forEach((letter) => {
        assert.strictEqual(LastWordContent.countOccurrences(backToTheFuture.answer, letter), 0);
        round = LastWordRound.guessConsonant(round, backToTheFuture, letter).roundState;
    });
    assert.strictEqual(round.strikes, 6);
    assert.strictEqual(round.outcome, 'struck-out');

    // Round is over: further guesses are rejected as no-ops.
    const afterOver = LastWordRound.guessConsonant(round, backToTheFuture, 'B');
    assert.strictEqual(afterOver.changed, false);
    assert.strictEqual(afterOver.reason, 'round-over');
});

check('SOLVE normalization ignores case, whitespace, and punctuation differences', () => {
    assert.strictEqual(
        LastWordRound.normalizeForCompare("don't cry over spilled milk"),
        LastWordRound.normalizeForCompare('DONT CRY OVER SPILLED MILK')
    );
    assert.strictEqual(
        LastWordRound.normalizeForCompare('  Back To The Future '),
        LastWordRound.normalizeForCompare('BACKTOTHEFUTURE')
    );
    assert.notStrictEqual(
        LastWordRound.normalizeForCompare('BACK TO THE PAST'),
        LastWordRound.normalizeForCompare(backToTheFuture.answer)
    );
});

check('a correct solve ends the round, reveals everything, and awards the current bonus', () => {
    const round = LastWordState.createRoundState(1, backToTheFuture.id, backToTheFuture.category);
    const result = LastWordRound.attemptSolve(round, backToTheFuture, 'back to the future');

    assert.strictEqual(result.correct, true);
    assert.strictEqual(result.bonusAwarded, 1500); // no actions taken yet -> full bonus
    assert.strictEqual(result.roundState.outcome, 'solved');
    assert.strictEqual(result.roundState.pointsThisRound, 1500);

    const board = LastWordRound.buildDisplayBoard(backToTheFuture, result.roundState);
    assert.ok(board.every((c) => c.revealed));
});

check('a wrong solve costs two strikes and does not end the round below strikeout', () => {
    const round = LastWordState.createRoundState(1, backToTheFuture.id, backToTheFuture.category);
    const result = LastWordRound.attemptSolve(round, backToTheFuture, 'totally wrong guess');

    assert.strictEqual(result.correct, false);
    assert.strictEqual(result.roundState.strikes, 2);
    assert.strictEqual(result.roundState.outcome, null); // round continues
    assert.strictEqual(result.roundState.pointsThisRound, 0);
});

check('a wrong solve that reaches 6 strikes ends the round as struck-out', () => {
    let round = LastWordState.createRoundState(1, backToTheFuture.id, backToTheFuture.category);
    round = LastWordRound.guessConsonant(round, backToTheFuture, 'Q').roundState; // strike 1
    round = LastWordRound.guessConsonant(round, backToTheFuture, 'X').roundState; // strike 2
    round = LastWordRound.guessConsonant(round, backToTheFuture, 'Z').roundState; // strike 3
    round = LastWordRound.guessConsonant(round, backToTheFuture, 'J').roundState; // strike 4

    const result = LastWordRound.attemptSolve(round, backToTheFuture, 'nope'); // +2 -> 6
    assert.strictEqual(result.roundState.strikes, 6);
    assert.strictEqual(result.roundState.outcome, 'struck-out');
});

check('SOLVE is rejected once the round is already over (no double-processing)', () => {
    let round = LastWordState.createRoundState(1, backToTheFuture.id, backToTheFuture.category);
    round = LastWordRound.attemptSolve(round, backToTheFuture, 'back to the future').roundState;
    const pointsAfterSolve = round.pointsThisRound;

    const secondAttempt = LastWordRound.attemptSolve(round, backToTheFuture, 'back to the future');
    assert.strictEqual(secondAttempt.changed, false);
    assert.strictEqual(secondAttempt.reason, 'round-over');
    assert.strictEqual(secondAttempt.roundState.pointsThisRound, pointsAfterSolve);
});

console.log('--- solve-bonus decay scenarios ---');

check('solving with zero actions taken awards the full 1500 bonus', () => {
    const round = LastWordState.createRoundState(1, backToTheFuture.id, backToTheFuture.category);
    assert.strictEqual(LastWordRound.computeSolveBonus(round), 1500);
});

check('decay: 150 per action, deterministic, floors at 0, never negative', () => {
    let round = LastWordState.createRoundState(1, backToTheFuture.id, backToTheFuture.category);
    assert.strictEqual(LastWordRound.computeSolveBonus(round), 1500);

    round = LastWordRound.guessConsonant(round, backToTheFuture, 'T').roundState; // 1 action
    assert.strictEqual(LastWordRound.computeSolveBonus(round), 1350);

    round = LastWordRound.guessConsonant(round, backToTheFuture, 'H').roundState; // 2 actions
    assert.strictEqual(LastWordRound.computeSolveBonus(round), 1200);

    // Drive past the point where the formula would go negative without the
    // floor, using every remaining CORRECT letter (consonants + purchased
    // vowels) so strikeout does not cut the action count short.
    const remainingConsonants = backToTheFuture.guessableLetters
        .filter(LastWordContent.isConsonant)
        .filter((l) => l !== 'T' && l !== 'H');
    remainingConsonants.forEach((letter) => {
        round = LastWordRound.guessConsonant(round, backToTheFuture, letter).roundState;
    });
    backToTheFuture.guessableLetters.filter(LastWordContent.isVowel).forEach((letter) => {
        round = LastWordRound.purchaseVowel(round, backToTheFuture, letter, 100000).roundState;
    });
    assert.strictEqual(round.strikes, 0); // every guessed/bought letter is actually present
    assert.ok(LastWordRound.actionsTaken(round) * 150 > 1500, 'expected enough actions to exceed the 1500 ceiling');
    assert.strictEqual(LastWordRound.computeSolveBonus(round), 0);
});

check('farming ALL remaining consonants+vowels then solving scores far less than solving immediately', () => {
    // "BACK TO THE FUTURE" guessable letters, split into consonants/vowels.
    const consonants = backToTheFuture.guessableLetters.filter(LastWordContent.isConsonant);
    const vowels = backToTheFuture.guessableLetters.filter(LastWordContent.isVowel);

    let round = LastWordState.createRoundState(1, backToTheFuture.id, backToTheFuture.category);
    consonants.forEach((letter) => {
        round = LastWordRound.guessConsonant(round, backToTheFuture, letter).roundState;
    });
    vowels.forEach((letter) => {
        // availableScore is generous here on purpose: this scenario is testing
        // whether farming *beats* solving immediately, not affordability.
        round = LastWordRound.purchaseVowel(round, backToTheFuture, letter, 100000).roundState;
    });
    assert.strictEqual(round.strikes, 0); // every letter present in this answer, all correct

    const solveResult = LastWordRound.attemptSolve(round, backToTheFuture, backToTheFuture.answer);
    const farmedTotal = solveResult.roundState.pointsThisRound;
    const immediateTotal = 1500; // solving from a fresh round

    assert.ok(
        farmedTotal < immediateTotal,
        `expected farming (${farmedTotal}) to score less than solving immediately (${immediateTotal})`
    );
});

check('farming ALL consonants+vowels across a second, differently-shaped puzzle also scores less than solving immediately', () => {
    const puzzle = LastWordContent.byId(puzzles, 'sayings-0001'); // "DON'T CRY OVER SPILLED MILK" — longer, more letters
    const consonants = puzzle.guessableLetters.filter(LastWordContent.isConsonant);
    const vowels = puzzle.guessableLetters.filter(LastWordContent.isVowel);

    let round = LastWordState.createRoundState(1, puzzle.id, puzzle.category);
    consonants.forEach((letter) => {
        round = LastWordRound.guessConsonant(round, puzzle, letter).roundState;
    });
    vowels.forEach((letter) => {
        round = LastWordRound.purchaseVowel(round, puzzle, letter, 100000).roundState;
    });

    const solveResult = LastWordRound.attemptSolve(round, puzzle, puzzle.answer);
    assert.ok(
        solveResult.roundState.pointsThisRound < 1500,
        `expected farmed total (${solveResult.roundState.pointsThisRound}) < 1500`
    );
});

check('a single early, cheap guess before solving still scores less than or comparable to solving immediately', () => {
    // A modest amount of farming (one correct single-occurrence consonant)
    // should not come close to beating an immediate solve.
    let round = LastWordState.createRoundState(1, backToTheFuture.id, backToTheFuture.category);
    const singleOccurrenceConsonant = backToTheFuture.guessableLetters
        .filter(LastWordContent.isConsonant)
        .find((l) => LastWordContent.countOccurrences(backToTheFuture.answer, l) === 1);
    assert.ok(singleOccurrenceConsonant, 'expected at least one single-occurrence consonant for this test');

    round = LastWordRound.guessConsonant(round, backToTheFuture, singleOccurrenceConsonant).roundState;
    const result = LastWordRound.attemptSolve(round, backToTheFuture, backToTheFuture.answer);

    assert.ok(result.roundState.pointsThisRound < 1500);
});

const commodore64 = LastWordContent.byId(puzzles, 'bbs-retro-0006'); // "COMMODORE 64"

check('digits in an answer (COMMODORE 64) are always-visible display characters, never guessable letters, and never block full reveal', () => {
    assert.strictEqual(commodore64.answer, 'COMMODORE 64');
    // Digits must never appear in guessableLetters: the player is never asked
    // to guess a digit despite having no digit controls in the UI.
    assert.deepStrictEqual(
        commodore64.guessableLetters.filter((l) => /[0-9]/.test(l)),
        []
    );

    let round = LastWordState.createRoundState(1, commodore64.id, commodore64.category);
    let board = LastWordRound.buildDisplayBoard(commodore64, round);

    // "6" and "4" (and the space) must be visible from round start, like any
    // other non-letter display character, before any letters are guessed.
    const digitIndexes = [];
    commodore64.answer.split('').forEach((ch, i) => { if (/[0-9]/.test(ch)) digitIndexes.push(i); });
    assert.ok(digitIndexes.length === 2, 'expected two digit characters in "COMMODORE 64"');
    digitIndexes.forEach((i) => {
        assert.strictEqual(board[i].isLetter, false);
        assert.strictEqual(board[i].revealed, true);
        assert.strictEqual(board[i].char, commodore64.answer[i]);
    });

    // Guessing every real letter (but never being asked about the digits)
    // must be sufficient for auto-solve/full-reveal detection to consider the
    // board fully revealed.
    commodore64.guessableLetters.filter(LastWordContent.isConsonant).forEach((letter) => {
        round = LastWordRound.guessConsonant(round, commodore64, letter).roundState;
    });
    commodore64.guessableLetters.filter(LastWordContent.isVowel).forEach((letter) => {
        round = LastWordRound.purchaseVowel(round, commodore64, letter, 100000).roundState;
    });
    assert.ok(LastWordAutoSolve.isFullyRevealed(commodore64, round.revealedLetters));

    // Full-solve comparison must accept the digits typed back correctly...
    const solved = LastWordRound.attemptSolve(round, commodore64, 'COMMODORE 64');
    assert.strictEqual(solved.correct, true);

    // ...and buildDisplayBoard must still show the digits (never masked) once
    // solved, same as every other non-letter display character.
    board = LastWordRound.buildDisplayBoard(commodore64, solved.roundState);
    digitIndexes.forEach((i) => {
        assert.strictEqual(board[i].isLetter, false);
        assert.strictEqual(board[i].revealed, true);
    });
});

console.log(`round.test.js: ${passed} passed`);
