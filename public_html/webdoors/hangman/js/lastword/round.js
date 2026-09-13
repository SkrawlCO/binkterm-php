/**
 * Last Word — normal-round rules engine (Round 1 in M1B; generalized to
 * Rounds 1-4 in M1C)
 *
 * Pure rules/state-mutation logic for one playable normal round. Deliberately
 * DOM-free (no `document`) so the rules can be unit-tested under plain Node
 * and, per the M1A hygiene goal, would not need to be reinvented for a future
 * Telnet client. All functions are non-mutating: they return a NEW round
 * state rather than modifying the one passed in, matching the style already
 * used by js/lastword/state.js.
 *
 * guessConsonant()/purchaseVowel() take an optional roundConfig argument
 * (one of LastWordState.ROUND_CONFIG[1..4]) so the SAME functions drive
 * every normal round; it defaults to ROUND_CONFIG[1] so every M1B call site
 * and test keeps working unchanged. Final Hangman (a different, non-normal
 * round shape) remains out of scope. See SOLVE_BONUS_DECAY_DIVISOR below and
 * the M1C report for the per-round decay values and the reasoning.
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
     * SOLVE-BONUS DECAY FORMULA (M1B introduced it for Round 1; M1C
     * generalizes it to Rounds 1-4 — the underlying principle is unchanged):
     *
     *   remainingSolveBonus = max(0, maxSolveBonus - decayPerAction * actionsTaken)
     *   decayPerAction       = maxSolveBonus / SOLVE_BONUS_DECAY_DIVISOR
     *
     * where actionsTaken = the number of distinct letter-guess actions taken
     * so far (every consonant guess attempt, right or wrong, counts once;
     * every vowel purchase counts once — occurrences within a single action
     * do NOT multiply the decay, only the letter-guess count does).
     *
     * SOLVE_BONUS_DECAY_DIVISOR = 10 for every round, giving:
     *   Round 1: 1500/10 = 150   (unchanged from M1B)
     *   Round 2: 2000/10 = 200
     *   Round 3: 2500/10 = 250
     *   Round 4: 3000/10 = 300
     *
     * One sentence for the UI: "Solve bonus decays by 1/10th of this round's
     * maximum for every letter you guess or buy (never below 0)."
     *
     * Why tie decay to maxSolveBonus (not to consonantValue, as M1B's "1.5x"
     * framing suggested) — a fixed multiple of consonantValue looked right in
     * isolation but drifts out of proportion across rounds because
     * maxSolveBonus does NOT grow at the same rate as consonantValue (1500,
     * 2000, 2500, 3000 vs. 100, 150, 200, 250): the same 1.5x-of-consonant
     * decay was verified to let a long, letter-dense puzzle occasionally beat
     * an immediate solve in Rounds 3-4. Tying decay to maxSolveBonus instead
     * keeps the "how many actions before the bonus is gone" ratio identical
     * across all four rounds, so the incentive strength does not quietly
     * weaken in the higher-stakes rounds. See the M1C report for the
     * worked-through scenarios (verified against all 16 seed puzzles across
     * all 4 rounds) and the one known remaining edge case.
     */
    var SOLVE_BONUS_DECAY_DIVISOR = 10;
    var SOLVE_BONUS_DECAY_PER_ACTION = ROUND1_CONFIG.maxSolveBonus / SOLVE_BONUS_DECAY_DIVISOR; // 150, kept for M1B compatibility

    function decayPerActionFor(maxSolveBonus) {
        return maxSolveBonus / SOLVE_BONUS_DECAY_DIVISOR;
    }

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
        decayPerAction = decayPerAction != null ? decayPerAction : decayPerActionFor(maxSolveBonus);
        return Math.max(0, maxSolveBonus - decayPerAction * actionsTaken(roundState));
    }

    /**
     * Guess a consonant. No direct point cost to guess; a correct guess
     * reveals every occurrence and awards consonantValue x occurrences; an
     * incorrect guess costs one strike. Already-guessed letters and
     * non-consonants are rejected as no-ops (changed:false) rather than
     * silently reprocessed.
     */
    function guessConsonant(roundState, puzzle, letter, roundConfig) {
        roundConfig = roundConfig || ROUND1_CONFIG;
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
            next.pointsThisRound += occurrences * roundConfig.consonantValue;
        } else {
            next.wrongGuesses.push(letter);
            next.strikes += 1;
        }

        if (next.strikes >= MAX_STRIKES) {
            next.outcome = 'struck-out';
        }

        return { roundState: next, changed: true, correct: correct, occurrences: occurrences };
    }

    function canAffordVowel(availableScore, roundConfig) {
        roundConfig = roundConfig || ROUND1_CONFIG;
        return availableScore >= roundConfig.vowelCost;
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
    function purchaseVowel(roundState, puzzle, letter, availableScore, roundConfig) {
        roundConfig = roundConfig || ROUND1_CONFIG;
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
        if (!canAffordVowel(availableScore, roundConfig)) {
            return { roundState: roundState, changed: false, reason: 'insufficient-score' };
        }

        var next = cloneRoundState(roundState);
        next.purchasedVowels.push(letter);
        next.pointsThisRound -= roundConfig.vowelCost;

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
        var decayPerAction = options.decayPerAction != null ? options.decayPerAction : decayPerActionFor(maxSolveBonus);

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
        SOLVE_BONUS_DECAY_DIVISOR: SOLVE_BONUS_DECAY_DIVISOR,
        decayPerActionFor: decayPerActionFor,
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
