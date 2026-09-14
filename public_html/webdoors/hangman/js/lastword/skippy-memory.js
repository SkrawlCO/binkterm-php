/**
 * Last Word — SKIPPY REMEMBERS (conscious bounded fork #1).
 *
 * Pure, DOM-free session-level memory for Skippy: a small set of facts
 * about what the CURRENT caller has done so far in the CURRENT four-round
 * + Final session, plus a curated set of deterministic reaction lines that
 * reference those facts. Matches every other js/lastword/*.js rules
 * module's contract — every function takes state in, returns a NEW object
 * out, nothing is mutated in place, and there is no localStorage/
 * UserStorage/network call anywhere in this file. This is explicitly NOT
 * persistence: `createMemory()` is called once per new session
 * (`startNewSession()` in app-final-skippy.js) and the object it returns
 * lives only in that page's in-memory JS state, exactly like `session`/
 * `round`/`finalState` already do.
 *
 * Scope (see the human brief this fork was built from): four facts worth
 * reacting to — a round lost at 6 strikes (a "Skippy incident"), a round
 * solved right at 5 strikes (a "five-strike save"), a round solved at 0
 * strikes (a "perfect round"), and conspicuous hint-buying — tracked both
 * as running-session totals (for the Final-aware reaction) and as "what
 * happened last round" (for the next round's opening reaction). No XP, no
 * relationship meter, no achievements — see recordRoundResult()'s single
 * job below.
 */
(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.LastWordSkippyMemory = factory();
    }
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    // A round solved with this many hints purchased (or more) reads as
    // "conspicuous" for that round's opening reaction. Tuned low on
    // purpose — hints cost 3x that round's vowel price (see hint.js), so
    // buying two or more in one round is already a notable amount of
    // spend, not routine play.
    var HEAVY_HINT_THRESHOLD = 2;

    // Session-total hint spend that reads as conspicuous for the Final
    // opening reaction (a coarser, whole-session version of the same
    // judgment above).
    var HEAVY_FINAL_HINT_THRESHOLD = 4;

    // Number of Skippy incidents (rounds lost at 6 strikes) this session
    // before the Final opening reaction turns suspicious rather than
    // neutral.
    var INCIDENT_SUSPICION_THRESHOLD = 2;

    // Combined perfect-round + five-strike-save count this session before
    // the Final opening reaction turns impressed rather than neutral.
    var STRONG_SHOWING_THRESHOLD = 2;

    function createMemory() {
        return {
            hintsPurchasedThisSession: 0,
            roundsSolved: 0,
            skippyIncidents: 0,   // rounds lost at 6 strikes
            perfectRounds: 0,     // rounds solved at 0 strikes
            fiveStrikeSaves: 0,   // rounds solved at 5 strikes
            previousRoundOutcome: null,      // 'solved' | 'struck-out' | null (no round finished yet)
            previousRoundStrikes: null,
            previousRoundHintsPurchased: null
        };
    }

    /**
     * Record the just-finished round's facts. `outcome` is round.js's own
     * `roundState.outcome` ('solved' or 'struck-out'), `strikes` is that
     * round's final strike count, `hintsPurchased` is how many times the
     * caller used the round's BUY HINT LETTER control (see hint.js) during
     * that round — app-final-skippy.js counts this itself since hint.js
     * doesn't add a dedicated counter field to roundState (see hint.js's
     * own header for why). Returns a NEW memory object; does not mutate
     * `memory`.
     */
    function recordRoundResult(memory, roundResult) {
        var outcome = roundResult.outcome;
        var strikes = roundResult.strikes;
        var hintsPurchased = roundResult.hintsPurchased || 0;

        var next = {
            hintsPurchasedThisSession: memory.hintsPurchasedThisSession + hintsPurchased,
            roundsSolved: memory.roundsSolved,
            skippyIncidents: memory.skippyIncidents,
            perfectRounds: memory.perfectRounds,
            fiveStrikeSaves: memory.fiveStrikeSaves,
            previousRoundOutcome: outcome,
            previousRoundStrikes: strikes,
            previousRoundHintsPurchased: hintsPurchased
        };

        if (outcome === 'solved') {
            next.roundsSolved += 1;
            if (strikes === 0) next.perfectRounds += 1;
            if (strikes === 5) next.fiveStrikeSaves += 1;
        } else if (outcome === 'struck-out') {
            next.skippyIncidents += 1;
        }

        return next;
    }

    // ---- reaction dialogue pools -------------------------------------
    // Deliberately small and sparse — see this module's header. Each pool
    // picked from via an injectable `rng` (defaults to Math.random),
    // following gallows-character.js's pickPanicLine() convention, so
    // tests can drive selection deterministically.

    var INCIDENT_LINES = [
        'You again.',
        'I remember you.'
    ];

    var FIVE_STRIKE_LINES = [
        'Could we not do THAT again?',
        'Cutting it a little close for my circuits.'
    ];

    var PERFECT_LINES = [
        'Okay. Maybe you DO know what you\'re doing.',
        'Huh. Didn\'t even break a sweat. I did, though.'
    ];

    var HEAVY_HINT_LINES = [
        'Planning to do any of this yourself?',
        'I\'m starting to feel less like a hint and more like an answer key.'
    ];

    var IMPRESSED_CLEAN_LINES = [
        'No hints last round. I\'m... cautiously impressed.',
        'Solved that one on your own. Noted.'
    ];

    function pick(pool, rng) {
        rng = rng || Math.random;
        var idx = Math.floor(rng() * pool.length);
        if (idx >= pool.length) idx = pool.length - 1; // guard rng() === 1
        return pool[idx];
    }

    /**
     * Opening reaction for the START of the next round, referring to what
     * happened in the PREVIOUS round only — see this module's header for
     * the priority order (most emotionally significant recent event
     * wins). Returns null when nothing interesting qualifies (including a
     * brand-new session, where `previousRoundOutcome` is still null) — a
     * new round must still sometimes begin silently/normally.
     */
    function pickOpeningReaction(memory, rng) {
        if (!memory || memory.previousRoundOutcome === null) return null;

        if (memory.previousRoundOutcome === 'struck-out') {
            return pick(INCIDENT_LINES, rng);
        }
        if (memory.previousRoundStrikes === 5) {
            return pick(FIVE_STRIKE_LINES, rng);
        }
        if (memory.previousRoundStrikes === 0) {
            return pick(PERFECT_LINES, rng);
        }
        if (memory.previousRoundHintsPurchased >= HEAVY_HINT_THRESHOLD) {
            return pick(HEAVY_HINT_LINES, rng);
        }
        if (memory.previousRoundHintsPurchased === 0) {
            return pick(IMPRESSED_CLEAN_LINES, rng);
        }
        return null;
    }

    var FINAL_SUSPICIOUS_LINES = [
        'You lost that gallows twice and you\'re STILL here?',
        'I\'ve seen you fail. Repeatedly. And yet — here we are.'
    ];

    var FINAL_IMPRESSED_LINES = [
        'You\'ve been unsettlingly good at this. Let\'s not ruin it now.',
        'Strong run so far. Try not to make me regret saying that.'
    ];

    var FINAL_HINT_COMMENT_LINES = [
        'You bought your way through most of that. Fair enough — this one\'s on you.',
        'That\'s a lot of hints to get here. No judgment. Some judgment.'
    ];

    var FINAL_NEUTRAL_LINES = [
        'We made it. Together. Against my better judgment.',
        'Final Hangman. Let\'s finish this.'
    ];

    /**
     * ONE restrained opening reaction for Final Hangman, reflecting the
     * accumulated session (all four normal rounds), not just the last
     * one — see this module's header for the priority order. Always
     * returns a line (never null): Final gets exactly one line no matter
     * what the journey looked like, per the accepted design.
     */
    function pickFinalReaction(memory, rng) {
        if (!memory) return pick(FINAL_NEUTRAL_LINES, rng);

        if (memory.skippyIncidents >= INCIDENT_SUSPICION_THRESHOLD) {
            return pick(FINAL_SUSPICIOUS_LINES, rng);
        }
        if ((memory.perfectRounds + memory.fiveStrikeSaves) >= STRONG_SHOWING_THRESHOLD) {
            return pick(FINAL_IMPRESSED_LINES, rng);
        }
        if (memory.hintsPurchasedThisSession >= HEAVY_FINAL_HINT_THRESHOLD) {
            return pick(FINAL_HINT_COMMENT_LINES, rng);
        }
        return pick(FINAL_NEUTRAL_LINES, rng);
    }

    return {
        HEAVY_HINT_THRESHOLD: HEAVY_HINT_THRESHOLD,
        HEAVY_FINAL_HINT_THRESHOLD: HEAVY_FINAL_HINT_THRESHOLD,
        INCIDENT_SUSPICION_THRESHOLD: INCIDENT_SUSPICION_THRESHOLD,
        STRONG_SHOWING_THRESHOLD: STRONG_SHOWING_THRESHOLD,
        createMemory: createMemory,
        recordRoundResult: recordRoundResult,
        pickOpeningReaction: pickOpeningReaction,
        pickFinalReaction: pickFinalReaction
    };
}));
