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
export function evaluateGuess(guess, answer) {
    if (guess.length !== answer.length) {
        throw new Error(`Cannot evaluate a ${guess.length}-letter guess against a ${answer.length}-letter answer`);
    }
    const length = guess.length;
    const result = new Array(length).fill('absent');
    // Remaining, unclaimed copies of each letter in the answer.
    const pool = new Map();
    for (let i = 0; i < length; i += 1) {
        const letter = answer[i];
        pool.set(letter, (pool.get(letter) ?? 0) + 1);
    }
    // Pass 1 — greens consume their letter before any yellow can claim it.
    for (let i = 0; i < length; i += 1) {
        const letter = guess[i];
        if (letter === answer[i]) {
            result[i] = 'correct';
            /* v8 ignore next -- the `?? 0` can't fire here: an exact match means the
               answer contains this letter, so the pool always has an entry for it.
               Kept because noUncheckedIndexedAccess requires the fallback. */
            pool.set(letter, (pool.get(letter) ?? 0) - 1);
        }
    }
    // Pass 2 — yellows, left to right, only while copies remain.
    for (let i = 0; i < length; i += 1) {
        if (result[i] === 'correct')
            continue;
        const letter = guess[i];
        const remaining = pool.get(letter) ?? 0;
        if (remaining > 0) {
            result[i] = 'present';
            pool.set(letter, remaining - 1);
        }
    }
    return result;
}
