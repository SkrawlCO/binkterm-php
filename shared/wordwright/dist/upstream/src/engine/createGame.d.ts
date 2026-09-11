import { type RandomSource } from './random.js';
import type { GameState, WordLength } from './types.js';
/**
 * Chooses the next answer, avoiding recent repeats (FR-4, EC-20).
 *
 * The suppression window is `min(RECENT_ANSWER_WINDOW, floor(pool / 4))`, so a
 * small pool can never starve the picker. If every candidate is suppressed —
 * only reachable with a tiny pool — the window resets and the full pool is
 * used again rather than throwing.
 *
 * @param pool         Candidate answers for this length.
 * @param recentlyUsed Most-recent-first list of previous answers.
 */
export declare function pickAnswer(pool: readonly string[], recentlyUsed: readonly string[], random: RandomSource): string;
export interface CreateGameOptions {
    readonly id: string;
    readonly wordLength: WordLength;
    readonly answer: string;
}
/**
 * Builds a fresh game in `playing` status.
 *
 * `startedAt` stays null until the first keystroke so the timer measures
 * thinking time rather than idle time (FR-22).
 */
export declare function createGame({ id, wordLength, answer }: CreateGameOptions): GameState;
