/**
 * Unit-local candidate-elimination techniques and the unlock search.
 * Each scan finds its first application, applies it to the state, and
 * reports the full story — pattern cells, locked digits, removed
 * candidates — so the grader can rank it and the hint engine can
 * explain it. Scan order is part of the grading contract: changing it
 * changes which boards the generator accepts. Wing- and fish-family
 * scans live in wings.ts.
 */
import { type CandidateState, type Elimination, type SingleFind } from "./candidates.ts";
/** Pointing: a digit confined to one row/col of a box leaves that line. */
export declare function pointing(s: CandidateState): Elimination | null;
/** Claiming: a digit confined to one box of a row/col leaves that box. */
export declare function claiming(s: CandidateState): Elimination | null;
/** Naked set: `size` cells sharing the same `size` candidates own them. */
export declare function nakedSet(s: CandidateState, size: number): Elimination | null;
/** Hidden set: `size` digits confined to the same `size` cells own them. */
export declare function hiddenSet(s: CandidateState, size: number): Elimination | null;
export type UnlockingPlacement = {
    /** The elimination whose removals make the placement visible. */
    elimination: Elimination;
    /** The single that emerges once the elimination is applied. */
    single: SingleFind;
    /** Eliminations silently applied before the unlocking one. */
    priorSteps: number;
};
/**
 * On a board whose singles have run dry, find the elimination that
 * makes the next placement visible. Prefers an elimination that
 * unlocks a single immediately — its explanation stands on the visible
 * board alone. When no technique unlocks anything directly, cheaper
 * eliminations are applied silently and the search repeats; priorSteps
 * counts them so a hint can be honest about the depth. Null when only
 * chains or guessing can progress, or when a single is still available
 * (the caller explains those itself).
 */
export declare function findUnlockingPlacement(puzzle: string): UnlockingPlacement | null;
