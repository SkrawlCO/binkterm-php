import { type Result } from '../lib/result.js';
import type { GuessError, WordLength } from './types.js';
/**
 * Validates a candidate guess before it is submitted (FR-14, FR-15).
 *
 * The dictionary arrives as an injected predicate rather than an import, which
 * is what keeps the engine framework- and data-independent (ADR-003) and gives
 * V2 hard mode a place to compose an extra rule without touching the reducer.
 *
 * Repeated guesses are deliberately allowed (FR-16): the original game permits
 * them, and blocking them would surprise players mid-deduction.
 *
 * @param input     Raw input from the active row; case-insensitive.
 * @param wordLength Required length.
 * @param isAllowed Membership test against the guess list — O(1) `Set.has` in
 *                  production (FR-17).
 */
export declare function validateGuess(input: string, wordLength: WordLength, isAllowed: (word: string) => boolean): Result<string, GuessError>;
