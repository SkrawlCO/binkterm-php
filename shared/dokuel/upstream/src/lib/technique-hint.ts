/**
 * Turns an unlocking elimination into a player-facing hint: names the
 * pattern in board language (rows, columns, boxes), says which digits
 * do the work, and states what the elimination leaves behind.
 */

import { boxIndex } from "./board-geometry.ts";
import type { Elimination } from "./candidates.ts";
import type { HintExplanation } from "./hint-engine.ts";
import {
  findUnlockingPlacement,
  type UnlockingPlacement,
} from "./techniques.ts";
import type { Board, HintTechnique, Position } from "./types.ts";

function toPosition(cell: number): Position {
  return { row: Math.floor(cell / 9), col: cell % 9 };
}

function boardValues(board: Board): string {
  let out = "";
  for (const row of board) {
    for (const cell of row) {
      out += cell.value === null ? "." : String(cell.value);
    }
  }
  return out;
}

/** Name the row, column, or box that contains every given cell. */
function sharedUnitName(cells: number[]): string {
  const rows = new Set(cells.map((c) => Math.floor(c / 9)));
  if (rows.size === 1) return `row ${[...rows][0]! + 1}`;
  const cols = new Set(cells.map((c) => c % 9));
  if (cols.size === 1) return `column ${[...cols][0]! + 1}`;
  return `box ${boxIndex(cells[0]!) + 1}`;
}

/** The row/col the pattern shares — pointing and claiming always have
 * one, since their cells sit in a single line of a single box. */
function sharedLineName(cells: number[]): string {
  const rows = new Set(cells.map((c) => Math.floor(c / 9)));
  if (rows.size === 1) return `row ${[...rows][0]! + 1}`;
  return `column ${(cells[0]! % 9) + 1}`;
}

function listDigits(digits: number[], joiner: string): string {
  if (digits.length === 1) return String(digits[0]);
  return `${digits.slice(0, -1).join(", ")} ${joiner} ${digits.at(-1)}`;
}

function describeElimination(e: Elimination): string {
  const digit = e.digits[0]!;
  switch (e.kind) {
    case "pointing": {
      const line = sharedLineName(e.patternCells);
      const box = boxIndex(e.patternCells[0]!) + 1;
      return `In box ${box}, every place for ${digit} sits in ${line}, so ${digit} can't appear anywhere else in ${line}.`;
    }
    case "claiming": {
      const line = sharedLineName(e.patternCells);
      const box = boxIndex(e.patternCells[0]!) + 1;
      return `In ${line}, ${digit} fits only inside box ${box}, so the rest of box ${box} can't hold ${digit}.`;
    }
    case "naked-pair":
    case "naked-triple":
    case "naked-quad": {
      const unit = sharedUnitName(e.patternCells);
      return `The highlighted cells in ${unit} hold only ${listDigits(e.digits, "and")} between them, so those digits fall out of the rest of ${unit}.`;
    }
    case "hidden-pair":
    case "hidden-triple":
    case "hidden-quad": {
      const unit = sharedUnitName(e.patternCells);
      return `Within ${unit}, ${listDigits(e.digits, "and")} fit only in the highlighted cells, so those cells can hold nothing else.`;
    }
    case "xy-wing": {
      // The pivot never holds the eliminated digit, so the hint must
      // name each cell's pair and walk the case split — role-free
      // prose cannot be followed on the board.
      const [pivot, pincer1, pincer2] = e.patternDigits!;
      const [x, y] = [
        pivot!.find((d) => pincer1!.includes(d))!,
        pivot!.find((d) => pincer2!.includes(d))!,
      ].sort((a, b) => a - b);
      return `An XY-wing: the pivot cell can only be ${x} or ${y}. If it's ${x}, the ${x}/${digit} cell must be ${digit}; if it's ${y}, the ${y}/${digit} cell must be ${digit}. Either way a ${digit} lands in one of them, so no cell that sees both can hold ${digit}.`;
    }
    case "x-wing":
    case "swordfish": {
      // Removals happen along the crossing lines: name both axes from
      // the pattern itself.
      const name = e.kind === "x-wing" ? "an X-wing" : "a swordfish";
      const rows = [...new Set(e.patternCells.map((c) => Math.floor(c / 9)))];
      const cols = [...new Set(e.patternCells.map((c) => c % 9))];
      const removedInCols = e.removed.every((r) => cols.includes(r.cell % 9));
      const [lines, crosses, crossKind] = removedInCols
        ? [
            rows.map((r) => `row ${r + 1}`),
            cols.map((c) => `column ${c + 1}`),
            "columns",
          ]
        : [
            cols.map((c) => `column ${c + 1}`),
            rows.map((r) => `row ${r + 1}`),
            "rows",
          ];
      return `${digit} forms ${name}: in ${listNames(lines)} it can only sit in ${listNames(crosses)}, so ${digit} falls out of the rest of those ${crossKind}.`;
    }
  }
}

function listNames(names: string[]): string {
  if (names.length <= 2) return names.join(" and ");
  return `${names.slice(0, -1).join(", ")} and ${names.at(-1)}`;
}

function describeConsequence(unlock: UnlockingPlacement): string {
  const { single } = unlock;
  if (single.kind === "naked") {
    return ` That leaves this cell just one option: ${single.digit}.`;
  }
  const unit =
    single.unitIndex < 9
      ? `row ${single.unitIndex + 1}`
      : single.unitIndex < 18
        ? `column ${single.unitIndex - 9 + 1}`
        : `box ${single.unitIndex - 18 + 1}`;
  return ` That makes this cell the only place for ${single.digit} in ${unit}.`;
}

const TECHNIQUE_OF_KIND: Record<Elimination["kind"], HintTechnique> = {
  pointing: "locked-candidates",
  claiming: "locked-candidates",
  "naked-pair": "naked-pair",
  "hidden-pair": "hidden-pair",
  "naked-triple": "naked-triple",
  "hidden-triple": "hidden-triple",
  "naked-quad": "naked-quad",
  "hidden-quad": "hidden-quad",
  "x-wing": "x-wing",
  "xy-wing": "xy-wing",
  swordfish: "swordfish",
};

/** The technique hint for a board whose singles have run dry — null
 * when only chains or guessing can progress. */
export function findTechniqueHint(board: Board): HintExplanation | null {
  const unlock = findUnlockingPlacement(boardValues(board));
  return unlock ? buildTechniqueHint(unlock) : null;
}

function buildTechniqueHint(unlock: UnlockingPlacement): HintExplanation {
  const { elimination, single, priorSteps } = unlock;
  const preamble =
    priorSteps > 0
      ? `This one sits ${priorSteps + 1} eliminations deep — the decisive step: `
      : "";
  const related = [
    ...elimination.patternCells,
    ...elimination.removed.map((r) => r.cell),
  ];
  return {
    position: toPosition(single.cell),
    value: single.digit,
    technique: TECHNIQUE_OF_KIND[elimination.kind],
    explanation:
      preamble + describeElimination(elimination) + describeConsequence(unlock),
    relatedCells: [...new Set(related)].map(toPosition),
  };
}
