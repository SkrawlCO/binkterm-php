import { findHint } from "./hint-engine.ts";
import {
  cellKey,
  getConflicts,
  getErrors,
  isBoardComplete,
  parsePuzzle,
} from "./sudoku.ts";
import type {
  ActiveHint,
  Board,
  Cell,
  ClearedNote,
  GameStatus,
  MoveAction,
  Position,
} from "./types.ts";

/**
 * The Sudoku board engine: a pure reducer that owns the playable
 * board state (cells, history, selection, notes mode, hints) for a
 * single game. React hooks bind to this via `useReducer`.
 *
 * The engine's interface — `State`, `Action`, `reducer`, `initState`
 * — is the test surface. Tests exercise behaviour through actions and
 * assert on observable state; they don't reach into internal helpers.
 */

// Cap the undo log. A long game with many pencil-mark taps would
// otherwise grow this unboundedly — each MoveAction carries previous
// cell state and cleared notes (~hundreds of bytes), which adds up on
// memory-constrained mobile browsers. 100 is well past any realistic
// undo depth a player would actually use.
const MAX_HISTORY = 100;

function pushHistory(history: MoveAction[], action: MoveAction): MoveAction[] {
  if (history.length < MAX_HISTORY) return [...history, action];
  return [...history.slice(history.length - MAX_HISTORY + 1), action];
}

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

export type Action =
  | { type: "SELECT_CELL"; row: number; col: number }
  | { type: "DESELECT_CELL" }
  | { type: "SET_SELECTED_CELLS"; cells: Set<number>; primary: Position }
  | {
      type: "PLACE_NUMBER";
      value: number;
      autoEliminateNotes: boolean;
      asNote?: boolean | undefined;
    }
  | { type: "PLACE_NOTE_AT"; row: number; col: number; value: number }
  | { type: "ERASE" }
  | { type: "UNDO" }
  | { type: "HINT" }
  | { type: "DISMISS_HINT" }
  | { type: "TOGGLE_NOTES" }
  | {
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

/**
 * Copy-on-write view over a board. Rows and cells are cloned only when
 * `edit` touches them, so every untouched Cell object keeps its
 * identity across a mutation — that identity is what lets memo(Cell)
 * actually skip the other ~80 cells on a keystroke (a full clone
 * defeated the memo entirely).
 */
type BoardEditor = {
  board: Board;
  /** Read a cell without cloning anything. */
  peek: (row: number, col: number) => Cell;
  /** Clone row + cell on first touch, then return the writable cell. */
  edit: (row: number, col: number) => Cell;
};

function editBoard(source: Board): BoardEditor {
  const board: Board = source.map((row) => row);
  const clonedRows = new Set<number>();
  const clonedCells = new Set<number>();
  return {
    board,
    peek: (row, col) => board[row]![col]!,
    edit: (row, col) => {
      if (!clonedRows.has(row)) {
        board[row] = [...board[row]!];
        clonedRows.add(row);
      }
      const key = row * 9 + col;
      if (!clonedCells.has(key)) {
        const prev = board[row]![col]!;
        board[row]![col] = { ...prev, notes: new Set(prev.notes) };
        clonedCells.add(key);
      }
      return board[row]![col]!;
    },
  };
}

function clearPeerNotes(
  editor: BoardEditor,
  row: number,
  col: number,
  value: number,
): ClearedNote[] {
  const cleared: ClearedNote[] = [];
  const boxRow = Math.floor(row / 3) * 3;
  const boxCol = Math.floor(col / 3) * 3;
  for (let i = 0; i < 9; i++) {
    if (i !== col && editor.peek(row, i).notes.has(value)) {
      editor.edit(row, i).notes.delete(value);
      cleared.push({ row, col: i, note: value });
    }
    if (i !== row && editor.peek(i, col).notes.has(value)) {
      editor.edit(i, col).notes.delete(value);
      cleared.push({ row: i, col, note: value });
    }
  }
  for (let r = boxRow; r < boxRow + 3; r++) {
    for (let c = boxCol; c < boxCol + 3; c++) {
      if (r !== row && c !== col && editor.peek(r, c).notes.has(value)) {
        editor.edit(r, c).notes.delete(value);
        cleared.push({ row: r, col: c, note: value });
      }
    }
  }
  return cleared;
}

function handlePlaceNumber(
  state: State,
  value: number,
  autoEliminateNotes: boolean,
  asNote?: boolean,
): State {
  if (!state.selectedCell || state.status === "completed") return state;
  const { row, col } = state.selectedCell;
  const cell = state.board[row]![col]!;
  const noteMode = asNote ?? state.notesMode;

  // Multi-cell batch note toggle
  if (noteMode && state.selectedCells.size > 1) {
    const editor = editBoard(state.board);
    const targets: Position[] = [];
    for (const key of state.selectedCells) {
      const r = Math.floor(key / 9);
      const c = key % 9;
      const target = editor.peek(r, c);
      if (!target.isGiven && target.value === null) {
        targets.push({ row: r, col: c });
      }
    }
    if (targets.length === 0) return state;

    // If all targets have the note, remove it; otherwise add it
    const allHave = targets.every((p) =>
      editor.peek(p.row, p.col).notes.has(value),
    );
    const added: Position[] = [];
    const removed: Position[] = [];
    for (const pos of targets) {
      if (allHave) {
        editor.edit(pos.row, pos.col).notes.delete(value);
        removed.push(pos);
      } else if (!editor.peek(pos.row, pos.col).notes.has(value)) {
        editor.edit(pos.row, pos.col).notes.add(value);
        added.push(pos);
      }
    }
    if (added.length === 0 && removed.length === 0) return state;
    const moveAction: MoveAction = {
      type: "batchToggleNote",
      note: value,
      added,
      removed,
    };
    return {
      ...state,
      board: editor.board,
      history: pushHistory(state.history, moveAction),
    };
  }

  if (cell.isGiven) return state;

  if (noteMode) {
    const editor = editBoard(state.board);
    const notes = editor.edit(row, col).notes;
    const moveAction: MoveAction = {
      type: "toggleNote",
      position: { row, col },
      note: value,
    };
    if (notes.has(value)) {
      notes.delete(value);
    } else {
      notes.add(value);
    }
    return {
      ...state,
      board: editor.board,
      history: pushHistory(state.history, moveAction),
    };
  }

  // A filled cell can't be overwritten by placing a number — the
  // player must erase it first. Guards a committed digit against an
  // accidental numpad tap or keyboard press silently clobbering it.
  if (cell.value !== null) return state;

  const editor = editBoard(state.board);
  const target = editor.edit(row, col);
  target.value = value;
  target.notes = new Set();
  const clearedNotes = autoEliminateNotes
    ? clearPeerNotes(editor, row, col, value)
    : [];
  const board = editor.board;
  const moveAction: MoveAction = {
    type: "place",
    position: { row, col },
    value,
    previousValue: cell.value,
    previousNotes: new Set(cell.notes),
    clearedNotes,
  };
  const conflicts = getConflicts(board);
  const complete = isBoardComplete(board, conflicts);

  return {
    ...state,
    board,
    status: complete ? "completed" : state.status,
    history: pushHistory(state.history, moveAction),
  };
}

/**
 * Toggle a note at an explicit cell, independent of the current
 * selection. Used by the digit drag-and-drop layer: a note dropped on
 * a cell must land there without the cell becoming selected, so the
 * board highlight stays on whatever the player was working with.
 */
function handlePlaceNoteAt(
  state: State,
  row: number,
  col: number,
  value: number,
): State {
  if (state.status === "completed") return state;
  const cell = state.board[row]?.[col];
  if (!cell || cell.isGiven || cell.value !== null) return state;

  const editor = editBoard(state.board);
  const notes = editor.edit(row, col).notes;
  const moveAction: MoveAction = {
    type: "toggleNote",
    position: { row, col },
    note: value,
  };
  if (notes.has(value)) {
    notes.delete(value);
  } else {
    notes.add(value);
  }
  return {
    ...state,
    board: editor.board,
    history: pushHistory(state.history, moveAction),
  };
}

function handleErase(state: State): State {
  if (!state.selectedCell || state.status === "completed") return state;

  // Multi-cell batch erase
  if (state.selectedCells.size > 1) {
    const editor = editBoard(state.board);
    const erased: {
      position: Position;
      previousValue: import("./types.ts").CellValue;
      previousNotes: Set<number>;
    }[] = [];
    for (const key of state.selectedCells) {
      const r = Math.floor(key / 9);
      const c = key % 9;
      const peeked = editor.peek(r, c);
      if (!peeked.isGiven && (peeked.value !== null || peeked.notes.size > 0)) {
        erased.push({
          position: { row: r, col: c },
          previousValue: peeked.value,
          previousNotes: new Set(peeked.notes),
        });
        const target = editor.edit(r, c);
        target.value = null;
        target.notes = new Set();
      }
    }
    if (erased.length === 0) return state;
    const moveAction: MoveAction = {
      type: "batchErase",
      cells: erased,
    };
    return {
      ...state,
      board: editor.board,
      history: pushHistory(state.history, moveAction),
    };
  }

  const { row, col } = state.selectedCell;
  const cell = state.board[row]![col]!;
  if (cell.isGiven) return state;

  const editor = editBoard(state.board);
  const moveAction: MoveAction = {
    type: "erase",
    position: { row, col },
    previousValue: cell.value,
    previousNotes: new Set(cell.notes),
  };
  const target = editor.edit(row, col);
  target.value = null;
  target.notes = new Set();

  return {
    ...state,
    board: editor.board,
    history: pushHistory(state.history, moveAction),
  };
}

function handleUndo(state: State): State {
  if (state.history.length === 0 || state.status === "completed") return state;
  const history = state.history.slice(0, -1);
  const lastAction = state.history[state.history.length - 1]!;
  const editor = editBoard(state.board);

  switch (lastAction.type) {
    case "place": {
      const { row, col } = lastAction.position;
      const target = editor.edit(row, col);
      target.value = lastAction.previousValue;
      target.notes = new Set(lastAction.previousNotes);
      for (const cleared of lastAction.clearedNotes) {
        editor.edit(cleared.row, cleared.col).notes.add(cleared.note);
      }
      break;
    }
    case "erase": {
      const { row, col } = lastAction.position;
      const target = editor.edit(row, col);
      target.value = lastAction.previousValue;
      target.notes = new Set(lastAction.previousNotes);
      break;
    }
    case "toggleNote": {
      const { row, col } = lastAction.position;
      const notes = editor.edit(row, col).notes;
      if (notes.has(lastAction.note)) {
        notes.delete(lastAction.note);
      } else {
        notes.add(lastAction.note);
      }
      break;
    }
    case "batchToggleNote": {
      for (const pos of lastAction.added) {
        editor.edit(pos.row, pos.col).notes.delete(lastAction.note);
      }
      for (const pos of lastAction.removed) {
        editor.edit(pos.row, pos.col).notes.add(lastAction.note);
      }
      break;
    }
    case "batchErase": {
      for (const entry of lastAction.cells) {
        const { row, col } = entry.position;
        const target = editor.edit(row, col);
        target.value = entry.previousValue;
        target.notes = new Set(entry.previousNotes);
      }
      break;
    }
  }

  return {
    ...state,
    board: editor.board,
    history,
  };
}

// A hint never writes to the board. It selects the deduced cell and
// surfaces the explanation so the player enters the value themselves —
// no value placed, no peer notes cleared, nothing pushed to history.
function handleHint(state: State): State {
  if (!state.solution || state.status === "completed") return state;

  const hint = findHint(state.board, state.solution, state.selectedCell);
  if (!hint) return state;

  const { row, col } = hint.position;

  return {
    ...state,
    selectedCell: hint.position,
    selectedCells: new Set([cellKey(row, col)]),
    activeHint: hint,
    hintsUsed: state.hintsUsed + 1,
  };
}

function dispatchAction(state: State, action: Action): State {
  switch (action.type) {
    case "SELECT_CELL": {
      const key = cellKey(action.row, action.col);
      return {
        ...state,
        selectedCell: { row: action.row, col: action.col },
        selectedCells: new Set([key]),
      };
    }

    case "DESELECT_CELL":
      return {
        ...state,
        selectedCell: null,
        selectedCells: new Set(),
      };

    case "SET_SELECTED_CELLS":
      return {
        ...state,
        selectedCell: action.primary,
        selectedCells: action.cells,
      };

    case "PLACE_NUMBER":
      return handlePlaceNumber(
        state,
        action.value,
        action.autoEliminateNotes,
        action.asNote,
      );

    case "PLACE_NOTE_AT":
      return handlePlaceNoteAt(state, action.row, action.col, action.value);

    case "ERASE":
      return handleErase(state);

    case "UNDO":
      return handleUndo(state);

    case "TOGGLE_NOTES":
      return { ...state, notesMode: !state.notesMode };

    case "DISMISS_HINT":
      return state;

    case "HINT":
      return handleHint(state);

    case "RESET":
      return initState({
        puzzle: action.puzzle,
        solution: action.solution,
        savedBoard: action.savedBoard,
      });

    default:
      return state;
  }
}

// activeHint is cleared after any action except the two that own it:
// HINT installs a new hint, TOGGLE_NOTES is unrelated and can be
// toggled while a hint banner is showing.
export function reducer(state: State, action: Action): State {
  const next = dispatchAction(state, action);
  if (action.type === "HINT" || action.type === "TOGGLE_NOTES") return next;
  if (next.activeHint === null) return next;
  return { ...next, activeHint: null };
}

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
export function projectBoard(
  board: Board,
  solution?: string | null,
): BoardProjection {
  const conflicts = getConflicts(board);
  const errors = solution ? getErrors(board, solution) : new Set<number>();
  const remainingCounts: Record<number, number> = {};
  for (let d = 1; d <= 9; d++) remainingCounts[d] = 9;
  let cellsRemaining = 0;
  for (const row of board) {
    for (const cell of row) {
      if (cell.value !== null && cell.value >= 1 && cell.value <= 9) {
        remainingCounts[cell.value]!--;
      } else {
        cellsRemaining++;
      }
    }
  }
  return { conflicts, errors, remainingCounts, cellsRemaining };
}

/**
 * Inverse of the `savedBoard` half of {@link initState}: project a live
 * Board back into the `SavedBoard` schema used by autosave. Pairing the
 * two keeps the `Board ↔ SavedBoard` round-trip owned by one module.
 */
export function serializeBoard(board: Board): SavedBoard {
  const values = board
    .flatMap((row) =>
      row.map((c) => (c.value === null ? "." : String(c.value))),
    )
    .join("");
  const notes = board.flatMap((row) => row.map((c) => Array.from(c.notes)));
  return { values, notes };
}

export function initState(args: {
  puzzle: string;
  solution?: string | undefined;
  savedBoard?: SavedBoard | undefined;
}): State {
  const board = parsePuzzle(args.puzzle);
  if (args.savedBoard) {
    for (let row = 0; row < 9; row++) {
      for (let col = 0; col < 9; col++) {
        const cell = board[row]![col]!;
        if (!cell.isGiven) {
          const i = row * 9 + col;
          const ch = args.savedBoard.values[i];
          cell.value = ch === "." ? null : Number(ch);
          cell.notes = new Set(args.savedBoard.notes[i] ?? []);
        }
      }
    }
  }
  return {
    board,
    solution: args.solution ?? null,
    status: "playing",
    selectedCell: null,
    selectedCells: new Set(),
    notesMode: false,
    history: [],
    hintsUsed: args.savedBoard?.hintsUsed ?? 0,
    activeHint: null,
  };
}
