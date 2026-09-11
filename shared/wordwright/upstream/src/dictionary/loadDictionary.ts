import type { WordLength } from '@/engine/types';

import { DICTIONARY_REGISTRY } from './registry';
import { DictionaryLoadError, type Dictionary } from './types';

/** Dictionaries already built this session. */
const cache = new Map<WordLength, Dictionary>();

/**
 * Loads in flight, keyed by length.
 *
 * Without this, React 19 StrictMode's double-invoked effects would import and
 * rebuild the same Set twice, and a fast length-switch could interleave two
 * loads of the same chunk.
 */
const inFlight = new Map<WordLength, Promise<Dictionary>>();

/**
 * Loads the word lists for a length, lazily and once (ADR-007).
 *
 * The chunk is bundled at build time, so this resolves from the browser's
 * existing asset cache and issues no network request after first load (NFR-5).
 *
 * @throws {DictionaryLoadError} when the chunk cannot be loaded, so the UI can
 *   offer Retry rather than dying silently (EC-14).
 */
export async function loadDictionary(wordLength: WordLength): Promise<Dictionary> {
  const cached = cache.get(wordLength);
  if (cached) return cached;

  const pending = inFlight.get(wordLength);
  if (pending) return pending;

  const load = (async (): Promise<Dictionary> => {
    try {
      const importLists = DICTIONARY_REGISTRY[wordLength];
      const { answers, guesses } = await importLists();

      const dictionary: Dictionary = {
        wordLength,
        answers,
        // Built once, so every subsequent validation is O(1) (FR-17).
        guesses: new Set(guesses),
      };

      cache.set(wordLength, dictionary);
      return dictionary;
    } catch (cause) {
      throw new DictionaryLoadError(wordLength, cause);
    } finally {
      inFlight.delete(wordLength);
    }
  })();

  inFlight.set(wordLength, load);
  return load;
}

/** Synchronous peek for callers that only want an already-loaded dictionary. */
export function getCachedDictionary(wordLength: WordLength): Dictionary | undefined {
  return cache.get(wordLength);
}

/** Test seam: clears memoised state between cases. */
export function resetDictionaryCache(): void {
  cache.clear();
  inFlight.clear();
}
