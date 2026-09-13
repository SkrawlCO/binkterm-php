/**
 * Last Word — Round 1 ("Warm-Up") rules engine (M1B)
 *
 * Pure rules/state-mutation logic for exactly ONE playable normal round.
 * Deliberately DOM-free (no `document`) so the rules can be unit-tested
 * under plain Node and, per the M1A hygiene goal, would not need to be
 * reinvented for a future Telnet client. All functions are non-mutating:
 * they return a NEW round state rather than modifying the one passed in,
 * matching the style already used by js/lastword/state.js.
 *
 * Only Round 1 values/rules are implemented here (Rounds 2-4 and Final
 * Hangman remain out of scope for M1B). See ROUND1_CONFIG /
 * SOLVE_BONUS_DECAY_PER_ACTION below and the M1B report for the exact
 * numbers and the reasoning behind the decay formula.
 */
(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        var LastWordContent = require('./content.js');
        var LastWordState = require('./state.js');
        module.exports = factory(LastWordContent, LastWordState);
    } else {
        root.LastWordRound = factory(root.LastWordContent, root.LastWordState);
    }
})(typeof self !== 'undefined' ? self : this, function (LastWordContent, LastWordState) {
    'use strict';

    var ROUND1_CONFIG = LastWordState.ROUND_CONFIG[1]; // { name, categorySelection, consonantValue, vowelCost, maxSolveBonus }

    /**
     * SOLVE-BONUS DECAY FORMULA (M1B; Round 1 only — Rounds 2-4 remain
     * unresolved per the product approval):
     *
     *   remainingSolveBonus = max(0, maxSolveBonus - DECAY_PER_ACTION * actionsTaken)
     *
     * where actionsTaken = the number of distinct letter-guess actions taken
     * so far (every consonant guess attempt, right or wrong, counts once;
     * every vowel purchase counts once — occurrences within a single action
     * do NOT multiply the decay, only the letter-guess count does).
     *
     * DECAY_PER_ACTION = 150 for Round 1 (maxSolveBonus 1500, consonant
     * value/vowel cost 100). One sentence for the UI: "Solve bonus: 1500,
     * minus 150 for every letter you guess or buy (never below 0)."
     *
     * Why 150 (1.5x the 100-point consonant/vowel value), not something
     * matching the payout 1:1: a 1:1 decay (100 per action) merely breaks
     * even with farming — earn 100, lose 100 bonus, net identical to solving
     * immediately — which does not create a "solve now" incentive, only a
     * "doesn't matter" one. Decaying faster than the per-action payout
     * average makes sustained farming strictly worse in the typical case,
     * while staying simple, fixed, and puzzle-independent (see the M1B
     * report for the worked-through scenarios that verified this against
     * the seed puzzles).
     */
    var SOLVE_BONUS_DECAY_PER_ACTION = 150;

    var MAX_STRIKES = LastWordState.MAX_STRIKES;

    function isRoundOver(roundState) {
        return roundState.outcome !== null;
    }

    function cloneRoundState(rs) {
        return {
            round: rs.round,
            puzzleId: rs.puzzleId,
            category: rs.category,
            revealedLetters: rs.revealedLetters.slice(),
            consonantsGuessed: rs.consonantsGuessed.slice(),
            purchasedVowels: rs.purchasedVowels.slice(),
            wrongGuesses: rs.wrongGuesses.slice(),
            strikes: rs.strikes,
            pointsThisRound: rs.pointsThisRound,
            solveAttempts: rs.solveAttempts.slice(),
            outcome: rs.outcome
        };
    }

    function actionsTaken(roundState) {
        return roundState.consonantsGuessed.length + roundState.purchasedVowels.length;
    }

    function computeSolveBonus(roundState, maxSolveBonus, decayPerAction) {
        maxSolveBonus = maxSolveBonus != null ? maxSolveBonus : ROUND1_CONFIG.maxSolveBonus;
        decayPerAction = decayPerAction != null ? decayPerAction : SOLVE_BONUS_DECAY_PER_ACTION;
        return Math.max(0, maxSolveBonus - decayPerAction * actionsTaken(roundState));
    }

    /**
     * Guess a consonant. No direct point cost to guess; a correct guess
     * reveals every occurrence and awards consonantValue x occurrences; an
     * incorrect guess costs one strike. Already-guessed letters and
     * non-consonants are rejected as no-ops (changed:false) rather than
     * silently reprocessed.
     */
    function guessConsonant(roundState, puzzle, letter) {
        letter = String(letter).toUpperCase();

        if (isRoundOver(roundState)) {
            return { roundState: roundState, changed: false, reason: 'round-over' };
        }
        if (!/^[A-Z]$/.test(letter) || !LastWordContent.isConsonant(letter)) {
            return { roundState: roundState, changed: false, reason: 'not-a-consonant' };
        }
        if (roundState.consonantsGuessed.indexOf(letter) !== -1) {
            return { roundState: roundState, changed: false, reason: 'already-guessed' };
        }

        var next = cloneRoundState(roundState);
        next.consonantsGuessed.push(letter);

        var occurrences = LastWordContent.countOccurrences(puzzle.answer, letter);
        var correct = occurrences > 0;

        if (correct) {
            next.revealedLetters.push(letter);
            next.pointsThisRound += occurrences * ROUND1_CONFIG.consonantValue;
        } else {
            next.wrongGuesses.push(letter);
            next.strikes += 1;
        }

        if (next.strikes >= MAX_STRIKES) {
            next.outcome = 'struck-out';
        }

        return { roundState: next, changed: true, correct: correct, occurrences: occurrences };
    }

    function canAffordVowel(availableScore) {
        return availableScore >= ROUND1_CONFIG.vowelCost;
    }

    /**
     * Purchase a vowel. Cost is charged whether or not the vowel is present
     * in the answer (chosen product default — buying information always
     * carries its 100-point cost/risk); a miss adds no additional strike.
     * Rejected as a no-op if already purchased, not a vowel, the round is
     * over, or the caller cannot afford it — the caller is expected to have
     * already disabled the control in that last case, but the check is
     * enforced here too so this can never go negative.
     */
    function purchaseVowel(roundState, puzzle, letter, availableScore) {
        letter = String(letter).toUpperCase();

        if (isRoundOver(roundState)) {
            return { roundState: roundState, changed: false, reason: 'round-over' };
        }
        if (!/^[A-Z]$/.test(letter) || !LastWordContent.isVowel(letter)) {
            return { roundState: roundState, changed: false, reason: 'not-a-vowel' };
        }
        if (roundState.purchasedVowels.indexOf(letter) !== -1) {
            return { roundState: roundState, changed: false, reason: 'already-purchased' };
        }
        if (!canAffordVowel(availableScore)) {
            return { roundState: roundState, changed: false, reason: 'insufficient-score' };
        }

        var next = cloneRoundState(roundState);
        next.purchasedVowels.push(letter);
        next.pointsThisRound -= ROUND1_CONFIG.vowelCost;

        var occurrences = LastWordContent.countOccurrences(puzzle.answer, letter);
        var present = occurrences > 0;
        if (present) {
            next.revealedLetters.push(letter);
        }

        return { roundState: next, changed: true, present: present, occurrences: occurrences };
    }

    /**
     * Comparison normalization for SOLVE: uppercase, then strip everything
     * that is not A-Z or 0-9. This intentionally ignores case, whitespace
     * differences, and decorative punctuation (apostrophes, commas, etc.)
     * so the player is not penalized for not reproducing punctuation exactly
     * while the letters themselves are otherwise correct.
     */
    function normalizeForCompare(text) {
        return String(text).toUpperCase().replace(/[^A-Z0-9]/g, '');
    }

    /**
     * Attempt to solve the whole puzzle. Correct: round ends 'solved' and
     * the current (decayed) solve bonus is added to pointsThisRound. Wrong:
     * two strikes, round continues unless that reaches strikeout.
     */
    function attemptSolve(roundState, puzzle, guessText, options) {
        options = options || {};
        var maxSolveBonus = options.maxSolveBonus != null ? options.maxSolveBonus : ROUND1_CONFIG.maxSolveBonus;
        var decayPerAction = options.decayPerAction != null ? options.decayPerAction : SOLVE_BONUS_DECAY_PER_ACTION;

        if (isRoundOver(roundState)) {
            return { roundState: roundState, changed: false, reason: 'round-over' };
        }

        var next = cloneRoundState(roundState);
        var correct = normalizeForCompare(guessText) === normalizeForCompare(puzzle.answer);
        next.solveAttempts.push({ text: String(guessText), correct: correct });

        if (correct) {
            var bonus = computeSolveBonus(roundState, maxSolveBonus, decayPerAction);
            next.pointsThisRound += bonus;
            next.revealedLetters = puzzle.guessableLetters.slice();
            next.outcome = 'solved';
            return { roundState: next, changed: true, correct: true, bonusAwarded: bonus };
        }

        next.strikes += 2;
        if (next.strikes >= MAX_STRIKES) {
            next.outcome = 'struck-out';
        }
        return { roundState: next, changed: true, correct: false, bonusAwarded: 0 };
    }

    /**
     * Project the puzzle answer into a display board: display characters
     * (spaces, punctuation) are always shown; letters are masked until
     * revealed, or fully revealed once the round has ended (solved or
     * struck out) so the answer is shown either way.
     */
    function buildDisplayBoard(puzzle, roundState) {
        var revealAll = roundState.outcome === 'solved' || roundState.outcome === 'struck-out';
        var revealedSet = {};
        roundState.revealedLetters.forEach(function (letter) { revealedSet[letter] = true; });

        return puzzle.answer.split('').map(function (ch) {
            var isLetter = /^[A-Z]$/.test(ch);
            if (!isLetter) {
                return { char: ch, isLetter: false, revealed: true };
            }
            var revealed = revealAll || !!revealedSet[ch];
            return { char: revealed ? ch : null, isLetter: true, revealed: revealed };
        });
    }

    return {
        ROUND1_CONFIG: ROUND1_CONFIG,
        SOLVE_BONUS_DECAY_PER_ACTION: SOLVE_BONUS_DECAY_PER_ACTION,
        MAX_STRIKES: MAX_STRIKES,
        isRoundOver: isRoundOver,
        actionsTaken: actionsTaken,
        computeSolveBonus: computeSolveBonus,
        guessConsonant: guessConsonant,
        canAffordVowel: canAffordVowel,
        purchaseVowel: purchaseVowel,
        normalizeForCompare: normalizeForCompare,
        attemptSolve: attemptSolve,
        buildDisplayBoard: buildDisplayBoard
    };
});
