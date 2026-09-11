import type { Guess, LetterState } from './types.js';
/**
 * Folds every evaluated guess into the best-known state per letter (FR-53).
 *
 * Derived on demand rather than stored, so the keyboard can never drift out of
 * sync with the board. A letter marked `present` in one row and `correct` in a
 * later row shows as `correct`; the reverse order gives the same answer.
 */
export declare function deriveKeyStates(guesses: readonly Guess[]): ReadonlyMap<string, LetterState>;
