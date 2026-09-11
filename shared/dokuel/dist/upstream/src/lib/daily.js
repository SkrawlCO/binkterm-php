import { todayLocalISO } from "./date.js";
import { generatePuzzleWithSolution } from "./sudoku.js";
/** Deterministic 32-bit string hash — the seed source for seededRandom. */
export function hashCode(str) {
    let hash = 0;
    for (let i = 0; i < str.length; i++) {
        hash = (Math.imul(31, hash) + str.charCodeAt(i)) | 0;
    }
    return hash >>> 0;
}
/**
 * Deterministic integer LCG → [0,1) float. Exported as the app's one
 * seeded Rng so tests exercise the exact generator the daily golden
 * vectors pin — a copied implementation could drift silently.
 */
export function seededRandom(seed) {
    let state = seed;
    return () => {
        state = (Math.imul(state, 1664525) + 1013904223) | 0;
        return (state >>> 0) / 0x100000000;
    };
}
export function getDailyPuzzle(date = todayLocalISO(), difficulty = "medium") {
    const seed = hashCode(`sudoku-daily-${date}-${difficulty}`);
    const rng = seededRandom(seed);
    const { puzzle, solution } = generatePuzzleWithSolution(difficulty, rng);
    return { puzzle, solution, date };
}
