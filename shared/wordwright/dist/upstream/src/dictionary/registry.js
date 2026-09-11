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
export const DICTIONARY_REGISTRY = {
    4: () => import('./data/lists-4.js'),
    5: () => import('./data/lists-5.js'),
    6: () => import('./data/lists-6.js'),
};
