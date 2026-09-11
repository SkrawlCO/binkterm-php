/**
 * Human-technique sudoku grader. Solves with the deductions a player can
 * actually execute and reports the hardest tier the puzzle demanded.
 * Pure functions, no dependencies; same 81-char board format as solver.
 *
 * Tiers:
 *   1 — naked/hidden singles
 *   2 — locked candidates, naked/hidden pairs
 *   3 — naked/hidden triples, X-wing
 *   4 — naked/hidden quads, XY-wing, swordfish
 *   5 — none of the above suffice: chains or trial-and-error required
 */
export type TechniqueTier = 1 | 2 | 3 | 4 | 5;
export type PuzzleGrade = {
    tier: TechniqueTier;
    /**
     * Empty cells left when tier ≤3 techniques exhaust — 0 when they solve
     * the puzzle, larger when more of the board resists everything short
     * of chains or guessing.
     */
    stuckCells: number;
};
/**
 * Grade a puzzle by the hardest technique required to finish it. A
 * malformed or internally contradictory puzzle grades as maximally
 * stuck — nothing a human technique could do with it.
 */
export declare function gradePuzzle(puzzle: string): PuzzleGrade;
