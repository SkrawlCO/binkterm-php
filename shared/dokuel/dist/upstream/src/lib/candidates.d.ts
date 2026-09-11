/**
 * Candidate-mask board state shared by the grader and the hint engine.
 * Tracks, per empty cell, which digits its filled peers still allow —
 * the working substrate every human solving technique reads and writes.
 */
export type CandidateState = {
    grid: Uint8Array;
    cand: Uint16Array;
    empty: number;
};
/** Build state from an 81-char board; null when malformed or a cell
 * has no candidate left (an unsolvable position). */
export declare function initCandidates(puzzle: string): CandidateState | null;
export declare function cloneCandidates(s: CandidateState): CandidateState;
export declare function place(s: CandidateState, cell: number, value: number): void;
export type EliminationKind = "pointing" | "claiming" | "naked-pair" | "hidden-pair" | "naked-triple" | "hidden-triple" | "naked-quad" | "hidden-quad" | "x-wing" | "xy-wing" | "swordfish";
/** One applied technique: the pattern, its digits, and what it removed. */
export type Elimination = {
    kind: EliminationKind;
    /** Digits the pattern locks (a single digit except for sets). */
    digits: number[];
    /** Cells forming the pattern — what a hint should highlight. */
    patternCells: number[];
    /** Candidates the pattern removes elsewhere. */
    removed: {
        cell: number;
        digit: number;
    }[];
    /**
     * XY-wing only: each pattern cell's candidate pair, aligned with
     * patternCells ([pivot, pincer, pincer]) so a hint can name the
     * roles — the pivot never holds the eliminated digit, so without
     * these the pattern is unreadable from the highlight alone.
     */
    patternDigits?: number[][];
};
export declare function eliminate(s: CandidateState, cell: number, bits: number, digitsOf: number[], removed: {
    cell: number;
    digit: number;
}[]): boolean;
export type SingleFind = {
    kind: "naked";
    cell: number;
    digit: number;
} | {
    kind: "hidden";
    cell: number;
    digit: number;
    unitIndex: number;
};
/**
 * First placeable single: naked singles in cell order, then hidden
 * singles in unit order (rows, columns, boxes) — the same scan order
 * the grader has always used, so grades cannot drift.
 */
export declare function findSingle(s: CandidateState): SingleFind | null;
