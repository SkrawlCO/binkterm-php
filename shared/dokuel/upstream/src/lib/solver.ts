/**
 * In-house sudoku solver and generator primitives. Pure functions, no
 * dependencies, all randomness injected via an Rng so callers (e.g. the
 * daily challenge) can make generation fully deterministic.
 *
 * Board representation: an 81-char string, digits 1-9 and "." for empty,
 * row-major — the same wire format used across the app and the Yjs doc.
 */

export type Rng = () => number;

// Bits 1..9 mark digits already used in a row/col/box.
const ALL_DIGITS = 0b1111111110;

type Masks = {
  rows: Uint16Array;
  cols: Uint16Array;
  boxes: Uint16Array;
};

function boxIndex(cell: number): number {
  return Math.floor(cell / 27) * 3 + Math.floor((cell % 9) / 3);
}

function toGrid(puzzle: string): Uint8Array | null {
  if (!/^[1-9.]{81}$/.test(puzzle)) return null;
  const grid = new Uint8Array(81);
  for (let i = 0; i < 81; i++) {
    const code = puzzle.charCodeAt(i);
    grid[i] = code === 46 ? 0 : code - 48;
  }
  return grid;
}

function toPuzzleString(grid: Uint8Array): string {
  let out = "";
  for (let i = 0; i < 81; i++) {
    const v = grid[i]!;
    out += v === 0 ? "." : String(v);
  }
  return out;
}

/** Build row/col/box usage masks; null when two givens already conflict. */
function buildMasks(grid: Uint8Array): Masks | null {
  const rows = new Uint16Array(9);
  const cols = new Uint16Array(9);
  const boxes = new Uint16Array(9);
  for (let i = 0; i < 81; i++) {
    const v = grid[i]!;
    if (v === 0) continue;
    const bit = 1 << v;
    const r = Math.floor(i / 9);
    const c = i % 9;
    const b = boxIndex(i);
    if ((rows[r]! | cols[c]! | boxes[b]!) & bit) return null;
    rows[r]! |= bit;
    cols[c]! |= bit;
    boxes[b]! |= bit;
  }
  return { rows, cols, boxes };
}

function popcount(mask: number): number {
  let n = 0;
  let m = mask;
  while (m) {
    m &= m - 1;
    n++;
  }
  return n;
}

/**
 * Depth-first search counting solutions up to `cap`. Picks the empty
 * cell with the fewest candidates (MRV) so ambiguity checks on sparse
 * boards stay fast. Writes the first solution found into `out`.
 */
function search(
  grid: Uint8Array,
  masks: Masks,
  cap: number,
  found: number,
  out: Uint8Array | null,
): number {
  let best = -1;
  let bestMask = 0;
  let bestCount = 10;
  for (let i = 0; i < 81; i++) {
    if (grid[i] !== 0) continue;
    const avail =
      ALL_DIGITS &
      ~(
        masks.rows[Math.floor(i / 9)]! |
        masks.cols[i % 9]! |
        masks.boxes[boxIndex(i)]!
      );
    if (avail === 0) return found;
    const count = popcount(avail);
    if (count < bestCount) {
      bestCount = count;
      best = i;
      bestMask = avail;
      if (count === 1) break;
    }
  }

  if (best === -1) {
    if (found === 0 && out) out.set(grid);
    return found + 1;
  }

  const r = Math.floor(best / 9);
  const c = best % 9;
  const b = boxIndex(best);
  let total = found;
  for (let v = 1; v <= 9; v++) {
    const bit = 1 << v;
    if (!(bestMask & bit)) continue;
    grid[best] = v;
    masks.rows[r]! |= bit;
    masks.cols[c]! |= bit;
    masks.boxes[b]! |= bit;
    total = search(grid, masks, cap, total, out);
    grid[best] = 0;
    masks.rows[r]! &= ~bit;
    masks.cols[c]! &= ~bit;
    masks.boxes[b]! &= ~bit;
    if (total >= cap) return total;
  }
  return total;
}

function countSolutionsGrid(grid: Uint8Array, cap: number): number {
  const masks = buildMasks(grid);
  if (!masks) return 0;
  return search(grid, masks, cap, 0, null);
}

/**
 * Count the puzzle's solutions, stopping at `cap` (default 2 — enough
 * to distinguish unsolvable / unique / ambiguous). Malformed input
 * counts as 0.
 */
export function countSolutions(puzzle: string, cap = 2): number {
  const grid = toGrid(puzzle);
  if (!grid) return 0;
  return countSolutionsGrid(grid, cap);
}

/**
 * Solve a puzzle. Returns the first solution found, or null when the
 * input is malformed or unsolvable. When the puzzle is ambiguous the
 * returned solution is one of several — callers that care must check
 * countSolutions first (the generator guarantees uniqueness instead).
 */
export function solve(puzzle: string): string | null {
  const grid = toGrid(puzzle);
  if (!grid) return null;
  const masks = buildMasks(grid);
  if (!masks) return null;
  const out = new Uint8Array(81);
  const found = search(grid, masks, 1, 0, out);
  if (found === 0) return null;
  return toPuzzleString(out);
}

function shuffle<T>(items: T[], rng: Rng): T[] {
  for (let i = items.length - 1; i > 0; i--) {
    const j = Math.floor(rng() * (i + 1));
    const tmp = items[i]!;
    items[i] = items[j]!;
    items[j] = tmp;
  }
  return items;
}

function fillGrid(
  grid: Uint8Array,
  masks: Masks,
  cell: number,
  rng: Rng,
): boolean {
  if (cell === 81) return true;
  const r = Math.floor(cell / 9);
  const c = cell % 9;
  const b = boxIndex(cell);
  const avail =
    ALL_DIGITS & ~(masks.rows[r]! | masks.cols[c]! | masks.boxes[b]!);
  if (avail === 0) return false;
  const digits: number[] = [];
  for (let v = 1; v <= 9; v++) {
    if (avail & (1 << v)) digits.push(v);
  }
  shuffle(digits, rng);
  for (const v of digits) {
    const bit = 1 << v;
    grid[cell] = v;
    masks.rows[r]! |= bit;
    masks.cols[c]! |= bit;
    masks.boxes[b]! |= bit;
    if (fillGrid(grid, masks, cell + 1, rng)) return true;
    grid[cell] = 0;
    masks.rows[r]! &= ~bit;
    masks.cols[c]! &= ~bit;
    masks.boxes[b]! &= ~bit;
  }
  return false;
}

/** Generate a complete valid grid, cell by cell with rng-shuffled digits. */
export function generateSolvedGrid(rng: Rng = Math.random): string {
  const grid = new Uint8Array(81);
  const masks: Masks = {
    rows: new Uint16Array(9),
    cols: new Uint16Array(9),
    boxes: new Uint16Array(9),
  };
  // A full backtracking fill from an empty grid always succeeds.
  fillGrid(grid, masks, 0, rng);
  return toPuzzleString(grid);
}

/**
 * Remove clues from a solved grid in rng-shuffled order, keeping the
 * puzzle uniquely solvable after every removal. Stops once targetClues
 * is reached, or when no further clue can be removed without creating
 * a second solution (a minimal puzzle). The result may therefore hold
 * more clues than requested — 17 is the theoretical floor and random
 * digging exhausts well above it.
 */
export function digPuzzle(
  solved: string,
  targetClues: number,
  rng: Rng = Math.random,
): string {
  const grid = toGrid(solved);
  if (!grid) throw new Error("digPuzzle requires a valid 81-char grid");

  const positions = shuffle(
    Array.from({ length: 81 }, (_, i) => i),
    rng,
  );
  let clues = 81;
  for (const pos of positions) {
    if (clues <= targetClues) break;
    const value = grid[pos]!;
    grid[pos] = 0;
    if (countSolutionsGrid(grid, 2) === 1) {
      clues--;
    } else {
      grid[pos] = value;
    }
  }
  return toPuzzleString(grid);
}
