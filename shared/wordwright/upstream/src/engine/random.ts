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
export const systemRandom: RandomSource = {
  next: () => Math.random(),
};

/**
 * mulberry32 — a small, fast, well-distributed 32-bit PRNG.
 *
 * Deterministic for a given seed, which is what makes seeded and daily games
 * possible later, and what makes engine tests reproducible now.
 */
export function seededRandom(seed: number): RandomSource {
  let state = seed >>> 0;

  return {
    next(): number {
      state = (state + 0x6d2b79f5) >>> 0;
      let t = state;
      t = Math.imul(t ^ (t >>> 15), t | 1);
      t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
      return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    },
  };
}

/** Picks a uniformly random element. Throws on an empty list — a caller bug. */
export function pickRandom<T>(items: readonly T[], random: RandomSource): T {
  if (items.length === 0) {
    throw new Error('Cannot pick from an empty list');
  }

  const index = Math.floor(random.next() * items.length);
  // `next()` is specified as [0, 1), but clamp anyway: a faulty custom source
  // returning exactly 1 would otherwise index out of bounds.
  const safeIndex = Math.min(index, items.length - 1);

  return items[safeIndex] as T;
}
