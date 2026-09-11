import type { GameRecord, GameState, WordLength } from '../engine/types.js';
/**
 * Runtime validation for everything we read back from storage.
 *
 * LocalStorage is user-writable and survives app upgrades, so parsed JSON is
 * untrusted input. These guards are the boundary where `unknown` becomes a
 * typed value; anything that fails is discarded in favour of defaults (EC-12).
 */
export type Theme = 'light' | 'dark' | 'system';
export type MotionPreference = 'system' | 'reduced' | 'full';
export interface Settings {
    readonly theme: Theme;
    readonly colorblind: boolean;
    readonly motion: MotionPreference;
    readonly defaultWordLength: WordLength;
}
/** Metrics tracked both overall and per word length (FR-35, FR-36). */
export interface StatsBucket {
    readonly gamesPlayed: number;
    readonly gamesWon: number;
    readonly currentStreak: number;
    readonly bestStreak: number;
    /** Wins by guess count; index 0 is a one-guess win (FR-40). */
    readonly distribution: readonly number[];
    /** Guesses used across wins only, for the average (FR-38). */
    readonly totalGuessesInWins: number;
    readonly totalScore: number;
    readonly totalSolveMs: number;
    readonly fastestSolveMs: number | null;
}
export interface StatsState {
    readonly overall: StatsBucket;
    readonly perLength: Readonly<Record<WordLength, StatsBucket>>;
    readonly recentGames: readonly GameRecord[];
    /** Ids already recorded, so a replayed completion cannot double-count (FR-20). */
    readonly recordedGameIds: readonly string[];
}
export interface StorageMeta {
    readonly schemaVersion: number;
    /** Most-recent-first answers per length, for the no-repeat window (FR-4). */
    readonly recentAnswers: Readonly<Record<WordLength, readonly string[]>>;
}
export declare const DEFAULT_SETTINGS: Settings;
export declare function emptyBucket(): StatsBucket;
export declare function defaultStats(): StatsState;
export declare function defaultMeta(): StorageMeta;
export declare function validateSettings(value: unknown): Settings | null;
export declare function validateStats(value: unknown): StatsState | null;
export declare function validateSession(value: unknown): GameState | null;
export declare function validateMeta(value: unknown): StorageMeta | null;
