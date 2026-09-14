/**
 * Last Word — Bob, a Last Word character (audition PASSED 2026-09-13 for
 * ROLE/DYNAMIC; his VISUAL DESIGN passed its own separate human gate the
 * same day via an isolated character-design lab — Fork #5, "WHO THE FUCK
 * IS BOB?" — and the accepted hat+beard construction was promoted into
 * this canonical file below. See the durable checkpoint memory for the
 * full verdict history and a pointer to an external, do-not-copy visual
 * reference that was consulted only for broad gnome-construction
 * principles, never traced or copied).
 *
 * BOB IS NOT YET AN L33TEST-WIDE CHARACTER/MASCOT — that possibility is
 * open but has not been decided. This remains a small, isolated module —
 * if a future direction drops Bob, delete this file and the two small
 * opt-in hooks it plugs into (js/lastword/safe-predicament.js's
 * `makeApparatus()`, js/lastword/app-final-skippy.js's
 * `bobEnabled`/intro-line wiring) and nothing else needs to change. Bob
 * has no lore, no name beyond the working name "Bob", and no dialogue —
 * see below.
 *
 * Bob is a tiny gnome who professionally operates the Suspended Safe
 * Predicament's rigging (js/lastword/safe-predicament.js) like ordinary
 * Tuesday work. SKIPPY TALKS. BOB DOES. He never speaks, thinks aloud, or
 * gets narrated — this module renders him and nothing else. He is drawn as
 * part of the Suspended Safe's own apparatus (see `makeApparatus()` in
 * safe-predicament.js), so he automatically inherits that Predicament's
 * pose-derived occlusion mask — Bob can never draw through Skippy's actual
 * figure, for free, without this module knowing anything about Skippy.
 *
 * Deliberately pure/DOM-free/stateless like every other character module
 * here: a function of (stateName) -> SVG markup string, nothing else.
 *
 * HUMAN-GATE CORRECTION (visual + behavioral): the first pass read as a
 * "filled-color geometric icon imported from another UI" and stood still
 * as a status badge. Redrawn ONCE, translated into Skippy's own loose
 * minimalist LINE-ART vocabulary — an unfilled head circle, open
 * shoulder/spine/hip body bars, thin stroked limbs with small foot ticks,
 * an unfilled pointed hat — the same construction grammar
 * gallows-character.js's contestant() uses, just scaled down to roughly
 * 40-45% of Skippy's own height. No large flat filled-body regions remain;
 * the only color accents are the crank's plain workshop-metal wheel (a
 * prop, not Bob himself), one small amber checkmark, and (as of the visual
 * promotion below) the hat's own cyan stroke. Given a small SILENT WORK
 * STORY across the safe's accepted strike progression instead of one
 * static pose — see POSE_BY_STATE below — so the comedy reads as contrast
 * (Skippy grows more emotional; Bob grows more professionally satisfied
 * that the job is going fine), not a fixed icon.
 *
 * VISUAL PROMOTION (Fork #5, human-accepted 2026-09-13): the disposable
 * character-design lab (js/lastword-bob-lab/, kept alongside this file
 * until canonical Bob passes human testing in the actual game) explored
 * Bob's silhouette and iterated the hat/beard geometry through several
 * correction rounds. The accepted result — a complete/closed gnome-hat
 * outline (`hat()`) and a compact facial beard with a small deliberate gap
 * beneath the mouth (`beard()`) — is promoted here as real canonical
 * geometry, integrated directly into `head()` so it renders correctly
 * across every pose including the tilted ones (NERVOUS's lean, SAVED's
 * turn), unlike the lab's own string-surgery proof technique. The ONE
 * approved art-direction change made during this promotion: the hat's
 * stroke is now cyan (`HAT_CYAN`) — still fully unfilled, no other new
 * accent colors, face/body/beard/limbs stay plain ink.
 */
(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.LastWordBobCharacter = factory();
    }
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    // The SAME outline ink the rest of the cast draws in — this is what
    // makes Bob read as belonging to Skippy's world rather than an
    // imported icon. No large filled body regions anywhere below.
    var INK = '#dbe4f5';
    var METAL = '#9aa4b6'; // the crank wheel — a workshop prop, not part of Bob's own line art
    var WARNING = '#f5c168'; // the one small checkmark accent, same accent color the safe's own warning marks use
    // The one approved art-direction accent from the character-lab
    // promotion (Fork #5, human-accepted 2026-09-13): Bob's hat STROKE
    // only. Still fully unfilled — no new filled regions, no other new
    // accent colors, face/body/beard/limbs stay plain INK.
    var HAT_CYAN = '#00e5ff';

    // Fixed perch near the safe's own rigging anchor (safe-predicament.js's
    // ANCHOR_X=175/CEILING_Y=14) but offset clear of it — an independent
    // constant, not an import, so this module stays a true standalone
    // proof with nothing to break if safe-predicament.js's own geometry
    // ever changes.
    var BOB_CX = 205;

    // Body landmarks — same shoulder/spine/hip-bar grammar
    // gallows-character.js's contestant() uses, scaled down. Skippy spans
    // roughly y=38 (head top) to y=204 (feet), ~166px; Bob spans y=4 to
    // y=74, ~70px — about 42% of Skippy's height.
    var HAT_TIP_Y = 4;
    var HEAD_CY = 26;
    var HEAD_R = 10;
    var SHOULDER_Y = 38;
    var HIP_Y = 54;
    var FOOT_Y = 74;
    var SHOULDER_L = BOB_CX - 6, SHOULDER_R = BOB_CX + 6;
    var HIP_L = BOB_CX - 5, HIP_R = BOB_CX + 5;

    // The crank/winch he's "operating" — a plain small wheel + handle,
    // fixed prop beside him.
    var CRANK_CX = BOB_CX + 21;
    var CRANK_CY = 42;
    var CRANK_R = 7;

    function lineStroke(pts, width) {
        var d = 'M' + pts[0] + ',' + pts[1];
        for (var i = 2; i < pts.length; i += 2) { d += ' L' + pts[i] + ',' + pts[i + 1]; }
        return '<path d="' + d + '" stroke="' + INK + '" stroke-width="' + (width || 1.5) + '" fill="none" ' +
            'stroke-linecap="round" stroke-linejoin="round"/>';
    }

    // A short foot tick, same idea as gallows-character.js's footMark — an
    // open line stub, not a filled boot shape.
    function footTick(x, y, angleDeg) {
        var rad = angleDeg * Math.PI / 180;
        var tx = x + Math.cos(rad) * 6, ty = y + Math.sin(rad) * 3;
        return lineStroke([x, y, tx, ty], 1.5);
    }

    // Closed, complete gnome-hat silhouette — one side rising to the
    // point, the other returning from the point back to the brim.
    // Promoted unchanged from the accepted character-lab candidate
    // (Fork #5 Round 2, hat FROZEN from Round 3 onward). CYAN stroke is
    // the one approved art-direction change made during this promotion;
    // the hat stays fully unfilled.
    function hat() {
        var headTop = HEAD_CY - HEAD_R;
        var baseY = headTop + 2;
        var leftBaseX = BOB_CX - 9, rightBaseX = BOB_CX + 9;
        var tipX = BOB_CX + 4, tipY = HAT_TIP_Y - 1;
        return (
            '<path d="M' + leftBaseX + ',' + baseY +
            ' L' + tipX + ',' + tipY +
            ' Q' + (tipX + 5) + ',' + (tipY + 7) + ' ' + rightBaseX + ',' + baseY +
            ' L' + leftBaseX + ',' + baseY + ' Z" ' +
            'stroke="' + HAT_CYAN + '" stroke-width="1.6" fill="none" stroke-linecap="round" stroke-linejoin="round"/>'
        );
    }

    // Compact facial beard — final accepted geometry (Fork #5 Round 4):
    // sits against the lower head arc, a small V ending at the chin, with
    // a deliberate ~2-3px optical gap beneath the mouth so the two read as
    // separate features rather than merging into one glyph at enlarged
    // scale. Plain INK, same as the rest of Bob's face — no new accent.
    function beard() {
        var headBottom = HEAD_CY + HEAD_R;
        var topY = headBottom - 3, bottomY = headBottom, midY = topY + (bottomY - topY) / 2;
        var leftX = BOB_CX - 3, rightX = BOB_CX + 3;
        return (
            '<path d="M' + leftX + ',' + topY +
            ' Q' + (BOB_CX - 1.5) + ',' + midY + ' ' + BOB_CX + ',' + bottomY +
            ' Q' + (BOB_CX + 1.5) + ',' + midY + ' ' + rightX + ',' + topY + '" ' +
            'stroke="' + INK + '" stroke-width="1.2" fill="none" stroke-linecap="round" stroke-linejoin="round"/>'
        );
    }

    function head() {
        return (
            hat() +
            // unfilled head circle — same convention as Skippy's own head
            '<circle cx="' + BOB_CX + '" cy="' + HEAD_CY + '" r="' + HEAD_R + '" fill="none" stroke="' + INK + '" stroke-width="1.6"/>' +
            // two tiny dot eyes, flat neutral mouth — pleasant, extremely simple
            '<circle cx="' + (BOB_CX - 3.5) + '" cy="' + (HEAD_CY - 1) + '" r="1.1" fill="' + INK + '"/>' +
            '<circle cx="' + (BOB_CX + 3.5) + '" cy="' + (HEAD_CY - 1) + '" r="1.1" fill="' + INK + '"/>' +
            '<path d="M' + (BOB_CX - 2.5) + ',' + (HEAD_CY + 4) + ' L' + (BOB_CX + 2.5) + ',' + (HEAD_CY + 4) + '" ' +
            'stroke="' + INK + '" stroke-width="1" stroke-linecap="round"/>' +
            beard()
        );
    }

    function bodyAndLegs() {
        return (
            lineStroke([SHOULDER_L, SHOULDER_Y, SHOULDER_R, SHOULDER_Y]) +
            lineStroke([BOB_CX, SHOULDER_Y, BOB_CX, HIP_Y]) +
            lineStroke([HIP_L, HIP_Y, HIP_R, HIP_Y]) +
            lineStroke([HIP_L, HIP_Y, HIP_L - 2, FOOT_Y]) + footTick(HIP_L - 2, FOOT_Y, 160) +
            lineStroke([HIP_R, HIP_Y, HIP_R + 2, FOOT_Y]) + footTick(HIP_R + 2, FOOT_Y, 20)
        );
    }

    function crank() {
        return (
            '<circle cx="' + CRANK_CX + '" cy="' + CRANK_CY + '" r="' + CRANK_R + '" fill="none" stroke="' + METAL + '" stroke-width="1.8"/>' +
            '<path d="M' + CRANK_CX + ',' + (CRANK_CY - CRANK_R) + ' L' + CRANK_CX + ',' + (CRANK_CY + CRANK_R) +
            ' M' + (CRANK_CX - CRANK_R) + ',' + CRANK_CY + ' L' + (CRANK_CX + CRANK_R) + ',' + CRANK_CY + '" ' +
            'stroke="' + METAL + '" stroke-width="1.2"/>' +
            '<path d="M' + (CRANK_CX + CRANK_R) + ',' + CRANK_CY + ' L' + (CRANK_CX + CRANK_R + 6) + ',' + (CRANK_CY - 3) + '" ' +
            'stroke="' + METAL + '" stroke-width="1.6" stroke-linecap="round"/>'
        );
    }

    // Small open clipboard — a bare rectangle outline with two tick lines,
    // never filled, held at chest height clear of the face.
    function clipboard(mark) {
        var x = BOB_CX - 19, y = SHOULDER_Y + 2, w = 10, h = 13;
        var board = (
            '<rect x="' + x + '" y="' + y + '" width="' + w + '" height="' + h + '" rx="1" ' +
            'fill="none" stroke="' + INK + '" stroke-width="1.3"/>' +
            '<path d="M' + (x + 2) + ',' + (y + 4) + ' L' + (x + w - 2) + ',' + (y + 4) +
            ' M' + (x + 2) + ',' + (y + 7) + ' L' + (x + w - 3) + ',' + (y + 7) + '" ' +
            'stroke="' + INK + '" stroke-width="0.9" opacity="0.75"/>'
        );
        if (mark) {
            board += (
                '<path d="M' + (x + 2.5) + ',' + (y + 9.5) + ' L' + (x + 4.5) + ',' + (y + 11.5) +
                ' L' + (x + w - 2) + ',' + (y + 6) + '" ' +
                'stroke="' + WARNING + '" stroke-width="1.4" fill="none" stroke-linecap="round" stroke-linejoin="round"/>'
            );
        }
        return board;
    }

    // ---- arm sets, one per work beat --------------------------------------

    function armsArrive() {
        // one hand lifting toward the mechanism as he arrives, not gripping yet
        return (
            lineStroke([SHOULDER_R, SHOULDER_Y, BOB_CX + 12, SHOULDER_Y + 4]) +
            lineStroke([SHOULDER_L, SHOULDER_Y, BOB_CX - 8, SHOULDER_Y + 11])
        );
    }

    function armsCrank(bothHands) {
        var right = lineStroke([SHOULDER_R, SHOULDER_Y, CRANK_CX - 5, CRANK_CY]);
        var left = bothHands
            ? lineStroke([SHOULDER_L, SHOULDER_Y, CRANK_CX + 3, CRANK_CY + 5])
            : lineStroke([SHOULDER_L, SHOULDER_Y, BOB_CX - 8, SHOULDER_Y + 11]);
        return right + left;
    }

    function armsClipboard() {
        return (
            lineStroke([SHOULDER_L, SHOULDER_Y, BOB_CX - 15, SHOULDER_Y + 6]) +
            lineStroke([SHOULDER_R, SHOULDER_Y, BOB_CX + 9, SHOULDER_Y + 12])
        );
    }

    function armsRelaxed() {
        return (
            lineStroke([SHOULDER_L, SHOULDER_Y, BOB_CX - 9, SHOULDER_Y + 13]) +
            lineStroke([SHOULDER_R, SHOULDER_Y, BOB_CX + 9, SHOULDER_Y + 13])
        );
    }

    function figure(extras, tiltDeg) {
        var group = bodyAndLegs() + (extras || '') + head();
        if (tiltDeg) {
            return '<g transform="rotate(' + tiltDeg + ' ' + BOB_CX + ' ' + HIP_Y + ')">' + group + '</g>';
        }
        return group;
    }

    // The small silent work story across the safe's accepted strike
    // progression — SKIPPY TALKS, BOB DOES. Strike 0 has no entry (Bob is
    // absent — see renderBob()). Six distinct beats, no dialogue, no new
    // pose introduced beyond what the human-gate correction asked for.
    var SCENES = {
        // Strike 1: arriving / taking position — not yet gripping the crank.
        CONFUSED: function () { return crank() + figure(armsArrive()); },
        // Strike 2: cranking, one hand on the wheel.
        CONCERNED: function () { return crank() + figure(armsCrank(false)); },
        // Strike 3: working harder — both hands on the crank, a slight lean into it.
        NERVOUS: function () { return crank() + figure(armsCrank(true), 6); },
        // Strike 4: pauses, consults the clipboard. Skippy stays the emotional center.
        PLEADING: function () { return crank() + figure(armsClipboard()) + clipboard(false); },
        // Strike 5: calmly marks the clipboard — "the job is ready" — plus the crank still visible behind him.
        TERRIFIED: function () { return crank() + figure(armsClipboard()) + clipboard(true); },
        // Strike 6/WHAM: calmly inspects the result, clipboard already marked — small, doesn't compete with the payoff.
        COMEDIC_DEFEAT: function () { return figure(armsClipboard()) + clipboard(true); },
        // SAVED: winch stopped, packing up — arms relaxed, a slight turn as if leaving.
        SAVED: function () { return crank() + figure(armsRelaxed(), -5); }
    };

    /**
     * Bob's markup for one gallows-character.js state name, or '' if he
     * isn't present in that state. Meant to be appended to the Suspended
     * Safe Predicament's own apparatus markup (see safe-predicament.js's
     * `makeApparatus()`) — never called standalone in production, but
     * usable that way for isolated testing.
     */
    function renderBob(stateName) {
        var scene = SCENES[stateName];
        if (!scene) { return ''; } // CONFIDENT (Strike 0): absent — Skippy's confident opening stays entirely his own.
        return '<g>' + scene() + '</g>';
    }

    return {
        BOB_CX: BOB_CX,
        HEAD_CY: HEAD_CY,
        HEAD_R: HEAD_R,
        FOOT_Y: FOOT_Y,
        POSE_BY_STATE: {
            CONFIDENT: null,
            CONFUSED: 'arrive',
            CONCERNED: 'crankLight',
            NERVOUS: 'crankHard',
            PLEADING: 'clipboardCheck',
            TERRIFIED: 'clipboardMark',
            COMEDIC_DEFEAT: 'inspectResult',
            SAVED: 'packUp'
        },
        renderBob: renderBob
    };
}));
