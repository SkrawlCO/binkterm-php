import type { WordLength } from '../engine/types.js';
import type { RawLists } from './types.js';
/**
 * Maps a word length to its lazily loaded data module (ADR-007).
 *
 * Each entry is a separate Vite chunk, so a 5-letter player never downloads the
 * 4- or 6-letter lists. The chunks are bundled at build time and served from
 * the same origin, so this stays entirely offline after first load (NFR-5).
 *
 * **Adding a new length (FR-29):** generate `data/lists-<n>.ts`, add one line
 * here, and widen `WORD_LENGTHS` in `@/engine/constants`. Nothing else changes.
 */
export declare const DICTIONARY_REGISTRY: Readonly<Record<WordLength, () => Promise<RawLists>>>;
