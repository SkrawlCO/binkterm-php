/**
 * Wing- and fish-family eliminations: X-wing, swordfish, XY-wing — the
 * tier 3-4 patterns built on line intersections and pivot logic. Same
 * contract as the unit-local techniques: first application found is
 * applied to the state and reported in full.
 */
import { type CandidateState, type Elimination } from "./candidates.ts";
export declare function xWing(s: CandidateState): Elimination | null;
export declare function swordfish(s: CandidateState): Elimination | null;
/**
 * XY-wing: a pivot holding {x,y} with one pincer {x,z} and one {y,z}.
 * Whichever way the pivot resolves, a pincer becomes z, so z leaves
 * every cell that sees both pincers.
 */
export declare function xyWing(s: CandidateState): Elimination | null;
