/**
 * Candidate-mask board state shared by the grader and the hint engine.
 * Tracks, per empty cell, which digits its filled peers still allow —
 * the working substrate every human solving technique reads and writes.
 */

import { ALL_DIGITS, PEERS, popcount, UNITS } from "./board-geometry.ts";

export type CandidateState = {
  grid: Uint8Array;
  cand: Uint16Array;
  empty: number;
};

/** Build state from an 81-char board; null when malformed or a cell
 * has no candidate left (an unsolvable position). */
export function initCandidates(puzzle: string): CandidateState | null {
  if (!/^[1-9.]{81}$/.test(puzzle)) return null;
  const grid = new Uint8Array(81);
  for (let i = 0; i < 81; i++) {
    const code = puzzle.charCodeAt(i);
    grid[i] = code === 46 ? 0 : code - 48;
  }
  const cand = new Uint16Array(81);
  let empty = 0;
  for (let i = 0; i < 81; i++) {
    if (grid[i] !== 0) continue;
    empty++;
    let mask = ALL_DIGITS;
    for (const p of PEERS[i]!) {
      const v = grid[p]!;
      if (v) mask &= ~(1 << v);
    }
    if (mask === 0) return null;
    cand[i] = mask;
  }
  return { grid, cand, empty };
}

export function cloneCandidates(s: CandidateState): CandidateState {
  return {
    grid: new Uint8Array(s.grid),
    cand: new Uint16Array(s.cand),
    empty: s.empty,
  };
}

export function place(s: CandidateState, cell: number, value: number): void {
  s.grid[cell] = value;
  s.cand[cell] = 0;
  s.empty--;
  const bit = 1 << value;
  for (const p of PEERS[cell]!) {
    s.cand[p]! &= ~bit;
  }
}

export type EliminationKind =
  | "pointing"
  | "claiming"
  | "naked-pair"
  | "hidden-pair"
  | "naked-triple"
  | "hidden-triple"
  | "naked-quad"
  | "hidden-quad"
  | "x-wing"
  | "xy-wing"
  | "swordfish";

/** One applied technique: the pattern, its digits, and what it removed. */
export type Elimination = {
  kind: EliminationKind;
  /** Digits the pattern locks (a single digit except for sets). */
  digits: number[];
  /** Cells forming the pattern — what a hint should highlight. */
  patternCells: number[];
  /** Candidates the pattern removes elsewhere. */
  removed: { cell: number; digit: number }[];
  /**
   * XY-wing only: each pattern cell's candidate pair, aligned with
   * patternCells ([pivot, pincer, pincer]) so a hint can name the
   * roles — the pivot never holds the eliminated digit, so without
   * these the pattern is unreadable from the highlight alone.
   */
  patternDigits?: number[][];
};

export function eliminate(
  s: CandidateState,
  cell: number,
  bits: number,
  digitsOf: number[],
  removed: { cell: number; digit: number }[],
): boolean {
  const hit = s.cand[cell]! & bits;
  if (!hit) return false;
  s.cand[cell]! &= ~hit;
  for (const digit of digitsOf) {
    if (hit & (1 << digit)) removed.push({ cell, digit });
  }
  return true;
}

export type SingleFind =
  | { kind: "naked"; cell: number; digit: number }
  | { kind: "hidden"; cell: number; digit: number; unitIndex: number };

/**
 * First placeable single: naked singles in cell order, then hidden
 * singles in unit order (rows, columns, boxes) — the same scan order
 * the grader has always used, so grades cannot drift.
 */
export function findSingle(s: CandidateState): SingleFind | null {
  for (let i = 0; i < 81; i++) {
    if (s.grid[i] === 0 && popcount(s.cand[i]!) === 1) {
      return { kind: "naked", cell: i, digit: 31 - Math.clz32(s.cand[i]!) };
    }
  }
  for (let unitIndex = 0; unitIndex < UNITS.length; unitIndex++) {
    const unit = UNITS[unitIndex]!;
    for (let v = 1; v <= 9; v++) {
      const bit = 1 << v;
      let count = 0;
      let where = -1;
      let placed = false;
      for (const cell of unit) {
        if (s.grid[cell] === v) {
          placed = true;
          break;
        }
        if (s.cand[cell]! & bit) {
          count++;
          where = cell;
        }
      }
      if (placed || count !== 1) continue;
      // The naked scan above already caught one-candidate cells, so
      // this placement is progress only singles-in-a-unit can see.
      return { kind: "hidden", cell: where, digit: v, unitIndex };
    }
  }
  return null;
}
