/**
 * Last Word — BUY HINT LETTER (SKIPPY playtest iteration #2).
 *
 * A score-funded guaranteed-hint mechanic for normal Rounds 1-4 only (Final
 * Hangman already has its own "buy an additional letter" flow and is
 * intentionally untouched by this module). Deliberately a SEPARATE, purely
 * additive file rather than an edit to js/lastword/round.js: round.js is
 * shared by the accepted M1D build and M1C, and this feature is scoped to
 * the isolated Skippy playable build only. This module does not modify, and
 * is not depended on by, round.js/state.js in any way.
 *
 * "Do not invent a parallel score system": this reuses the EXACT same
 * roundState fields round.js's own purchaseVowel()/guessConsonant() already
 * use — `pointsThisRound` (the same pool `session.cumulativeScore +
 * round.pointsThisRound` availableScore() is computed from in the app),
 * `revealedLetters` (so js/lastword/round.js's own buildDisplayBoard() reveals
 * a hinted letter with no changes needed there), and `consonantsGuessed` /
 * `purchasedVowels` (so a hinted letter is correctly treated as "already
 * used" by round.js's own already-guessed/already-purchased guards AND
 * counted by round.js's own `actionsTaken()` for solve-bonus decay — one
 * hint purchase equals exactly one decay action, automatically, with no
 * separate counter needed). The only new field this module reads/writes
 * that round.js doesn't already define is: none — it writes only to
 * `revealedLetters` and `consonantsGuessed`/`purchasedVowels`, exactly like
 * a normal correct guess would, but WITHOUT going through
 * guessConsonant()/purchaseVowel() (so no strike risk and no per-occurrence
 * points are ever applied — a hint always guarantees presence, and the
 * approved rule is "no points awarded for the revealed occurrences").
 *
 * Pure/DOM-free, matching every other js/lastword/*.js rules file: every
 * function returns a NEW roundState rather than mutating the one passed in.
 */
(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        var LastWordContent = require('./content.js');
        var LastWordState = require('./state.js');
        module.exports = factory(LastWordContent, LastWordState);
    } else {
        root.LastWordHint = factory(root.LastWordContent, root.LastWordState);
    }
})(typeof self !== 'undefined' ? self : this, function (LastWordContent, LastWordState) {
    'use strict';

    var ROUND1_CONFIG = LastWordState.ROUND_CONFIG[1];

    // Approved initial pricing is exactly 3x that round's vowel cost (Round
    // 1: 100->300, Round 2: 150->450, Round 3: 200->600, Round 4: 250->750).
    // Computed from roundConfig.vowelCost rather than hardcoded per-round so
    // a future vowel-cost rebalance can't silently drift the hint price out
    // of its intended 3x relationship.
    var HINT_COST_MULTIPLIER = 3;

    function hintCostFor(roundConfig) {
        roundConfig = roundConfig || ROUND1_CONFIG;
        return roundConfig.vowelCost * HINT_COST_MULTIPLIER;
    }

    /**
     * Letters eligible for a hint: every letter actually present in the
     * puzzle (`puzzle.guessableLetters`) that is not yet revealed. This is
     * sufficient on its own — any present letter the player already
     * correctly guessed or successfully bought is already in
     * `revealedLetters`, so a separate check against consonantsGuessed/
     * purchasedVowels is not needed (an absent letter the player guessed
     * wrong is never in guessableLetters at all, so it can never be an
     * eligible hint target either).
     */
    function hintCandidates(roundState, puzzle) {
        return puzzle.guessableLetters.filter(function (letter) {
            return roundState.revealedLetters.indexOf(letter) === -1;
        });
    }

    function canAffordHint(availableScore, roundConfig) {
        return availableScore >= hintCostFor(roundConfig);
    }

    // Mirrors round.js's own (private, unexported) cloneRoundState field
    // list exactly, kept minimal and manually in sync rather than importing
    // it, so this file can stay fully independent of round.js's internals.
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

    /**
     * Buy one guaranteed hint letter. Picks uniformly at random from
     * hintCandidates() (consonant or vowel, either is eligible), reveals
     * ALL occurrences, costs hintCostFor(roundConfig) from pointsThisRound
     * with NO points awarded for the reveal and NO strike either way (a
     * hint never risks a wrong answer — the candidate pool guarantees
     * presence). Rejected as a no-op if the round is already over, no
     * unrevealed guessable letters remain, or the cost exceeds
     * `availableScore` (the caller's `session.cumulativeScore +
     * round.pointsThisRound`, same as every other purchase in this game —
     * this can never drive score negative for exactly the same reason
     * purchaseVowel() can't: the affordability check runs before any
     * deduction). `rng` defaults to Math.random but accepts an injected
     * `() => number in [0,1)` for deterministic tests.
     */
    function purchaseHintLetter(roundState, puzzle, availableScore, roundConfig, rng) {
        roundConfig = roundConfig || ROUND1_CONFIG;
        rng = rng || Math.random;

        if (roundState.outcome !== null) {
            return { roundState: roundState, changed: false, reason: 'round-over' };
        }

        var candidates = hintCandidates(roundState, puzzle);
        if (!candidates.length) {
            return { roundState: roundState, changed: false, reason: 'no-letters-remaining' };
        }

        var cost = hintCostFor(roundConfig);
        if (cost > availableScore) {
            return { roundState: roundState, changed: false, reason: 'insufficient-score' };
        }

        var idx = Math.floor(rng() * candidates.length);
        if (idx < 0) { idx = 0; }
        if (idx >= candidates.length) { idx = candidates.length - 1; } // guard rng() === 1
        var letter = candidates[idx];

        var next = cloneRoundState(roundState);
        if (LastWordContent.isVowel(letter)) {
            next.purchasedVowels.push(letter);
        } else {
            next.consonantsGuessed.push(letter);
        }
        next.revealedLetters.push(letter);
        next.pointsThisRound -= cost;

        return { roundState: next, changed: true, letter: letter, cost: cost };
    }

    return {
        HINT_COST_MULTIPLIER: HINT_COST_MULTIPLIER,
        hintCostFor: hintCostFor,
        hintCandidates: hintCandidates,
        canAffordHint: canAffordHint,
        purchaseHintLetter: purchaseHintLetter
    };
});
