import { RECENT_GAMES_LIMIT } from '../engine/constants.js';
import { createRepository } from './createRepository.js';
import { STORAGE_KEYS } from './keys.js';
import { defaultStats, emptyBucket, validateStats, } from './schemas.js';
/** Folds one completed game into a metrics bucket (FR-35, FR-37). */
function applyToBucket(bucket, record) {
    const gamesPlayed = bucket.gamesPlayed + 1;
    if (!record.won) {
        return {
            ...bucket,
            gamesPlayed,
            // A loss breaks the streak but leaves the best untouched (FR-37).
            currentStreak: 0,
        };
    }
    const currentStreak = bucket.currentStreak + 1;
    const distribution = [...bucket.distribution];
    const index = record.guessesUsed - 1;
    if (index >= 0 && index < distribution.length) {
        distribution[index] = (distribution[index] ?? 0) + 1;
    }
    return {
        gamesPlayed,
        gamesWon: bucket.gamesWon + 1,
        currentStreak,
        bestStreak: Math.max(bucket.bestStreak, currentStreak),
        distribution,
        totalGuessesInWins: bucket.totalGuessesInWins + record.guessesUsed,
        totalScore: bucket.totalScore + record.score,
        totalSolveMs: bucket.totalSolveMs + record.solveMs,
        fastestSolveMs: bucket.fastestSolveMs === null
            ? record.solveMs
            : Math.min(bucket.fastestSolveMs, record.solveMs),
    };
}
/**
 * Records a completed game (FR-20, FR-35…FR-39).
 *
 * Idempotent by game id: the reveal callback, a re-render, or a restored
 * session replaying its final state must not inflate the player's stats. This
 * is the single funnel every completed game passes through, which is also
 * where V2 achievements would hook in.
 */
export function recordGame(stats, record) {
    if (stats.recordedGameIds.includes(record.id))
        return stats;
    const perLength = {
        ...stats.perLength,
        [record.wordLength]: applyToBucket(stats.perLength[record.wordLength], record),
    };
    return {
        overall: applyToBucket(stats.overall, record),
        perLength,
        recentGames: [record, ...stats.recentGames].slice(0, RECENT_GAMES_LIMIT),
        // Bounded alongside the log: an id we would no longer display cannot be
        // replayed either, and the list must not grow without limit.
        recordedGameIds: [record.id, ...stats.recordedGameIds].slice(0, RECENT_GAMES_LIMIT * 2),
    };
}
/** Clears metrics but keeps the recent-games log (FR-47). */
export function resetStatistics(stats) {
    return {
        ...defaultStats(),
        recentGames: stats.recentGames,
        recordedGameIds: stats.recordedGameIds,
    };
}
/** Clears the recent-games log but keeps aggregate metrics (FR-47). */
export function resetHistory(stats) {
    return { ...stats, recentGames: [], recordedGameIds: [] };
}
/* ------------------------------------------------------------------ *
 * Derived values — computed on read, never stored (FR-35, FR-38)
 * ------------------------------------------------------------------ */
export function winPercentage(bucket) {
    if (bucket.gamesPlayed === 0)
        return 0;
    return Math.round((bucket.gamesWon / bucket.gamesPlayed) * 100);
}
/** Average guesses across wins only; null when there are none (FR-38). */
export function averageGuesses(bucket) {
    if (bucket.gamesWon === 0)
        return null;
    return bucket.totalGuessesInWins / bucket.gamesWon;
}
export function averageSolveMs(bucket) {
    if (bucket.gamesWon === 0)
        return null;
    return bucket.totalSolveMs / bucket.gamesWon;
}
export function createStatsRepository(onIssue) {
    return createRepository({
        key: STORAGE_KEYS.stats,
        defaults: defaultStats,
        validate: validateStats,
        ...(onIssue ? { onIssue } : {}),
    });
}
export { emptyBucket };
