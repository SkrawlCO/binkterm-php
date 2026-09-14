/**
 * Last Word — SKIPPY vs. BOB lifetime rivalry (Fork #7, "Picking Sides").
 *
 * Pure/DOM-free, exactly like every other js/lastword/*.js rules file: no
 * `document`, no `fetch`, no BinkTermPHP/storage knowledge of any kind. This
 * module knows nothing about slots, game IDs, or the network — it only knows
 * how to represent a rivalry record and how one Final outcome updates it.
 * The host-facing persistence adapter (js/lastword/storage.js) and the
 * controller that wires this module to it stay entirely separate, per the
 * standing portability rule: BinkTermPHP is one HOST, not the game itself.
 *
 * The caller does not choose a team. Their PLAY chooses who they helped:
 * a Final ending 'solved' helped SKIPPY; a Final ending 'struck-out' helped
 * BOB. This is deliberately NOT "saved/killed" fiction — Skippy needs no
 * canonical death/resurrection state, and Bob is not a threat. It is a
 * lifetime tally of which side each completed session's Final fell on.
 */
(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.LastWordRivalry = factory();
    }
})(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    var RIVALRY_SCHEMA_VERSION = 1;

    /** A fresh, never-played rivalry record — the natural first-play state. */
    function createRivalryRecord() {
        return { version: RIVALRY_SCHEMA_VERSION, skippy: 0, bob: 0 };
    }

    /**
     * Coerce anything (a real loaded record, `null`/`undefined` for "no save
     * yet", or a malformed/partial object from a future schema change or a
     * corrupted store) into a safe, valid rivalry record. Never throws: a bad
     * or missing persisted value sanitizes to a fresh 0/0 record rather than
     * blocking boot.
     */
    function sanitizeRivalryRecord(raw) {
        var skippy = sanitizeCount(raw && raw.skippy);
        var bob = sanitizeCount(raw && raw.bob);
        return { version: RIVALRY_SCHEMA_VERSION, skippy: skippy, bob: bob };
    }

    function sanitizeCount(value) {
        var n = typeof value === 'number' ? value : Number(value);
        if (!isFinite(n) || isNaN(n)) return 0;
        n = Math.floor(n);
        return n > 0 ? n : 0;
    }

    /**
     * Apply exactly one completed session's Final outcome to a rivalry
     * record, returning a NEW record (the input is never mutated, matching
     * every other js/lastword/*.js state function). `outcome` must be the
     * same string final.js/state.js already use: 'solved' increments Skippy,
     * 'struck-out' increments Bob. Any other value (including the in-
     * progress `null`) is a no-op that returns the SAME reference — callers
     * only ever need to call this once, at the one place a Final's outcome
     * becomes final, but this stays defensively harmless if ever called
     * with a round that isn't actually over.
     */
    function applyOutcome(record, outcome) {
        var safe = sanitizeRivalryRecord(record);
        if (outcome === 'solved') {
            return { version: RIVALRY_SCHEMA_VERSION, skippy: safe.skippy + 1, bob: safe.bob };
        }
        if (outcome === 'struck-out') {
            return { version: RIVALRY_SCHEMA_VERSION, skippy: safe.skippy, bob: safe.bob + 1 };
        }
        return record;
    }

    return {
        RIVALRY_SCHEMA_VERSION: RIVALRY_SCHEMA_VERSION,
        createRivalryRecord: createRivalryRecord,
        sanitizeRivalryRecord: sanitizeRivalryRecord,
        applyOutcome: applyOutcome
    };
});
