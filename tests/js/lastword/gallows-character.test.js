/**
 * Plain Node assertions for js/lastword/gallows-character.js — Skippy V1
 * (ACCEPTED, character lab CLOSED): contestant + apparatus renderer,
 * isolated from M1D. Not yet wired into the live game.
 * Run: node tests/js/lastword/gallows-character.test.js
 */
'use strict';

const assert = require('assert');
const GC = require('../../../public_html/webdoors/hangman/js/lastword/gallows-character.js');

let passed = 0;
function check(name, fn) {
    fn();
    passed++;
    console.log('  ok - ' + name);
}

console.log('gallows-character.test.js');

const ORDER = ['CONFIDENT', 'CONFUSED', 'CONCERNED', 'NERVOUS', 'PLEADING', 'TERRIFIED', 'COMEDIC_DEFEAT'];

check('strikes 0-6 map to the 7 ordered states', () => {
    ORDER.forEach((name, strikes) => {
        assert.strictEqual(GC.pickStateName(strikes, false), name, 'strikes=' + strikes);
    });
});

check('MAX_STRIKES matches the 7-state strike range (0-6)', () => {
    assert.strictEqual(GC.MAX_STRIKES, 6);
    assert.strictEqual(GC.STRIKE_STATE_NAMES.length, GC.MAX_STRIKES + 1);
});

check('strikes above MAX_STRIKES clamp to COMEDIC_DEFEAT rather than throwing', () => {
    assert.strictEqual(GC.pickStateName(7, false), 'COMEDIC_DEFEAT');
    assert.strictEqual(GC.pickStateName(99, false), 'COMEDIC_DEFEAT');
});

check('solved=true always yields SAVED, regardless of strikes', () => {
    [0, 1, 3, 6].forEach((strikes) => {
        assert.strictEqual(GC.pickStateName(strikes, true), 'SAVED', 'strikes=' + strikes);
    });
});

check('a fresh round (strikes 0, not solved) after a SAVED round resets to CONFIDENT', () => {
    assert.strictEqual(GC.pickStateName(0, true), 'SAVED');
    // Simulates "Continue" starting round N+1: new round state starts at
    // strikes 0 / solved false again, same as createRoundState in state.js.
    assert.strictEqual(GC.pickStateName(0, false), 'CONFIDENT');
});

check('renderMarkup produces distinct, well-formed SVG for all 8 states', () => {
    const seen = new Set();
    ORDER.concat(['SAVED']).forEach((name) => {
        const markup = GC.renderMarkup(name);
        assert.ok(markup.startsWith('<svg'), name + ' should start with <svg');
        assert.ok(markup.trim().endsWith('</svg>'), name + ' should end with </svg>');
        assert.ok(markup.includes('data-state="' + name + '"'), name + ' should tag its own state');
        assert.ok(!seen.has(markup), name + ' markup should be distinct from every other state');
        seen.add(markup);
    });
    assert.strictEqual(seen.size, 8);
});

check('renderMarkup rejects an unknown state name rather than silently rendering nothing', () => {
    assert.throws(() => GC.renderMarkup('NOT_A_STATE'));
});

// ---- Experiment #8: Strike-5 rotating panic lines ----------------------

check('PANIC_LINES is a pool of concise, non-empty, unique strings', () => {
    assert.ok(Array.isArray(GC.PANIC_LINES));
    assert.ok(GC.PANIC_LINES.length >= 8, 'pool should have a real handful of lines');
    const seen = new Set();
    GC.PANIC_LINES.forEach((line) => {
        assert.strictEqual(typeof line, 'string');
        assert.ok(line.length > 0 && line.length <= 40, 'line should stay concise: ' + line);
        assert.ok(!seen.has(line), 'line should be unique: ' + line);
        seen.add(line);
    });
});

check('pickPanicLine always returns a line from the pool', () => {
    for (let i = 0; i < 50; i++) {
        const line = GC.pickPanicLine(null, () => i / 50);
        assert.ok(GC.PANIC_LINES.includes(line));
    }
});

check('pickPanicLine is deterministic given the same rng input (testable, not actually random)', () => {
    const a = GC.pickPanicLine(null, () => 0.42);
    const b = GC.pickPanicLine(null, () => 0.42);
    assert.strictEqual(a, b);
});

check('pickPanicLine avoids an immediate repeat of the previous line', () => {
    GC.PANIC_LINES.forEach((previous) => {
        // Sweep the full rng range; none of these draws should reproduce `previous`.
        for (let i = 0; i < 20; i++) {
            const next = GC.pickPanicLine(previous, () => i / 20);
            assert.notStrictEqual(next, previous, 'should not immediately repeat ' + previous);
        }
    });
});

check('pickPanicLine with no previous line can still return any pool entry (fresh round picks anew)', () => {
    const rngForIndex = (idx) => () => idx / GC.PANIC_LINES.length;
    const results = new Set();
    for (let idx = 0; idx < GC.PANIC_LINES.length; idx++) {
        results.add(GC.pickPanicLine(null, rngForIndex(idx)));
    }
    assert.strictEqual(results.size, GC.PANIC_LINES.length);
});

check('renderMarkup(TERRIFIED, {panicLine}) shows the requested line and stays stable across re-renders', () => {
    const chosen = GC.PANIC_LINES[3];
    const first = GC.renderMarkup('TERRIFIED', { panicLine: chosen });
    const second = GC.renderMarkup('TERRIFIED', { panicLine: chosen });
    assert.ok(first.includes(chosen));
    assert.strictEqual(first, second, 're-rendering the same state+line should be stable, not re-randomized');
});

check('renderMarkup(TERRIFIED) with no opts falls back to a deterministic default line', () => {
    const markup = GC.renderMarkup('TERRIFIED');
    assert.ok(markup.includes(GC.PANIC_LINES[0]));
});

// ---- Experiment #9: pocket-protector A/B toggle -------------------------

const FIGURE_STATES = ['CONFIDENT', 'CONFUSED', 'CONCERNED', 'NERVOUS', 'PLEADING', 'TERRIFIED', 'SAVED'];

check('pocket protector is present by default (ON) on every state with a figure', () => {
    FIGURE_STATES.forEach((name) => {
        const markup = GC.renderMarkup(name);
        assert.ok(markup.includes('rx="1"'), name + ' should show the pocket-protector rect by default');
    });
});

check('showPocket:false hides the pocket protector on every state with a figure', () => {
    FIGURE_STATES.forEach((name) => {
        const markup = GC.renderMarkup(name, { showPocket: false });
        assert.ok(!markup.includes('rx="1"'), name + ' should omit the pocket-protector rect when disabled');
    });
});

check('COMEDIC_DEFEAT has no figure, so the pocket-protector toggle has nothing to show/hide', () => {
    const on = GC.renderMarkup('COMEDIC_DEFEAT');
    const off = GC.renderMarkup('COMEDIC_DEFEAT', { showPocket: false });
    assert.strictEqual(on, off);
});

// ---- Experiment #10: "Skippy's face is sacred" bubble placement --------
// Every speech-bearing state rotates Skippy's whole figure (including his
// head+hair) around a fixed pivot before it's ever drawn; the speech
// bubble is added afterward, unrotated, in absolute canvas coordinates.
// These tests recompute where the rotated head+hair actually land for
// each state's known tilt and confirm the rendered bubble's <rect> never
// reaches into that zone — a real (if independently-derived) geometric
// check, not just "the text is present".

const PIVOT_X = 78, PIVOT_Y = 118, HEAD_LOCAL_X = 78, HEAD_LOCAL_Y = 60, HEAD_R = 22;
const HAIR_MARGIN = 12; // generous allowance for the hair mass above/around the head
const STATE_TILT_DEG = { PLEADING: 26, TERRIFIED: 17, SAVED: -20 };

function headSafetyBox(tiltDeg) {
    const a = tiltDeg * Math.PI / 180;
    const dx = HEAD_LOCAL_X - PIVOT_X; // 0
    const dy = HEAD_LOCAL_Y - PIVOT_Y; // -58
    const ndx = dx * Math.cos(a) - dy * Math.sin(a);
    const ndy = dx * Math.sin(a) + dy * Math.cos(a);
    const cx = PIVOT_X + ndx, cy = PIVOT_Y + ndy;
    const rad = HEAD_R + HAIR_MARGIN;
    return { left: cx - rad, right: cx + rad, top: cy - rad, bottom: cy + rad };
}

function extractBubbleRect(markup) {
    const m = markup.match(/<rect x="([-\d.]+)" y="([-\d.]+)" width="([-\d.]+)" height="([-\d.]+)" rx="10"/);
    assert.ok(m, 'expected exactly one speech-bubble rect (rx="10") in the markup');
    return { x: parseFloat(m[1]), y: parseFloat(m[2]), w: parseFloat(m[3]), h: parseFloat(m[4]) };
}

function assertClearOfHead(stateName, markup) {
    const box = headSafetyBox(STATE_TILT_DEG[stateName]);
    const rect = extractBubbleRect(markup);
    const clearsVertically = rect.y >= box.bottom || (rect.y + rect.h) <= box.top;
    const clearsHorizontally = rect.x >= box.right || (rect.x + rect.w) <= box.left;
    assert.ok(clearsVertically || clearsHorizontally,
        stateName + ' bubble ' + JSON.stringify(rect) + ' overlaps the rotated head/hair box ' + JSON.stringify(box));
    // and stay inside the 260x260 canvas
    assert.ok(rect.x >= 0 && rect.x + rect.w <= 260, stateName + ' bubble must stay within the 260-wide canvas');
    assert.ok(rect.y >= 0 && rect.y + rect.h <= 260, stateName + ' bubble must stay within the 260-tall canvas');
}

check('PLEADING speech bubble never overlaps Skippy\'s head/hair', () => {
    assertClearOfHead('PLEADING', GC.renderMarkup('PLEADING'));
});

check('SAVED speech bubble never overlaps Skippy\'s head/hair', () => {
    assertClearOfHead('SAVED', GC.renderMarkup('SAVED'));
});

check('every one of the 10 rotating Strike-5 panic lines stays clear of Skippy\'s head/hair', () => {
    GC.PANIC_LINES.forEach((line) => {
        assertClearOfHead('TERRIFIED', GC.renderMarkup('TERRIFIED', { panicLine: line }));
    });
});

check('Strike-5 panic-line stability/no-repeat behavior is unaffected by the new placement', () => {
    // Re-assert #8's contract still holds after the bubble-placement change.
    const chosen = GC.PANIC_LINES[5];
    const a = GC.renderMarkup('TERRIFIED', { panicLine: chosen });
    const b = GC.renderMarkup('TERRIFIED', { panicLine: chosen });
    assert.strictEqual(a, b, 'same requested line should render identically on re-render');
    GC.PANIC_LINES.forEach((previous) => {
        for (let i = 0; i < 10; i++) {
            const next = GC.pickPanicLine(previous, () => i / 10);
            assert.notStrictEqual(next, previous);
        }
    });
});

// ---- Experiment #11: hair is open strokes, not a filled cap/helmet -----

check('hair is no longer a filled/closed shape on any figure-bearing state', () => {
    FIGURE_STATES.forEach((name) => {
        const markup = GC.renderMarkup(name);
        // #10's flat-top was one closed contour: "...Z" fill="<INK>" stroke="<INK>"...
        // That exact closed-and-filled construction must be gone.
        assert.ok(!/Z"\s*fill="#dbe4f5"\s*stroke="#dbe4f5"/.test(markup),
            name + ' should not contain a filled/closed hair contour');
    });
});

// ---- Experiment #13: round/expressive eyes by default -------------------

check('CONFIDENT, CONCERNED, NERVOUS, and PLEADING use round eyes (an <ellipse>), not a flat dash', () => {
    ['CONFIDENT', 'CONCERNED', 'NERVOUS', 'PLEADING'].forEach((name) => {
        const markup = GC.renderMarkup(name);
        const ellipseCount = (markup.match(/<ellipse/g) || []).length;
        // each of these states has exactly 2 round eyes (plus PLEADING's
        // separate mouth <ellipse>, and CONCERNED has none elsewhere)
        assert.ok(ellipseCount >= 2, name + ' should have at least 2 round-eye <ellipse> elements, got ' + ellipseCount);
    });
});

check('TERRIFIED keeps its already-round eyes (a deliberate wide/panicked look)', () => {
    const markup = GC.renderMarkup('TERRIFIED');
    assert.ok(markup.includes('<circle cx="69" cy="56"') || /circle cx="\d+(\.\d+)?" cy="56" r="8"/.test(markup),
        'TERRIFIED should still render round (circle) eyes');
});

check('SAVED keeps its deliberate closed happy-eye arcs (the accepted "ecstatic relief" exception)', () => {
    const markup = GC.renderMarkup('SAVED');
    assert.ok(markup.includes('Q75,50 82,57') || markup.includes('Q93,50 100,57'),
        'SAVED should still use closed curved-line eyes, not round open ones');
});

// ---- SKIPPY V1: frozen, human-barbered hair defaults --------------------
// Matt manually tuned Skippy's hair live in the BARBER tool and chose
// these exact final values — they are now authoritative canon, baked in
// as gallows-character.js's SKIPPY_HAIR_DEFAULTS. These are NOT
// placeholder/no-op values (unlike every earlier experiment's hair
// defaults) — regression here means someone reintroduced a different
// haircut, generated or otherwise, without being asked to.
const MATT_BARBER_VALUES = {
    xOffset: 0,
    yOffset: 1,
    spacing: 1,
    length: 0.7,
    angle: -60,
    longEndDX: -15,
    longEndDY: -11
};

check('SKIPPY_HAIR_DEFAULTS exactly matches Matt\'s frozen BARBER selection', () => {
    assert.deepStrictEqual(GC.SKIPPY_HAIR_DEFAULTS, MATT_BARBER_VALUES);
});

check('computeHairGeometry with no hairParams renders Matt\'s frozen hair with no opts required', () => {
    const withNoOpts = GC.computeHairGeometry(GC.HEAD_CX);
    const withExplicitMattValues = GC.computeHairGeometry(GC.HEAD_CX, MATT_BARBER_VALUES);
    assert.deepStrictEqual(withNoOpts, withExplicitMattValues);
    assert.strictEqual(withNoOpts.bristles.length, 12);
    assert.strictEqual(withNoOpts.longStrands.length, 3);
});

check('renderMarkup(state) with no opts at all renders Matt\'s frozen hair (no opts.hairParams required)', () => {
    FIGURE_STATES.forEach((name) => {
        const bare = GC.renderMarkup(name);
        const explicit = GC.renderMarkup(name, { hairParams: MATT_BARBER_VALUES });
        assert.strictEqual(bare, explicit, name + ' should render identically with or without an explicit hairParams override');
    });
});

// These mechanism tests deliberately pass an explicit, neutral baseline
// (rather than relying on the module's defaults) so they verify how each
// knob behaves in isolation and stay correct regardless of what
// SKIPPY_HAIR_DEFAULTS happens to be — every hairParams field is an
// absolute override, not a delta added to whatever default it replaces.
const NEUTRAL_HAIR_PARAMS = { xOffset: 0, yOffset: 0, spacing: 1, length: 1, angle: 0, longEndDX: 0, longEndDY: 0 };

check('xOffset/yOffset shift every bristle and long strand by the same amount', () => {
    const base = GC.computeHairGeometry(GC.HEAD_CX, NEUTRAL_HAIR_PARAMS);
    const shifted = GC.computeHairGeometry(GC.HEAD_CX, Object.assign({}, NEUTRAL_HAIR_PARAMS, { xOffset: 10, yOffset: -5 }));
    base.bristles.forEach((b, i) => {
        const s = shifted.bristles[i];
        assert.strictEqual(s.x1, b.x1 + 10);
        assert.strictEqual(s.y1, b.y1 - 5);
        assert.strictEqual(s.x2, b.x2 + 10);
        assert.strictEqual(s.y2, b.y2 - 5);
    });
    base.longStrands.forEach((s0, i) => {
        const s = shifted.longStrands[i];
        assert.strictEqual(s.x1, s0.x1 + 10);
        assert.strictEqual(s.y1, s0.y1 - 5);
    });
});

check('length scales bristle stroke length without moving the start point', () => {
    const base = GC.computeHairGeometry(GC.HEAD_CX, NEUTRAL_HAIR_PARAMS);
    const longer = GC.computeHairGeometry(GC.HEAD_CX, Object.assign({}, NEUTRAL_HAIR_PARAMS, { length: 2 }));
    base.bristles.forEach((b, i) => {
        const L = longer.bristles[i];
        assert.strictEqual(L.x1, b.x1, 'start point should not move');
        assert.strictEqual(L.y1, b.y1, 'start point should not move');
        const baseLen = Math.hypot(b.x2 - b.x1, b.y2 - b.y1);
        const longLen = Math.hypot(L.x2 - L.x1, L.y2 - L.y1);
        assert.ok(Math.abs(longLen - baseLen * 2) < 0.01, 'length:2 should double stroke length');
    });
});

check('angle rotates the bristle lean without changing its length', () => {
    const base = GC.computeHairGeometry(GC.HEAD_CX, NEUTRAL_HAIR_PARAMS);
    const rotated = GC.computeHairGeometry(GC.HEAD_CX, Object.assign({}, NEUTRAL_HAIR_PARAMS, { angle: 90 }));
    base.bristles.forEach((b, i) => {
        const r = rotated.bristles[i];
        const baseLen = Math.hypot(b.x2 - b.x1, b.y2 - b.y1);
        const rotLen = Math.hypot(r.x2 - r.x1, r.y2 - r.y1);
        assert.ok(Math.abs(rotLen - baseLen) < 0.01, 'rotating should not change length');
    });
});

check('longEndDX/longEndDY nudge only the long strands\' end points', () => {
    const base = GC.computeHairGeometry(GC.HEAD_CX, NEUTRAL_HAIR_PARAMS);
    const nudged = GC.computeHairGeometry(GC.HEAD_CX, Object.assign({}, NEUTRAL_HAIR_PARAMS, { longEndDX: 5, longEndDY: 5 }));
    base.longStrands.forEach((s0, i) => {
        const s = nudged.longStrands[i];
        assert.strictEqual(s.x1, s0.x1, 'long-strand start should be untouched');
        assert.strictEqual(s.x2, s0.x2 + 5);
        assert.strictEqual(s.y2, s0.y2 + 5);
    });
    // bristles must be completely unaffected by long-strand-only knobs
    assert.deepStrictEqual(nudged.bristles, base.bristles);
});

check('renderMarkup honors opts.hairParams end-to-end on a figure-bearing state', () => {
    const defaultMarkup = GC.renderMarkup('CONFIDENT');
    const tunedMarkup = GC.renderMarkup('CONFIDENT', { hairParams: { xOffset: 20 } });
    assert.notStrictEqual(defaultMarkup, tunedMarkup, 'a hairParams override should change the rendered markup');
});

console.log(passed + ' passed');
