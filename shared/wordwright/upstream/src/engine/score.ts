import { MAX_GUESSES, SCORE } from './constants';
import type { WordLength } from './types';

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
export function scoreGame({ won, guessesUsed, wordLength, solveMs }: ScoreInput): number {
  if (!won) return 0;

  const base = (MAX_GUESSES + 1 - guessesUsed) * SCORE.BASE_PER_GUESS;
  const multiplier = SCORE.LENGTH_MULTIPLIER[wordLength];

  const solveSeconds = Math.floor(Math.max(0, solveMs) / 1000);
  const secondsSaved = Math.max(0, SCORE.SPEED_BONUS_CUTOFF_SECONDS - solveSeconds);
  const speedBonus = secondsSaved * SCORE.SPEED_BONUS_PER_SECOND;

  return Math.round(base * multiplier) + speedBonus;
}
