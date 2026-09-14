/**
 * Last Word — Final Hangman rules engine (M1D)
 *
 * Pure, DOM-free rules for the Final round, on top of the M1A GameSession
 * foundation's `finalState` shape (LastWordState.createFinalState /
 * FINAL_CONFIG) rather than a second state model. Mirrors round.js's style:
 * every function returns a NEW finalState rather than mutating the one
 * passed in.
 *
 * Reuses LastWordRound.normalizeForCompare() and
 * LastWordRound.buildDisplayBoard() directly — finalState has the same
 * `revealedLetters`/`outcome` shape a normal RoundState does, so the same
 * board-projection logic applies unchanged.
 */
(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        var LastWordContent = require('./content.js');
        var LastWordState = require('./state.js');
        var LastWordRound = require('./round.js');
        module.exports = factory(LastWordContent, LastWordState, LastWordRound);
    } else {
        root.LastWordFinal = factory(root.LastWordContent, root.LastWordState, root.LastWordRound);
    }
})(typeof self !== 'undefined' ? self : this, function (LastWordContent, LastWordState, LastWordRound) {
    'use strict';

    var FINAL_CONFIG = LastWordState.FINAL_CONFIG;
    var MAX_STRIKES = LastWordState.MAX_STRIKES;

    function isFinalOver(finalState) {
        return finalState.outcome !== null;
    }

    function cloneFinalState(fs) {
        return {
            puzzleId: fs.puzzleId,
            category: fs.category,
            chosenOpeningLetters: fs.chosenOpeningLetters.slice(),
            revealedLetters: fs.revealedLetters.slice(),
            additionalLettersPurchased: fs.additionalLettersPurchased.slice(),
            spentThisFinal: fs.spentThisFinal,
            strikes: fs.strikes,
            solveAttempts: fs.solveAttempts.slice(),
            outcome: fs.outcome
        };
    }

    /** Every letter already chosen (opening) or purchased (additional) — never repurchasable. */
    function usedLetters(finalState) {
        return finalState.chosenOpeningLetters.concat(finalState.additionalLettersPurchased);
    }

    function openingCostFor(count) {
        var cost = FINAL_CONFIG.openingLetterCost[count];
        if (cost === undefined) {
            throw new Error('LastWordFinal: invalid opening letter count: ' + count);
        }
        return cost;
    }

    /**
     * Commit the player's opening-help choice: `letters` (0-3 unique A-Z
     * characters) are revealed for every occurrence where present; an
     * absent chosen letter still consumes that choice (no refund). Cost is
     * fixed by COUNT (openingLetterCost[letters.length]), not by which
     * letters are picked. Rejected as a no-op if opening help was already
     * chosen, the count is invalid, letters contains a duplicate, or the
     * cost exceeds the score available entering Final.
     */
    function purchaseOpening(finalState, puzzle, letters, availableScore) {
        if (finalState.chosenOpeningLetters.length > 0 || finalState.additionalLettersPurchased.length > 0) {
            return { finalState: finalState, changed: false, reason: 'opening-already-chosen' };
        }

        var normalized = [];
        for (var i = 0; i < letters.length; i++) {
            var letter = String(letters[i]).toUpperCase();
            if (!/^[A-Z]$/.test(letter)) {
                return { finalState: finalState, changed: false, reason: 'invalid-letter' };
            }
            if (normalized.indexOf(letter) !== -1) {
                return { finalState: finalState, changed: false, reason: 'duplicate-letter' };
            }
            normalized.push(letter);
        }

        var cost;
        try {
            cost = openingCostFor(normalized.length);
        } catch (e) {
            return { finalState: finalState, changed: false, reason: 'invalid-count' };
        }
        if (cost > availableScore) {
            return { finalState: finalState, changed: false, reason: 'insufficient-score' };
        }

        var next = cloneFinalState(finalState);
        normalized.forEach(function (letter) {
            next.chosenOpeningLetters.push(letter);
            if (LastWordContent.countOccurrences(puzzle.answer, letter) > 0) {
                next.revealedLetters.push(letter);
            }
        });
        next.spentThisFinal += cost;

        return { finalState: next, changed: true, cost: cost };
    }

    function canAffordAdditionalLetter(availableScore) {
        return availableScore >= FINAL_CONFIG.additionalLetterCost;
    }

    /**
     * Buy one additional letter during Final play. Flat cost regardless of
     * consonant/vowel; reveals every occurrence if present; an absent letter
     * still costs the full price with NO strike (matches the approved
     * product rule — buying information always carries its cost, never an
     * extra penalty for guessing wrong about presence). Rejected as a no-op
     * if already used (opening or additional), Final is already over, or
     * the cost exceeds the remaining score.
     */
    function purchaseAdditionalLetter(finalState, puzzle, letter, availableScore) {
        letter = String(letter).toUpperCase();

        if (isFinalOver(finalState)) {
            return { finalState: finalState, changed: false, reason: 'final-over' };
        }
        if (!/^[A-Z]$/.test(letter)) {
            return { finalState: finalState, changed: false, reason: 'invalid-letter' };
        }
        if (usedLetters(finalState).indexOf(letter) !== -1) {
            return { finalState: finalState, changed: false, reason: 'already-used' };
        }
        if (!canAffordAdditionalLetter(availableScore)) {
            return { finalState: finalState, changed: false, reason: 'insufficient-score' };
        }

        var next = cloneFinalState(finalState);
        next.additionalLettersPurchased.push(letter);
        var present = LastWordContent.countOccurrences(puzzle.answer, letter) > 0;
        if (present) {
            next.revealedLetters.push(letter);
        }
        next.spentThisFinal += FINAL_CONFIG.additionalLetterCost;

        return { finalState: next, changed: true, present: present };
    }

    /**
     * Attempt to solve the Final puzzle. Correct: outcome 'solved' (the
     * +5000 bonus is applied by computeFinalScore(), not stored here — this
     * keeps finalState itself just a record of what happened, not a running
     * total). Wrong: two strikes, ending Final at 6.
     */
    function attemptSolveFinal(finalState, puzzle, guessText) {
        if (isFinalOver(finalState)) {
            return { finalState: finalState, changed: false, reason: 'final-over' };
        }

        var next = cloneFinalState(finalState);
        var correct = LastWordRound.normalizeForCompare(guessText) === LastWordRound.normalizeForCompare(puzzle.answer);
        next.solveAttempts.push({ text: String(guessText), correct: correct });

        if (correct) {
            next.revealedLetters = puzzle.guessableLetters.slice();
            next.outcome = 'solved';
            return { finalState: next, changed: true, correct: true };
        }

        next.strikes += FINAL_CONFIG.wrongSolveStrikes;
        if (next.strikes >= MAX_STRIKES) {
            next.outcome = 'struck-out';
        }
        return { finalState: next, changed: true, correct: false };
    }

    /**
     * Final score = (score entering Final) - (amount spent on opening +
     * additional letters) + (5000 if solved, else 0). Never negative by
     * construction: every purchase is already gated by availableScore, so
     * spentThisFinal can never exceed scoreEnteringFinal.
     */
    function computeFinalScore(scoreEnteringFinal, finalState) {
        var bonus = finalState.outcome === 'solved' ? FINAL_CONFIG.correctSolveBonus : 0;
        return scoreEnteringFinal - finalState.spentThisFinal + bonus;
    }

    return {
        FINAL_CONFIG: FINAL_CONFIG,
        isFinalOver: isFinalOver,
        usedLetters: usedLetters,
        openingCostFor: openingCostFor,
        purchaseOpening: purchaseOpening,
        canAffordAdditionalLetter: canAffordAdditionalLetter,
        purchaseAdditionalLetter: purchaseAdditionalLetter,
        attemptSolveFinal: attemptSolveFinal,
        computeFinalScore: computeFinalScore
    };
});
