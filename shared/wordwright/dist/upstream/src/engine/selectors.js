import { MAX_GUESSES } from './constants.js';
/** True once the game has finished, either way. */
export function isGameOver(state) {
    return state.status === 'won' || state.status === 'lost';
}
/** Index of the row the player is typing into; `MAX_GUESSES` when finished. */
export function currentRowIndex(state) {
    return Math.min(state.guesses.length, MAX_GUESSES);
}
export function guessesUsed(state) {
    return state.guesses.length;
}
export function remainingGuesses(state) {
    return Math.max(0, MAX_GUESSES - state.guesses.length);
}
/**
 * Projects state into the fixed 6 × wordLength grid the board renders (FR-50).
 *
 * Always returns exactly `MAX_GUESSES` rows so the layout never reflows as the
 * game proceeds, and the UI needs no index arithmetic of its own.
 */
export function boardRows(state) {
    const activeRow = currentRowIndex(state);
    const revealingRow = state.status === 'revealing' ? state.guesses.length - 1 : -1;
    return Array.from({ length: MAX_GUESSES }, (_unused, rowIndex) => {
        const guess = state.guesses[rowIndex];
        if (guess) {
            return {
                tiles: guess.evaluation.map((evaluationState, index) => ({
                    /* v8 ignore next -- evaluation always has one entry per letter,
                         so the index is in range; the fallback satisfies the type only. */
                    letter: guess.word[index] ?? '',
                    state: evaluationState,
                })),
                isActive: false,
                isRevealing: rowIndex === revealingRow,
            };
        }
        const isActive = rowIndex === activeRow && !isGameOver(state);
        const input = isActive ? state.currentInput : '';
        return {
            tiles: Array.from({ length: state.wordLength }, (_tile, index) => {
                const letter = input[index];
                return letter === undefined ? { letter: '', state: 'empty' } : { letter, state: 'filled' };
            }),
            isActive,
            isRevealing: false,
        };
    });
}
