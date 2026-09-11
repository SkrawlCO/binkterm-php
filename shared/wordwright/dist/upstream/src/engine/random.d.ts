/**
 * Randomness is injected, never ambient (ADR-009).
 *
 * The engine never calls `Math.random()` — a lint rule enforces it. Tests pass
 * a fixed sequence for reproducibility, and V2's daily challenge and shareable
 * seeded games become `seededRandom(seed)` with no rule changes.
 */
export interface RandomSource {
    /** A float in [0, 1), like `Math.random`. */
    readonly next: () => number;
}
/** Production source. The one place ambient randomness is allowed in. */
export declare const systemRandom: RandomSource;
/**
 * mulberry32 — a small, fast, well-distributed 32-bit PRNG.
 *
 * Deterministic for a given seed, which is what makes seeded and daily games
 * possible later, and what makes engine tests reproducible now.
 */
export declare function seededRandom(seed: number): RandomSource;
/** Picks a uniformly random element. Throws on an empty list — a caller bug. */
export declare function pickRandom<T>(items: readonly T[], random: RandomSource): T;
