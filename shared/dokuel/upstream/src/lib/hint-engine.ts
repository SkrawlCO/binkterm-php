import { getErrors } from "./sudoku.ts";
import { findTechniqueHint } from "./technique-hint.ts";
import type { ActiveHint, Board, Position } from "./types.ts";

// Alias, not a parallel definition: board-engine stores findHint's
// result in an ActiveHint field, and two structurally-identical types
// only stay identical by luck.
export type HintExplanation = ActiveHint;

function peersOf(row: number, col: number): Position[] {
  const peers: Position[] = [];
  const seen = new Set<number>();

  const add = (r: number, c: number) => {
    if (r === row && c === col) return;
    const key = r * 9 + c;
    if (seen.has(key)) return;
    seen.add(key);
    peers.push({ row: r, col: c });
  };

  for (let c = 0; c < 9; c++) add(row, c);
  for (let r = 0; r < 9; r++) add(r, col);

  const boxRow = Math.floor(row / 3) * 3;
  const boxCol = Math.floor(col / 3) * 3;
  for (let r = boxRow; r < boxRow + 3; r++) {
    for (let c = boxCol; c < boxCol + 3; c++) {
      add(r, c);
    }
  }

  return peers;
}

function candidatesAt(board: Board, row: number, col: number): Set<number> {
  const used = new Set<number>();
  for (const { row: r, col: c } of peersOf(row, col)) {
    const v = board[r]![c]!.value;
    if (v !== null) used.add(v);
  }
  const candidates = new Set<number>();
  for (let d = 1; d <= 9; d++) {
    if (!used.has(d)) candidates.add(d);
  }
  return candidates;
}

function eliminatingCells(board: Board, row: number, col: number): Position[] {
  return peersOf(row, col).filter(
    ({ row: r, col: c }) => board[r]![c]!.value !== null,
  );
}

// Shared between the selected-cell preference branch and the board sweep
// so the two paths can't drift on explanation text or related-cell logic.
function nakedSingleAt(
  board: Board,
  row: number,
  col: number,
): HintExplanation | null {
  if (board[row]![col]!.value !== null) return null;
  const candidates = candidatesAt(board, row, col);
  if (candidates.size !== 1) return null;
  const value = [...candidates][0]!;
  return {
    position: { row, col },
    value,
    technique: "naked-single",
    explanation: `This cell can only be ${value}. All other digits (1-9) already appear in its row, column, or box.`,
    relatedCells: eliminatingCells(board, row, col),
  };
}

function findNakedSingle(board: Board): HintExplanation | null {
  for (let row = 0; row < 9; row++) {
    for (let col = 0; col < 9; col++) {
      const hint = nakedSingleAt(board, row, col);
      if (hint) return hint;
    }
  }
  return null;
}

function groupName(type: "row" | "col" | "box", index: number): string {
  if (type === "row") return `row ${index + 1}`;
  if (type === "col") return `column ${index + 1}`;
  return `box ${index + 1}`;
}

function findEliminatorsForDigit(
  board: Board,
  row: number,
  col: number,
  digit: number,
  excludeGroupType: "row" | "col" | "box",
  excludeGroupIndex: number,
): Position[] {
  const eliminators: Position[] = [];
  if (excludeGroupType !== "row" || excludeGroupIndex !== row) {
    for (let c = 0; c < 9; c++) {
      if (c !== col && board[row]![c]!.value === digit) {
        eliminators.push({ row, col: c });
      }
    }
  }
  if (excludeGroupType !== "col" || excludeGroupIndex !== col) {
    for (let r = 0; r < 9; r++) {
      if (r !== row && board[r]![col]!.value === digit) {
        eliminators.push({ row: r, col });
      }
    }
  }
  const boxIndex = Math.floor(row / 3) * 3 + Math.floor(col / 3);
  if (excludeGroupType !== "box" || excludeGroupIndex !== boxIndex) {
    const boxRow = Math.floor(row / 3) * 3;
    const boxCol = Math.floor(col / 3) * 3;
    for (let r = boxRow; r < boxRow + 3; r++) {
      for (let c = boxCol; c < boxCol + 3; c++) {
        if ((r !== row || c !== col) && board[r]![c]!.value === digit) {
          eliminators.push({ row: r, col: c });
        }
      }
    }
  }
  return eliminators;
}

function cellsOfGroup(type: "row" | "col" | "box", index: number): Position[] {
  const cells: Position[] = [];
  if (type === "row") {
    for (let c = 0; c < 9; c++) cells.push({ row: index, col: c });
  } else if (type === "col") {
    for (let r = 0; r < 9; r++) cells.push({ row: r, col: index });
  } else {
    const boxRow = Math.floor(index / 3) * 3;
    const boxCol = (index % 3) * 3;
    for (let r = boxRow; r < boxRow + 3; r++) {
      for (let c = boxCol; c < boxCol + 3; c++) cells.push({ row: r, col: c });
    }
  }
  return cells;
}

function findHiddenSingleInGroup(
  board: Board,
  type: "row" | "col" | "box",
  index: number,
): HintExplanation | null {
  const groupCells = cellsOfGroup(type, index);
  const emptyCells = groupCells
    .filter(({ row, col }) => board[row]![col]!.value === null)
    .map(({ row, col }) => ({
      row,
      col,
      candidates: candidatesAt(board, row, col),
    }));

  for (let d = 1; d <= 9; d++) {
    const possibleCells = emptyCells.filter((c) => c.candidates.has(d));
    if (possibleCells.length === 1) {
      const cell = possibleCells[0]!;
      if (cell.candidates.size === 1) continue;

      const name = groupName(type, index);
      const related: Position[] = groupCells.filter(
        ({ row, col }) =>
          (row !== cell.row || col !== cell.col) &&
          board[row]![col]!.value !== null,
      );

      for (const other of emptyCells) {
        if (other === cell) continue;
        if (!other.candidates.has(d)) {
          const eliminators = findEliminatorsForDigit(
            board,
            other.row,
            other.col,
            d,
            type,
            index,
          );
          for (const e of eliminators) {
            related.push(e);
          }
        }
      }

      return {
        position: { row: cell.row, col: cell.col },
        value: d,
        technique: "hidden-single",
        explanation: `In ${name}, ${d} can only go here. The other empty cells in this ${type === "box" ? "box" : type} can't contain ${d} because of conflicts in their rows, columns, or boxes.`,
        relatedCells: related,
      };
    }
  }
  return null;
}

function findHiddenSingle(board: Board): HintExplanation | null {
  for (let row = 0; row < 9; row++) {
    const result = findHiddenSingleInGroup(board, "row", row);
    if (result) return result;
  }
  for (let col = 0; col < 9; col++) {
    const result = findHiddenSingleInGroup(board, "col", col);
    if (result) return result;
  }
  for (let box = 0; box < 9; box++) {
    const result = findHiddenSingleInGroup(board, "box", box);
    if (result) return result;
  }
  return null;
}

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
export function findHint(
  board: Board,
  solution: string,
  selectedCell?: Position | null,
): HintExplanation | null {
  // A wrong entry poisons every deduction below it: singles derived
  // from a false premise recommend provably wrong digits with full
  // confidence. Surface the mistake first — on a mistake-free board,
  // every single the scans find necessarily matches the solution.
  const errors = getErrors(board, solution);
  if (errors.size > 0) {
    const key = Math.min(...errors);
    const row = Math.floor(key / 9);
    const col = key % 9;
    const wrongValue = board[row]![col]!.value;
    return {
      position: { row, col },
      value: Number(solution[key]),
      technique: "mistake",
      explanation: `This cell holds ${wrongValue}, but that can't be right — it makes the rest of the puzzle unsolvable. Clear it, then re-check its row, column, and box.`,
      relatedCells: [],
    };
  }

  if (selectedCell) {
    const preferred = nakedSingleAt(board, selectedCell.row, selectedCell.col);
    if (preferred) return preferred;
  }

  const nakedSingle = findNakedSingle(board);
  if (nakedSingle) return nakedSingle;

  const hiddenSingle = findHiddenSingle(board);
  if (hiddenSingle) return hiddenSingle;

  // No single anywhere — the state graded boards put players in. Look
  // for the elimination (locked candidates, pairs, triples, X-wing)
  // whose removals make the next placement visible, and teach that.
  const techniqueHint = findTechniqueHint(board);
  if (techniqueHint) return techniqueHint;

  // Fallback: use solution to find the target cell and explain what's possible
  let targetRow = -1;
  let targetCol = -1;
  if (
    selectedCell &&
    board[selectedCell.row]![selectedCell.col]!.value === null
  ) {
    targetRow = selectedCell.row;
    targetCol = selectedCell.col;
  } else {
    for (let r = 0; r < 9 && targetRow === -1; r++) {
      for (let c = 0; c < 9; c++) {
        if (board[r]![c]!.value === null) {
          targetRow = r;
          targetCol = c;
          break;
        }
      }
    }
  }
  if (targetRow === -1) return null;

  const value = Number(solution[targetRow * 9 + targetCol]);
  const candidates = candidatesAt(board, targetRow, targetCol);

  return {
    position: { row: targetRow, col: targetCol },
    value,
    technique: "reveal",
    explanation:
      candidates.size <= 3
        ? `No single, pair, triple, or X-wing decides a cell right now — this board needs chain logic. This cell can be ${[...candidates].sort().join(", ")}; the answer is ${value}, and placing it will open the board back up.`
        : `No single, pair, triple, or X-wing decides a cell right now — this board needs chain logic. This cell still has ${candidates.size} candidates; the answer is ${value}, and placing it will open the board back up.`,
    relatedCells: eliminatingCells(board, targetRow, targetCol),
  };
}
