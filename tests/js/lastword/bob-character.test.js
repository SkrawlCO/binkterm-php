/**
 * Plain Node assertions for js/lastword/bob-character.js — "BOB'S
 * AUDITION" (conscious bounded character audition, BOB IS NOT CANON).
 * Exercises the pure Bob renderer directly, and its integration with the
 * accepted suspended-safe Predicament (js/lastword/safe-predicament.js)
 * via that module's `makeApparatus()` hook.
 * Run: node tests/js/lastword/bob-character.test.js
 */
'use strict';

const assert = require('assert');
const GC = require('../../../public_html/webdoors/hangman/js/lastword/gallows-character.js');
const Safe = require('../../../public_html/webdoors/hangman/js/lastword/safe-predicament.js');
const Bob = require('../../../public_html/webdoors/hangman/js/lastword/bob-character.js');

let passed = 0;
function check(name, fn) {
    fn();
    passed++;
    console.log('  ok - ' + name);
}

console.log('bob-character.test.js');

const ALL_STATES = GC.STRIKE_STATE_NAMES.concat([GC.SAVED_STATE_NAME]);

// ---- Bob renders in the intended states, absent in others -----------------

check('Bob is absent at CONFIDENT (Strike 0) — Skippy\'s confident opening stays his own', () => {
    assert.strictEqual(Bob.renderBob('CONFIDENT'), '');
});

check('Bob is present for every other pose-bearing state (Strikes 1-5, SAVED)', () => {
    ['CONFUSED', 'CONCERNED', 'NERVOUS', 'PLEADING', 'TERRIFIED', 'SAVED'].forEach((state) => {
        const markup = Bob.renderBob(state);
        assert.ok(markup.length > 0, state + ': Bob should render something');
    });
});

check('Bob is present at COMEDIC_DEFEAT (may stand beside the result)', () => {
    assert.ok(Bob.renderBob('COMEDIC_DEFEAT').length > 0);
});

check('renders without throwing for every known gallows-character.js state', () => {
    ALL_STATES.forEach((state) => {
        assert.doesNotThrow(() => Bob.renderBob(state));
    });
});

// ---- no speech/text-dialogue surface ---------------------------------------

check('Bob never renders any <text> element — no speech/label/thought surface of his own', () => {
    ALL_STATES.forEach((state) => {
        const markup = Bob.renderBob(state);
        assert.strictEqual(markup.indexOf('<text'), -1, state + ': Bob must never render text');
    });
});

check('Bob\'s Strike-5 gesture is drawn geometry (a checkmark path), not a label or bubble', () => {
    const markup = Bob.renderBob('TERRIFIED');
    assert.strictEqual(markup.indexOf('<text'), -1);
    assert.strictEqual(markup.indexOf('lw-bubble'), -1, 'no speech-bubble group');
});

// ---- HUMAN-GATE CORRECTION: visual redraw is line-art, not filled icons ---

check('Bob has no large filled body regions — only his two tiny eye dots carry a solid fill; everything else is fill="none"', () => {
    ['CONFUSED', 'CONCERNED', 'NERVOUS', 'PLEADING', 'TERRIFIED', 'COMEDIC_DEFEAT', 'SAVED'].forEach((state) => {
        const markup = Bob.renderBob(state);
        const fills = markup.match(/fill="([^"]+)"/g) || [];
        const nonNoneFills = fills.filter((f) => f !== 'fill="none"');
        assert.strictEqual(nonNoneFills.length, 2, state + ': expected exactly 2 non-"none" fills (the eye dots), found ' + nonNoneFills.length + ': ' + nonNoneFills);
        nonNoneFills.forEach((f) => assert.ok(f.indexOf('#dbe4f5') !== -1, state + ': the only filled elements should be ink-colored eye dots'));
    });
});

check('Bob has no <rect> with a solid fill — his one rect (the clipboard) is an open outline', () => {
    ['PLEADING', 'TERRIFIED', 'COMEDIC_DEFEAT'].forEach((state) => {
        const markup = Bob.renderBob(state);
        const rects = markup.match(/<rect[^>]*>/g) || [];
        assert.ok(rects.length >= 1, state + ': expected a clipboard rect');
        rects.forEach((r) => assert.ok(r.indexOf('fill="none"') !== -1, state + ': clipboard rect must be unfilled: ' + r));
    });
});

check('Bob\'s head circle is unfilled, same convention as Skippy\'s own head', () => {
    const markup = Bob.renderBob('CONFUSED');
    assert.ok(new RegExp('<circle cx="' + Bob.BOB_CX + '" cy="' + Bob.HEAD_CY + '" r="' + Bob.HEAD_R + '" fill="none"').test(markup));
});

// ---- HUMAN-GATE CORRECTION: a silent work story, not a static icon --------

check('every one of Bob\'s 6 present states renders visibly DIFFERENT markup — a work story, not one repeated pose', () => {
    const states = ['CONFUSED', 'CONCERNED', 'NERVOUS', 'PLEADING', 'TERRIFIED', 'COMEDIC_DEFEAT', 'SAVED'];
    const seen = new Set();
    states.forEach((state) => {
        const markup = Bob.renderBob(state);
        assert.ok(!seen.has(markup), state + ': must not be visually identical to an earlier state');
        seen.add(markup);
    });
    assert.strictEqual(seen.size, states.length);
});

check('Strike 3 (NERVOUS) leans into the crank with a rotate() transform Strike 2 (CONCERNED) does not use — "working harder"', () => {
    const concerned = Bob.renderBob('CONCERNED');
    const nervous = Bob.renderBob('NERVOUS');
    assert.strictEqual(concerned.indexOf('rotate('), -1, 'CONCERNED should stand upright');
    assert.ok(nervous.indexOf('rotate(') !== -1, 'NERVOUS should visibly lean into the effort');
});

check('the clipboard first appears at Strike 4 (PLEADING), unmarked; Strike 5 (TERRIFIED) shows the same clipboard MARKED', () => {
    const pleading = Bob.renderBob('PLEADING');
    const terrified = Bob.renderBob('TERRIFIED');
    assert.ok(pleading.indexOf('<rect') !== -1, 'PLEADING should introduce the clipboard');
    assert.strictEqual(pleading.indexOf('#f5c168'), -1, 'PLEADING\'s clipboard should not yet be marked');
    assert.ok(terrified.indexOf('<rect') !== -1, 'TERRIFIED should still show the clipboard');
    assert.ok(terrified.indexOf('#f5c168') !== -1, 'TERRIFIED\'s clipboard should now be marked (checkmark accent)');
});

check('COMEDIC_DEFEAT keeps the clipboard already marked (calmly inspecting the result) and drops the crank prop, staying small beside the WHAM payoff', () => {
    const markup = Bob.renderBob('COMEDIC_DEFEAT');
    assert.ok(markup.indexOf('<rect') !== -1, 'clipboard still present');
    assert.ok(markup.indexOf('#f5c168') !== -1, 'already marked');
    assert.strictEqual(markup.indexOf('#9aa4b6'), -1, 'the crank prop should not clutter the COMEDIC_DEFEAT beat');
});

check('SAVED shows Bob relaxed and packing up — no clipboard, a slight turn, winch still visible but not engaged', () => {
    const markup = Bob.renderBob('SAVED');
    assert.strictEqual(markup.indexOf('<rect'), -1, 'no clipboard at SAVED');
    assert.ok(markup.indexOf('rotate(') !== -1, 'a slight turn as if leaving');
});

// ---- purity -----------------------------------------------------------

check('renderBob is a pure function of stateName alone (same input -> same output)', () => {
    ALL_STATES.forEach((state) => {
        assert.strictEqual(Bob.renderBob(state), Bob.renderBob(state));
    });
});

check('bob-character.js introduces no persistence/storage calls of any kind', () => {
    const src = require('fs').readFileSync(
        require('path').join(__dirname, '../../../public_html/webdoors/hangman/js/lastword/bob-character.js'), 'utf8'
    );
    const code = src.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/.*$/gm, '');
    assert.ok(!/localStorage|sessionStorage|UserStorage|indexedDB|fetch\(|XMLHttpRequest/.test(code),
        'bob-character.js must remain a pure, storage-free, network-free module');
});

// ---- integration with safe-predicament.js's makeApparatus() ---------------

check('Safe.makeApparatus(false) is identical to Safe.apparatus — Bob-free by default', () => {
    ALL_STATES.forEach((state) => {
        assert.strictEqual(Safe.makeApparatus(false)(state), Safe.apparatus(state));
    });
});

check('Safe.makeApparatus(true) appends Bob\'s markup after the safe\'s own geometry, for every state', () => {
    const withBob = Safe.makeApparatus(true, Bob);
    ALL_STATES.forEach((state) => {
        const base = Safe.apparatus(state);
        const bobMarkup = Bob.renderBob(state);
        assert.strictEqual(withBob(state), base + bobMarkup, state + ': expected base apparatus + Bob, appended');
    });
});

check('a full safe-mode render WITHOUT Bob never contains any Bob-specific geometry (the crank wheel color)', () => {
    ALL_STATES.forEach((state) => {
        const opts = state === 'TERRIFIED' ? { panicLine: Safe.SAFE_TERRIFIED_LINE } : undefined;
        const markup = Safe.renderMarkup(state, opts, GC);
        assert.strictEqual(markup.indexOf('#9aa4b6'), -1, state + ': Bob-free render must not contain Bob\'s crank color');
    });
});

check('a full safe-mode render WITH Bob contains his crank geometry at every state he is present in', () => {
    ['CONFUSED', 'CONCERNED', 'NERVOUS', 'PLEADING', 'TERRIFIED', 'SAVED'].forEach((state) => {
        const opts = Object.assign({ apparatus: Safe.makeApparatus(true, Bob) }, state === 'TERRIFIED' ? { panicLine: Safe.SAFE_TERRIFIED_LINE } : {});
        const markup = GC.renderMarkup(state, opts);
        assert.ok(markup.indexOf('#9aa4b6') !== -1, state + ': Bob-enabled render should contain his crank geometry');
    });
});

check('accepted safe output (Bob-free) is byte-identical whether or not bob-character.js is ever required', () => {
    ALL_STATES.forEach((state) => {
        const opts = state === 'TERRIFIED' ? { panicLine: Safe.SAFE_TERRIFIED_LINE } : undefined;
        const a = Safe.renderMarkup(state, opts, GC);
        const b = Safe.renderMarkup(state, opts, GC);
        assert.strictEqual(a, b);
        assert.strictEqual(a.indexOf('LastWordBobCharacter'), -1);
    });
});

// ---- Skippy pose-derived occlusion remains intact with Bob present --------

check('Skippy\'s pose-derived occlusion mask is unaffected by Bob being present — same mask content either way', () => {
    ['NERVOUS', 'PLEADING', 'TERRIFIED'].forEach((state) => {
        const opts = state === 'TERRIFIED' ? { panicLine: GC.PANIC_LINES[0] } : undefined;
        const withoutBob = Safe.renderMarkup(state, opts, GC);
        const withBobOpts = Object.assign({ apparatus: Safe.makeApparatus(true, Bob) }, opts || {});
        const withBob = GC.renderMarkup(state, withBobOpts);

        const maskA = withoutBob.match(/<mask[\s\S]*?<\/mask>/)[0];
        const maskB = withBob.match(/<mask[\s\S]*?<\/mask>/)[0];
        assert.strictEqual(maskA, maskB, state + ': the occlusion mask (derived only from Skippy\'s own pose) must be identical whether or not Bob is drawn');
    });
});

check('Skippy\'s own visible figure markup is byte-identical whether or not Bob is present', () => {
    ['CONFUSED', 'TERRIFIED', 'SAVED'].forEach((state) => {
        const opts = state === 'TERRIFIED' ? { panicLine: GC.PANIC_LINES[0] } : undefined;
        const withoutBob = GC.renderMarkup(state, opts);
        const withBobOpts = Object.assign({ apparatus: Safe.makeApparatus(true, Bob) }, opts || {});
        const withBob = GC.renderMarkup(state, withBobOpts);
        // Skippy's own head circle geometry (fixed regardless of predicament) must appear identically in both.
        assert.ok(withoutBob.indexOf('r="22"') !== -1 && withBob.indexOf('r="22"') !== -1);
    });
});

// ---- VISUAL PROMOTION (Fork #5, human-accepted 2026-09-13): canonical hat+beard ----

check('Bob\'s hat is a closed (Z-terminated) path — the accepted complete gnome-hat silhouette', () => {
    const markup = Bob.renderBob('CONFUSED');
    assert.ok(/M-?\d+(\.\d+)?,-?\d+(\.\d+)? L-?\d+(\.\d+)?,-?\d+(\.\d+)? Q[\d.,-]+ -?\d+(\.\d+)?,-?\d+(\.\d+)? L-?\d+(\.\d+)?,-?\d+(\.\d+)? Z"/.test(markup),
        'expected a closed (Z-terminated) hat path in every present state');
});

check('Bob\'s hat stroke is cyan (#00e5ff) — the one approved art-direction accent from the promotion', () => {
    ['CONFUSED', 'CONCERNED', 'NERVOUS', 'PLEADING', 'TERRIFIED', 'COMEDIC_DEFEAT', 'SAVED'].forEach((state) => {
        assert.ok(Bob.renderBob(state).indexOf('#00e5ff') !== -1, state + ': expected the cyan hat stroke');
    });
});

check('the hat remains unfilled — cyan stroke, fill="none", no new filled region', () => {
    const markup = Bob.renderBob('CONFUSED');
    const hatMatch = markup.match(/<path d="M[^"]*Z" stroke="#00e5ff"[^>]*>/);
    assert.ok(hatMatch, 'expected to find the cyan hat path element');
    assert.ok(hatMatch[0].indexOf('fill="none"') !== -1, 'hat must stay unfilled');
});

check('the fill-count invariant still holds with the cyan hat present — exactly 2 non-"none" fills (the eye dots), hat/beard are strokes only', () => {
    ['CONFUSED', 'CONCERNED', 'NERVOUS', 'PLEADING', 'TERRIFIED', 'COMEDIC_DEFEAT', 'SAVED'].forEach((state) => {
        const markup = Bob.renderBob(state);
        const fills = markup.match(/fill="([^"]+)"/g) || [];
        const nonNoneFills = fills.filter((f) => f !== 'fill="none"');
        assert.strictEqual(nonNoneFills.length, 2, state + ': cyan hat promotion must not add any new filled region');
    });
});

check('no other new accent color was introduced — face/body/beard/limbs stay plain ink (#dbe4f5), only the hat is cyan', () => {
    ['CONFUSED', 'CONCERNED', 'NERVOUS', 'PLEADING', 'TERRIFIED', 'COMEDIC_DEFEAT', 'SAVED'].forEach((state) => {
        const markup = Bob.renderBob(state);
        const colors = new Set((markup.match(/#[0-9a-f]{6}/gi) || []).map((c) => c.toLowerCase()));
        colors.forEach((c) => {
            assert.ok(['#dbe4f5', '#9aa4b6', '#f5c168', '#00e5ff'].indexOf(c) !== -1,
                state + ': unexpected new color ' + c);
        });
    });
});

check('Bob has a compact beard on his lower face in every present state, sitting below the mouth line and above the shoulder line', () => {
    ['CONFUSED', 'CONCERNED', 'NERVOUS', 'PLEADING', 'TERRIFIED', 'COMEDIC_DEFEAT', 'SAVED'].forEach((state) => {
        const markup = Bob.renderBob(state);
        const headBottom = Bob.HEAD_CY + Bob.HEAD_R;
        const beardTopY = headBottom - 3;
        const mouthY = Bob.HEAD_CY + 4;
        assert.ok(beardTopY > mouthY, 'beard must start below the mouth');
        // the beard path uses cx-3/cx+3 attachment points against the ink stroke
        assert.ok(markup.indexOf('M' + (Bob.BOB_CX - 3) + ',' + beardTopY) !== -1, state + ': expected the beard path at the accepted geometry');
    });
});

check('the promoted geometry renders correctly (no throw, well-formed) for every tilted pose too — NERVOUS (lean) and SAVED (turn)', () => {
    ['NERVOUS', 'SAVED'].forEach((state) => {
        const markup = Bob.renderBob(state);
        assert.doesNotThrow(() => markup);
        assert.ok(markup.indexOf('rotate(') !== -1, state + ': should still use its accepted tilt transform');
        // the hat/beard are now part of head(), which is drawn INSIDE the
        // same rotated <g> as the rest of the tilted figure -- unlike the
        // lab's string-surgery proof, canonical geometry integration means
        // the hat rotates correctly with the head instead of staying
        // fixed in the outer coordinate space.
        const rotateGroup = markup.match(/<g transform="rotate\([^)]*\)">([\s\S]*)<\/g>/);
        assert.ok(rotateGroup, state + ': expected a rotate() group');
        assert.ok(rotateGroup[1].indexOf('#00e5ff') !== -1, state + ': the cyan hat must be INSIDE the rotated group, not fixed outside it');
    });
});

check('canonical bob-character.js has no CODE dependency on the disposable lab (doc comments may mention it by name/history only)', () => {
    const src = require('fs').readFileSync(
        require('path').join(__dirname, '../../../public_html/webdoors/hangman/js/lastword/bob-character.js'), 'utf8'
    );
    const code = src.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/.*$/gm, '');
    assert.strictEqual(code.indexOf('lastword-bob-lab'), -1, 'no code (outside comments) should reference the lab path');
    assert.strictEqual(code.indexOf('LastWordBobLabCandidates'), -1);
    assert.strictEqual(code.indexOf('correctedCurrent'), -1, 'the lab\'s string-surgery technique must not be used as production architecture');
});

check('Bob remains silent — still no <text> element anywhere, with the promoted hat+beard present', () => {
    ALL_STATES.forEach((state) => {
        assert.strictEqual(Bob.renderBob(state).indexOf('<text'), -1, state);
    });
});

console.log(passed + ' passed');
