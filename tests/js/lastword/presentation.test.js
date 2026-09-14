/**
 * Plain Node assertions for js/lastword/presentation.js — CONSCIOUS FORK #6
 * ("MAKE THE GAME LAND") restrained game-feel layer. Covers the pure
 * classifier directly and the two DOM-effect helpers via a minimal fake
 * element (no jsdom needed — matches the classList/hidden contract
 * app-final-skippy.js's own fake DOM test harness already relies on).
 * Run: node tests/js/lastword/presentation.test.js
 */
'use strict';

const assert = require('assert');
const Presentation = require('../../../public_html/webdoors/hangman/js/lastword/presentation.js');
const LastWordState = require('../../../public_html/webdoors/hangman/js/lastword/state.js');

let passed = 0;
function check(name, fn) {
    fn();
    passed++;
    console.log('  ok - ' + name);
}

console.log('presentation.test.js');

function fakeClassList() {
    const set = new Set();
    return { add: (c) => set.add(c), remove: (c) => set.delete(c), contains: (c) => set.has(c), _set: set };
}

function fakeEl() {
    return { textContent: '', hidden: true, classList: fakeClassList() };
}

/** A manually-advanced fake clock, same contract as the setTimeout the app
 * file's own test harness injects — no real delays. */
function makeFakeClock() {
    let time = 0;
    let nextId = 1;
    const timers = new Map();
    return {
        setTimeout: (fn, delay) => {
            const id = nextId++;
            timers.set(id, { fn, at: time + (delay || 0) });
            return id;
        },
        clearTimeout: (id) => { timers.delete(id); },
        pendingCount: () => timers.size,
        advance(ms) {
            time += ms;
            Array.from(timers.entries())
                .filter(([, t]) => t.at <= time)
                .sort((a, b) => a[1].at - b[1].at)
                .forEach(([id, t]) => { if (timers.has(id)) { timers.delete(id); t.fn(); } });
        }
    };
}

// ---- classifyScoreDelta (pure) -----------------------------------------

check('classifyScoreDelta returns null for a zero delta', () => {
    assert.strictEqual(Presentation.classifyScoreDelta(0, LastWordState.ROUND_CONFIG[1]), null);
});

check('classifyScoreDelta returns "small" for an ordinary consonant/vowel-sized delta', () => {
    [1, 2, 3, 4].forEach((r) => {
        const cfg = LastWordState.ROUND_CONFIG[r];
        assert.strictEqual(Presentation.classifyScoreDelta(cfg.consonantValue, cfg), 'small');
        assert.strictEqual(Presentation.classifyScoreDelta(-cfg.vowelCost, cfg), 'small');
    });
});

check('classifyScoreDelta returns "big" once a delta reaches 30% of that round\'s max solve bonus', () => {
    [1, 2, 3, 4].forEach((r) => {
        const cfg = LastWordState.ROUND_CONFIG[r];
        const threshold = Math.round(cfg.maxSolveBonus * 0.3);
        assert.strictEqual(Presentation.classifyScoreDelta(threshold, cfg), 'big');
        assert.strictEqual(Presentation.classifyScoreDelta(threshold - 1, cfg), 'small');
    });
});

check('classifyScoreDelta classifies by magnitude regardless of sign', () => {
    const cfg = LastWordState.ROUND_CONFIG[1];
    const big = Math.round(cfg.maxSolveBonus * 0.3);
    assert.strictEqual(Presentation.classifyScoreDelta(-big, cfg), 'big');
});

check('a full solve-bonus payoff always classifies as "big" (proves the payoff/tiny-change gap)', () => {
    [1, 2, 3, 4].forEach((r) => {
        const cfg = LastWordState.ROUND_CONFIG[r];
        // Even a heavily-decayed solve bonus (down to 40% of max) still
        // reads as "big" next to a single consonant/vowel-sized change.
        const decayedBonus = Math.round(cfg.maxSolveBonus * 0.4);
        assert.strictEqual(Presentation.classifyScoreDelta(decayedBonus, cfg), 'big');
        assert.strictEqual(Presentation.classifyScoreDelta(cfg.consonantValue, cfg), 'small');
    });
});

// ---- presentScoreDelta (DOM effect) ------------------------------------

check('HUMAN-GATE: ordinary delta stays fully readable (no fading class) for its whole dwell, then fades, then clears — dwell is ~1.5-2.0s', () => {
    const clock = makeFakeClock();
    const scoreEl = fakeEl();
    const deltaEl = fakeEl();
    Presentation.presentScoreDelta({ scoreEl, deltaEl }, 250, 'small', { setTimeout: clock.setTimeout });

    assert.strictEqual(deltaEl.textContent, '+250');
    assert.strictEqual(deltaEl.hidden, false);
    assert.ok(deltaEl.classList.contains('lw-score-delta-small'));
    assert.ok(scoreEl.classList.contains('lw-score-pulse'));
    assert.ok(Presentation.SMALL_DELTA_DWELL_MS >= 1500 && Presentation.SMALL_DELTA_DWELL_MS <= 2000,
        'ordinary score-delta dwell must land in the human-gate\'s requested 1.5-2.0s window');

    // Still fully readable (no fade started) most of the way through dwell.
    clock.advance(Presentation.SMALL_DELTA_DWELL_MS - 1);
    assert.strictEqual(deltaEl.hidden, false);
    assert.ok(!deltaEl.classList.contains('lw-score-delta-fading'), 'must not start leaving before its stationary dwell has elapsed');

    // Dwell elapses -> brief "leave quietly" fade begins, but it is not
    // gone yet.
    clock.advance(1);
    assert.ok(deltaEl.classList.contains('lw-score-delta-fading'));
    assert.strictEqual(deltaEl.hidden, false, 'the fade itself must still be visible, not an instant disappearance');

    clock.advance(Presentation.SMALL_DELTA_DISPLAY_MS - Presentation.SMALL_DELTA_DWELL_MS);
    assert.strictEqual(deltaEl.hidden, true);
    assert.strictEqual(deltaEl.textContent, '');
    assert.ok(!scoreEl.classList.contains('lw-score-pulse'), 'pulse class must not linger past its own short duration');
});

check('presentScoreDelta shows "-N" and no big classes/label for a small negative (spend) delta', () => {
    const deltaEl = fakeEl();
    Presentation.presentScoreDelta({ scoreEl: fakeEl(), deltaEl }, -100, 'small', { setTimeout: () => 1 });
    assert.strictEqual(deltaEl.textContent, '-100');
    assert.ok(deltaEl.classList.contains('lw-score-delta-neg'));
    assert.ok(!deltaEl.classList.contains('lw-score-delta-big'));
});

check('HUMAN-GATE: a large solve payoff dwells noticeably longer (~2.0-2.5s) than an ordinary change, and may carry a "SOLVE BONUS" label', () => {
    const scoreEl = fakeEl();
    const deltaEl = fakeEl();
    Presentation.presentScoreDelta({ scoreEl, deltaEl }, 1200, 'big', { setTimeout: () => 1 });
    assert.ok(scoreEl.classList.contains('lw-score-pulse-big'));
    assert.ok(!scoreEl.classList.contains('lw-score-pulse'));
    assert.ok(deltaEl.classList.contains('lw-score-delta-big'));
    assert.strictEqual(deltaEl.textContent, 'SOLVE BONUS +1200');
    assert.ok(Presentation.BIG_DELTA_DISPLAY_MS > Presentation.SMALL_DELTA_DISPLAY_MS,
        'big payoffs must read as more important — held on screen longer than a small change');
    assert.ok(Presentation.BIG_DELTA_DWELL_MS >= 2000 && Presentation.BIG_DELTA_DWELL_MS <= 2500,
        'solve-payoff dwell must land in the human-gate\'s requested 2.0-2.5s window');
    assert.ok(Presentation.BIG_DELTA_DWELL_MS > Presentation.SMALL_DELTA_DWELL_MS);
});

check('the "SOLVE BONUS" label never appears on a negative/spend delta, even one classified "big"', () => {
    const deltaEl = fakeEl();
    Presentation.presentScoreDelta({ scoreEl: fakeEl(), deltaEl }, -1200, 'big', { setTimeout: () => 1 });
    assert.strictEqual(deltaEl.textContent, '-1200');
    assert.ok(deltaEl.classList.contains('lw-score-delta-neg'));
});

check('presentScoreDelta no-ops on a zero delta (nothing to show)', () => {
    const scoreEl = fakeEl();
    const deltaEl = fakeEl();
    Presentation.presentScoreDelta({ scoreEl, deltaEl }, 0, null, { setTimeout: () => 1 });
    assert.strictEqual(deltaEl.textContent, '');
    assert.strictEqual(deltaEl.hidden, true);
});

check('reducedMotion swaps in the static (non-animated) class but the +N/-N indicator itself still appears and dwells', () => {
    const clock = makeFakeClock();
    const scoreEl = fakeEl();
    const deltaEl = fakeEl();
    Presentation.presentScoreDelta({ scoreEl, deltaEl }, 500, 'big', { setTimeout: clock.setTimeout, reducedMotion: true });
    assert.ok(!scoreEl.classList.contains('lw-score-pulse-big'), 'no motion class when reduced motion is requested');
    assert.ok(deltaEl.classList.contains('lw-score-delta-static'), 'motion is skipped via a dedicated static class, not by hiding the delta');
    assert.strictEqual(deltaEl.textContent, 'SOLVE BONUS +500', 'the actual number/label must never depend on motion being enabled');
    assert.strictEqual(deltaEl.hidden, false);

    // Reduced-motion users must still get the full dwell — skip motion,
    // not information.
    clock.advance(Presentation.BIG_DELTA_DWELL_MS - 1);
    assert.strictEqual(deltaEl.hidden, false);
    assert.ok(!deltaEl.classList.contains('lw-score-delta-fading'), 'the fading class is a motion effect — never added under reduced motion');
    clock.advance(Presentation.BIG_DELTA_DISPLAY_MS - (Presentation.BIG_DELTA_DWELL_MS - 1));
    assert.strictEqual(deltaEl.hidden, true, 'still disappears eventually — reduced motion changes HOW it leaves, not whether it does');
});

check('ONE ACTIVE DELTA: a new score event cleanly replaces a still-showing previous one instead of racing its stale timers', () => {
    const clock = makeFakeClock();
    const scoreEl = fakeEl();
    const deltaEl = fakeEl();
    const opts = { setTimeout: clock.setTimeout, clearTimeout: clock.clearTimeout };

    Presentation.presentScoreDelta({ scoreEl, deltaEl }, 100, 'small', opts);
    assert.strictEqual(deltaEl.textContent, '+100');

    // A second event fires well before the first one's dwell would have
    // elapsed — e.g. two quick consonant guesses.
    clock.advance(200);
    Presentation.presentScoreDelta({ scoreEl, deltaEl }, 150, 'small', opts);
    assert.strictEqual(deltaEl.textContent, '+150', 'the newer number must be showing, not the stale first one');

    // The FIRST event's original hide-timer target (its own dwell+fade from
    // its own start time) must not fire and blank the newer number early.
    clock.advance(Presentation.SMALL_DELTA_DISPLAY_MS - 200);
    assert.strictEqual(deltaEl.hidden, false, 'the newer delta must still be showing — a stale timer from the replaced one must not hide it early');
    assert.strictEqual(deltaEl.textContent, '+150');

    // The second (current) event's own full duration, from ITS start,
    // does eventually hide it normally.
    clock.advance(200);
    assert.strictEqual(deltaEl.hidden, true);
    assert.strictEqual(deltaEl.textContent, '');
});

check('presentScoreDelta tolerates a missing deltaEl/scoreEl (defensive, never throws)', () => {
    assert.doesNotThrow(() => Presentation.presentScoreDelta({}, 50, 'small', { setTimeout: () => 1 }));
    assert.doesNotThrow(() => Presentation.presentScoreDelta(null, 50, 'small', {}));
});

// ---- revealPanelWithFade (DOM effect) ----------------------------------

check('revealPanelWithFade adds the enter class, then the active class one tick later, then cleans up', () => {
    const clock = makeFakeClock();
    const panel = fakeEl();
    Presentation.revealPanelWithFade(panel, { setTimeout: clock.setTimeout });

    assert.ok(panel.classList.contains('lw-fade-in'));
    assert.ok(!panel.classList.contains('lw-fade-in-active'), 'active state must not apply on the same tick — needs a frame to commit the enter state first');

    clock.advance(Presentation.PANEL_FADE_START_DELAY_MS);
    assert.ok(panel.classList.contains('lw-fade-in-active'));

    clock.advance(Presentation.PANEL_FADE_MS);
    assert.ok(!panel.classList.contains('lw-fade-in'), 'transition classes are cleaned up once the fade completes');
    assert.ok(!panel.classList.contains('lw-fade-in-active'));
});

check('revealPanelWithFade fires the transition exactly once per call (no repeat/re-trigger)', () => {
    const clock = makeFakeClock();
    const panel = fakeEl();
    Presentation.revealPanelWithFade(panel, { setTimeout: clock.setTimeout });
    // Two separate advances so each level of the (enter -> active -> cleanup)
    // chain gets its own turn to schedule and fire its own next step, the
    // same as the fully-flushed sequence the earlier test already checked.
    clock.advance(Presentation.PANEL_FADE_START_DELAY_MS);
    clock.advance(Presentation.PANEL_FADE_MS);
    const classesAfterFirstRun = panel.classList._set.size;
    clock.advance(10000);
    assert.strictEqual(panel.classList._set.size, classesAfterFirstRun, 'nothing should fire again without a fresh call');
});

check('revealPanelWithFade does nothing when reducedMotion is requested — an instant reveal (what showOnly() already did)', () => {
    const panel = fakeEl();
    Presentation.revealPanelWithFade(panel, { setTimeout: () => 1, reducedMotion: true });
    assert.strictEqual(panel.classList._set.size, 0);
});

check('revealPanelWithFade tolerates a missing panel element', () => {
    assert.doesNotThrow(() => Presentation.revealPanelWithFade(null, {}));
});

// ---- prefersReducedMotion -----------------------------------------------

check('prefersReducedMotion defaults to false when matchMedia is unavailable (never forces motion off by inability to ask)', () => {
    assert.strictEqual(Presentation.prefersReducedMotion(null), false);
    assert.strictEqual(Presentation.prefersReducedMotion({}), false);
});

check('prefersReducedMotion reflects a real matchMedia result', () => {
    const reduceWin = { matchMedia: (q) => ({ matches: q.indexOf('reduce') !== -1 }) };
    assert.strictEqual(Presentation.prefersReducedMotion(reduceWin), true);
});

check('prefersReducedMotion swallows a throwing matchMedia rather than crashing the caller', () => {
    const throwingWin = { matchMedia: () => { throw new Error('nope'); } };
    assert.strictEqual(Presentation.prefersReducedMotion(throwingWin), false);
});

console.log(passed + ' assertions passed');
