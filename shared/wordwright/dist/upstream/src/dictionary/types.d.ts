import type { WordLength } from '../engine/types.js';
/** The raw shape of a generated `lists-<n>.ts` module. */
export interface RawLists {
    readonly answers: readonly string[];
    readonly guesses: readonly string[];
}
/**
 * A loaded, ready-to-use dictionary for one word length.
 *
 * `answers` stays an array because we index into it randomly (FR-3);
 * `guesses` becomes a Set so validation is O(1) per keystroke (FR-17).
 */
export interface Dictionary {
    readonly wordLength: WordLength;
    readonly answers: readonly string[];
    readonly guesses: ReadonlySet<string>;
}
/** Raised when a dictionary chunk cannot be loaded, so the UI can offer Retry (EC-14). */
export declare class DictionaryLoadError extends Error {
    readonly wordLength: WordLength;
    constructor(wordLength: WordLength, cause?: unknown);
}
