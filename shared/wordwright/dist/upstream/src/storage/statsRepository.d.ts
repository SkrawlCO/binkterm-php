import type { GameRecord } from '../engine/types.js';
import { type Repository, type StorageIssue } from './createRepository.js';
import { emptyBucket, type StatsBucket, type StatsState } from './schemas.js';
/**
 * Records a completed game (FR-20, FR-35…FR-39).
 *
 * Idempotent by game id: the reveal callback, a re-render, or a restored
 * session replaying its final state must not inflate the player's stats. This
 * is the single funnel every completed game passes through, which is also
 * where V2 achievements would hook in.
 */
export declare function recordGame(stats: StatsState, record: GameRecord): StatsState;
/** Clears metrics but keeps the recent-games log (FR-47). */
export declare function resetStatistics(stats: StatsState): StatsState;
/** Clears the recent-games log but keeps aggregate metrics (FR-47). */
export declare function resetHistory(stats: StatsState): StatsState;
export declare function winPercentage(bucket: StatsBucket): number;
/** Average guesses across wins only; null when there are none (FR-38). */
export declare function averageGuesses(bucket: StatsBucket): number | null;
export declare function averageSolveMs(bucket: StatsBucket): number | null;
export declare function createStatsRepository(onIssue?: (issue: StorageIssue, key: string) => void): Repository<StatsState>;
export { emptyBucket };
