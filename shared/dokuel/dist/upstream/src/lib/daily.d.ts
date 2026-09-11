import type { Difficulty } from "./types.ts";
/** Deterministic 32-bit string hash — the seed source for seededRandom. */
export declare function hashCode(str: string): number;
/**
 * Deterministic integer LCG → [0,1) float. Exported as the app's one
 * seeded Rng so tests exercise the exact generator the daily golden
 * vectors pin — a copied implementation could drift silently.
 */
export declare function seededRandom(seed: number): () => number;
export declare function getDailyPuzzle(date?: string, difficulty?: Difficulty): {
    puzzle: string;
    solution: string;
    date: string;
};
