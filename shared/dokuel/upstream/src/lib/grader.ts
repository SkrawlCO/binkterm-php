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

import { findSingle, initCandidates, place } from "./candidates.ts";
import { claiming, hiddenSet, nakedSet, pointing } from "./techniques.ts";
import { swordfish, xWing, xyWing } from "./wings.ts";

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
export function gradePuzzle(puzzle: string): PuzzleGrade {
  const s = initCandidates(puzzle);
  if (!s) return { tier: 5, stuckCells: 81 };
  let tier: TechniqueTier = 1;
  while (s.empty > 0) {
    const single = findSingle(s);
    if (single) {
      place(s, single.cell, single.digit);
      continue;
    }
    if (pointing(s) || claiming(s) || nakedSet(s, 2) || hiddenSet(s, 2)) {
      tier = tier < 2 ? 2 : tier;
      continue;
    }
    if (nakedSet(s, 3) || hiddenSet(s, 3) || xWing(s)) {
      tier = tier < 3 ? 3 : tier;
      continue;
    }
    if (nakedSet(s, 4) || hiddenSet(s, 4) || xyWing(s) || swordfish(s)) {
      tier = tier < 4 ? 4 : tier;
      continue;
    }
    return { tier: 5, stuckCells: s.empty };
  }
  return { tier, stuckCells: 0 };
}
