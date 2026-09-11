/**
 * Turns an unlocking elimination into a player-facing hint: names the
 * pattern in board language (rows, columns, boxes), says which digits
 * do the work, and states what the elimination leaves behind.
 */
import type { HintExplanation } from "./hint-engine.ts";
import type { Board } from "./types.ts";
/** The technique hint for a board whose singles have run dry — null
 * when only chains or guessing can progress. */
export declare function findTechniqueHint(board: Board): HintExplanation | null;
