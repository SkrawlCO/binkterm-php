/**
 * Last Word — SKIPPY IS WATCHING (conscious bounded fork #2).
 *
 * Pure, DOM-free, round-scoped situational-awareness layer: a SEPARATE
 * module from js/lastword/skippy-memory.js (Fork #1, "I remember what you
 * did") on purpose — this one is "I see what you're doing right now."
 * Skippy-memory.js stays exactly what it was: session-spanning facts read
 * at round/Final START. This module tracks a few facts ONLY for the
 * ROUND CURRENTLY IN PROGRESS and reacts to them mid-round, in real time,
 * as the caller's actions warrant it. Deliberately not folded into
 * skippy-memory.js — see that module's own header ("do not turn
 * skippy-memory.js into a giant personality engine").
 *
 * Same contract as every other js/lastword/*.js rules module: no DOM, no
 * wall-clock, every function returns a NEW state object rather than
 * mutating the one passed in, and `rng` is always injectable (defaults to
 * Math.random) for deterministic tests. No localStorage/UserStorage/
 * network call anywhere in this file — this is round-local memory, gone
 * the instant the round ends (a fresh `createRoundAwareness()` per round,
 * exactly like Fork #1's per-round `hintsPurchasedThisRound` counter).
 *
 * SITUATIONS PROVEN (see this fork's brief — normal rounds only, Final is
 * out of scope):
 *
 *   1. FIVE STRIKES + CALLER CAN AFFORD A HINT — Skippy notices the
 *      caller is risking him while sitting on enough score to buy help.
 *      Checked on every state-mutating action (`strikes`/`canBuyHint` are
 *      read live from the caller, never hardcoded to one round's price —
 *      `canBuyHint` is the SAME affordability+availability check
 *      hint.js's own button-disable logic already uses).
 *
 *   2. REPEATED HINT USE IN THE SAME ROUND — fires once, on the round's
 *      Nth hint purchase (`HINT_DEPENDENCE_THRESHOLD`), not on every hint.
 *
 *   3. REPEATED WRONG FULL-SOLVE ATTEMPTS — fires once, on the round's
 *      Nth wrong SOLVE submission (`WRONG_SOLVE_THRESHOLD`).
 *
 * A 4th situation from the brief — "at 5 strikes, idle chatter should use
 * more urgent content" — needed NO new code: js/lastword/idle-chatter.js
 * already reads Skippy's live strike-state and already has a distinct,
 * urgent TERRIFIED pool ("THE CLAW IS ALMOST SHUT!" etc.) separate from
 * CONFIDENT/CONFUSED/... — context already changes WHAT idle chatter says
 * at strike 5 without this fork touching its timing. See
 * app-final-skippy-dom.test.js for a test proving that pre-existing
 * behavior rather than re-implementing it here.
 *
 * SALIENCE POLICY (single deterministic entry point, `onAction()`):
 *   - each of the 3 situations above fires AT MOST ONCE per round (a
 *     `*Fired` boolean flag per situation, reset only by
 *     `createRoundAwareness()` at round start) — never repeats, no
 *     re-triggering on further identical events.
 *   - event-triggered, not polled: a caller calls `onAction()` only from
 *     its existing action handlers (a letter guess, a hint purchase, a
 *     solve submission) — no new timer of any kind.
 *   - priority when more than one situation could fire from the same
 *     action (e.g. a wrong SOLVE that also happens to land strikes on 5
 *     with an affordable hint): FIVE-STRIKE DANGER (survival-threat, the
 *     most urgent) > REPEATED WRONG SOLVE > HINT DEPENDENCE — the same
 *     "most emotionally significant event wins" principle Fork #1's
 *     `pickOpeningReaction()` priority order already established. Only
 *     ONE reaction (or none) is ever returned per call.
 *   - never touches round/session/score state — `onAction()` reads facts
 *     a caller computed from the real gameplay state and only ever
 *     returns a NEW awareness object plus an optional dialogue string.
 */
(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.LastWordSituationalAwareness = factory();
    }
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    // Round strike count at which "risking Skippy while sitting on an
    // affordable hint" becomes worth pointing out. Strike 5 specifically
    // (not any earlier strike) — one mistake from a Skippy incident, per
    // gallows-character.js's own TERRIFIED threshold.
    var DANGER_STRIKE_COUNT = 5;

    // Hint purchases within ONE round before Skippy notices the pattern.
    // Not 1 — a single hint is normal play, not "dependence".
    var HINT_DEPENDENCE_THRESHOLD = 2;

    // Wrong full-SOLVE submissions within ONE round before Skippy's
    // confidence visibly deteriorates. Not 1 — everybody misses once.
    var WRONG_SOLVE_THRESHOLD = 2;

    function createRoundAwareness() {
        return {
            hintsPurchasedThisRound: 0,
            wrongSolveAttemptsThisRound: 0,
            fiveStrikeDangerFired: false,
            hintDependenceFired: false,
            repeatedWrongSolveFired: false
        };
    }

    // ---- dialogue pools ---------------------------------------------------
    // Small, curated, in-character — see this module's header and the
    // fork's SKIPPY CHARACTER RULE: sarcastic/scared/petty/suspicious, not
    // needy for affection, not a tutorial assistant.

    var FIVE_STRIKE_HINT_LINES = [
        'YOU HAVE ENOUGH FOR A HINT!',
        'MY LIFE IS WORTH {cost} POINTS, RIGHT?'
    ];

    var HINT_DEPENDENCE_LINES = [
        'We\'re calling this teamwork now?',
        'Want me to guess one too?'
    ];

    var REPEATED_WRONG_SOLVE_LINES = [
        'That was your SECOND final answer.',
        'Maybe stop saying things with confidence.'
    ];

    function pick(pool, rng) {
        rng = rng || Math.random;
        var idx = Math.floor(rng() * pool.length);
        if (idx >= pool.length) idx = pool.length - 1; // guard rng() === 1
        return pool[idx];
    }

    /** Substitutes the literal `{cost}` token — the only piece of live game state any of these lines reference. */
    function withCost(line, hintCost) {
        return line.replace('{cost}', String(hintCost));
    }

    /**
     * Single deterministic entry point. Call this from an action handler
     * right after the round state it describes has already been computed
     * (so `strikes`/`isRoundOver`/`canBuyHint` reflect the CURRENT round,
     * not the one before the action).
     *
     * action = {
     *   kind: 'hintPurchased' | 'wrongSolveAttempt' | 'other',
     *   strikes: number,
     *   isRoundOver: boolean,
     *   canBuyHint: boolean,   // exactly hint.js's own button-enabled check
     *   hintCost: number       // that round's current hint cost, for the danger line's {cost} token
     * }
     *
     * Returns { awareness: <new RoundAwareness>, reaction: string|null }.
     * `awareness` must replace the caller's held reference (pure, like
     * every other module here) even when `reaction` is null — counters
     * still advance.
     */
    function onAction(awareness, action, rng) {
        var next = {
            hintsPurchasedThisRound: awareness.hintsPurchasedThisRound + (action.kind === 'hintPurchased' ? 1 : 0),
            wrongSolveAttemptsThisRound: awareness.wrongSolveAttemptsThisRound + (action.kind === 'wrongSolveAttempt' ? 1 : 0),
            fiveStrikeDangerFired: awareness.fiveStrikeDangerFired,
            hintDependenceFired: awareness.hintDependenceFired,
            repeatedWrongSolveFired: awareness.repeatedWrongSolveFired
        };

        // Priority 1: five-strike danger — checked on every action, not
        // just a specific kind, since any action (letter, hint, or a
        // wrong solve's +2 strikes) can land strikes on 5.
        if (!next.fiveStrikeDangerFired && !action.isRoundOver &&
            action.strikes === DANGER_STRIKE_COUNT && action.canBuyHint) {
            next.fiveStrikeDangerFired = true;
            return { awareness: next, reaction: withCost(pick(FIVE_STRIKE_HINT_LINES, rng), action.hintCost) };
        }

        // Priority 2: repeated wrong solve — only relevant on the
        // wrongSolveAttempt event itself.
        if (action.kind === 'wrongSolveAttempt' && !next.repeatedWrongSolveFired &&
            next.wrongSolveAttemptsThisRound >= WRONG_SOLVE_THRESHOLD) {
            next.repeatedWrongSolveFired = true;
            return { awareness: next, reaction: pick(REPEATED_WRONG_SOLVE_LINES, rng) };
        }

        // Priority 3: hint dependence — only relevant on the
        // hintPurchased event itself.
        if (action.kind === 'hintPurchased' && !next.hintDependenceFired &&
            next.hintsPurchasedThisRound >= HINT_DEPENDENCE_THRESHOLD) {
            next.hintDependenceFired = true;
            return { awareness: next, reaction: pick(HINT_DEPENDENCE_LINES, rng) };
        }

        return { awareness: next, reaction: null };
    }

    return {
        DANGER_STRIKE_COUNT: DANGER_STRIKE_COUNT,
        HINT_DEPENDENCE_THRESHOLD: HINT_DEPENDENCE_THRESHOLD,
        WRONG_SOLVE_THRESHOLD: WRONG_SOLVE_THRESHOLD,
        createRoundAwareness: createRoundAwareness,
        onAction: onAction
    };
}));
