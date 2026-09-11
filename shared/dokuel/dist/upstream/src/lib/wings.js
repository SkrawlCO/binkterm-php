/**
 * Wing- and fish-family eliminations: X-wing, swordfish, XY-wing — the
 * tier 3-4 patterns built on line intersections and pivot logic. Same
 * contract as the unit-local techniques: first application found is
 * applied to the state and reported in full.
 */
import { kCombinations, maskDigits, PEERS, popcount, } from "./board-geometry.js";
import { eliminate, } from "./candidates.js";
/**
 * Fish of `size` lines: a digit held to the same `size` crossing lines
 * across `size` lines (size 2 = X-wing rectangle, 3 = swordfish); the
 * digit leaves the rest of those crossing lines. `cellAt` maps (line,
 * cross) to a cell so one scan covers both orientations.
 */
function fishOriented(s, v, size, cellAt) {
    const bit = 1 << v;
    const crossesOf = [];
    const lines = [];
    for (let line = 0; line < 9; line++) {
        const held = [];
        for (let cross = 0; cross < 9; cross++) {
            if (s.cand[cellAt(line, cross)] & bit)
                held.push(cross);
        }
        crossesOf.push(held);
        if (held.length >= 2 && held.length <= size)
            lines.push(line);
    }
    for (const combo of kCombinations(lines, size)) {
        const union = new Set();
        for (const line of combo) {
            for (const cross of crossesOf[line])
                union.add(cross);
        }
        if (union.size !== size)
            continue;
        const removed = [];
        let changed = false;
        for (let line = 0; line < 9; line++) {
            if (combo.includes(line))
                continue;
            for (const cross of union) {
                changed =
                    eliminate(s, cellAt(line, cross), bit, [v], removed) || changed;
            }
        }
        if (changed) {
            return {
                kind: size === 2 ? "x-wing" : "swordfish",
                digits: [v],
                patternCells: combo.flatMap((line) => crossesOf[line].map((cross) => cellAt(line, cross))),
                removed,
            };
        }
    }
    return null;
}
function fish(s, size) {
    for (let v = 1; v <= 9; v++) {
        const rows = fishOriented(s, v, size, (line, cross) => line * 9 + cross);
        if (rows)
            return rows;
        const cols = fishOriented(s, v, size, (line, cross) => cross * 9 + line);
        if (cols)
            return cols;
    }
    return null;
}
export function xWing(s) {
    return fish(s, 2);
}
export function swordfish(s) {
    return fish(s, 3);
}
/**
 * XY-wing: a pivot holding {x,y} with one pincer {x,z} and one {y,z}.
 * Whichever way the pivot resolves, a pincer becomes z, so z leaves
 * every cell that sees both pincers.
 */
export function xyWing(s) {
    for (let pivot = 0; pivot < 81; pivot++) {
        if (s.grid[pivot] !== 0 || popcount(s.cand[pivot]) !== 2)
            continue;
        const pivotMask = s.cand[pivot];
        const peers = PEERS[pivot];
        for (let i = 0; i < peers.length; i++) {
            const p1 = peers[i];
            const m1 = s.cand[p1];
            if (s.grid[p1] !== 0 || popcount(m1) !== 2)
                continue;
            if (popcount(m1 & pivotMask) !== 1)
                continue;
            for (let j = i + 1; j < peers.length; j++) {
                const p2 = peers[j];
                const m2 = s.cand[p2];
                if (s.grid[p2] !== 0 || popcount(m2) !== 2)
                    continue;
                if (popcount(m2 & pivotMask) !== 1)
                    continue;
                // Pincers must cover different pivot digits and agree on z.
                if ((m1 & pivotMask) === (m2 & pivotMask))
                    continue;
                const zMask = m1 & m2 & ~pivotMask;
                if (popcount(zMask) !== 1)
                    continue;
                const z = 31 - Math.clz32(zMask);
                const removed = [];
                let changed = false;
                for (const c of PEERS[p1]) {
                    if (c === pivot || c === p2 || !PEERS[p2].includes(c))
                        continue;
                    changed = eliminate(s, c, zMask, [z], removed) || changed;
                }
                if (changed) {
                    return {
                        kind: "xy-wing",
                        digits: [z],
                        patternCells: [pivot, p1, p2],
                        patternDigits: [
                            maskDigits(pivotMask),
                            maskDigits(m1),
                            maskDigits(m2),
                        ],
                        removed,
                    };
                }
            }
        }
    }
    return null;
}
