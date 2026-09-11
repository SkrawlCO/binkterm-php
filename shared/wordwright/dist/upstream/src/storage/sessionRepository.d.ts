import type { GameState, WordLength } from '../engine/types.js';
import { type Repository, type StorageIssue } from './createRepository.js';
import { type StorageMeta } from './schemas.js';
/**
 * The in-progress game (FR-32, EC-9, EC-10).
 *
 * Stored as the raw `GameState`, so a restore is a straight assignment with no
 * reconstruction step. A session saved mid-reveal is normalised to `playing`
 * on read: the guess is already recorded and evaluated, so the correct
 * behaviour after a refresh is to show that row revealed and let play continue
 * (EC-10).
 */
export declare function createSessionRepository(onIssue?: (issue: StorageIssue, key: string) => void): Repository<GameState | null>;
/**
 * True when a restored session is still usable (FR-33).
 *
 * The answer must still exist in the current dictionary: regenerating the word
 * lists can retire a word, and resuming a game whose solution is no longer
 * valid would be unwinnable.
 */
export declare function isSessionUsable(session: GameState | null, wordLength: WordLength, isKnownAnswer: (word: string) => boolean): session is GameState;
export declare function createMetaRepository(onIssue?: (issue: StorageIssue, key: string) => void): Repository<StorageMeta>;
/** Pushes an answer onto the front of its length's ring buffer (FR-4). */
export declare function rememberAnswer(meta: StorageMeta, wordLength: WordLength, answer: string): StorageMeta;
