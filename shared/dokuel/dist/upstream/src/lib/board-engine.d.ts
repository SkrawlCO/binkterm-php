import type { ActiveHint, Board, GameStatus, MoveAction, Position } from "./types.ts";
export type State = {
    board: Board;
    solution: string | null;
    status: GameStatus;
    selectedCell: Position | null;
    selectedCells: Set<number>;
    notesMode: boolean;
    history: MoveAction[];
    hintsUsed: number;
    activeHint: ActiveHint | null;
};
export type Action = {
    type: "SELECT_CELL";
    row: number;
    col: number;
} | {
    type: "DESELECT_CELL";
} | {
    type: "SET_SELECTED_CELLS";
    cells: Set<number>;
    primary: Position;
} | {
    type: "PLACE_NUMBER";
    value: number;
    autoEliminateNotes: boolean;
    asNote?: boolean | undefined;
} | {
    type: "PLACE_NOTE_AT";
    row: number;
    col: number;
    value: number;
} | {
    type: "ERASE";
} | {
    type: "UNDO";
} | {
    type: "HINT";
} | {
    type: "DISMISS_HINT";
} | {
    type: "TOGGLE_NOTES";
} | {
    type: "RESET";
    puzzle: string;
    solution?: string | undefined;
    savedBoard?: SavedBoard | undefined;
};
export type SavedBoard = {
    values: string;
    notes: number[][];
    hintsUsed?: number | undefined;
};
export declare function reducer(state: State, action: Action): State;
export type BoardProjection = {
    /** Cell keys (row*9+col) that participate in a row/col/box conflict. */
    conflicts: Set<number>;
    /** Cell keys whose user-entered value differs from the solution. Empty when no solution is supplied. */
    errors: Set<number>;
    /** How many times each digit 1–9 still needs to be placed. */
    remainingCounts: Record<number, number>;
    /** Empty cell count (0–81). */
    cellsRemaining: number;
};
/**
 * The single Board → read-only view function. Both React (useSudoku)
 * and non-React callers (multiplayer progress reporting, future
 * analytics) project a Board through this one seam so the derivation
 * rules can't drift.
 */
export declare function projectBoard(board: Board, solution?: string | null): BoardProjection;
/**
 * Inverse of the `savedBoard` half of {@link initState}: project a live
 * Board back into the `SavedBoard` schema used by autosave. Pairing the
 * two keeps the `Board ↔ SavedBoard` round-trip owned by one module.
 */
export declare function serializeBoard(board: Board): SavedBoard;
export declare function initState(args: {
    puzzle: string;
    solution?: string | undefined;
    savedBoard?: SavedBoard | undefined;
}): State;
