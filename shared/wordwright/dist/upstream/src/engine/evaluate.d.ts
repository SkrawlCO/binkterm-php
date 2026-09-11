import type { Evaluation } from './types.js';
/**
 * Evaluates a guess against the answer (SPEC §6.3).
 *
 * Two passes over a consumable pool of the answer's letters:
 *
 *   1. Exact matches claim their letter first, so a green is never denied by a
 *      yellow that appeared earlier in the word.
 *   2. Remaining positions become `present` only while an unclaimed copy of
 *      that letter is still in the pool, scanning left to right.
 *
 * The pool is what makes duplicate letters behave (FR-12, FR-13, EC-1). With
 * answer `SPEED` and guess `EEEEE`, the two `E`s at indices 2 and 3 match
 * exactly and consume both copies, so the remaining three `E`s are grey —
 * the single most commonly botched case in Wordle clones.
 *
 * O(n) time, O(k) space in the number of distinct letters.
 *
 * @param guess  Uppercase word, same length as `answer`.
 * @param answer Uppercase solution.
 */
export declare function evaluateGuess(guess: string, answer: string): Evaluation;
