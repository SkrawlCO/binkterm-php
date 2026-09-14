/**
 * Plain Node assertions for js/lastword/safe-predicament.js — SKIPPY'S
 * PREDICAMENT: THE SUSPENDED SAFE (accepted, human-signed-off
 * 2026-09-13, Predicament #1). Exercises the pure alternate-apparatus
 * module directly, and its integration with the accepted
 * js/lastword/gallows-character.js via that module's `opts.apparatus`
 * hook and pose-derived occlusion mask.
 * Run: node tests/js/lastword/safe-predicament.test.js
 */
'use strict';

const assert = require('assert');
const GC = require('../../../public_html/webdoors/hangman/js/lastword/gallows-character.js');
const Safe = require('../../../public_html/webdoors/hangman/js/lastword/safe-predicament.js');

let passed = 0;
function check(name, fn) {
    fn();
    passed++;
    console.log('  ok - ' + name);
}

console.log('safe-predicament.test.js');

const ALL_STATES = GC.STRIKE_STATE_NAMES.concat([GC.SAVED_STATE_NAME]);

// ---- renders for every strike state 0-6 plus SAVED ------------------------

check('safe apparatus renders for every strike state 0-6 without throwing', () => {
    GC.STRIKE_STATE_NAMES.forEach((name) => {
        const markup = Safe.renderMarkup(name, null, GC);
        assert.ok(markup.indexOf('<svg') === 0, name + ' should produce svg markup');
        assert.ok(markup.indexOf('data-state="' + name + '"') !== -1);
    });
});

check('SAVED renders correctly under the safe predicament', () => {
    const markup = Safe.renderMarkup('SAVED', null, GC);
    assert.ok(markup.indexOf('data-state="SAVED"') !== -1);
    assert.ok(markup.indexOf('<rect') !== -1, 'SAVED should still show the (retracted) safe');
});

// ---- threat visibly/progressively advances with strike count --------------
// HUMAN-GATE CORRECTION: the safe must converge toward Skippy's own
// head/body axis (not just descend beside him) as strikes rise.

check('the safe box grows and lowers monotonically from CONFUSED through TERRIFIED', () => {
    const order = ['CONFUSED', 'CONCERNED', 'NERVOUS', 'PLEADING', 'TERRIFIED'];
    let prevSize = -Infinity, prevCy = -Infinity;
    order.forEach((state) => {
        const g = Safe.STATE_GEOMETRY[state];
        assert.ok(g.size > prevSize, state + ' size should be larger than the previous strike (' + g.size + ' vs ' + prevSize + ')');
        assert.ok(g.cy > prevCy, state + ' cy should be lower than the previous strike (' + g.cy + ' vs ' + prevCy + ')');
        prevSize = g.size;
        prevCy = g.cy;
    });
});

check('the safe converges HORIZONTALLY onto Skippy\'s own axis monotonically from CONFUSED through TERRIFIED', () => {
    const order = ['CONFUSED', 'CONCERNED', 'NERVOUS', 'PLEADING', 'TERRIFIED'];
    let prevDistance = Infinity;
    order.forEach((state) => {
        const g = Safe.STATE_GEOMETRY[state];
        const distance = Math.abs(g.cx - Safe.SKIPPY_AXIS_X);
        assert.ok(distance < prevDistance,
            state + ' should be closer to Skippy\'s axis (cx=' + Safe.SKIPPY_AXIS_X + ') than the previous strike ' +
            '(distance ' + distance + ' vs ' + prevDistance + ')');
        prevDistance = distance;
    });
});

check('by Strike 5 (TERRIFIED) the safe sits directly on Skippy\'s own axis, not merely lower/bigger', () => {
    const g = Safe.STATE_GEOMETRY.TERRIFIED;
    assert.ok(Math.abs(g.cx - Safe.SKIPPY_AXIS_X) <= 4,
        'TERRIFIED cx (' + g.cx + ') should sit within a few px of Skippy\'s axis (' + Safe.SKIPPY_AXIS_X + ')');
});

check('by Strike 4 (PLEADING) the safe already visibly overlaps Skippy\'s danger zone (head spans roughly cx 56-100)', () => {
    const g = Safe.STATE_GEOMETRY.PLEADING;
    const boxLeft = g.cx - g.size / 2, boxRight = g.cx + g.size / 2;
    assert.ok(boxRight >= 56 && boxLeft <= 100, 'PLEADING\'s safe box should overlap Skippy\'s head x-range by Strike 4');
});

check('CONFUSED (Strike 1) starts upper-right, clearly NOT yet overlapping Skippy\'s head x-range', () => {
    const g = Safe.STATE_GEOMETRY.CONFUSED;
    const boxLeft = g.cx - g.size / 2;
    assert.ok(boxLeft > 100, 'CONFUSED should still read as "off to the side", not already over him');
});

check('COMEDIC_DEFEAT lands on the same axis Strike 5 was converging toward, not a different spot', () => {
    assert.strictEqual(Safe.LANDED_CX, Safe.SKIPPY_AXIS_X);
    const terrifiedCx = Safe.STATE_GEOMETRY.TERRIFIED.cx;
    assert.ok(Math.abs(Safe.LANDED_CX - terrifiedCx) <= 4,
        'the landed safe\'s cx should be a direct continuation of Strike 5\'s cx, not a jump');
});

check('CONFIDENT shows only a cable/ceiling hint, no safe box yet', () => {
    // Use apparatusBody() (pre-mask) here — apparatus() now always wraps
    // every state in the face-clear mask's own <defs><rect.../></mask>,
    // which is unrelated to whether a safe BOX was drawn for this state.
    const markup = Safe.apparatusBody('CONFIDENT');
    assert.ok(markup.indexOf('<rect') === -1, 'CONFIDENT should not draw any safe geometry rect');
    assert.ok(markup.indexOf('<path') !== -1, 'CONFIDENT should still draw the ceiling/cable hint');
});

// ---- COMEDIC_DEFEAT: no standing Skippy figure -----------------------------

check('COMEDIC_DEFEAT under the safe predicament contains no normal standing Skippy figure', () => {
    const markup = Safe.renderMarkup('COMEDIC_DEFEAT', null, GC);
    // gallows-character.js's own COMEDIC_DEFEAT scene never calls
    // contestant() for any predicament — that scene has no head <circle>
    // of contestant()'s HEAD_R radius, whichever apparatus is plugged in.
    assert.ok(markup.indexOf('r="22"') === -1, 'no contestant head circle (r=HEAD_R=22) should be present');
    assert.ok(markup.indexOf('WHAM') !== -1, 'the landed safe should show its comedic impact');
});

check('accepted gallows COMEDIC_DEFEAT (no predicament override) also has no standing figure — shared baseline unaffected', () => {
    const markup = GC.renderMarkup('COMEDIC_DEFEAT');
    assert.ok(markup.indexOf('r="22"') === -1);
    assert.ok(markup.indexOf('NEXT') !== -1, 'accepted gallows keeps its own NEXT CONTESTANT sign');
});

// ---- accepted gallows rendering remains byte-identical with no override ---

check('gallows-character.js renders IDENTICALLY to its pre-fork output when opts.apparatus is not supplied', () => {
    ALL_STATES.forEach((name) => {
        const bare = GC.renderMarkup(name);
        const explicitEmptyOpts = GC.renderMarkup(name, {});
        assert.strictEqual(bare, explicitEmptyOpts, name + ' should be unaffected by an opts object with no apparatus key');
    });
});

check('gallows-character.js TERRIFIED default panic-line pool behavior is unaffected by this fork', () => {
    const markup = GC.renderMarkup('TERRIFIED', { panicLine: GC.PANIC_LINES[2] });
    assert.ok(markup.indexOf(GC.PANIC_LINES[2]) !== -1);
    assert.ok(markup.indexOf('WHAM') === -1, 'no safe-predicament content should leak into the accepted gallows render');
});

// ---- selecting safe changes presentation only ------------------------------

check('selecting the safe predicament changes only the apparatus markup, not the contestant pose markup', () => {
    GC.STRIKE_STATE_NAMES.concat([GC.SAVED_STATE_NAME]).forEach((name) => {
        const opts = name === 'TERRIFIED' ? { panicLine: GC.PANIC_LINES[0] } : undefined;
        const gallowsMarkup = GC.renderMarkup(name, opts);
        const safeMarkup = Safe.renderMarkup(name, opts, GC);
        // Both must carry the exact same hair defaults / head circle
        // radius / pocket-protector geometry — i.e. Skippy himself is
        // pixel-identical — for every state EXCEPT COMEDIC_DEFEAT, where
        // neither predicament draws a contestant figure at all.
        if (name === 'COMEDIC_DEFEAT') { return; }
        assert.ok(gallowsMarkup.indexOf('r="22"') !== -1 && safeMarkup.indexOf('r="22"') !== -1,
            name + ': both presentations must still render Skippy\'s canonical head');
        // But the two full markups must differ (the apparatus itself did change).
        assert.notStrictEqual(gallowsMarkup, safeMarkup, name + ': apparatus should visibly differ between predicaments');
    });
});

// ---- purity / no persistence -----------------------------------------------

check('apparatus() is a pure function of stateName alone (same input -> same output)', () => {
    GC.STRIKE_STATE_NAMES.concat([GC.SAVED_STATE_NAME]).forEach((name) => {
        assert.strictEqual(Safe.apparatus(name), Safe.apparatus(name));
    });
});

check('safe-predicament.js introduces no persistence/storage calls of any kind', () => {
    const src = require('fs').readFileSync(
        require('path').join(__dirname, '../../../public_html/webdoors/hangman/js/lastword/safe-predicament.js'), 'utf8'
    );
    const code = src.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/.*$/gm, '');
    assert.ok(!/localStorage|sessionStorage|UserStorage|indexedDB|fetch\(|XMLHttpRequest/.test(code),
        'safe-predicament.js must remain a pure, storage-free, network-free module');
});

check('unknown state name throws rather than silently rendering nothing', () => {
    assert.throws(() => Safe.apparatus('NOT_A_STATE'));
});

// ---- no double speech: gallows-character.js's suppressSpeechBubble hook ---
// (HUMAN-GATE CORRECTION) — the additive hook safe-predicament.js's caller
// (app-final-skippy.js) uses to avoid ever showing two Skippy speech
// bubbles at once. Tested here on gallows-character.js directly since the
// hook lives there; app-final-skippy-dom.test.js covers the full wiring.

check('opts.suppressSpeechBubble omits the baked TERRIFIED bubble entirely', () => {
    const shown = GC.renderMarkup('TERRIFIED', { panicLine: GC.PANIC_LINES[0] });
    const suppressed = GC.renderMarkup('TERRIFIED', { panicLine: GC.PANIC_LINES[0], suppressSpeechBubble: true });
    assert.ok(shown.indexOf(GC.PANIC_LINES[0]) !== -1, 'sanity: the bubble is normally drawn');
    assert.ok(suppressed.indexOf(GC.PANIC_LINES[0]) === -1, 'suppressSpeechBubble must omit the baked bubble/text');
    assert.ok(suppressed.indexOf('lw-bubble') === -1, 'no bubble group at all should be present when suppressed');
});

check('opts.suppressSpeechBubble does not affect Skippy\'s pose — only the bubble', () => {
    const shown = GC.renderMarkup('TERRIFIED', { panicLine: GC.PANIC_LINES[0] });
    const suppressed = GC.renderMarkup('TERRIFIED', { panicLine: GC.PANIC_LINES[0], suppressSpeechBubble: true });
    assert.ok(shown.indexOf('r="22"') !== -1 && suppressed.indexOf('r="22"') !== -1,
        'Skippy\'s canonical head must still render either way');
});

check('omitting suppressSpeechBubble (the default) reproduces the pre-correction accepted output exactly', () => {
    const bare = GC.renderMarkup('TERRIFIED', { panicLine: GC.PANIC_LINES[0] });
    const explicitFalse = GC.renderMarkup('TERRIFIED', { panicLine: GC.PANIC_LINES[0], suppressSpeechBubble: false });
    assert.strictEqual(bare, explicitFalse);
});

// ---- HUMAN-GATE CORRECTION #3: occlusion ROOT FIX --------------------------
// Corrections #1 and #2 masked against fixed approximation shapes
// (independent of Skippy's real per-state pose) — the human retest caught
// real misalignment at Strikes 3-5. The root fix: this module no longer
// does ANY occlusion of its own (`apparatus()` returns plain geometry — see
// its own comment). The occlusion mask now lives in gallows-character.js's
// `apparatusFor()`, built from `contestantSilhouette(poseOpts)` — the SAME
// `poseOpts` object each SCENES function passes to its own real, visible
// `contestant(poseOpts)` call. These tests prove that end-to-end: not
// abstract mask coordinates, but the ACTUAL per-state limb coordinates
// gallows-character.js's own accepted (non-predicament) render produces,
// cross-checked against what ends up inside the safe-mode mask.

/**
 * Extract the 4 limb line-paths (leftLeg/rightLeg/leftArm/rightArm) from a
 * rendered scene's markup, exactly as gallows-character.js's own
 * contestant() draws them: a 3-point `<path d="M{hipOrShoulder},{y} L... L...">`
 * with stroke="#dbe4f5" (INK) stroke-width 2.2 or 2.5, starting at one of
 * the 4 fixed shoulder/hip x-anchors (SHOULDER_L/R=69/87, HIP_L/R=70/86).
 * This reads the REAL rendered coordinates back out of the markup — it
 * does not hardcode or assume any per-state pose values itself.
 */
function realLimbPathsFrom(markup) {
    const re = /<path d="([^"]+)" stroke="#dbe4f5" stroke-width="2\.[25]"/g;
    const out = [];
    let m;
    while ((m = re.exec(markup))) {
        const d = m[1];
        const lCount = (d.match(/ L/g) || []).length;
        if (lCount === 2 && /^M(69|70|86|87),/.test(d)) { out.push(d); }
    }
    return out;
}

const POSE_BEARING_STATES = GC.STRIKE_STATE_NAMES.filter((n) => n !== 'COMEDIC_DEFEAT').concat([GC.SAVED_STATE_NAME]);

check('sanity: exactly 4 real limb paths (leftLeg/rightLeg/leftArm/rightArm) are extractable from the accepted gallows render, for every pose-bearing state', () => {
    POSE_BEARING_STATES.forEach((state) => {
        const opts = state === 'TERRIFIED' ? { panicLine: GC.PANIC_LINES[0] } : undefined;
        const accepted = GC.renderMarkup(state, opts);
        const limbs = realLimbPathsFrom(accepted);
        assert.strictEqual(limbs.length, 4, state + ': expected exactly 4 limb paths, found ' + limbs.length);
    });
});

check('for EVERY pose-bearing state (0-5, SAVED), the safe-mode occlusion mask contains that EXACT state\'s real limb coordinates — not a fixed approximation', () => {
    POSE_BEARING_STATES.forEach((state) => {
        const acceptedOpts = state === 'TERRIFIED' ? { panicLine: GC.PANIC_LINES[0] } : undefined;
        const accepted = GC.renderMarkup(state, acceptedOpts);
        const realLimbs = realLimbPathsFrom(accepted);

        const safeOpts = state === 'TERRIFIED' ? { panicLine: Safe.SAFE_TERRIFIED_LINE } : undefined;
        const safeMarkup = Safe.renderMarkup(state, safeOpts, GC);
        const maskMatch = safeMarkup.match(/<mask[\s\S]*?<\/mask>/);
        assert.ok(maskMatch, state + ': expected an occlusion mask in safe-mode markup');
        const maskContent = maskMatch[0];

        realLimbs.forEach((d) => {
            assert.ok(maskContent.indexOf(d) !== -1,
                state + ': the mask must contain this exact real limb path from the accepted render: ' + d);
        });
    });
});

check('the occlusion mask is DIFFERENT for different states (proving it tracks the actual pose, not a single reused shape)', () => {
    const confidentMarkup = Safe.renderMarkup('CONFIDENT', null, GC);
    const terrifiedMarkup = Safe.renderMarkup('TERRIFIED', { panicLine: Safe.SAFE_TERRIFIED_LINE }, GC);
    const confidentMask = confidentMarkup.match(/<mask[\s\S]*?<\/mask>/)[0];
    const terrifiedMask = terrifiedMarkup.match(/<mask[\s\S]*?<\/mask>/)[0];
    assert.notStrictEqual(confidentMask, terrifiedMask, 'CONFIDENT and TERRIFIED have different poses and must produce different masks');
});

check('the occlusion mask applies the SAME rotate() transform (tilt) the real visible figure uses for that state', () => {
    ['PLEADING', 'TERRIFIED', 'SAVED'].forEach((state) => {
        const opts = state === 'TERRIFIED' ? { panicLine: GC.PANIC_LINES[0] } : undefined;
        const accepted = GC.renderMarkup(state, opts);
        const tiltMatch = accepted.match(/<g transform="rotate\(([-\d.]+) 78 118\)">/);
        assert.ok(tiltMatch, state + ': expected to find the real figure\'s tilt transform');

        const safeOpts = state === 'TERRIFIED' ? { panicLine: Safe.SAFE_TERRIFIED_LINE } : undefined;
        const safeMarkup = Safe.renderMarkup(state, safeOpts, GC);
        const maskContent = safeMarkup.match(/<mask[\s\S]*?<\/mask>/)[0];
        assert.ok(maskContent.indexOf('rotate(' + tiltMatch[1] + ' 78 118)') !== -1,
            state + ': the mask silhouette must rotate by the exact same tilt (' + tiltMatch[1] + ') as the real figure');
    });
});

check('COMEDIC_DEFEAT has no occlusion mask at all — no standing figure is ever drawn there, so nothing needs protecting and the WHAM payoff stays fully visible', () => {
    const markup = Safe.renderMarkup('COMEDIC_DEFEAT', null, GC);
    assert.strictEqual(markup.indexOf('lw-pose-knockout-mask'), -1);
    assert.ok(markup.indexOf('WHAM') !== -1, 'the landed safe\'s impact should be fully visible, uncut');
});

check('safe-predicament.js\'s own apparatus() does no masking of its own — apparatus() === apparatusBody() for every state', () => {
    GC.STRIKE_STATE_NAMES.concat([GC.SAVED_STATE_NAME]).forEach((name) => {
        assert.strictEqual(Safe.apparatus(name), Safe.apparatusBody(name),
            name + ': occlusion must live entirely in gallows-character.js now, not duplicated here');
    });
});

check('the accepted gallows (no predicament override) never contains any occlusion mask markup — this correction only activates for an alternate predicament', () => {
    POSE_BEARING_STATES.forEach((state) => {
        const opts = state === 'TERRIFIED' ? { panicLine: GC.PANIC_LINES[0] } : undefined;
        const markup = GC.renderMarkup(state, opts);
        assert.strictEqual(markup.indexOf('lw-pose-knockout-mask'), -1, state + ': accepted gallows must stay byte-unaffected');
    });
});

check('the occlusion mask is not an obvious large rectangle — its only <rect> is the full-canvas white (visible) backdrop', () => {
    const markup = Safe.renderMarkup('TERRIFIED', { panicLine: Safe.SAFE_TERRIFIED_LINE }, GC);
    const maskContent = markup.match(/<mask[\s\S]*?<\/mask>/)[0];
    assert.ok(maskContent.indexOf('<rect x="0" y="0" width="260" height="260" fill="#fff"') !== -1);
    assert.strictEqual((maskContent.match(/<rect[^>]*fill="#000"/g) || []).length, 0, 'no rectangular cutout shape');
});

console.log(passed + ' passed');
