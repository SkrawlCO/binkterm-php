/**
 * Last Word — SKIPPY IDLE CHATTER (restrained, state-aware, zero gameplay
 * effect).
 *
 * Companion to js/lastword/gallows-character.js (Skippy V1, ACCEPTED/CLOSED
 * — do not touch that module's character/art). This module only supplies
 * idle *dialogue* — short lines Skippy mutters while the player has gone
 * quiet — and the setTimeout-based timing controller that decides when to
 * show one. It never reads or writes any round/session/score state; a
 * caller decides what "Skippy's current state" is (via `getStateName`) and
 * what to do with a chosen line (via `onChatter`) — this module is inert
 * without those.
 *
 * Design constraints (see product direction — restrained, not Clippy):
 *   - no polling loop: every wait is a single setTimeout
 *   - first idle remark after ~12-20s of no meaningful player action
 *   - later remarks ~20-35s apart
 *   - at most 2-3 remarks per uninterrupted idle stretch, then it goes
 *     quiet until the next meaningful action resets the stretch
 *   - dialogue depends on Skippy's current strike/danger state
 *   - avoid repeating the immediately-previous line
 *   - a caller resets the stretch on every meaningful player action, and
 *     stops the controller outright on round/state transitions (so nothing
 *     fires over a screen the player has already left)
 *
 * Like gallows-character.js, the pure pieces (dialogue pools, line
 * selection) take no DOM and no wall-clock — only an injectable `rng` for
 * deterministic tests. The timer controller itself is unavoidably
 * effectful (it schedules callbacks), so it takes injectable
 * `setTimeout`/`clearTimeout` too, so tests can drive it without real
 * delays.
 */
(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.LastWordIdleChatter = factory();
    }
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    // ---- dialogue pools ---------------------------------------------------
    // One pool per gallows-character.js STRIKE_STATE_NAMES entry, plus SAVED.
    // COMEDIC_DEFEAT has no pool on purpose: he's gone (trapdoor already
    // dropped him), there's no one left to mutter idle chatter. Kept short,
    // in-character, and free of any score/hint/gameplay content — flavor
    // only, restrained rather than constant.
    var IDLE_CHATTER_LINES = {
        CONFIDENT: [
            'Take your time. I have nowhere to be. Probably.',
            'This rig has a great safety record. Statistically.',
            'I could do this puzzle in my sleep. Please don\'t make me.',
            'Fun fact: I asked for hazard pay. Still waiting.'
        ],
        CONFUSED: [
            'Wait, what does that light do?',
            'I didn\'t sign up for the amber light part.',
            'Is it supposed to hum like that?',
            'Someone tell me that\'s a decorative claw.'
        ],
        CONCERNED: [
            'So... any letters coming to mind?',
            'No rush. Genuinely. Any letter at all.',
            'I\'m going to go ahead and start sweating preemptively.',
            'The claw just twitched. I saw that.'
        ],
        NERVOUS: [
            'Okay. Okay okay okay.',
            'I\'d like to file a formal complaint. To the universe.',
            'The trapdoor seam is NOT supposed to be visible yet.',
            'On a scale of one to ten, how confident are we?'
        ],
        PLEADING: [
            'Any letter! I\'m not picky anymore!',
            'I can see the trapdoor hinges from here!',
            'This is fine. This is FINE.',
            'Vowels are cheaper than my dignity at this point.'
        ],
        TERRIFIED: [
            'THE CLAW IS ALMOST SHUT!',
            'I REGRET EVERY CHOICE THAT LED HERE!',
            'DO NOT LOOK AWAY FROM THE BOARD!',
            'THIS IS NOT A DRILL, WELL, IT IS, BUT STILL!'
        ],
        SAVED: [
            'BEST. DAY. EVER.',
            'I knew it! Mostly! Eventually!',
            'Tell my understudy he can stand down.'
        ]
    };

    /**
     * Pick one idle-chatter line for `stateName`. Returns null when the
     * state has no pool (COMEDIC_DEFEAT — nothing left to say). Excludes
     * `previousLine` from the draw when practical, matching
     * gallows-character.js's pickPanicLine so a caller re-triggering
     * chatter in the same state twice in a row doesn't hear an immediate
     * repeat. `rng` defaults to Math.random but accepts an injected
     * `() => number in [0,1)` for deterministic tests.
     */
    function pickChatterLine(stateName, previousLine, rng) {
        rng = rng || Math.random;
        var pool = IDLE_CHATTER_LINES[stateName];
        if (!pool || !pool.length) { return null; }
        var candidates = (previousLine && pool.length > 1)
            ? pool.filter(function (line) { return line !== previousLine; })
            : pool;
        var idx = Math.floor(rng() * candidates.length);
        if (idx < 0) { idx = 0; }
        if (idx >= candidates.length) { idx = candidates.length - 1; } // guard rng() === 1
        return candidates[idx];
    }

    // ---- timing controller -------------------------------------------------

    var FIRST_DELAY_MIN_S = 12;
    var FIRST_DELAY_MAX_S = 20;
    var LATER_DELAY_MIN_S = 20;
    var LATER_DELAY_MAX_S = 35;
    var MIN_REMARKS_PER_STRETCH = 2;
    var MAX_REMARKS_PER_STRETCH = 3;

    /**
     * Create a controller that schedules idle chatter via setTimeout. Every
     * call is inert until `resetIdle()` is called once — a caller should
     * call `resetIdle()` whenever gameplay begins (a round/Final starts)
     * and again on every meaningful player action (a letter guess, a solve
     * attempt, opening the solve form, ...). Call `stop()` on every
     * round/state transition (round result, Final intro, game complete,
     * a new session) so nothing fires over a screen the player has left.
     *
     * opts:
     *   getStateName() -> current gallows-character.js state name string,
     *                     read fresh at fire time (not snapshotted at
     *                     reset time) so a stale strike count never shows.
     *                     Returning a falsy value (e.g. gameplay already
     *                     ended) silently skips that firing.
     *   onChatter(line, stateName) -> called with the chosen line.
     *   rng, setTimeout, clearTimeout -> injectable for tests; default to
     *                     Math.random/setTimeout/clearTimeout.
     */
    function createIdleChatterController(opts) {
        opts = opts || {};
        var getStateName = opts.getStateName;
        var onChatter = opts.onChatter;
        var rng = opts.rng || Math.random;
        var setTimeoutFn = opts.setTimeout || (typeof setTimeout !== 'undefined' ? setTimeout : null);
        var clearTimeoutFn = opts.clearTimeout || (typeof clearTimeout !== 'undefined' ? clearTimeout : null);

        var timerId = null;
        var active = false;
        var remarksShown = 0;
        var maxRemarksThisStretch = 0;
        var lastLine = null;

        function randDelayMs(minS, maxS) {
            return (minS + rng() * (maxS - minS)) * 1000;
        }

        function clearTimer() {
            if (timerId !== null) {
                clearTimeoutFn(timerId);
                timerId = null;
            }
        }

        function scheduleNext(isFirst) {
            clearTimer();
            if (!active) { return; }
            if (remarksShown >= maxRemarksThisStretch) { return; } // stretch budget spent — stay quiet
            var delay = isFirst
                ? randDelayMs(FIRST_DELAY_MIN_S, FIRST_DELAY_MAX_S)
                : randDelayMs(LATER_DELAY_MIN_S, LATER_DELAY_MAX_S);
            timerId = setTimeoutFn(fire, delay);
        }

        function fire() {
            timerId = null;
            if (!active) { return; }
            var stateName = getStateName ? getStateName() : null;
            if (stateName) {
                var line = pickChatterLine(stateName, lastLine, rng);
                if (line) {
                    lastLine = line;
                    remarksShown++;
                    if (onChatter) { onChatter(line, stateName); }
                    scheduleNext(false);
                    return;
                }
            }
            // No state, or a state with nothing to say (COMEDIC_DEFEAT) —
            // don't spend the stretch's budget on silence; just try again
            // later in case the state changes before the stretch itself
            // is stopped/reset by the caller.
            scheduleNext(false);
        }

        return {
            /** Begin (or restart) an idle stretch. Cancels any pending timer. */
            resetIdle: function () {
                active = true;
                clearTimer();
                remarksShown = 0;
                maxRemarksThisStretch = MIN_REMARKS_PER_STRETCH +
                    Math.floor(rng() * (MAX_REMARKS_PER_STRETCH - MIN_REMARKS_PER_STRETCH + 1));
                lastLine = null;
                scheduleNext(true);
            },
            /** Cancel any pending timer and go quiet until resetIdle() again. */
            stop: function () {
                active = false;
                clearTimer();
            },
            /** Test/inspection helper — not used by production call sites. */
            _debugState: function () {
                return { active: active, remarksShown: remarksShown, maxRemarksThisStretch: maxRemarksThisStretch, lastLine: lastLine, timerPending: timerId !== null };
            }
        };
    }

    return {
        IDLE_CHATTER_LINES: IDLE_CHATTER_LINES,
        pickChatterLine: pickChatterLine,
        createIdleChatterController: createIdleChatterController,
        FIRST_DELAY_MIN_S: FIRST_DELAY_MIN_S,
        FIRST_DELAY_MAX_S: FIRST_DELAY_MAX_S,
        LATER_DELAY_MIN_S: LATER_DELAY_MIN_S,
        LATER_DELAY_MAX_S: LATER_DELAY_MAX_S,
        MIN_REMARKS_PER_STRETCH: MIN_REMARKS_PER_STRETCH,
        MAX_REMARKS_PER_STRETCH: MAX_REMARKS_PER_STRETCH
    };
}));
