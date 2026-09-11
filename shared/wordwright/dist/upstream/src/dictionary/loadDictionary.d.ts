import type { WordLength } from '../engine/types.js';
import { type Dictionary } from './types.js';
/**
 * Loads the word lists for a length, lazily and once (ADR-007).
 *
 * The chunk is bundled at build time, so this resolves from the browser's
 * existing asset cache and issues no network request after first load (NFR-5).
 *
 * @throws {DictionaryLoadError} when the chunk cannot be loaded, so the UI can
 *   offer Retry rather than dying silently (EC-14).
 */
export declare function loadDictionary(wordLength: WordLength): Promise<Dictionary>;
/** Synchronous peek for callers that only want an already-loaded dictionary. */
export declare function getCachedDictionary(wordLength: WordLength): Dictionary | undefined;
/** Test seam: clears memoised state between cases. */
export declare function resetDictionaryCache(): void;
