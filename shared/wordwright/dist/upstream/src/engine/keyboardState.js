/** Higher wins. A key never downgrades once it has been seen (FR-53). */
const PRECEDENCE = {
    absent: 0,
    present: 1,
    correct: 2,
};
/**
 * Folds every evaluated guess into the best-known state per letter (FR-53).
 *
 * Derived on demand rather than stored, so the keyboard can never drift out of
 * sync with the board. A letter marked `present` in one row and `correct` in a
 * later row shows as `correct`; the reverse order gives the same answer.
 */
export function deriveKeyStates(guesses) {
    const states = new Map();
    for (const guess of guesses) {
        guess.evaluation.forEach((state, index) => {
            const letter = guess.word[index];
            if (letter === undefined)
                return;
            const current = states.get(letter);
            if (current === undefined || PRECEDENCE[state] > PRECEDENCE[current]) {
                states.set(letter, state);
            }
        });
    }
    return states;
}
