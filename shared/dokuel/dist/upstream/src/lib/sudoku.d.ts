import { type Rng } from "./solver.ts";
import type { Board, Difficulty } from "./types.ts";
/**
 * Generate a puzzle together with the solved grid it was dug from.
 * Every puzzle has exactly one solution by construction — digPuzzle
 * re-verifies uniqueness after each clue removal — so the returned
 * solution is THE solution, safe for error-highlighting and hints.
 */
export declare function generatePuzzleWithSolution(difficulty: Difficulty, rng?: Rng): {
    puzzle: string;
    solution: string;
};
export declare function generatePuzzle(difficulty: Difficulty, rng?: Rng): string;
/**
 * Solve an arbitrary puzzle string. Returns null when the input is
 * malformed or unsolvable — callers treat that as a corrupt save or
 * corrupt room state, never as a crash.
 */
export declare function solvePuzzle(puzzle: string): string | null;
export declare function parsePuzzle(puzzle: string): Board;
/** Encode row,col as a single number for use as Set key. */
export declare function cellKey(row: number, col: number): number;
/**
 * Get all conflicting cell positions as a Set of numeric keys (row*9+col).
 * A conflict = same non-null value in the same row, column, or 3x3 box.
 */
export declare function getConflicts(board: Board): Set<number>;
/**
 * Get all cells whose user-entered value differs from the solution.
 * Only checks non-given cells that have a value. Returns a Set of
 * numeric keys (row*9+col), same format as getConflicts.
 */
export declare function getErrors(board: Board, solution: string): Set<number>;
/**
 * Check if board is complete: all cells filled and no conflicts.
 * Accepts pre-computed conflicts to avoid redundant recomputation.
 */
export declare function isBoardComplete(board: Board, conflicts?: Set<number>): boolean;
