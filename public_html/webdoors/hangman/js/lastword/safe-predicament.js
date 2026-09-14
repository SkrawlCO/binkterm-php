/**
 * Last Word — SKIPPY'S PREDICAMENT: THE SUSPENDED SAFE (accepted,
 * human-signed-off 2026-09-13, Predicament #1).
 *
 * Proves — and now ships — Last Word's six-strike mechanic working with a
 * different comedic environmental threat than Hangman's original gallows,
 * with the accepted gallows/apparatus (js/lastword/gallows-character.js)
 * completely untouched and the game itself (strikes, scoring, hints,
 * puzzle selection, Final mechanics, session memory, situational
 * awareness) completely unaffected. The original gallows is preserved as
 * the default presentation — conceptually "Predicament #0" — not Last
 * Word's defining fiction; see the "Predicament: Gallows" comment in
 * gallows-character.js for the same idea from that side. Whether/how a
 * real session eventually chooses or varies its Predicament in production
 * is intentionally still an open decision — this module is the first
 * accepted Predicament that decision will one day select between.
 *
 * This module renders ONLY the environment threatening Skippy — never
 * Skippy himself. It plugs into gallows-character.js's one small additive
 * abstraction point (`opts.apparatus`, a `function (stateName) -> markup`)
 * so every state still reuses Skippy's exact canonical pose/face/hair/
 * pocket-protector/speech-bubble rendering untouched — the world around him
 * changes, not who he is. See gallows-character.js's "CONSCIOUS BOUNDED
 * FORK #3" comment for the mechanism.
 *
 * Deliberately pure/DOM-free/stateless, same discipline as
 * gallows-character.js: a function of (stateName) -> SVG markup string,
 * nothing else. No rng needed — the safe's position/size is a fixed
 * function of state, not randomized.
 *
 * Visual language: a heavy square safe (flat fill so it reads as a solid
 * object, distinct from Skippy's thin line-art) rigged from a FIXED
 * ceiling anchor at Skippy's stage-right, swinging in on a diagonal cable
 * as strikes rise. It enters upper-right and small (his own worry, not
 * yet a real threat), then grows, lowers, AND converges horizontally onto
 * Skippy's own head/body axis (gallows-character.js's HEAD_CX=78) —
 * closer, bigger, AND aimed at him reads as "about to land ON him", not
 * just "descending somewhere nearby". Both axes move monotonically with
 * strikes; nothing here alters Skippy's own accepted poses — only the
 * threat moves toward him.
 *
 * At COMEDIC_DEFEAT the cable has snapped and the safe has landed at that
 * same axis (continuing Strike 5's flight path), riffing on the same
 * "no gore" comedic-disappearance beat gallows-character.js's own
 * COMEDIC_DEFEAT scene already draws (that scene's vanish-gag debris/
 * footmarks/NEXT CONTESTANT sign are shared, predicament-agnostic, and
 * untouched by this module). At SAVED the safe is winched back up out of
 * the way, small and inert.
 *
 * OCCLUSION: this module does none of its own — see the "HUMAN-GATE
 * CORRECTION #3" comment further down. Skippy occluding the safe wherever
 * their silhouettes intersect is handled entirely by gallows-character.js,
 * using the exact same per-state pose geometry it renders him with.
 */
(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.LastWordSafePredicament = factory();
    }
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    var INK = '#dbe4f5';
    var SAFE_FILL = '#232733';
    var WARNING = '#f5c168';

    // FIXED rigging anchor — the physical pulley point the cable feeds
    // from, always Skippy's stage-right (never moves). The safe ITSELF
    // swings in from here toward Skippy's own axis as strikes rise; see
    // STATE_GEOMETRY's `cx` below.
    var ANCHOR_X = 175;
    var CEILING_Y = 14;

    // Skippy's own head/body axis — gallows-character.js's HEAD_CX (his
    // head, and the vertical spine below it, are both centered here at
    // every tilt/pose; see contestant()'s shoulder/hip bars). The safe's
    // `cx` converges onto this exact value by Strike 5, so "directly
    // overhead" means directly over the same x his figure occupies, not
    // just "lower".
    var SKIPPY_AXIS_X = 78;

    // Per-state geometry for the six hanging strike-states (CONFIDENT has
    // no box yet — see apparatus() below). `size` = safe's side length,
    // `cy`/`cx` = its center. Growing size, lowering cy, AND cx converging
    // toward SKIPPY_AXIS_X together read as "the safe is closing in on
    // him", not just "descending somewhere off to the side". Monotonic in
    // both axes: cx strictly decreases toward SKIPPY_AXIS_X, cy strictly
    // increases, size strictly grows, strike over strike.
    var STATE_GEOMETRY = {
        CONFUSED: { cx: 170, cy: 40, size: 46, wobble: 0 },   // upper-right — Skippy notices it
        CONCERNED: { cx: 145, cy: 56, size: 53, wobble: 0 },  // lower, somewhat closer
        NERVOUS: { cx: 118, cy: 74, size: 60, wobble: -4 },   // visibly drifting/converging toward him
        PLEADING: { cx: 98, cy: 92, size: 67, wobble: 5 },    // overlapping his danger zone (head sits at cx 56-100)
        TERRIFIED: { cx: 80, cy: 108, size: 75, wobble: -6 }  // directly over his own head/body axis
    };

    // COMEDIC_DEFEAT's landed safe sits on Skippy's own axis, continuing
    // Strike 5's flight path (cx 80 -> 78) rather than jumping sideways —
    // it fell ON him, it didn't stay parked to the side. Large, since it's
    // now close-up.
    var LANDED_CX = SKIPPY_AXIS_X;
    var LANDED_CY = 205;
    var LANDED_SIZE = 82;

    // SAVED retracts the safe back to a small, high, inert shape — same
    // "low threat" read as CONFIDENT's cable-stub-only hint, but shown as
    // a fully drawn (harmless) safe rather than an absence, since a caller
    // who just solved the puzzle earned seeing it made safe.
    var SAVED_SIZE = 32;
    var SAVED_CY = 32;

    // HUMAN-GATE CORRECTION #3 ("occlusion root fix"): corrections #1 and
    // #2 masked the safe against fixed approximation shapes (a face circle,
    // then a 3-blob torso/head/legs silhouette) — both were geometrically
    // independent of Skippy's ACTUAL per-state pose/tilt, so they drifted
    // out of alignment at poses those shapes didn't anticipate (exactly
    // what the human-gate retest caught at Strikes 3-5). This module no
    // longer does ANY occlusion work itself: `apparatus()` below returns
    // plain, unmasked geometry — occlusion now lives entirely in
    // gallows-character.js's `apparatusFor()`, which has access to the
    // SAME `poseOpts` object each SCENES function is about to pass to its
    // own real, visible `contestant(poseOpts)` call. That guarantees the
    // occlusion shape is always derived from Skippy's actual current pose,
    // not a separate approximation this module would have to keep in sync
    // by hand. See gallows-character.js's `contestantSilhouette()` and
    // `apparatusFor()` for the mechanism; this module supplies only the
    // `opts.apparatus` hook gallows-character.js already wraps for it.

    function ceilingMount(opacity) {
        return (
            '<g stroke="' + INK + '" stroke-width="2.5" fill="none" stroke-linecap="round" opacity="' + opacity + '">' +
            '<path d="M' + (ANCHOR_X - 14) + ',' + CEILING_Y + ' L' + (ANCHOR_X + 14) + ',' + CEILING_Y + '"/>' +
            '<path d="M' + (ANCHOR_X - 9) + ',' + CEILING_Y + ' L' + ANCHOR_X + ',' + (CEILING_Y + 8) + ' L' + (ANCHOR_X + 9) + ',' + CEILING_Y + '"/>' +
            '</g>'
        );
    }

    // Diagonal cable from the FIXED rigging anchor down to the safe's
    // current top-center — as the box's cx converges toward Skippy, this
    // line visibly swings inward with it (a real pendulum reads as
    // "converging", not a vertical line that would imply the box teleports
    // sideways at a fixed drop point).
    function cable(fromX, fromY, toX, toY, opacity) {
        return '<path d="M' + fromX + ',' + fromY + ' L' + toX + ',' + toY + '" stroke="' + INK +
            '" stroke-width="2.5" fill="none" stroke-linecap="round" opacity="' + (opacity || 0.85) + '"/>';
    }

    // A frayed/snapped cable end for COMEDIC_DEFEAT — a short jagged
    // zigzag stub instead of a clean line straight down to a box, so it
    // reads as "broke", not "still holding something offscreen".
    function frayedCableStub(x, y) {
        return (
            '<path d="M' + x + ',' + CEILING_Y + ' L' + x + ',' + (y - 10) +
            ' L' + (x - 5) + ',' + (y - 4) + ' L' + (x + 4) + ',' + (y + 2) + '" ' +
            'stroke="' + INK + '" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round" opacity="0.7"/>'
        );
    }

    /**
     * The safe body itself: heavy square, a simple offset door seam, a
     * small wheel/handle on the door, and — once it's big enough to stay
     * legible (STRIKE 2 / CONCERNED onward) — a tiny cartoon warning mark.
     * Kept to a handful of primitives on purpose (README-level "avoid
     * excessive detail" instruction): a rect, one seam line, one handle
     * (circle + two spokes), one small triangle+"!" — nothing more.
     */
    function safeBox(cx, cy, size, wobbleDeg) {
        var half = size / 2;
        var x = cx - half, y = cy - half;
        var seamX = cx + size * 0.12;
        var handleR = Math.max(3, size * 0.1);
        var group = (
            '<rect x="' + x + '" y="' + y + '" width="' + size + '" height="' + size + '" rx="' + (size * 0.08) + '" ' +
            'fill="' + SAFE_FILL + '" stroke="' + INK + '" stroke-width="2.5"/>' +
            '<path d="M' + seamX + ',' + y + ' L' + seamX + ',' + (y + size) + '" ' +
            'stroke="' + INK + '" stroke-width="1.6" opacity="0.7"/>' +
            '<circle cx="' + seamX + '" cy="' + cy + '" r="' + handleR + '" fill="none" stroke="' + INK + '" stroke-width="2"/>' +
            '<path d="M' + (seamX - handleR - 3) + ',' + cy + ' L' + (seamX + handleR + 3) + ',' + cy +
            ' M' + seamX + ',' + (cy - handleR - 3) + ' L' + seamX + ',' + (cy + handleR + 3) + '" ' +
            'stroke="' + INK + '" stroke-width="1.6" stroke-linecap="round"/>'
        );
        if (size >= 53) {
            // tiny warning triangle + "!" near the top-left corner of the
            // door face — scaled to the box so it stays readable at every
            // size it appears at, never crowding the handle/seam.
            var wx = x + size * 0.22, wy = y + size * 0.28;
            var tri = size * 0.16;
            var fontSize = Math.max(8, size * 0.15);
            group += (
                '<path d="M' + wx + ',' + (wy + tri) + ' L' + (wx + tri) + ',' + (wy + tri) + ' L' + (wx + tri / 2) + ',' + wy + ' Z" ' +
                'fill="none" stroke="' + WARNING + '" stroke-width="1.6" stroke-linejoin="round"/>' +
                '<text x="' + (wx + tri / 2) + '" y="' + (wy + tri - 1) + '" text-anchor="middle" ' +
                'font-size="' + fontSize + '" font-weight="bold" fill="' + WARNING + '">!</text>'
            );
        }
        if (wobbleDeg) {
            return '<g transform="rotate(' + wobbleDeg + ' ' + cx + ' ' + (y - 6) + ')">' + group + '</g>';
        }
        return group;
    }

    function whamBurst(cx, cy) {
        var g = '<g stroke="' + WARNING + '" stroke-width="2.2" stroke-linecap="round" opacity="0.8">';
        var rays = [ -40, -15, 10, 35, 200, 225, 250 ];
        rays.forEach(function (deg) {
            var rad = deg * Math.PI / 180;
            var x1 = cx + Math.cos(rad) * 30, y1 = cy + Math.sin(rad) * 30;
            var x2 = cx + Math.cos(rad) * 54, y2 = cy + Math.sin(rad) * 54;
            g += '<path d="M' + x1 + ',' + y1 + ' L' + x2 + ',' + y2 + '"/>';
        });
        g += '</g>';
        return g +
            '<text x="' + cx + '" y="' + (cy - 46) + '" text-anchor="middle" font-size="20" ' +
            'font-weight="bold" fill="' + WARNING + '" transform="rotate(-6 ' + cx + ' ' + (cy - 46) + ')">WHAM!</text>';
    }

    // Per-state raw geometry. apparatus() (below) returns this unchanged —
    // gallows-character.js's own apparatusFor() is what wraps it in the
    // pose-accurate occlusion mask, whenever it's called with a poseOpts.
    function apparatusBody(stateName) {
        if (stateName === 'CONFIDENT') {
            // "Something exists above/outside his comfort zone" — cable
            // hint only, no box yet, low opacity so it stays non-threatening.
            // Hangs straight down from the fixed anchor (nothing to
            // converge toward yet).
            return ceilingMount(0.4) + cable(ANCHOR_X, CEILING_Y + 6, ANCHOR_X, CEILING_Y + 22, 0.4);
        }
        if (stateName === 'COMEDIC_DEFEAT') {
            // Cable snapped, safe landed on Skippy's own axis — the direct
            // continuation of Strike 5's flight path. No standing Skippy
            // figure here — gallows-character.js's COMEDIC_DEFEAT scene
            // never calls contestant() for ANY predicament, so this stays
            // true automatically; this apparatus only supplies the
            // WHAM/landed safe in place of the accepted rig's open trapdoor.
            return frayedCableStub(ANCHOR_X, CEILING_Y + 30) +
                safeBox(LANDED_CX, LANDED_CY, LANDED_SIZE, 0) +
                whamBurst(LANDED_CX, LANDED_CY - LANDED_SIZE * 0.15);
        }
        if (stateName === 'SAVED') {
            // Winched back up and inert, straight above its fixed anchor —
            // the tiny payoff the fork description allows, nothing more
            // elaborate.
            return ceilingMount(0.7) +
                cable(ANCHOR_X, CEILING_Y + 6, ANCHOR_X, SAVED_CY - SAVED_SIZE / 2, 0.7) +
                safeBox(ANCHOR_X, SAVED_CY, SAVED_SIZE, 0);
        }
        var g = STATE_GEOMETRY[stateName];
        if (!g) { throw new Error('safe-predicament: unknown state ' + stateName); }
        // The cable always feeds from the same fixed rigging anchor, but
        // now swings diagonally in to meet the box wherever it currently
        // sits — visibly "reeling in" toward Skippy as cx converges.
        return ceilingMount(0.85) +
            cable(ANCHOR_X, CEILING_Y + 6, g.cx, g.cy - g.size / 2, 0.85) +
            safeBox(g.cx, g.cy, g.size, g.wobble);
    }

    /**
     * The full apparatus for one gallows-character.js state name — pass as
     * `opts.apparatus` to `LastWordGallowsCharacter.renderMarkup()`. Never
     * draws Skippy himself, and does no occlusion of its own — plain,
     * unmasked geometry every time (see this file's header comment for
     * why); gallows-character.js's `apparatusFor()` is what wraps this
     * output in the pose-accurate occlusion mask before Skippy's own
     * `contestant()` call draws on top of it.
     */
    function apparatus(stateName) {
        return apparatusBody(stateName);
    }

    // One optional, tiny predicament-aware line (the fork's "at most one
    // or two lines" allowance) proving Skippy can recognize what is
    // threatening him specifically, not just that something is. NOT wired
    // into gallows-character.js's own PANIC_LINES pool and NOT used unless
    // a caller explicitly opts in when the safe predicament is active
    // (see app-final-skippy.js's predicament toggle) — the accepted
    // gallows TERRIFIED pool is completely unaffected either way.
    var SAFE_TERRIFIED_LINE = 'THAT SAFE DOESN\'T EVEN HAVE MY COMBINATION!';

    /**
     * Convenience wrapper: render a full Skippy scene with the suspended
     * safe in place of the accepted gallows/rig. `opts` is passed through
     * to gallows-character.js's renderMarkup verbatim (panicLine,
     * showPocket, hairParams, ...) with `apparatus` set for you.
     */
    function renderMarkup(stateName, opts, GallowsCharacter) {
        GallowsCharacter = GallowsCharacter ||
            (typeof module === 'object' && module.exports ? require('./gallows-character.js') : root.LastWordGallowsCharacter);
        var merged = {};
        for (var k in (opts || {})) { if (Object.prototype.hasOwnProperty.call(opts, k)) { merged[k] = opts[k]; } }
        merged.apparatus = apparatus;
        return GallowsCharacter.renderMarkup(stateName, merged);
    }

    return {
        ANCHOR_X: ANCHOR_X,
        CEILING_Y: CEILING_Y,
        SKIPPY_AXIS_X: SKIPPY_AXIS_X,
        STATE_GEOMETRY: STATE_GEOMETRY,
        LANDED_CX: LANDED_CX,
        LANDED_CY: LANDED_CY,
        LANDED_SIZE: LANDED_SIZE,
        SAVED_SIZE: SAVED_SIZE,
        SAVED_CY: SAVED_CY,
        apparatusBody: apparatusBody,
        apparatus: apparatus,
        renderMarkup: renderMarkup,
        SAFE_TERRIFIED_LINE: SAFE_TERRIFIED_LINE
    };
}));
