/**
 * Last Word — SKIPPY V1 (ACCEPTED, character lab CLOSED).
 *
 * The contestant is canonically named SKIPPY. This module is the
 * accepted, human-signed-off Skippy character/apparatus renderer:
 * pickStateName/renderMarkup/pickPanicLine are the canonical API a future
 * integration should call. It is NOT YET wired into M1D, M1C, or the live
 * catalog Hangman experience (js/lastword/app-final.js's drawGallowsOn
 * canvas stick-figure) — that integration, plus restrained state-aware
 * idle chatter, is intentionally the next piece of work, not this one.
 *
 * Renders a small cast: a contestant who reacts to strikes, and a wacky
 * game-show elimination rig that assembles around him one piece per
 * strike. No gore, no literal hanging — the rig is a blinking-light/
 * mechanical-claw/trapdoor contraption, and defeat is a comedic
 * disappearance gag (trapdoor + shoes left behind + "NEXT CONTESTANT"
 * sign), not a hanged figure.
 *
 * Skippy went through many iterations of contestant geometry and hair
 * before landing here (an earlier isolated comparison/tuning harness this
 * file was developed against has since been removed as disposable dev
 * scaffolding, its job done). His hair specifically ended on a human-in-
 * the-loop process: after several generated-redesign passes, a small
 * BARBER tool exposed `computeHairGeometry()`'s adjustable knobs
 * (xOffset/yOffset/spacing/length/angle/longEndDX/longEndDY) live so a
 * human could tune the geometry by eye instead of asking for another
 * redesign. Matt manually tuned it and chose final values, now baked in
 * as `SKIPPY_HAIR_DEFAULTS` — Skippy V1's frozen canon haircut, human-
 * accepted live. Do not reinterpret, "improve," normalize, or redraw it;
 * only changing those authoritative numbers changes the haircut, and no
 * further character/art iteration is authorized without a new request.
 *
 * Apparatus, the 8-state mapping/poses, states 0-6/SAVED, the Strike-5
 * pleading pose, the rotating panic lines, the pocket-protector toggle,
 * the eye language, and every speech bubble's placement/text are all
 * accepted as shipped here.
 *
 * Predicament: Gallows — this rig/apparatus is Last Word's original,
 * default environmental threat, preserved exactly as accepted above. Since
 * the suspended-safe Predicament (js/lastword/safe-predicament.js,
 * accepted 2026-09-13) proved the six-strike mechanic works independently
 * of this specific fiction, the gallows is now conceptually "Predicament
 * #0" rather than Last Word's defining metaphor — Skippy's actual
 * accepted pose/face/silhouette (this file) is what stays constant across
 * any Predicament; only the threat surrounding him changes. See
 * `apparatusFor()`/`contestantSilhouette()`/`DEFAULT_APPARATUS` further
 * below for the seam that makes that swap possible and this Predicament's
 * own rig/warningLight/clawArm/trapdoor geometry.
 *
 * Deliberately pure/DOM-free like js/lastword/state.js: `pickStateName` and
 * `renderMarkup` are plain functions over strings/numbers that return an
 * SVG markup string, so this file is Node-testable without a DOM and stays
 * a drop-in for a future Telnet-safe or server-rendered client.
 */
(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.LastWordGallowsCharacter = factory();
    }
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    var MAX_STRIKES = 6;

    // Index = strike count 0-6. SAVED is a separate terminal state reached
    // only via solved=true, independent of strikes.
    var STRIKE_STATE_NAMES = [
        'CONFIDENT',
        'CONFUSED',
        'CONCERNED',
        'NERVOUS',
        'PLEADING',
        'TERRIFIED',
        'COMEDIC_DEFEAT'
    ];
    var SAVED_STATE_NAME = 'SAVED';

    /**
     * Map (strikes, solved) -> one of the 8 visual state names. `solved`
     * always wins (a solved puzzle powers the rig down regardless of how
     * many strikes were taken getting there). Out-of-range strikes are
     * clamped rather than throwing, so a defensive caller can pass a raw
     * round.strikes value straight through.
     */
    function pickStateName(strikes, solved) {
        if (solved) { return SAVED_STATE_NAME; }
        var n = typeof strikes === 'number' && strikes > 0 ? strikes : 0;
        if (n > MAX_STRIKES) { n = MAX_STRIKES; }
        return STRIKE_STATE_NAMES[Math.floor(n)];
    }

    // Shared ink color for the whole drawing (contestant + apparatus) so
    // both read as one sparse line-art piece rather than two styles glued
    // together. Kept light so it draws cleanly on the dark BBS panel.
    var INK = '#dbe4f5';

    function esc(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    // ---- apparatus (UNCHANGED since Experiment #4 — same activation
    // logic per strike, same thin ink-line rendering).

    function rigFrame(active) {
        var op = active ? '0.9' : '0.45';
        return (
            '<path d="M20,236 L100,236 M40,236 L40,26 L214,26 L214,54" ' +
            'stroke="' + INK + '" stroke-width="2.5" fill="none" stroke-linecap="round" ' +
            'stroke-linejoin="round" opacity="' + op + '"/>'
        );
    }

    function warningLight(mode) {
        if (mode === 'off') {
            return '<circle cx="214" cy="26" r="7" fill="none" stroke="' + INK + '" stroke-width="2" opacity="0.4"/>';
        }
        var stroke = mode === 'red' ? '#ff8f8f' : '#f5c168';
        var pulseClass = mode === 'red' ? ' class="lw-pulse"' : '';
        return (
            '<circle cx="214" cy="26" r="7" fill="none" stroke="' + stroke + '" stroke-width="2.5"' + pulseClass + '/>'
        );
    }

    function clawArm(reach) {
        if (reach <= 0) { return ''; }
        var len = 40 + reach * 24;
        var y2 = 54 + len;
        var open = reach < 3 ? 14 : 5; // snaps nearly shut by the final strike
        return (
            '<g stroke="' + INK + '" stroke-width="2.5" fill="none" stroke-linecap="round">' +
            '<line x1="214" y1="54" x2="214" y2="' + y2 + '"/>' +
            '<path d="M' + (214 - open) + ',' + (y2 - 6) + ' L214,' + y2 + ' L' + (214 + open) + ',' + (y2 - 6) + '"/>' +
            '</g>'
        );
    }

    function trapdoor(mode) {
        if (mode === 'hidden') { return ''; }
        if (mode === 'open') {
            return (
                '<g stroke="' + INK + '" stroke-width="2" fill="none" opacity="0.7">' +
                '<path d="M20,236 L52,236 M108,236 L140,236"/>' +
                '<path d="M52,236 L60,250 M108,236 L100,250" stroke-dasharray="3,4"/>' +
                '</g>'
            );
        }
        return '<path d="M20,236 L140,236" stroke="' + INK + '" stroke-width="2" stroke-dasharray="5,5" fill="none" opacity="0.6"/>';
    }

    /**
     * SKIPPY'S FACE IS SACRED (Experiment #10): dialogue must never sit
     * over his head/hair/face, so every call site below places the bubble
     * in genuinely open canvas space instead, and a `tailSide` says which
     * edge the pointer comes off of so it still visibly points back at
     * him from wherever it landed. `text` may be a single string or an
     * array of lines — pass an array (or let a caller pre-wrap) rather
     * than widening the bubble over Skippy when a line is long.
     */
    function speechBubble(text, x, y, w, tailSide) {
        if (!text) { return ''; }
        var lines = Array.isArray(text) ? text : [text];
        if (!lines.length) { return ''; }
        w = w || 118;
        tailSide = tailSide || 'down';
        var lineH = 16;
        var h = 20 + lines.length * lineH;

        var tail;
        if (tailSide === 'up') {
            // bubble sits BELOW Skippy — tail juts up off the top edge
            var tux = x + Math.min(30, w * 0.3);
            tail = '<path d="M' + tux + ',' + y + ' L' + (tux - 8) + ',' + (y - 10) + ' L' + (tux + 8) + ',' + y + ' Z" ' +
                'fill="#1a1d24" stroke="#6ea8ff" stroke-width="2"/>';
        } else if (tailSide === 'left') {
            // bubble sits to the RIGHT of Skippy — tail juts left off the side
            var tly = y + Math.min(24, h * 0.4);
            tail = '<path d="M' + x + ',' + tly + ' L' + (x - 10) + ',' + (tly + 8) + ' L' + x + ',' + (tly + 16) + ' Z" ' +
                'fill="#1a1d24" stroke="#6ea8ff" stroke-width="2"/>';
        } else {
            // legacy default — bubble sits ABOVE Skippy, tail hangs down
            tail = '<path d="M' + (x + 18) + ',' + (y + h) + ' L' + (x + 10) + ',' + (y + h + 10) + ' L' + (x + 30) + ',' + (y + h) + ' Z" ' +
                'fill="#1a1d24" stroke="#6ea8ff" stroke-width="2"/>';
        }

        var textEls = lines.map(function (line, i) {
            return '<text x="' + (x + w / 2) + '" y="' + (y + 20 + i * lineH) + '" text-anchor="middle" ' +
                'font-size="12" font-weight="bold" fill="#e7eefc">' + esc(line) + '</text>';
        }).join('');

        return (
            '<g class="lw-bubble">' +
            '<rect x="' + x + '" y="' + y + '" width="' + w + '" height="' + h + '" rx="10" ' +
            'fill="#1a1d24" stroke="#6ea8ff" stroke-width="2"/>' +
            tail + textEls +
            '</g>'
        );
    }

    // ---- contestant: true stick figure (Experiment #6) ------------------
    // No torso mass, no shirt shape. A shoulder bar + spine + hip bar
    // stand in for the body; thin two-segment stick limbs; a small plain
    // head circle. Everything the character "says" now comes from head
    // angle, a couple of face marks, whole-body pose, and the hair.

    function line(pts, width) {
        var d = 'M' + pts[0] + ',' + pts[1];
        for (var i = 2; i < pts.length; i += 2) {
            d += ' L' + pts[i] + ',' + pts[i + 1];
        }
        return '<path d="' + d + '" stroke="' + INK + '" stroke-width="' + (width || 2.5) + '" fill="none" ' +
            'stroke-linecap="round" stroke-linejoin="round"/>';
    }

    // ROUND/EXPRESSIVE EYES — Experiment #13 house rule: Skippy's default
    // eye language is round and open (an outline + a pupil dot), not a
    // flat dash. `rx`/`ry` let a state widen, narrow, or flatten the
    // shape for its own read (wide-eyed, tense/squinted, etc.) while
    // staying an open eye; `pupilDx/Dy` aims the pupil (e.g. up for a
    // pleading or startled look). Closed/dash marks are still used
    // directly (not via this helper) for the handful of states that
    // specifically call for a shut eye — CONFUSED and SAVED.
    function eye(cx, cy, rx, ry, pupilDx, pupilDy, pupilR) {
        pupilDx = pupilDx || 0;
        pupilDy = pupilDy || 0;
        pupilR = typeof pupilR === 'number' ? pupilR : 1.8;
        return (
            '<ellipse cx="' + cx + '" cy="' + cy + '" rx="' + rx + '" ry="' + ry + '" ' +
            'fill="none" stroke="' + INK + '" stroke-width="2"/>' +
            '<circle cx="' + (cx + pupilDx) + '" cy="' + (cy + pupilDy) + '" r="' + pupilR + '" fill="' + INK + '"/>'
        );
    }

    // ---- HAIR (SKIPPY V1 — FROZEN, human-barbered) ----------------------
    // The base geometry below (a dense short-bristle row + 3 longer
    // curved strands) went through several generated redesigns before a
    // human tuned it by eye via `computeHairGeometry()`'s adjustable
    // knobs and chose final values. Those values are now baked in below
    // as SKIPPY_HAIR_DEFAULTS — this is Skippy V1's frozen haircut, not a
    // suggestion. Do not reinterpret, "improve," normalize, or redraw it;
    // a different haircut only happens by changing these authoritative
    // numbers directly. `computeHairGeometry()` is the single source of
    // truth: with no `hairParams` (or any field omitted), it falls back
    // to SKIPPY_HAIR_DEFAULTS, so ordinary rendering needs no opts at all.
    var HAIR_BASE_BRISTLES_DX = [-6, -3, 0, 3, 6, 9, 12, 15, 18, 21, 24, 27];
    var HAIR_BASE_BRISTLES_DY = [-2, -2, -2, -2, -2, -1, 0, 1, 3, 4, 6, 8];
    var HAIR_BASE_LEAN = [{ dx: 3.8, dy: 12.5 }, { dx: 2.5, dy: 13.7 }]; // alternating, ~13.1px / ~13.9px
    var HAIR_BASE_LONG_STRANDS = [
        { x: 10, y: 0, mdx: 9, mdy: 10, edx: 15, edy: 25 },
        { x: 17, y: 2, mdx: 10, mdy: 11, edx: 17, edy: 27 },
        { x: 24, y: 5, mdx: 8, mdy: 12, edx: 14, edy: 28 }
    ];

    // Matt's exact human-chosen BARBER values — Skippy V1's canon hair.
    // Frozen so nothing (including this module) can accidentally mutate
    // the authoritative numbers at runtime.
    var SKIPPY_HAIR_DEFAULTS = Object.freeze({
        xOffset: 0,
        yOffset: 1,
        spacing: 1,
        length: 0.7,
        angle: -60,
        longEndDX: -15,
        longEndDY: -11
    });

    /**
     * Resolve the full hair definition (short bristles + long strands)
     * into absolute canvas points, given optional manual adjustments:
     *   xOffset, yOffset  — shift the WHOLE hair block
     *   spacing           — multiplier on each bristle/strand's base
     *                        horizontal position (spreads/compresses them)
     *   length            — multiplier on the short-bristle lean vector
     *   angle             — degrees added to the short-bristle lean angle
     *   longEndDX/longEndDY — nudge every long strand's end point
     * Any field left out of `hairParams` (or `hairParams` omitted
     * entirely) falls back to SKIPPY_HAIR_DEFAULTS — Skippy's frozen V1
     * haircut — so `computeHairGeometry(cx)` alone renders it with no
     * caller-supplied opts required.
     */
    function computeHairGeometry(cx, hairParams) {
        hairParams = hairParams || {};
        var d = SKIPPY_HAIR_DEFAULTS;
        var top = HEAD_CY - HEAD_R;
        var xOffset = typeof hairParams.xOffset === 'number' ? hairParams.xOffset : d.xOffset;
        var yOffset = typeof hairParams.yOffset === 'number' ? hairParams.yOffset : d.yOffset;
        var spacing = typeof hairParams.spacing === 'number' ? hairParams.spacing : d.spacing;
        var lengthScale = typeof hairParams.length === 'number' ? hairParams.length : d.length;
        var angleDeg = typeof hairParams.angle === 'number' ? hairParams.angle : d.angle;
        var longEndDX = typeof hairParams.longEndDX === 'number' ? hairParams.longEndDX : d.longEndDX;
        var longEndDY = typeof hairParams.longEndDY === 'number' ? hairParams.longEndDY : d.longEndDY;

        var rad = angleDeg * Math.PI / 180;
        var cosA = Math.cos(rad), sinA = Math.sin(rad);

        var bristles = [];
        for (var i = 0; i < HAIR_BASE_BRISTLES_DX.length; i++) {
            var v = HAIR_BASE_LEAN[i % 2];
            var rdx = v.dx * cosA - v.dy * sinA;
            var rdy = v.dx * sinA + v.dy * cosA;
            var x1 = cx + HAIR_BASE_BRISTLES_DX[i] * spacing + xOffset;
            var y1 = top + HAIR_BASE_BRISTLES_DY[i] + yOffset;
            bristles.push({ x1: x1, y1: y1, x2: x1 + rdx * lengthScale, y2: y1 + rdy * lengthScale });
        }

        var longStrands = HAIR_BASE_LONG_STRANDS.map(function (s) {
            var x1 = cx + s.x * spacing + xOffset;
            var y1 = top + s.y + yOffset;
            return {
                x1: x1, y1: y1,
                cx: x1 + s.mdx, cy: y1 + s.mdy,
                x2: x1 + s.edx + longEndDX, y2: y1 + s.edy + longEndDY
            };
        });

        return { bristles: bristles, longStrands: longStrands };
    }

    function hairScribble(cx, topY, level, hairParams) { // eslint-disable-line no-unused-vars
        var data = computeHairGeometry(cx, hairParams);
        var strands = data.bristles.map(function (b) {
            return line([b.x1, b.y1, b.x2, b.y2], 2.1);
        });
        data.longStrands.forEach(function (s) {
            // a few much-longer open strands, gently curved, projecting
            // forward from among the short bristles — thinner stroke so
            // they read as wispier/longer hair rather than more bristles
            strands.push(
                '<path d="M' + s.x1 + ',' + s.y1 + ' Q' + s.cx + ',' + s.cy + ' ' + s.x2 + ',' + s.y2 + '" ' +
                'stroke="' + INK + '" stroke-width="1.7" fill="none" stroke-linecap="round"/>'
            );
        });
        return '<g>' + strands.join('') + '</g>';
    }

    // POCKET PROTECTOR — Experiment #9 A/B prototype. A tiny symbolic
    // mark (an open pocket rect + two short pen ticks) at Skippy's left
    // chest; there is no shirt/body outline for it to sit "on", so it's
    // kept deliberately small and simple to read as a symbol, not a
    // floating shape.
    function pocketProtector(x, y) {
        return (
            '<g stroke="' + INK + '" stroke-width="1.6" fill="none" opacity="0.85">' +
            '<rect x="' + (x - 4) + '" y="' + y + '" width="8" height="9" rx="1"/>' +
            '<path d="M' + (x - 2) + ',' + y + ' L' + (x - 3) + ',' + (y - 6) + '" stroke-linecap="round"/>' +
            '<path d="M' + (x + 1) + ',' + y + ' L' + (x + 3) + ',' + (y - 7) + '" stroke-linecap="round"/>' +
            '</g>'
        );
    }

    function handMark(x, y, angle) {
        var rad = angle * Math.PI / 180;
        var ax = x + Math.cos(rad) * 6, ay = y + Math.sin(rad) * 6;
        var bx = x + Math.cos(rad + 1) * 6, by = y + Math.sin(rad + 1) * 6;
        return '<path d="M' + ax + ',' + ay + ' L' + x + ',' + y + ' L' + bx + ',' + by + '" ' +
            'stroke="' + INK + '" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/>';
    }

    // Foot tick pointing off at `angle` degrees (0 = right, 180 = left).
    function footMark(x, y, angle) {
        var rad = angle * Math.PI / 180;
        var tx = x + Math.cos(rad) * 12, ty = y + Math.sin(rad) * 4;
        return line([x, y, tx, ty], 2.5);
    }

    // ---- sparse secondary acting marks (unchanged from #5) --------------

    function sweatDrop(x, y) {
        return '<path d="M' + x + ',' + y + ' Q' + (x + 5) + ',' + (y + 8) + ' ' + x + ',' + (y + 13) +
            ' Q' + (x - 5) + ',' + (y + 8) + ' ' + x + ',' + y + ' Z" fill="#6ea8ff" opacity="0.85"/>';
    }

    function tremble(x, y) {
        return '<path d="M' + (x - 4) + ',' + (y - 2) + ' L' + (x + 4) + ',' + (y - 2) +
            ' M' + (x - 4) + ',' + (y + 3) + ' L' + (x + 4) + ',' + (y + 3) + '" ' +
            'stroke="' + INK + '" stroke-width="1.4" opacity="0.6" stroke-linecap="round"/>';
    }

    function burst(cx, cy, n, len, startDeg, spanDeg, opacity) {
        var g = '<g stroke="' + INK + '" stroke-width="1.6" stroke-linecap="round" opacity="' + (opacity || 0.75) + '">';
        for (var i = 0; i < n; i++) {
            var deg = startDeg + (n > 1 ? spanDeg * i / (n - 1) : 0);
            var rad = deg * Math.PI / 180;
            var x1 = cx + Math.cos(rad) * (len * 0.4), y1 = cy + Math.sin(rad) * (len * 0.4);
            var x2 = cx + Math.cos(rad) * len, y2 = cy + Math.sin(rad) * len;
            g += '<path d="M' + x1 + ',' + y1 + ' L' + x2 + ',' + y2 + '"/>';
        }
        return g + '</g>';
    }

    // Body landmarks shared by every pose: a small head, a bare
    // shoulder-bar/spine/hip-bar instead of any torso mass, no shirt.
    // EXPERIMENT #8: head enlarged substantially (15 -> 22) to carry
    // expression — tiny stick body + oversized head + huge hair. The
    // shoulder bar stays put (there's still an 8px neck gap), so no limb
    // geometry needed to change; only the face marks inside each head
    // were rescaled to use the extra area.
    var HEAD_CX = 78, HEAD_CY = 60, HEAD_R = 22;
    var SHOULDER_Y = 90, HIP_Y = 144;
    var SHOULDER_L = HEAD_CX - 9, SHOULDER_R = HEAD_CX + 9;
    var HIP_L = HEAD_CX - 8, HIP_R = HEAD_CX + 8;

    function contestant(opts) {
        opts = opts || {};
        var pivotY = 118; // rotate around roughly chest height
        var tilt = opts.tilt || 0;

        var eyes = opts.eyes || (eye(72, 57, 4, 4.2) + eye(89, 57, 4, 4.2));
        var mouth = opts.mouth || '<path d="M71,72 Q80,76 91,72" stroke="' + INK + '" stroke-width="2.2" fill="none" stroke-linecap="round"/>';

        var head = '<circle cx="' + HEAD_CX + '" cy="' + HEAD_CY + '" r="' + HEAD_R + '" ' +
            'stroke="' + INK + '" stroke-width="2.2" fill="none"/>';

        // shoulder bar -> spine -> hip bar stand in for the whole torso;
        // a tiny collar V is the only nod to "wearing a shirt".
        var body = (
            line([SHOULDER_L, SHOULDER_Y, SHOULDER_R, SHOULDER_Y], 2.5) +
            line([HEAD_CX, SHOULDER_Y, HEAD_CX, HIP_Y], 2.5) +
            line([HIP_L, HIP_Y, HIP_R, HIP_Y], 2.5) +
            '<path d="M74,' + SHOULDER_Y + ' L78,' + (SHOULDER_Y + 6) + ' L82,' + SHOULDER_Y + '" ' +
            'stroke="' + INK + '" stroke-width="1.6" fill="none" stroke-linecap="round"/>'
        );

        var leftArm = opts.leftArm || [SHOULDER_L, SHOULDER_Y, 54, 108, 46, 128];
        var rightArm = opts.rightArm || [SHOULDER_R, SHOULDER_Y, 102, 108, 110, 128];
        var leftHandAngle = typeof opts.leftHandAngle === 'number' ? opts.leftHandAngle : 200;
        var rightHandAngle = typeof opts.rightHandAngle === 'number' ? opts.rightHandAngle : -20;

        var leftLeg = opts.leftLeg || [HIP_L, HIP_Y, 62, 172, 56, 200];
        var rightLeg = opts.rightLeg || [HIP_R, HIP_Y, 94, 172, 100, 200];
        var leftFootAngle = typeof opts.leftFootAngle === 'number' ? opts.leftFootAngle : 160;
        var rightFootAngle = typeof opts.rightFootAngle === 'number' ? opts.rightFootAngle : 20;

        var legs = opts.legs !== false ? (
            line(leftLeg, 2.5) + footMark(leftLeg[4], leftLeg[5], leftFootAngle) +
            line(rightLeg, 2.5) + footMark(rightLeg[4], rightLeg[5], rightFootAngle)
        ) : '';

        var hairLevel = opts.hair || 'normal';
        var extras = opts.extras || '';
        // A/B toggle (Experiment #9): default ON, pass showPocket:false to omit.
        var showPocket = opts.showPocket !== false;
        var pocket = showPocket ? pocketProtector(SHOULDER_L + 3, SHOULDER_Y + 10) : '';

        return (
            '<g transform="rotate(' + tilt + ' ' + HEAD_CX + ' ' + pivotY + ')">' +
            legs +
            body +
            pocket +
            line(leftArm, 2.2) + handMark(leftArm[4], leftArm[5], leftHandAngle) +
            line(rightArm, 2.2) + handMark(rightArm[4], rightArm[5], rightHandAngle) +
            head +
            eyes + mouth +
            hairScribble(HEAD_CX, HEAD_CY - HEAD_R - 3, hairLevel, opts.hairParams) +
            extras +
            '</g>'
        );
    }

    // HUMAN-GATE CORRECTION #3 ("occlusion root fix"): a thick/filled
    // silhouette variant of contestant() used ONLY to build a mask cutout
    // (see apparatusFor() below) — never rendered visibly itself. It takes
    // the EXACT SAME `opts` object a SCENES function already builds for its
    // real, visible `contestant(opts)` call (same tilt/leftArm/rightArm/
    // leftLeg/rightLeg/leg angles — every per-state pose parameter), so the
    // occlusion shape is derived from the actual current pose, not an
    // independent approximation: correct by construction for every tilt/
    // pose Skippy's accepted figure ever takes, with no separate geometry
    // to keep in sync. Deliberately covers only the parts of him large
    // and load-bearing enough to matter for occlusion — head, shoulder/
    // spine/hip bars, arms, legs — using thick round-capped strokes (or a
    // filled circle for the head) so it fully backs his own much thinner
    // (2.2-2.5px) visible strokes with a safety margin; hair/eyes/mouth are
    // thin decorative detail on top of the head and don't need their own
    // separate coverage beyond the head circle already accounting for them.
    function contestantSilhouette(opts) {
        opts = opts || {};
        var pivotY = 118;
        var tilt = opts.tilt || 0;
        var THICK = 15; // generous vs. contestant()'s ~2.2-2.5px visible strokes

        function thickLine(pts) {
            var d = 'M' + pts[0] + ',' + pts[1];
            for (var i = 2; i < pts.length; i += 2) { d += ' L' + pts[i] + ',' + pts[i + 1]; }
            return '<path d="' + d + '" stroke="#000" stroke-width="' + THICK + '" fill="none" ' +
                'stroke-linecap="round" stroke-linejoin="round"/>';
        }

        var head = '<circle cx="' + HEAD_CX + '" cy="' + HEAD_CY + '" r="' + (HEAD_R + 5) + '" fill="#000"/>';

        var body = (
            thickLine([SHOULDER_L, SHOULDER_Y, SHOULDER_R, SHOULDER_Y]) +
            thickLine([HEAD_CX, SHOULDER_Y, HEAD_CX, HIP_Y]) +
            thickLine([HIP_L, HIP_Y, HIP_R, HIP_Y])
        );

        var leftArm = opts.leftArm || [SHOULDER_L, SHOULDER_Y, 54, 108, 46, 128];
        var rightArm = opts.rightArm || [SHOULDER_R, SHOULDER_Y, 102, 108, 110, 128];
        var leftLeg = opts.leftLeg || [HIP_L, HIP_Y, 62, 172, 56, 200];
        var rightLeg = opts.rightLeg || [HIP_R, HIP_Y, 94, 172, 100, 200];
        var legs = opts.legs !== false ? (thickLine(leftLeg) + thickLine(rightLeg)) : '';
        var arms = thickLine(leftArm) + thickLine(rightArm);

        return (
            '<g transform="rotate(' + tilt + ' ' + HEAD_CX + ' ' + pivotY + ')">' +
            legs + body + arms + head +
            '</g>'
        );
    }

    // ---- per-state scenes ------------------------------------------------
    // Every pose should read from head angle + face + stick-body
    // silhouette alone, even with the state label and speech bubble
    // hidden — that was verified human-side during development.

    // CONSCIOUS BOUNDED FORK #3 ("SKIPPY'S PREDICAMENT"): the one small
    // abstraction point that lets an alternate Predicament (see
    // safe-predicament.js) reuse Skippy's canonical contestant pose/face/
    // hair/speech-bubble rendering for every state while swapping out only
    // the THREAT surrounding him — never who he is. Each SCENES entry below
    // opens with `apparatusFor(stateName, opts)`, which returns the exact
    // same rig/warningLight/clawArm/trapdoor markup as before UNLESS the
    // caller passes `opts.apparatus` (a `function (stateName) -> markup`),
    // in which case that markup is used instead. No opts.apparatus (the
    // default, and every call site prior to this fork) reproduces the
    // original output byte-for-byte — this is additive only.
    var DEFAULT_APPARATUS = {
        CONFIDENT: function () { return rigFrame(false) + warningLight('off') + clawArm(0) + trapdoor('hidden'); },
        CONFUSED: function () { return rigFrame(true) + warningLight('amber') + clawArm(0) + trapdoor('hidden'); },
        CONCERNED: function () { return rigFrame(true) + warningLight('amber') + clawArm(1) + trapdoor('hidden'); },
        NERVOUS: function () { return rigFrame(true) + warningLight('amber') + clawArm(2) + trapdoor('seam'); },
        PLEADING: function () { return rigFrame(true) + warningLight('amber') + clawArm(2) + trapdoor('seam'); },
        TERRIFIED: function () { return rigFrame(true) + warningLight('red') + clawArm(3) + trapdoor('seam'); },
        COMEDIC_DEFEAT: function () { return rigFrame(true) + warningLight('off') + clawArm(0) + trapdoor('open'); },
        SAVED: function () { return rigFrame(false) + warningLight('off') + clawArm(0) + trapdoor('hidden'); }
    };

    // Fixed literal id (not per-call-generated) — see contestantSilhouette's
    // own comment above for why that's safe even with multiple
    // simultaneously-rendered surfaces (round + Final).
    var POSE_KNOCKOUT_MASK_ID = 'lw-pose-knockout-mask';

    /**
     * `poseOpts`, when given, is the SAME opts object the calling SCENES
     * function is about to pass to `contestant(poseOpts)` for the actual
     * visible figure — see each SCENES entry below. When an alternate
     * apparatus is active (opts.apparatus set) AND a pose is available
     * (every state except COMEDIC_DEFEAT, which never draws a figure),
     * the returned apparatus markup is wrapped in a mask cut to that EXACT
     * pose's silhouette (contestantSilhouette(poseOpts)) so the alternate
     * apparatus can never draw through wherever Skippy's actual current
     * figure stands, whatever pose/tilt that is — see the "painter model"
     * in the human-gate correction: apparatus, then this knockout, then
     * the real visible contestant(poseOpts) on top (drawn by the caller
     * immediately after this returns). The accepted default gallows (no
     * opts.apparatus) and COMEDIC_DEFEAT (no poseOpts) are both returned
     * completely unwrapped — byte-identical to every call site before this
     * correction.
     */
    function apparatusFor(stateName, opts, poseOpts) {
        var raw = (opts && typeof opts.apparatus === 'function')
            ? opts.apparatus(stateName)
            : DEFAULT_APPARATUS[stateName]();
        if (!(opts && opts.apparatus) || !poseOpts) {
            return raw;
        }
        return (
            '<defs><mask id="' + POSE_KNOCKOUT_MASK_ID + '" maskUnits="userSpaceOnUse" x="0" y="0" width="260" height="260">' +
            '<rect x="0" y="0" width="260" height="260" fill="#fff"/>' +
            contestantSilhouette(poseOpts) +
            '</mask></defs>' +
            '<g mask="url(#' + POSE_KNOCKOUT_MASK_ID + ')">' + raw + '</g>'
        );
    }

    var SCENES = {
        CONFIDENT: function (opts, stateName) {
            var poseOpts = {
                tilt: -9, // smug lean
                hair: 'normal',
                showPocket: opts && opts.showPocket,
                hairParams: opts && opts.hairParams,
                // round, relaxed — casual downward pupil glance, not squinting
                eyes: eye(73, 58, 4.2, 3.6, 0, 1) + eye(89, 58, 4.2, 3.6, 0, 1),
                mouth: '<path d="M71,72 Q84,79 94,66" stroke="' + INK + '" stroke-width="2.2" fill="none" stroke-linecap="round"/>',
                // hand cocked on hip, elbow flared out — "I've got this"
                rightArm: [SHOULDER_R, SHOULDER_Y, 106, 100, 88, 122],
                rightHandAngle: 200,
                leftArm: [SHOULDER_L, SHOULDER_Y, 44, 116, 50, 136],
                leftHandAngle: 110,
                leftLeg: [HIP_L, HIP_Y, 52, 174, 44, 202], leftFootAngle: 150,
                rightLeg: [HIP_R, HIP_Y, 100, 174, 110, 202], rightFootAngle: 30
            };
            return (
                apparatusFor(stateName, opts, poseOpts) +
                contestant(poseOpts)
            );
        },
        CONFUSED: function (opts, stateName) {
            var poseOpts = {
                tilt: 15, // craning back hard
                hair: 'normal',
                showPocket: opts && opts.showPocket,
                hairParams: opts && opts.hairParams,
                // round, wide, pupils cast up toward the apparatus — slightly
                // uneven sizing keeps the goofy startled asymmetry
                eyes: eye(71, 56, 4.6, 4.6, 0, -1.6, 1.9) + eye(90, 56, 4, 4, 0, -1.6, 1.7),
                mouth: '<path d="M72,73 Q81,66 90,73" stroke="' + INK + '" stroke-width="2.2" fill="none" stroke-linecap="round"/>',
                leftArm: [SHOULDER_L, SHOULDER_Y, 32, 86, 16, 66], leftHandAngle: -110,
                rightArm: [SHOULDER_R, SHOULDER_Y, 116, 100, 132, 90], rightHandAngle: -10,
                extras:
                    '<text x="112" y="22" font-size="16" font-weight="bold" fill="' + INK + '" opacity="0.8">?</text>' +
                    burst(78, 30, 3, 9, 250, 40, 0.5)
            };
            return (
                apparatusFor(stateName, opts, poseOpts) +
                contestant(poseOpts)
            );
        },
        CONCERNED: function (opts, stateName) {
            // Strike 2 = "Oh shit. This may actually be a problem." Must
            // read distinctly from Strike 1 (CONFUSED = off-balance WTF)
            // and Strike 3 (NERVOUS = compressed/tensing): worried brows,
            // attention snapped toward the apparatus/puzzle, a hand raised
            // to the head — the first sign confidence is slipping, not yet
            // panic.
            var poseOpts = {
                tilt: 9, // attention snapping toward the apparatus/puzzle
                hair: 'normal',
                showPocket: opts && opts.showPocket,
                hairParams: opts && opts.hairParams,
                // worried "tent" brows above (unchanged, distinct from
                // CONFUSED's uneven ticks and NERVOUS's flat ones) +
                // round, normal-sized, slightly downcast worried eyes
                eyes: '<path d="M65,50 L74,45" stroke="' + INK + '" stroke-width="2.2" stroke-linecap="round"/>' +
                    '<path d="M82,45 L91,50" stroke="' + INK + '" stroke-width="2.2" stroke-linecap="round"/>' +
                    eye(73, 58, 3.6, 3.6, 0, 0.6) + eye(83, 58, 3.6, 3.6, 0, 0.6),
                // flat/uncertain mouth that dips slightly — a small frown, not a shrug-smirk
                mouth: '<path d="M70,74 Q80,80 90,74" stroke="' + INK + '" stroke-width="2.2" fill="none" stroke-linecap="round"/>',
                // one hand raised to the side of the head — a clear "oh no" gesture
                rightArm: [SHOULDER_R, SHOULDER_Y, 92, 68, 96, 54], rightHandAngle: 160,
                leftArm: [SHOULDER_L, SHOULDER_Y, 58, 110, 54, 130], leftHandAngle: 200,
                leftLeg: [HIP_L, HIP_Y, 66, 174, 62, 202], leftFootAngle: 170,
                rightLeg: [HIP_R, HIP_Y, 90, 174, 94, 202], rightFootAngle: 10
            };
            return (
                apparatusFor(stateName, opts, poseOpts) +
                contestant(poseOpts)
            );
        },
        NERVOUS: function (opts, stateName) {
            var poseOpts = {
                tilt: 0, // compressed rather than leaning
                hair: 'frazzled',
                showPocket: opts && opts.showPocket,
                hairParams: opts && opts.hairParams,
                // round but tense — flattened/narrowed ovals rather than a
                // dash, so the eyes still read as open, just tight
                eyes: eye(73, 57, 3.2, 2.2, 0, 0, 1.4) + eye(89, 57, 3.2, 2.2, 0, 0, 1.4),
                mouth: '<path d="M72,73 L78,76 L82,72 L87,76 L91,73" stroke="' + INK + '" stroke-width="2.2" fill="none" stroke-linecap="round"/>',
                leftArm: [SHOULDER_L, SHOULDER_Y + 4, 58, 106, 56, 122], leftHandAngle: 260,
                rightArm: [SHOULDER_R, SHOULDER_Y + 4, 100, 106, 102, 122], rightHandAngle: 280,
                leftLeg: [HIP_L, HIP_Y - 6, 68, 158, 66, 180], leftFootAngle: 170,
                rightLeg: [HIP_R, HIP_Y - 6, 88, 158, 90, 180], rightFootAngle: 10,
                extras: sweatDrop(92, 54) + tremble(56, 122) + tremble(102, 122)
            };
            return (
                apparatusFor(stateName, opts, poseOpts) +
                contestant(poseOpts)
            );
        },
        PLEADING: function (opts, stateName) {
            var poseOpts = {
                tilt: 26, // folds himself toward the puzzle
                hair: 'frazzled',
                showPocket: opts && opts.showPocket,
                hairParams: opts && opts.hairParams,
                // big, round, pupils cast up — beseeching puppy-eyes
                eyes: eye(72, 59, 4.4, 4.8, 0, -1.6, 1.9) + eye(90, 59, 4.4, 4.8, 0, -1.6, 1.9),
                mouth: '<ellipse cx="81" cy="75" rx="7" ry="6.5" fill="none" stroke="' + INK + '" stroke-width="2.2"/>',
                leftArm: [SHOULDER_L, SHOULDER_Y, 34, 96, 6, 108], leftHandAngle: -30,
                rightArm: [SHOULDER_R, SHOULDER_Y, 88, 108, 62, 122], rightHandAngle: 210,
                // lunging crouch: front leg bent low, back leg trailing
                leftLeg: [HIP_L, HIP_Y, 56, 166, 42, 184], leftFootAngle: 170,
                rightLeg: [HIP_R, HIP_Y, 104, 162, 118, 180], rightFootAngle: 350,
                extras: sweatDrop(96, 50) + sweatDrop(58, 54) + tremble(6, 108) + tremble(62, 122)
            };
            return (
                apparatusFor(stateName, opts, poseOpts) +
                contestant(poseOpts) +
                // Skippy's face is sacred: placed well below his crouched
                // pose (his head/hair never reach past y~100 at any tilt,
                // and this crouch's own feet land above y~192) rather than
                // over his head the way earlier experiments had it.
                speechBubble('BUY. A. VOWEL.', 55, 196, 150, 'up')
            );
        },
        TERRIFIED: function (opts, stateName) {
            // Strike 5 = one mistake left. Not passive fear — actively
            // PLEADING FOR HIS LIFE: crouched/kneeling toward the player,
            // hands clasped and begging, huge eyes, hair at maximum chaos.
            // The gameplay stakes (BUY. A. VOWEL. at Strike 4) have fully
            // given way to "forget the puzzle, save me" by Strike 5.
            //
            // The panic line rotates (see PANIC_LINES/pickPanicLine below)
            // so Skippy doesn't say the same thing every time; the caller
            // picks/holds the line (this module stays stateless) and can
            // pass it in via `opts.panicLine` — falls back to the first
            // pool entry so a bare renderMarkup('TERRIFIED') call (no
            // opts, e.g. from existing tests) stays deterministic.
            // Placed below Skippy (same reasoning as PLEADING below) so
            // even the longest of the 10 rotating panic lines never has
            // to crowd toward his head to fit — the full canvas width is
            // open down there, so every line fits on one row.
            //
            // CONSCIOUS BOUNDED FORK #3 ("SKIPPY'S PREDICAMENT") — one more
            // small additive hook, same shape as `opts.apparatus`:
            // `opts.suppressSpeechBubble` (default falsy) skips drawing
            // this baked bubble entirely. Added because an alternate
            // predicament may want to surface Strike-5 dialogue through a
            // caller's own single transient speech-bubble presenter
            // instead (so there is never more than one Skippy speech
            // bubble visible on screen at once) — see safe-predicament.js.
            // Omitted (the default, and every call site before this fork)
            // reproduces the original behavior exactly.
            var line = (opts && opts.panicLine) || PANIC_LINES[0];
            var bw = Math.min(244, Math.max(126, line.length * 7.4 + 26));
            var bx = Math.max(6, Math.min(254 - bw, Math.round((260 - bw) / 2)));
            var poseOpts = {
                tilt: 17, // pitched forward, begging toward the player
                hair: 'electrified',
                showPocket: opts && opts.showPocket,
                hairParams: opts && opts.hairParams,
                eyes: '<circle cx="69" cy="56" r="8" fill="none" stroke="' + INK + '" stroke-width="2.2"/>' +
                    '<circle cx="69" cy="56" r="2.3" fill="' + INK + '"/>' +
                    '<circle cx="93" cy="56" r="8" fill="none" stroke="' + INK + '" stroke-width="2.2"/>' +
                    '<circle cx="93" cy="56" r="2.3" fill="' + INK + '"/>',
                mouth: '<ellipse cx="81" cy="76" rx="6.5" ry="8" fill="none" stroke="' + INK + '" stroke-width="2.2"/>',
                // both arms drawn in to a single clasped-hands point — begging, not bracing
                leftArm: [SHOULDER_L, SHOULDER_Y, 60, 112, 74, 128], leftHandAngle: -60,
                rightArm: [SHOULDER_R, SHOULDER_Y, 96, 112, 82, 128], rightHandAngle: 240,
                // crouched/kneeling toward the player — legs folded short, not standing straight
                leftLeg: [HIP_L, HIP_Y, 56, 160, 52, 178], leftFootAngle: 170,
                rightLeg: [HIP_R, HIP_Y, 98, 160, 102, 178], rightFootAngle: 10,
                extras: sweatDrop(98, 40) + sweatDrop(55, 43) + sweatDrop(81, 22) +
                    tremble(74, 128) + tremble(82, 128) + tremble(52, 178) + tremble(102, 178)
            };
            return (
                apparatusFor(stateName, opts, poseOpts) +
                contestant(poseOpts) +
                ((opts && opts.suppressSpeechBubble) ? '' : speechBubble(line, bx, 194, bw, 'up'))
            );
        },
        COMEDIC_DEFEAT: function (opts, stateName) {
            // No figure at all — he's gone. Trapdoor open, a scribbled puff,
            // foot-tick marks left behind, and the sign.
            return (
                apparatusFor(stateName, opts) +
                '<g stroke="' + INK + '" stroke-width="2" fill="none" opacity="0.6">' +
                '<path d="M64,222 Q76,206 66,196 M74,220 Q84,208 76,198 M56,218 Q64,210 58,202 ' +
                'M84,224 Q92,212 82,206"/>' +
                '</g>' +
                burst(76, 208, 6, 15, 200, 160, 0.35) +
                footMark(58, 226, 160) + footMark(98, 224, 20) +
                '<g transform="rotate(-8 170 60)">' +
                '<rect x="118" y="34" width="104" height="52" rx="6" fill="#f5a623" stroke="#241a00" stroke-width="3"/>' +
                '<text x="170" y="56" text-anchor="middle" font-size="13" font-weight="bold" fill="#241a00">NEXT</text>' +
                '<text x="170" y="74" text-anchor="middle" font-size="13" font-weight="bold" fill="#241a00">CONTESTANT</text>' +
                '</g>'
            );
        },
        SAVED: function (opts, stateName) {
            var poseOpts = {
                tilt: -20, // head thrown all the way back, biggest tilt of the set
                hair: 'flying',
                showPocket: opts && opts.showPocket,
                hairParams: opts && opts.hairParams,
                eyes: '<path d="M68,57 Q75,50 82,57" stroke="' + INK + '" stroke-width="2.2" fill="none" stroke-linecap="round"/>' +
                    '<path d="M85,57 Q93,50 100,57" stroke="' + INK + '" stroke-width="2.2" fill="none" stroke-linecap="round"/>',
                mouth: '<path d="M64,69 Q81,95 98,69" stroke="' + INK + '" stroke-width="2.2" fill="none" stroke-linecap="round"/>',
                // both arms flung straight overhead
                leftArm: [SHOULDER_L, SHOULDER_Y, 44, 60, 38, 32], leftHandAngle: -90,
                rightArm: [SHOULDER_R, SHOULDER_Y, 112, 60, 118, 32], rightHandAngle: -90,
                // one leg kicked out/up, the other planted — a genuine jump
                leftLeg: [HIP_L, HIP_Y, 50, 150, 30, 140], leftFootAngle: -20,
                rightLeg: [HIP_R, HIP_Y, 98, 178, 102, 204], rightFootAngle: 20,
                extras: burst(78, 26, 7, 20, 200, 160, 0.55) +
                    burst(30, 34, 3, 11, 210, 60, 0.5) + burst(126, 34, 3, 11, 270, 60, 0.5)
            };
            return (
                apparatusFor(stateName, opts, poseOpts) +
                contestant(poseOpts) +
                // Below him, clear of both his flung-up arms/hair and his
                // planted/kicked feet — same "never over the face" rule.
                speechBubble('SAVED!', 85, 196, 90, 'up')
            );
        }
    };

    // ---- Strike-5 rotating panic lines -----------------------------------
    // A small fixed pool; Skippy shouldn't say the same thing every time he
    // hits Strike 5. This module stays stateless/pure (like the rest of
    // it) — it does NOT remember what was said last on its own. A caller
    // should hold the currently-selected line for as long as the round
    // stays at Strike 5, and call `pickPanicLine(previousLine)` again only
    // when freshly entering Strike 5 (a new round/session, or after
    // leaving and re-entering it). No dialogue framework: this is one
    // array + one function.
    var PANIC_LINES = [
        'I HAVE A FAMILY!',
        'WE CAN TALK ABOUT THIS!',
        'I\'M TOO YOUNG FOR THIS!',
        'PLEASE KNOW A WORD!',
        'THIS WAS A TERRIBLE GIG!',
        'CALL A FRIEND! ANY FRIEND!',
        'I BELIEVE IN YOU! SORT OF!',
        'I SHOULD\'VE READ THE WAIVER!',
        'THINK OF MY SEARCH HISTORY!',
        'JUST BUY A DAMN LETTER!'
    ];

    /**
     * Pick a Strike-5 panic line. Pass the previously-shown line (if any)
     * and it's excluded from the draw so re-entering Strike 5 avoids an
     * immediate repeat "where practical" (skipped automatically if the
     * pool ever shrank to one line). `rng` defaults to Math.random but
     * accepts an injected `() => number in [0,1)` for deterministic tests.
     */
    function pickPanicLine(previousLine, rng) {
        rng = rng || Math.random;
        var pool = PANIC_LINES;
        var candidates = (previousLine && pool.length > 1)
            ? pool.filter(function (line) { return line !== previousLine; })
            : pool;
        var idx = Math.floor(rng() * candidates.length);
        if (idx < 0) { idx = 0; }
        if (idx >= candidates.length) { idx = candidates.length - 1; } // guard rng() === 1
        return candidates[idx];
    }

    /**
     * Returns a self-contained <svg> markup string for the given state
     * name (one of STRIKE_STATE_NAMES or SAVED_STATE_NAME). 260x260
     * viewBox, matching the M1D canvas's element size so it's a drop-in
     * replacement footprint. `opts` is currently only read by TERRIFIED
     * (Strike 5): pass `{ panicLine: '...' }` to control which line from
     * PANIC_LINES is shown; omitted, it falls back to PANIC_LINES[0].
     */
    function renderMarkup(stateName, opts) {
        var build = SCENES[stateName];
        if (!build) { throw new Error('unknown gallows-character state: ' + stateName); }
        return (
            '<svg viewBox="0 0 260 260" width="260" height="260" role="img" ' +
            'aria-label="Contestant status: ' + esc(stateName) + '" data-state="' + esc(stateName) + '">' +
            build(opts, stateName) +
            '</svg>'
        );
    }

    return {
        MAX_STRIKES: MAX_STRIKES,
        STRIKE_STATE_NAMES: STRIKE_STATE_NAMES,
        SAVED_STATE_NAME: SAVED_STATE_NAME,
        pickStateName: pickStateName,
        renderMarkup: renderMarkup,
        PANIC_LINES: PANIC_LINES,
        pickPanicLine: pickPanicLine,
        // HEAD_CX so a caller can resolve hair geometry at the same
        // anchor contestant() uses, and the geometry resolver itself —
        // both exported so tooling/tests can inspect or retune hair from
        // one source of truth rather than duplicating its math.
        HEAD_CX: HEAD_CX,
        computeHairGeometry: computeHairGeometry,
        // Skippy V1's frozen, human-barbered hair values (see
        // computeHairGeometry's defaults) — exported so tests/tools can
        // assert against them without re-typing the numbers.
        SKIPPY_HAIR_DEFAULTS: SKIPPY_HAIR_DEFAULTS,
        // HUMAN-GATE CORRECTION #3: exported so an alternate predicament's
        // own tests can prove its occlusion is derived from the SAME
        // per-state pose geometry as the real visible figure, not an
        // independent approximation. SHOULDER_Y/HIP_Y/HIP_L/HIP_R exported
        // alongside HEAD_CX (already exported above) so those tests can
        // assert against the real body landmarks without re-typing them.
        contestantSilhouette: contestantSilhouette,
        POSE_KNOCKOUT_MASK_ID: POSE_KNOCKOUT_MASK_ID,
        SHOULDER_Y: SHOULDER_Y,
        HIP_Y: HIP_Y,
        HIP_L: HIP_L,
        HIP_R: HIP_R
    };
}));
