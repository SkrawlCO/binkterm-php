/**
 * Last Word — AUTO-SOLVE ON FULL REVEAL (core-game blocker fix).
 *
 * Human playtest found: during Final Hangman, revealing every letter in
 * "GALILEO GALILEI" left the board fully visible but the game did NOT
 * recognize completion — the player had to open SOLVE and retype the
 * already-visible answer by hand. The same defect exists in Rounds 1-4 for
 * exactly the same reason.
 *
 * ROOT CAUSE: round.js's guessConsonant()/purchaseVowel(), final.js's
 * purchaseAdditionalLetter(), and this build's own hint.js's
 * purchaseHintLetter() all reveal letters into revealedLetters, but none of
 * them ever checks whether that reveal just completed the puzzle — only
 * attemptSolve()/attemptSolveFinal() (an explicit typed answer) ever sets
 * outcome:'solved'. So a board can read as 100% revealed while
 * round.outcome/finalState.outcome is still null.
 *
 * FIX, at the state level (not the DOM): this module is a small, ISOLATED,
 * additive module — like js/lastword/hint.js, it is NOT an edit to
 * round.js/final.js/state.js, which stay byte-identical (so accepted M1D/
 * M1C, which load those same files, are provably unaffected). It reuses
 * those files' own canonical completion math instead of reinventing it:
 * applyRoundAutoSolve() awards the bonus via LastWordRound's own
 * computeSolveBonus()/decayPerActionFor() — the exact formula
 * attemptSolve() itself uses — computed from the round's CURRENT state
 * (post the action that just completed the reveal), so a round that
 * auto-completes scores identically to a player who typed the correct
 * answer at that same moment. applyFinalAutoSolve() just sets
 * finalState.outcome:'solved' (finalState stores no bonus itself —
 * computeFinalScore() already derives the +5000 from outcome, so no
 * separate math is needed there).
 *
 * Both functions are no-ops (return the SAME object reference) whenever the
 * round/Final is already over or is not yet fully revealed — a caller can
 * unconditionally call one of these after every action that might reveal a
 * letter and only needs to follow it with the SAME
 * isRoundOver()/isFinalOver() check + finish-round call it already uses for
 * an explicit solve, so the canonical completion path (scoring, session
 * history, Skippy SAVED, the payoff hold, the one-time screen transition)
 * runs exactly once, unchanged, regardless of whether the puzzle ended via
 * a typed answer or via its last letter simply being revealed.
 *
 * Deliberately pure/DOM-free like every other js/lastword/*.js rules file.
 */
(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        var LastWordRound = require('./round.js');
        var LastWordFinal = require('./final.js');
        module.exports = factory(LastWordRound, LastWordFinal);
    } else {
        root.LastWordAutoSolve = factory(root.LastWordRound, root.LastWordFinal);
    }
})(typeof self !== 'undefined' ? self : this, function (LastWordRound, LastWordFinal) {
    'use strict';

    /**
     * True once every one of the puzzle's guessable letters is present in
     * `revealedLetters` — the same fact the board rendering itself already
     * reflects (buildDisplayBoard() reveals a letter cell exactly when its
     * character is in this same array), just read from state rather than
     * from rendered DOM text.
     */
    function isFullyRevealed(puzzle, revealedLetters) {
        return puzzle.guessableLetters.every(function (letter) {
            return revealedLetters.indexOf(letter) !== -1;
        });
    }

    /**
     * Round-scoped (Rounds 1-4) auto-solve. Returns `roundState` unchanged
     * (same reference) if the round is already over or not yet fully
     * revealed. Otherwise returns a NEW roundState with outcome:'solved'
     * and the current solve bonus (per roundConfig.maxSolveBonus, decayed
     * by the actions already taken — identical to what attemptSolve() would
     * award for a correct answer submitted at this exact moment) added to
     * pointsThisRound. Non-mutating, matching every round.js function.
     */
    function applyRoundAutoSolve(roundState, puzzle, roundConfig) {
        if (LastWordRound.isRoundOver(roundState)) { return roundState; }
        if (!isFullyRevealed(puzzle, roundState.revealedLetters)) { return roundState; }

        var decayPerAction = LastWordRound.decayPerActionFor(roundConfig.maxSolveBonus);
        var bonus = LastWordRound.computeSolveBonus(roundState, roundConfig.maxSolveBonus, decayPerAction);

        return {
            round: roundState.round,
            puzzleId: roundState.puzzleId,
            category: roundState.category,
            // Already complete by the precondition above; re-assigning from
            // puzzle.guessableLetters (rather than roundState.revealedLetters)
            // mirrors attemptSolve()'s own normalization and costs nothing
            // since the two sets are already equal here.
            revealedLetters: puzzle.guessableLetters.slice(),
            consonantsGuessed: roundState.consonantsGuessed.slice(),
            purchasedVowels: roundState.purchasedVowels.slice(),
            wrongGuesses: roundState.wrongGuesses.slice(),
            strikes: roundState.strikes,
            pointsThisRound: roundState.pointsThisRound + bonus,
            solveAttempts: roundState.solveAttempts.slice(),
            outcome: 'solved'
        };
    }

    /**
     * Final-Hangman-scoped auto-solve. Returns `finalState` unchanged (same
     * reference) if Final is already over or not yet fully revealed.
     * Otherwise returns a NEW finalState with outcome:'solved' (finalState
     * carries no stored bonus of its own — computeFinalScore() already
     * applies FINAL_CONFIG.correctSolveBonus purely from outcome, so there
     * is nothing further to compute here). Non-mutating, matching final.js.
     */
    function applyFinalAutoSolve(finalState, puzzle) {
        if (LastWordFinal.isFinalOver(finalState)) { return finalState; }
        if (!isFullyRevealed(puzzle, finalState.revealedLetters)) { return finalState; }

        return {
            puzzleId: finalState.puzzleId,
            category: finalState.category,
            chosenOpeningLetters: finalState.chosenOpeningLetters.slice(),
            revealedLetters: puzzle.guessableLetters.slice(),
            additionalLettersPurchased: finalState.additionalLettersPurchased.slice(),
            spentThisFinal: finalState.spentThisFinal,
            strikes: finalState.strikes,
            solveAttempts: finalState.solveAttempts.slice(),
            outcome: 'solved'
        };
    }

    return {
        isFullyRevealed: isFullyRevealed,
        applyRoundAutoSolve: applyRoundAutoSolve,
        applyFinalAutoSolve: applyFinalAutoSolve
    };
});
