import type { ActiveHint, Board, Position } from "./types.ts";
export type HintExplanation = ActiveHint;
/**
 * Find the best hint for the current board state.
 * Tries techniques in order of simplicity:
 * 1. Naked single (only one candidate possible)
 * 2. Hidden single (value can only go in one place in a group)
 * Falls back to solution if no logical deduction found.
 *
 * When `selectedCell` is provided and it has a naked single, it wins
 * over any other naked single elsewhere on the board.
 */
export declare function findHint(board: Board, solution: string, selectedCell?: Position | null): HintExplanation | null;
