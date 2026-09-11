/**
 * Engine constants.
 *
 * Pure data only — see ADR-003 for why nothing in `src/engine` may reach for
 * React, the DOM, storage, timers, or ambient randomness.
 */

/**
 * Supported word lengths (FR-1).
 *
 * Adding a length here plus a registry entry and a generated data module is the
 * complete change required to support it (FR-29).
 */
export const WORD_LENGTHS = [4, 5, 6] as const;

/** Default word length for a first-time player (FR-1). */
export const DEFAULT_WORD_LENGTH = 5;

/** Guesses allowed per game, at every word length (FR-2, ADR-010). */
export const MAX_GUESSES = 6;

/** Letters accepted as input (FR-6). */
export const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

/**
 * Scoring weights (FR-23).
 *
 * Difficulty is expressed through the multiplier rather than through a varying
 * guess count, which keeps the board shape and the guess distribution
 * comparable across lengths (ADR-010).
 */
export const SCORE = {
  /** A win on guess `n` scores `(MAX_GUESSES + 1 - n) * BASE_PER_GUESS`. */
  BASE_PER_GUESS: 100,
  /** Multiplier applied to the base score, by word length. */
  LENGTH_MULTIPLIER: { 4: 1.0, 5: 1.2, 6: 1.5 },
  /** Solve seconds after which no speed bonus remains. */
  SPEED_BONUS_CUTOFF_SECONDS: 120,
  /** Points awarded per second saved under the cutoff. */
  SPEED_BONUS_PER_SECOND: 2,
} as const;

/**
 * How many recent answers are suppressed before a word may repeat (FR-4).
 * The effective window is `min(this, floor(poolSize / 4))`.
 */
export const RECENT_ANSWER_WINDOW = 50;

/** Maximum completed games retained in the recent-games log (FR-39). */
export const RECENT_GAMES_LIMIT = 50;
