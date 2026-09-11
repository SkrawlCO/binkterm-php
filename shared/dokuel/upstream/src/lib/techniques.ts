/**
 * Unit-local candidate-elimination techniques and the unlock search.
 * Each scan finds its first application, applies it to the state, and
 * reports the full story — pattern cells, locked digits, removed
 * candidates — so the grader can rank it and the hint engine can
 * explain it. Scan order is part of the grading contract: changing it
 * changes which boards the generator accepts. Wing- and fish-family
 * scans live in wings.ts.
 */

import {
  boxIndex,
  kCombinations,
  maskDigits,
  popcount,
  UNITS,
} from "./board-geometry.ts";
import {
  type CandidateState,
  cloneCandidates,
  type Elimination,
  type EliminationKind,
  eliminate,
  findSingle,
  initCandidates,
  type SingleFind,
} from "./candidates.ts";
import { swordfish, xWing, xyWing } from "./wings.ts";

const NAKED_KINDS: Record<number, EliminationKind> = {
  2: "naked-pair",
  3: "naked-triple",
  4: "naked-quad",
};
const HIDDEN_KINDS: Record<number, EliminationKind> = {
  2: "hidden-pair",
  3: "hidden-triple",
  4: "hidden-quad",
};

/** Pointing: a digit confined to one row/col of a box leaves that line. */
export function pointing(s: CandidateState): Elimination | null {
  for (let b = 0; b < 9; b++) {
    for (let v = 1; v <= 9; v++) {
      const bit = 1 << v;
      const cells = UNITS[18 + b]!.filter((c) => s.cand[c]! & bit);
      if (cells.length < 2) continue;
      const rows = new Set(cells.map((c) => Math.floor(c / 9)));
      const cols = new Set(cells.map((c) => c % 9));
      let line: number[] | null = null;
      if (rows.size === 1) line = UNITS[[...rows][0]!]!;
      else if (cols.size === 1) line = UNITS[9 + [...cols][0]!]!;
      if (!line) continue;
      const removed: { cell: number; digit: number }[] = [];
      let changed = false;
      for (const cell of line) {
        if (boxIndex(cell) !== b) {
          changed = eliminate(s, cell, bit, [v], removed) || changed;
        }
      }
      if (changed) {
        return { kind: "pointing", digits: [v], patternCells: cells, removed };
      }
    }
  }
  return null;
}

/** Claiming: a digit confined to one box of a row/col leaves that box. */
export function claiming(s: CandidateState): Elimination | null {
  for (let u = 0; u < 18; u++) {
    const unit = UNITS[u]!;
    for (let v = 1; v <= 9; v++) {
      const bit = 1 << v;
      const cells = unit.filter((c) => s.cand[c]! & bit);
      if (cells.length < 2) continue;
      const boxes = new Set(cells.map(boxIndex));
      if (boxes.size !== 1) continue;
      const removed: { cell: number; digit: number }[] = [];
      let changed = false;
      for (const cell of UNITS[18 + [...boxes][0]!]!) {
        if (!unit.includes(cell)) {
          changed = eliminate(s, cell, bit, [v], removed) || changed;
        }
      }
      if (changed) {
        return { kind: "claiming", digits: [v], patternCells: cells, removed };
      }
    }
  }
  return null;
}

/** Naked set: `size` cells sharing the same `size` candidates own them. */
export function nakedSet(s: CandidateState, size: number): Elimination | null {
  for (const unit of UNITS) {
    const open = unit.filter((c) => s.grid[c] === 0);
    if (open.length <= size) continue;
    const narrow = open.filter((c) => popcount(s.cand[c]!) <= size);
    for (const combo of kCombinations(narrow, size)) {
      let union = 0;
      for (const c of combo) union |= s.cand[c]!;
      if (popcount(union) !== size) continue;
      const digits = maskDigits(union);
      const removed: { cell: number; digit: number }[] = [];
      let changed = false;
      for (const c of open) {
        if (!combo.includes(c)) {
          changed = eliminate(s, c, union, digits, removed) || changed;
        }
      }
      if (changed) {
        return {
          kind: NAKED_KINDS[size]!,
          digits,
          patternCells: combo,
          removed,
        };
      }
    }
  }
  return null;
}

/** Hidden set: `size` digits confined to the same `size` cells own them. */
export function hiddenSet(s: CandidateState, size: number): Elimination | null {
  for (const unit of UNITS) {
    const open = unit.filter((c) => s.grid[c] === 0);
    if (open.length <= size) continue;
    const digits: number[] = [];
    for (let v = 1; v <= 9; v++) {
      if (open.some((c) => s.cand[c]! & (1 << v))) digits.push(v);
    }
    if (digits.length <= size) continue;
    for (const combo of kCombinations(digits, size)) {
      let mask = 0;
      for (const v of combo) mask |= 1 << v;
      const holders = open.filter((c) => s.cand[c]! & mask);
      if (holders.length !== size) continue;
      const removed: { cell: number; digit: number }[] = [];
      let changed = false;
      for (const c of holders) {
        const others = s.cand[c]! & ~mask;
        changed =
          eliminate(s, c, others, maskDigits(others), removed) || changed;
      }
      if (changed) {
        return {
          kind: HIDDEN_KINDS[size]!,
          digits: combo,
          patternCells: holders,
          removed,
        };
      }
    }
  }
  return null;
}

export type UnlockingPlacement = {
  /** The elimination whose removals make the placement visible. */
  elimination: Elimination;
  /** The single that emerges once the elimination is applied. */
  single: SingleFind;
  /** Eliminations silently applied before the unlocking one. */
  priorSteps: number;
};

// Cheapest-first, matching the grader's ladder so a hint never cites
// an X-wing where a pointing pair would do.
const TECHNIQUES: ((s: CandidateState) => Elimination | null)[] = [
  pointing,
  claiming,
  (s) => nakedSet(s, 2),
  (s) => hiddenSet(s, 2),
  (s) => nakedSet(s, 3),
  (s) => hiddenSet(s, 3),
  xWing,
  (s) => nakedSet(s, 4),
  (s) => hiddenSet(s, 4),
  xyWing,
  swordfish,
];

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
export function findUnlockingPlacement(
  puzzle: string,
): UnlockingPlacement | null {
  const s = initCandidates(puzzle);
  if (!s || findSingle(s)) return null;
  // Eliminations strictly shrink the candidate pool, so the walk
  // terminates; the cap is a backstop, not a tuning knob.
  for (let priorSteps = 0; priorSteps < 128; priorSteps++) {
    for (const technique of TECHNIQUES) {
      const preview = cloneCandidates(s);
      const elimination = technique(preview);
      if (!elimination) continue;
      const single = findSingle(preview);
      if (single) return { elimination, single, priorSteps };
    }
    let applied: Elimination | null = null;
    for (const technique of TECHNIQUES) {
      applied = technique(s);
      if (applied) break;
    }
    if (!applied) return null;
  }
  return null;
}
