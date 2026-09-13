/**
 * Last Word — four-round session orchestration (M1C)
 *
 * Pure, DOM-free logic that sits ABOVE round.js: category selection per the
 * four approved round behaviors, session-scoped puzzle deal (no repeats
 * within one session), and round-to-round transition using the M1A
 * GameSession foundation (LastWordState.completeRound) rather than a second
 * session model. Final Hangman is out of scope — this stops after Round 4.
 */
(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        var LastWordContent = require('./content.js');
        var LastWordState = require('./state.js');
        module.exports = factory(LastWordContent, LastWordState);
    } else {
        root.LastWordSession = factory(root.LastWordContent, root.LastWordState);
    }
})(typeof self !== 'undefined' ? self : this, function (LastWordContent, LastWordState) {
    'use strict';

    var LAST_NORMAL_ROUND = 4;

    function shuffle(list) {
        var arr = list.slice();
        for (var i = arr.length - 1; i > 0; i--) {
            var j = Math.floor(Math.random() * (i + 1));
            var tmp = arr[i];
            arr[i] = arr[j];
            arr[j] = tmp;
        }
        return arr;
    }

    /**
     * Category the game auto-picks for Round 2 ("a different category") and
     * Round 4 ("game chooses, preferring unused"): prefer a category not yet
     * used this session; fall back to any category if every category has
     * already been used (graceful degradation for a small seed corpus / a
     * long future session, never an error).
     */
    function pickAutoCategory(allCategories, usedCategories) {
        var unused = allCategories.filter(function (c) { return usedCategories.indexOf(c) === -1; });
        var pool = unused.length > 0 ? unused : allCategories;
        return pool[Math.floor(Math.random() * pool.length)];
    }

    /**
     * Up to `count` categories to OFFER the player (Round 3's "choose one of
     * three"): unused categories first, topped up with already-used ones
     * only if there are not enough unused categories to fill the offer. If
     * the whole corpus has fewer than `count` categories, offers whatever
     * exists rather than padding/erroring.
     */
    function offerCategoryChoices(allCategories, usedCategories, count) {
        count = count || 3;
        var unused = shuffle(allCategories.filter(function (c) { return usedCategories.indexOf(c) === -1; }));
        var used = shuffle(allCategories.filter(function (c) { return usedCategories.indexOf(c) !== -1; }));
        return unused.concat(used).slice(0, Math.min(count, allCategories.length));
    }

    /** Puzzle IDs already used anywhere in this session (completed rounds + the in-progress one, if any). */
    function usedPuzzleIds(session) {
        var ids = session.rounds.map(function (r) { return r.puzzleId; });
        if (session.currentRound) ids.push(session.currentRound.puzzleId);
        return ids;
    }

    /**
     * Deal a puzzle for `category`, avoiding any puzzle already used this
     * session. Falls through content.js's own graceful fallback (ignore the
     * exclusion rather than return nothing) when the category is too small
     * to avoid repeats — session-level dedup is a preference, not a
     * guarantee, against a small seed corpus.
     */
    function dealPuzzle(puzzles, category, session) {
        return LastWordContent.pickRandom(puzzles, {
            category: category,
            excludeIds: usedPuzzleIds(session)
        });
    }

    /**
     * Start the given round number: deals a puzzle for `category` and
     * returns a NEW session with `currentRound` populated. Does not mutate
     * the session passed in.
     */
    function dealRound(session, puzzles, roundNumber, category) {
        var puzzle = dealPuzzle(puzzles, category, session);
        var roundState = LastWordState.createRoundState(roundNumber, puzzle.id, puzzle.category);
        var next = cloneSessionShallow(session);
        next.round = roundNumber;
        next.currentRound = roundState;
        return { session: next, puzzle: puzzle };
    }

    /**
     * Complete the current round: folds its points into cumulativeScore via
     * LastWordState.completeRound (unconditionally — a struck-out round's
     * legitimately-earned points are kept, not zeroed, matching the approved
     * "one bad round should not sink the session" rule) and advances `round`.
     */
    function finishRound(session, roundState) {
        return LastWordState.completeRound(session, roundState, roundState.pointsThisRound);
    }

    /**
     * Session completion is decided SOLELY by how many rounds have actually
     * been recorded into history — never by the `session.round` counter,
     * and never by whether the last round was solved or failed. `round` is
     * really "which round to deal/offer next" bookkeeping for dealRound();
     * `rounds.length` is the one simple, monotonic, append-only signal for
     * "how many rounds are actually done." A human-reported defect (Continue
     * after a failed Round 4 falling back into a stale Round 2 screen)
     * could not be reproduced end-to-end after extensive testing, but the
     * two-signal check here was a real, unnecessary fragility for exactly
     * this symptom shape — this collapses it to the one signal that can't
     * drift from the other.
     */
    function isSessionComplete(session) {
        return session.rounds.length >= LAST_NORMAL_ROUND;
    }

    function cloneSessionShallow(session) {
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

    return {
        LAST_NORMAL_ROUND: LAST_NORMAL_ROUND,
        pickAutoCategory: pickAutoCategory,
        offerCategoryChoices: offerCategoryChoices,
        usedPuzzleIds: usedPuzzleIds,
        dealPuzzle: dealPuzzle,
        dealRound: dealRound,
        finishRound: finishRound,
        isSessionComplete: isSessionComplete
    };
});
