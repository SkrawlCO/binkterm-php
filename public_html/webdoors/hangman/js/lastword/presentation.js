/**
 * Last Word — CONSCIOUS FORK #6 ("MAKE THE GAME LAND") presentation layer.
 *
 * Restrained game-feel helpers only — no gameplay rules live here, and none
 * of it is wired into M1D/app-final.js or ordinary Hangman. Three narrow
 * jobs, matching the fork's three proofs:
 *
 *   1. classifyScoreDelta()  — pure: is a score change "small" or "big"?
 *   2. presentScoreDelta()   — DOM: show a transient +N/-N and a brief pulse
 *                              next to a score number, sized to (1).
 *   3. revealPanelWithFade() — DOM: swap `showOnly()`'s abrupt panel switch
 *                              for one short fade-in on the panel being
 *                              revealed.
 *
 * Like gallows-character.js/idle-chatter.js, the pure piece takes no DOM;
 * the DOM pieces take an injectable `setTimeout` (defaults to the global)
 * so tests can drive them with a fake clock instead of real delays. Both DOM
 * helpers no-op safely on elements the fake test DOM doesn't provide
 * (`classList`/`hidden`) rather than throwing.
 *
 * Motion: `prefersReducedMotion()` reads `window.matchMedia` defensively
 * (absent in the vm-sandboxed test harness and in very old browsers) and
 * defaults to false — never true — when it can't be asked, so the normal
 * restrained motion is what's disabled-by-default, not the reverse. Callers
 * pass the result in explicitly; nothing here reads global state on its own
 * beyond that one query, so it stays testable without a browser.
 */
(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.LastWordPresentation = factory();
    }
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    // A score change only reads as "I just earned something substantial"
    // relative to that round's own ceiling — round 4's consonants are worth
    // more than round 1's, so a fixed point cutoff would misjudge both ends.
    // 30% of the round's max solve bonus comfortably separates an ordinary
    // multi-occurrence consonant/solve-bonus tail from an early, still-large
    // solve bonus, across every round's configured values.
    var BIG_DELTA_FRACTION = 0.3;

    /**
     * Pure. Returns null for no change, 'small' or 'big' otherwise.
     * `roundConfig` is the same per-round object app-final-skippy.js already
     * reads maxSolveBonus/consonantValue/vowelCost from (see state.js).
     */
    function classifyScoreDelta(delta, roundConfig) {
        if (!delta) return null;
        var bigThreshold = Math.round((roundConfig && roundConfig.maxSolveBonus || 0) * BIG_DELTA_FRACTION);
        return Math.abs(delta) >= bigThreshold ? 'big' : 'small';
    }

    // HUMAN-GATE CORRECTION (playtest: the original 700ms/1200ms display
    // was too brief to read peripherally without staring at the score at
    // the exact instant of the action). New philosophy per that feedback:
    // APPEAR QUICKLY, STAY READABLE, LEAVE QUIETLY. `pulseMs` is just the
    // brief scale emphasis on the score NUMBER itself (unchanged in spirit,
    // short); `dwellMs` is how long the +N/-N stays fully, stationarily
    // readable; `fadeOutMs` is the short quiet fade after that; `totalMs`
    // (= dwellMs + fadeOutMs) is when it's actually removed from the DOM.
    // Big keeps its stronger classification but is held noticeably longer,
    // so a payoff still reads as more important than an ordinary change —
    // dwell/comprehension, not spectacle.
    var SMALL_PULSE_MS = 260;
    var BIG_PULSE_MS = 420;
    var SMALL_DELTA_DWELL_MS = 1700;
    var SMALL_DELTA_FADE_MS = 250;
    var SMALL_DELTA_DISPLAY_MS = SMALL_DELTA_DWELL_MS + SMALL_DELTA_FADE_MS; // ~1950ms
    var BIG_DELTA_DWELL_MS = 2200;
    var BIG_DELTA_FADE_MS = 300;
    var BIG_DELTA_DISPLAY_MS = BIG_DELTA_DWELL_MS + BIG_DELTA_FADE_MS; // ~2500ms
    var SOLVE_BONUS_LABEL = 'SOLVE BONUS ';

    function safeClassAdd(el, cls) {
        if (el && el.classList && typeof el.classList.add === 'function') el.classList.add(cls);
    }
    function safeClassRemove(el, cls) {
        if (el && el.classList && typeof el.classList.remove === 'function') el.classList.remove(cls);
    }

    function formatDeltaText(delta, isBig) {
        var signed = (delta > 0 ? '+' : '') + delta;
        // The label is comprehension-only flavor on the big/positive case
        // (a genuine solve payoff) — never on an ordinary or negative
        // (spend) delta, where it would just be noise.
        return (isBig && delta > 0) ? SOLVE_BONUS_LABEL + signed : signed;
    }

    /**
     * DOM effect. `nodes` = { scoreEl, deltaEl } — `scoreEl` is the plain
     * number span already showing the new value (its text is set by the
     * caller as always; this only adds/removes a transient pulse class on
     * it), `deltaEl` is a small adjacent element used only for this transient
     * +N/-N — see lastword-skippy.html's `*ScoreDelta` spans and
     * css/lastword-skippy.css's `.lw-score-delta*` rules.
     *
     * Never the sole signal: the score numbers themselves already changed
     * via the caller's normal textContent update before/after this runs, so
     * a reduced-motion or non-visual reading of the page still sees the
     * correct number with or without this.
     *
     * ONE ACTIVE DELTA: any timers left over from a still-showing previous
     * transient at this same location are cancelled first, so a fresh score
     * event always cleanly replaces/restarts the display rather than one of
     * its own stale timers hiding the new number early (or a late one
     * clearing text a subsequent call already overwrote).
     */
    function presentScoreDelta(nodes, delta, magnitude, opts) {
        if (!delta || !magnitude || !nodes) return;
        opts = opts || {};
        var scheduleTimeout = opts.setTimeout || (typeof setTimeout !== 'undefined' ? setTimeout : null);
        var cancelTimeout = opts.clearTimeout || (typeof clearTimeout !== 'undefined' ? clearTimeout : null);
        var reducedMotion = !!opts.reducedMotion;
        var deltaEl = nodes.deltaEl;
        var scoreEl = nodes.scoreEl;
        var isBig = magnitude === 'big';

        if (deltaEl) {
            if (deltaEl._lwPendingTimers && cancelTimeout) {
                deltaEl._lwPendingTimers.forEach(function (id) { cancelTimeout(id); });
            }
            deltaEl._lwPendingTimers = [];

            deltaEl.textContent = formatDeltaText(delta, isBig);
            deltaEl.hidden = false;
            safeClassRemove(deltaEl, 'lw-score-delta-small');
            safeClassRemove(deltaEl, 'lw-score-delta-big');
            safeClassRemove(deltaEl, 'lw-score-delta-neg');
            safeClassRemove(deltaEl, 'lw-score-delta-fading');
            safeClassRemove(deltaEl, 'lw-score-delta-static');
            safeClassAdd(deltaEl, isBig ? 'lw-score-delta-big' : 'lw-score-delta-small');
            if (delta < 0) safeClassAdd(deltaEl, 'lw-score-delta-neg');
            // Reduced motion: skip the fade/slide keyframes entirely (see
            // css/lastword-skippy.css's `.lw-score-delta-static`) but the
            // number itself still appears and dwells for the same reading
            // time — motion is what's skipped, never the information.
            if (reducedMotion) safeClassAdd(deltaEl, 'lw-score-delta-static');
        }
        if (!reducedMotion) {
            safeClassAdd(scoreEl, isBig ? 'lw-score-pulse-big' : 'lw-score-pulse');
        }

        if (scheduleTimeout) {
            var pulseMs = isBig ? BIG_PULSE_MS : SMALL_PULSE_MS;
            var dwellMs = isBig ? BIG_DELTA_DWELL_MS : SMALL_DELTA_DWELL_MS;
            var totalMs = isBig ? BIG_DELTA_DISPLAY_MS : SMALL_DELTA_DISPLAY_MS;

            var pulseTimerId = scheduleTimeout(function () {
                safeClassRemove(scoreEl, 'lw-score-pulse');
                safeClassRemove(scoreEl, 'lw-score-pulse-big');
            }, pulseMs);

            // "Leave quietly": a brief fade begins only once the full
            // stationary dwell has elapsed, rather than disappearing the
            // instant it's been read.
            var fadeTimerId = scheduleTimeout(function () {
                if (deltaEl && !reducedMotion) safeClassAdd(deltaEl, 'lw-score-delta-fading');
            }, dwellMs);

            var hideTimerId = scheduleTimeout(function () {
                if (deltaEl) {
                    deltaEl.hidden = true;
                    deltaEl.textContent = '';
                    safeClassRemove(deltaEl, 'lw-score-delta-fading');
                    deltaEl._lwPendingTimers = [];
                }
            }, totalMs);

            if (deltaEl) deltaEl._lwPendingTimers.push(pulseTimerId, fadeTimerId, hideTimerId);
        }
    }

    var PANEL_FADE_START_DELAY_MS = 16; // ~one frame: lets the 'enter' state commit before animating to 'active'
    var PANEL_FADE_MS = 200;

    /**
     * DOM effect. Reveals `panelEl` (already switched to its visible
     * className by the caller's own `showOnly()`) with one short fade/slide
     * instead of the previous instant hard cut. Fires once per call — this
     * is not a general transition framework, just the one seam Proof C asks
     * for (the solved-round → round-result switch).
     */
    function revealPanelWithFade(panelEl, opts) {
        if (!panelEl) return;
        opts = opts || {};
        var scheduleTimeout = opts.setTimeout || (typeof setTimeout !== 'undefined' ? setTimeout : null);
        if (opts.reducedMotion || !scheduleTimeout) return; // instant reveal is already what showOnly() did
        safeClassAdd(panelEl, 'lw-fade-in');
        scheduleTimeout(function () {
            safeClassAdd(panelEl, 'lw-fade-in-active');
            scheduleTimeout(function () {
                safeClassRemove(panelEl, 'lw-fade-in');
                safeClassRemove(panelEl, 'lw-fade-in-active');
            }, PANEL_FADE_MS);
        }, PANEL_FADE_START_DELAY_MS);
    }

    /**
     * Reads `window.matchMedia` defensively — absent in the test harness and
     * in old browsers, in which case motion stays on (the restrained
     * default), never forced off by an inability to ask.
     */
    function prefersReducedMotion(win) {
        win = win || (typeof window !== 'undefined' ? window : null);
        if (!win || typeof win.matchMedia !== 'function') return false;
        try {
            return !!win.matchMedia('(prefers-reduced-motion: reduce)').matches;
        } catch (e) {
            return false;
        }
    }

    return {
        classifyScoreDelta: classifyScoreDelta,
        presentScoreDelta: presentScoreDelta,
        revealPanelWithFade: revealPanelWithFade,
        prefersReducedMotion: prefersReducedMotion,
        SMALL_DELTA_DWELL_MS: SMALL_DELTA_DWELL_MS,
        SMALL_DELTA_DISPLAY_MS: SMALL_DELTA_DISPLAY_MS,
        BIG_DELTA_DWELL_MS: BIG_DELTA_DWELL_MS,
        BIG_DELTA_DISPLAY_MS: BIG_DELTA_DISPLAY_MS,
        PANEL_FADE_START_DELAY_MS: PANEL_FADE_START_DELAY_MS,
        PANEL_FADE_MS: PANEL_FADE_MS
    };
}));
