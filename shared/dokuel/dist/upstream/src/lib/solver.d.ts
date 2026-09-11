/**
 * In-house sudoku solver and generator primitives. Pure functions, no
 * dependencies, all randomness injected via an Rng so callers (e.g. the
 * daily challenge) can make generation fully deterministic.
 *
 * Board representation: an 81-char string, digits 1-9 and "." for empty,
 * row-major — the same wire format used across the app and the Yjs doc.
 */
export type Rng = () => number;
/**
 * Count the puzzle's solutions, stopping at `cap` (default 2 — enough
 * to distinguish unsolvable / unique / ambiguous). Malformed input
 * counts as 0.
 */
export declare function countSolutions(puzzle: string, cap?: number): number;
/**
 * Solve a puzzle. Returns the first solution found, or null when the
 * input is malformed or unsolvable. When the puzzle is ambiguous the
 * returned solution is one of several — callers that care must check
 * countSolutions first (the generator guarantees uniqueness instead).
 */
export declare function solve(puzzle: string): string | null;
/** Generate a complete valid grid, cell by cell with rng-shuffled digits. */
export declare function generateSolvedGrid(rng?: Rng): string;
/**
 * Remove clues from a solved grid in rng-shuffled order, keeping the
 * puzzle uniquely solvable after every removal. Stops once targetClues
 * is reached, or when no further clue can be removed without creating
 * a second solution (a minimal puzzle). The result may therefore hold
 * more clues than requested — 17 is the theoretical floor and random
 * digging exhausts well above it.
 */
export declare function digPuzzle(solved: string, targetClues: number, rng?: Rng): string;
