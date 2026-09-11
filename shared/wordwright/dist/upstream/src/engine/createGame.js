import { RECENT_ANSWER_WINDOW } from './constants.js';
import { pickRandom } from './random.js';
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
export function pickAnswer(pool, recentlyUsed, random) {
    if (pool.length === 0) {
        throw new Error('Cannot pick an answer from an empty pool');
    }
    const windowSize = Math.min(RECENT_ANSWER_WINDOW, Math.floor(pool.length / 4));
    const suppressed = new Set(recentlyUsed.slice(0, windowSize));
    const candidates = pool.filter((word) => !suppressed.has(word));
    /* v8 ignore next -- the `: pool` fallback is unreachable through pickAnswer's
       own window, which suppresses at most floor(pool/4) entries. It guards a
       caller that passes a `recentlyUsed` list covering the whole pool (EC-20). */
    return pickRandom(candidates.length > 0 ? candidates : pool, random);
}
/**
 * Builds a fresh game in `playing` status.
 *
 * `startedAt` stays null until the first keystroke so the timer measures
 * thinking time rather than idle time (FR-22).
 */
export function createGame({ id, wordLength, answer }) {
    return {
        id,
        wordLength,
        answer: answer.toUpperCase(),
        guesses: [],
        currentInput: '',
        status: 'playing',
        startedAt: null,
        finishedAt: null,
    };
}
