import type { WordLength } from './types.js';
export interface ScoreInput {
    readonly won: boolean;
    /** Guesses used, 1…MAX_GUESSES. Ignored for a loss. */
    readonly guessesUsed: number;
    readonly wordLength: WordLength;
    /** Wall-clock milliseconds spent solving (FR-22). */
    readonly solveMs: number;
}
/**
 * Scores a completed game (FR-23).
 *
 *   base       = (MAX_GUESSES + 1 - guessesUsed) * 100   // 600 down to 100
 *   multiplier = 1.0 / 1.2 / 1.5 by word length          // difficulty (ADR-010)
 *   speedBonus = max(0, 120 - solveSeconds) * 2          // 0…240
 *   score      = round(base * multiplier) + speedBonus
 *
 * A loss scores zero. Difficulty is rewarded here rather than by varying the
 * guess count, which keeps the board and the statistics comparable across
 * lengths.
 */
export declare function scoreGame({ won, guessesUsed, wordLength, solveMs }: ScoreInput): number;
