/**
 * Last Word — session/round state foundation (M1A foundation)
 *
 * This is the SMALLEST clean state shape able to eventually represent the
 * full four-round + Final Hangman game described in the Last Word product
 * approval. M1A only builds the shape and its round-trip/serialization
 * behavior — no scoring/guessing mutations, no solve-bonus formula, no Final
 * gameplay. Those are M1B+.
 *
 * Deliberately pure/DOM-free: every field is a plain string/number/boolean
 * or an array of those, so a session serializes with plain JSON.stringify /
 * JSON.parse (no Set/Map rehydration step needed) and round-trips cleanly
 * through the WebDoor storage API in js/lastword/storage.js. This also keeps
 * the door open for a future Telnet/BbsSession client to reuse the same state
 * shape without any DOM dependency (see docs/WebDoors.md discussion in the
 * M1A recon — not attempted here).
 */
(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.LastWordState = factory();
    }
})(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    var STATE_VERSION = 1;
    var MAX_STRIKES = 6;

    /**
     * Per-round configuration. Values are the "currently proposed" numbers
     * from the product approval. The solve-bonus DECAY FORMULA (how
     * maxSolveBonus shrinks as more of the puzzle is revealed) is explicitly
     * UNRESOLVED and is not implemented here — only the round-level ceiling
     * values are recorded, as plain data a future rules engine will consume.
     */
    var ROUND_CONFIG = {
        1: { name: 'Warm-Up', categorySelection: 'player', consonantValue: 100, vowelCost: 100, maxSolveBonus: 1500 },
        2: { name: "Dealer's Choice", categorySelection: 'game', consonantValue: 150, vowelCost: 150, maxSolveBonus: 2000 },
        3: { name: "Player's Choice", categorySelection: 'choice-of-3', consonantValue: 200, vowelCost: 200, maxSolveBonus: 2500 },
        4: { name: 'Wildcard', categorySelection: 'game', consonantValue: 250, vowelCost: 250, maxSolveBonus: 3000 }
    };

    /**
     * Final Hangman opening-letter purchase costs and in-final costs. Also
     * plain data — no Final gameplay is implemented in M1A.
     */
    var FINAL_CONFIG = {
        openingLetterCost: { 0: 0, 1: 500, 2: 1250, 3: 2250 },
        additionalLetterCost: 250,
        correctSolveBonus: 5000,
        wrongSolveStrikes: 2,
        maxStrikes: MAX_STRIKES
    };

    function isValidRound(round) {
        return round === 1 || round === 2 || round === 3 || round === 4;
    }

    /**
     * A fresh top-level game session. `currentRound` and `finalState` start
     * null; the caller (M1B+) fills `currentRound` in once a puzzle is dealt.
     */
    function createSession() {
        return {
            stateVersion: STATE_VERSION,
            round: 1,
            cumulativeScore: 0,
            categoriesUsed: [],
            rounds: [],
            currentRound: null,
            finalState: null,
            startedAt: Date.now(),
            finished: null
        };
    }

    /**
     * Round-scoped play state for rounds 1-4. Every guess/reveal/strike field
     * lives here so it cannot leak into the next round — starting a new round
     * means calling this again, not mutating the previous round's object.
     */
    function createRoundState(round, puzzleId, category) {
        if (!isValidRound(round)) {
            throw new Error('LastWordState: invalid round number: ' + round);
        }
        return {
            round: round,
            puzzleId: puzzleId,
            category: category,
            revealedLetters: [],
            consonantsGuessed: [],
            purchasedVowels: [],
            wrongGuesses: [],
            strikes: 0,
            pointsThisRound: 0,
            solveAttempts: [],
            outcome: null // null | 'solved' | 'revealed-out' | 'struck-out'
        };
    }

    /**
     * Final Hangman state shape. Not populated/used by any gameplay logic in
     * M1A — this only proves the session shape has an obvious, non-awkward
     * place for it to live once M1D builds Final Hangman.
     */
    function createFinalState(puzzleId, category) {
        return {
            puzzleId: puzzleId,
            category: category,
            chosenOpeningLetters: [],
            revealedLetters: [],
            additionalLettersPurchased: [],
            spentThisFinal: 0,
            strikes: 0,
            solveAttempts: [],
            outcome: null // null | 'solved' | 'struck-out'
        };
    }

    /**
     * Move a round's outcome into session history and advance `round`.
     * Cumulative score survives the transition; round-scoped fields do not
     * carry forward (the next round starts from createRoundState() again).
     */
    function completeRound(session, roundState, pointsAwarded) {
        var next = shallowCloneSession(session);
        next.cumulativeScore = session.cumulativeScore + pointsAwarded;
        next.categoriesUsed = session.categoriesUsed.concat([roundState.category]);
        next.rounds = session.rounds.concat([roundState]);
        next.currentRound = null;
        next.round = roundState.round < 4 ? roundState.round + 1 : roundState.round;
        return next;
    }

    function shallowCloneSession(session) {
        return {
            stateVersion: session.stateVersion,
            round: session.round,
            cumulativeScore: session.cumulativeScore,
            categoriesUsed: session.categoriesUsed.slice(),
            rounds: session.rounds.slice(),
            currentRound: session.currentRound,
            finalState: session.finalState,
            startedAt: session.startedAt,
            finished: session.finished
        };
    }

    /**
     * Structural validation for a deserialized session (e.g. after loading
     * from WebDoor storage) — cheap sanity check, not a full schema
     * validator. Throws with a specific reason on failure.
     */
    function validateSession(session) {
        function assert(cond, msg) {
            if (!cond) throw new Error('LastWordState: invalid session — ' + msg);
        }
        assert(session && typeof session === 'object', 'not an object');
        assert(session.stateVersion === STATE_VERSION, 'unsupported stateVersion: ' + session.stateVersion);
        assert(isValidRound(session.round), 'invalid round: ' + session.round);
        assert(typeof session.cumulativeScore === 'number', 'cumulativeScore must be a number');
        assert(Array.isArray(session.categoriesUsed), 'categoriesUsed must be an array');
        assert(Array.isArray(session.rounds), 'rounds must be an array');
        return true;
    }

    return {
        STATE_VERSION: STATE_VERSION,
        MAX_STRIKES: MAX_STRIKES,
        ROUND_CONFIG: ROUND_CONFIG,
        FINAL_CONFIG: FINAL_CONFIG,
        isValidRound: isValidRound,
        createSession: createSession,
        createRoundState: createRoundState,
        createFinalState: createFinalState,
        completeRound: completeRound,
        validateSession: validateSession
    };
});
