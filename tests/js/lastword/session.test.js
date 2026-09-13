/**
 * Plain Node assertions for js/lastword/session.js (M1C four-round
 * orchestration) plus round.js's generalized per-round config support.
 * Run: node tests/js/lastword/session.test.js
 */
'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');

const LastWordContent = require('../../../public_html/webdoors/hangman/js/lastword/content.js');
const LastWordState = require('../../../public_html/webdoors/hangman/js/lastword/state.js');
const LastWordRound = require('../../../public_html/webdoors/hangman/js/lastword/round.js');
const LastWordSession = require('../../../public_html/webdoors/hangman/js/lastword/session.js');

const puzzlesPath = path.join(
    __dirname, '../../../public_html/webdoors/hangman/lastword/puzzles.json'
);
const puzzles = LastWordContent.loadPuzzleSet(JSON.parse(fs.readFileSync(puzzlesPath, 'utf8')));
const allCategories = LastWordContent.listCategories(puzzles);

let passed = 0;
function check(name, fn) {
    fn();
    passed++;
    console.log('  ok - ' + name);
}

console.log('session.test.js');

// --- category selection ---------------------------------------------------

check('pickAutoCategory prefers an unused category', () => {
    const used = [allCategories[0]];
    for (let i = 0; i < 30; i++) {
        const picked = LastWordSession.pickAutoCategory(allCategories, used);
        assert.notStrictEqual(picked, used[0]);
    }
});

check('pickAutoCategory falls back gracefully once every category is used', () => {
    const picked = LastWordSession.pickAutoCategory(allCategories, allCategories);
    assert.ok(allCategories.includes(picked)); // does not throw/return nothing
});

check('offerCategoryChoices returns 3 distinct categories, unused ones first', () => {
    const used = [allCategories[0], allCategories[1]];
    for (let i = 0; i < 15; i++) {
        const offered = LastWordSession.offerCategoryChoices(allCategories, used, 3);
        assert.strictEqual(offered.length, 3);
        assert.strictEqual(new Set(offered).size, 3); // no duplicates
        const unusedOffered = offered.filter((c) => !used.includes(c));
        assert.ok(unusedOffered.length > 0, 'expected at least one unused category offered');
    }
});

check('offerCategoryChoices degrades gracefully when fewer categories exist than requested', () => {
    const tinyCategoryList = allCategories.slice(0, 2);
    const offered = LastWordSession.offerCategoryChoices(tinyCategoryList, [], 3);
    assert.strictEqual(offered.length, 2); // never pads with fake categories
});

// --- session-scoped puzzle dedup -------------------------------------------

check('dealPuzzle never repeats a puzzle already used this session', () => {
    let session = LastWordState.createSession();
    const category = 'BBS & Retro'; // only 2 seed puzzles in this category
    const dealt = [];

    let { session: s1, puzzle: p1 } = LastWordSession.dealRound(session, puzzles, 1, category);
    s1 = LastWordSession.finishRound(s1, { ...s1.currentRound, pointsThisRound: 0, outcome: 'solved' });
    dealt.push(p1.id);

    const { puzzle: p2 } = LastWordSession.dealRound(s1, puzzles, 2, category);
    dealt.push(p2.id);

    assert.notStrictEqual(p1.id, p2.id);
});

check('dealPuzzle falls back gracefully rather than failing when a category is exhausted', () => {
    let session = LastWordState.createSession();
    const category = 'BBS & Retro'; // exactly 2 puzzles
    const inCategory = LastWordContent.filterByCategory(puzzles, category);
    assert.strictEqual(inCategory.length, 2);

    // Simulate both already used this session.
    session.rounds = inCategory.map((p, i) => ({ round: i + 1, puzzleId: p.id, category, outcome: 'solved' }));

    const dealt = LastWordSession.dealPuzzle(puzzles, category, session);
    assert.strictEqual(dealt.category, category); // still returns a real puzzle, does not throw
});

// --- full four-round flow ---------------------------------------------------

function playRoundToSolve(session, puzzles, roundNumber, category) {
    const { session: dealtSession, puzzle } = LastWordSession.dealRound(session, puzzles, roundNumber, category);
    const roundConfig = LastWordState.ROUND_CONFIG[roundNumber];
    const solveResult = LastWordRound.attemptSolve(dealtSession.currentRound, puzzle, puzzle.answer, {
        maxSolveBonus: roundConfig.maxSolveBonus,
        decayPerAction: LastWordRound.decayPerActionFor(roundConfig.maxSolveBonus)
    });
    const finished = LastWordSession.finishRound(dealtSession, solveResult.roundState);
    return { session: finished, puzzle, roundConfig, solveResult };
}

check('a full 4-round session: round-specific values, category rules, cumulative score, completion', () => {
    let session = LastWordState.createSession();
    const categoriesSeen = [];
    const puzzleIdsSeen = [];

    // Round 1: player freely chooses.
    const round1Category = allCategories[0];
    let r1 = playRoundToSolve(session, puzzles, 1, round1Category);
    assert.strictEqual(r1.roundConfig.consonantValue, 100);
    assert.strictEqual(r1.roundConfig.vowelCost, 100);
    assert.strictEqual(r1.roundConfig.maxSolveBonus, 1500);
    assert.strictEqual(r1.solveResult.bonusAwarded, 1500); // solved immediately, 0 actions
    session = r1.session;
    categoriesSeen.push(r1.puzzle.category);
    puzzleIdsSeen.push(r1.puzzle.id);
    assert.strictEqual(session.cumulativeScore, 1500);
    assert.strictEqual(session.round, 2);

    // Round 2: game picks a category different from Round 1 (when possible).
    const round2Category = LastWordSession.pickAutoCategory(allCategories, session.categoriesUsed);
    assert.notStrictEqual(round2Category, round1Category);
    let r2 = playRoundToSolve(session, puzzles, 2, round2Category);
    assert.strictEqual(r2.roundConfig.consonantValue, 150);
    assert.strictEqual(r2.roundConfig.maxSolveBonus, 2000);
    session = r2.session;
    categoriesSeen.push(r2.puzzle.category);
    puzzleIdsSeen.push(r2.puzzle.id);
    assert.strictEqual(session.cumulativeScore, 1500 + 2000);
    assert.strictEqual(session.round, 3);

    // Round 3: three categories offered, player picks one.
    const offered3 = LastWordSession.offerCategoryChoices(allCategories, session.categoriesUsed, 3);
    assert.strictEqual(offered3.length, 3);
    const round3Category = offered3[0];
    let r3 = playRoundToSolve(session, puzzles, 3, round3Category);
    assert.strictEqual(r3.roundConfig.consonantValue, 200);
    assert.strictEqual(r3.roundConfig.maxSolveBonus, 2500);
    session = r3.session;
    categoriesSeen.push(r3.puzzle.category);
    puzzleIdsSeen.push(r3.puzzle.id);
    assert.strictEqual(session.cumulativeScore, 1500 + 2000 + 2500);
    assert.strictEqual(session.round, 4);

    // Round 4: wildcard, game picks (preferring unused).
    const round4Category = LastWordSession.pickAutoCategory(allCategories, session.categoriesUsed);
    let r4 = playRoundToSolve(session, puzzles, 4, round4Category);
    assert.strictEqual(r4.roundConfig.consonantValue, 250);
    assert.strictEqual(r4.roundConfig.maxSolveBonus, 3000);
    session = r4.session;
    categoriesSeen.push(r4.puzzle.category);
    puzzleIdsSeen.push(r4.puzzle.id);

    // No score double-counting: cumulative == sum of each round's own points exactly once.
    assert.strictEqual(session.cumulativeScore, 1500 + 2000 + 2500 + 3000);
    assert.strictEqual(session.rounds.length, 4);
    assert.strictEqual(new Set(puzzleIdsSeen).size, 4); // no puzzle repeated across the session
    assert.strictEqual(session.round, 4); // stays at 4, does not roll to 5
    assert.ok(LastWordSession.isSessionComplete(session));
});

check('a struck-out round keeps its legitimately-earned points and the session continues', () => {
    let session = LastWordState.createSession();
    const category = allCategories[0];
    const { session: dealtSession, puzzle } = LastWordSession.dealRound(session, puzzles, 1, category);

    // Earn some real points from a correct consonant, then strike out on purpose.
    let roundState = dealtSession.currentRound;
    const correctLetter = puzzle.guessableLetters.find((l) => LastWordContent.isConsonant(l));
    roundState = LastWordRound.guessConsonant(roundState, puzzle, correctLetter, LastWordState.ROUND_CONFIG[1]).roundState;
    const earnedPoints = roundState.pointsThisRound;
    assert.ok(earnedPoints > 0);

    // Six wrong guesses to force strikeout (letters guaranteed absent from any of our seed answers).
    const wrongLetters = ['Q', 'X', 'Z', 'J', 'W', 'V'].filter((l) => l !== correctLetter);
    wrongLetters.forEach((l) => {
        if (!LastWordRound.isRoundOver(roundState)) {
            roundState = LastWordRound.guessConsonant(roundState, puzzle, l, LastWordState.ROUND_CONFIG[1]).roundState;
        }
    });
    assert.strictEqual(roundState.outcome, 'struck-out');
    assert.strictEqual(roundState.pointsThisRound, earnedPoints); // no bonus, but earned points intact

    const finished = LastWordSession.finishRound(dealtSession, roundState);
    assert.strictEqual(finished.cumulativeScore, earnedPoints); // kept, not zeroed
    assert.strictEqual(finished.round, 2); // session proceeds to the next round
    assert.strictEqual(finished.rounds[0].outcome, 'struck-out');
});

// --- M1C second human-found defect: Round 4 -> session completion --------

/** Plays one round to a forced outcome ('solved' or 'struck-out') and folds it into the session. */
function playRoundToOutcome(session, puzzles, roundNumber, category, outcome) {
    const { session: dealtSession, puzzle } = LastWordSession.dealRound(session, puzzles, roundNumber, category);
    const roundConfig = LastWordState.ROUND_CONFIG[roundNumber];
    let roundState;
    if (outcome === 'solved') {
        const result = LastWordRound.attemptSolve(dealtSession.currentRound, puzzle, puzzle.answer, {
            maxSolveBonus: roundConfig.maxSolveBonus,
            decayPerAction: LastWordRound.decayPerActionFor(roundConfig.maxSolveBonus)
        });
        roundState = result.roundState;
    } else {
        roundState = dealtSession.currentRound;
        const wrongLetters = ['Q', 'X', 'Z', 'J', 'V', 'W', 'Y', 'K']
            .filter((l) => LastWordContent.countOccurrences(puzzle.answer, l) === 0);
        for (const l of wrongLetters) {
            if (LastWordRound.isRoundOver(roundState)) break;
            roundState = LastWordRound.guessConsonant(roundState, puzzle, l, roundConfig).roundState;
        }
        assert.strictEqual(roundState.outcome, 'struck-out', 'expected the forced strikeout to actually occur');
    }
    return { session: LastWordSession.finishRound(dealtSession, roundState), puzzle };
}

/** Plays a full 4-round session to the requested per-round outcomes and returns the final session. */
function playFullSession(outcomes) {
    let session = LastWordState.createSession();
    const categoryPickers = [
        () => allCategories[0],
        () => LastWordSession.pickAutoCategory(allCategories, session.categoriesUsed),
        () => LastWordSession.offerCategoryChoices(allCategories, session.categoriesUsed, 3)[0],
        () => LastWordSession.pickAutoCategory(allCategories, session.categoriesUsed)
    ];
    for (let i = 0; i < 4; i++) {
        const category = categoryPickers[i]();
        const result = playRoundToOutcome(session, puzzles, i + 1, category, outcomes[i]);
        session = result.session;
    }
    return session;
}

check('REGRESSION: a SOLVED Round 4 reaches session complete', () => {
    const session = playFullSession(['solved', 'solved', 'solved', 'solved']);
    assert.ok(LastWordSession.isSessionComplete(session));
    assert.strictEqual(session.rounds.length, 4);
    assert.strictEqual(session.rounds[3].outcome, 'solved');
});

check('REGRESSION: a FAILED Round 4 (out of strikes) still reaches session complete — the exact human-reported path', () => {
    const session = playFullSession(['solved', 'solved', 'solved', 'struck-out']);
    assert.ok(LastWordSession.isSessionComplete(session));
    assert.strictEqual(session.rounds.length, 4);
    assert.strictEqual(session.rounds[3].outcome, 'struck-out');
    // The failed round's answer/points must still be visible in history, not discarded.
    assert.ok(session.rounds[3].pointsThisRound >= 0);
});

check('REGRESSION: a mixed solved/failed four-round session reaches session complete', () => {
    const session = playFullSession(['struck-out', 'solved', 'struck-out', 'solved']);
    assert.ok(LastWordSession.isSessionComplete(session));
    assert.strictEqual(session.rounds.length, 4);
    assert.deepStrictEqual(session.rounds.map((r) => r.outcome), ['struck-out', 'solved', 'struck-out', 'solved']);
});

check('exactly four round-history entries exist after a four-round session, one per round number, in order', () => {
    const session = playFullSession(['solved', 'struck-out', 'solved', 'struck-out']);
    assert.strictEqual(session.rounds.length, 4);
    assert.deepStrictEqual(session.rounds.map((r) => r.round), [1, 2, 3, 4]);
});

check('cumulative score after a mixed session equals the exact sum of each round\'s own points, once each', () => {
    const session = playFullSession(['struck-out', 'solved', 'struck-out', 'solved']);
    const expected = session.rounds.reduce((sum, r) => sum + r.pointsThisRound, 0);
    assert.strictEqual(session.cumulativeScore, expected);
});

check('isSessionComplete depends only on rounds.length, not on session.round', () => {
    // A session object where `round` and `rounds.length` intentionally
    // disagree (the exact shape of the human-reported symptom, whatever its
    // cause) must still be judged complete once 4 rounds are recorded.
    const session = LastWordState.createSession();
    session.round = 2; // deliberately "wrong"/stale
    session.rounds = [
        { round: 1, outcome: 'solved' }, { round: 2, outcome: 'solved' },
        { round: 3, outcome: 'solved' }, { round: 4, outcome: 'struck-out' }
    ];
    assert.ok(LastWordSession.isSessionComplete(session));
});

check('a session with fewer than 4 recorded rounds is not complete, regardless of session.round', () => {
    const session = LastWordState.createSession();
    session.round = 4; // dealt round 4, but it has not finished yet
    session.rounds = [{ round: 1, outcome: 'solved' }, { round: 2, outcome: 'solved' }, { round: 3, outcome: 'solved' }];
    assert.strictEqual(LastWordSession.isSessionComplete(session), false);
});

// --- per-round decay values --------------------------------------------------

check('decayPerActionFor matches the reported per-round values', () => {
    assert.strictEqual(LastWordRound.decayPerActionFor(1500), 150);
    assert.strictEqual(LastWordRound.decayPerActionFor(2000), 200);
    assert.strictEqual(LastWordRound.decayPerActionFor(2500), 250);
    assert.strictEqual(LastWordRound.decayPerActionFor(3000), 300);
});

check('farming every letter in Round 4 before solving still generally loses to solving immediately', () => {
    const puzzle = LastWordContent.byId(puzzles, 'games-0001'); // "THE LEGEND OF ZELDA"
    const roundConfig = LastWordState.ROUND_CONFIG[4];
    let round = LastWordState.createRoundState(4, puzzle.id, puzzle.category);

    puzzle.guessableLetters.filter(LastWordContent.isConsonant).forEach((l) => {
        round = LastWordRound.guessConsonant(round, puzzle, l, roundConfig).roundState;
    });
    puzzle.guessableLetters.filter(LastWordContent.isVowel).forEach((l) => {
        round = LastWordRound.purchaseVowel(round, puzzle, l, 100000, roundConfig).roundState;
    });

    const result = LastWordRound.attemptSolve(round, puzzle, puzzle.answer, {
        maxSolveBonus: roundConfig.maxSolveBonus,
        decayPerAction: LastWordRound.decayPerActionFor(roundConfig.maxSolveBonus)
    });

    assert.ok(
        result.roundState.pointsThisRound < roundConfig.maxSolveBonus,
        `expected farmed total (${result.roundState.pointsThisRound}) < immediate solve (${roundConfig.maxSolveBonus})`
    );
});

console.log(`session.test.js: ${passed} passed`);
