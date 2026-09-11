import type { GameState, RowView } from './types.js';
/** True once the game has finished, either way. */
export declare function isGameOver(state: GameState): boolean;
/** Index of the row the player is typing into; `MAX_GUESSES` when finished. */
export declare function currentRowIndex(state: GameState): number;
export declare function guessesUsed(state: GameState): number;
export declare function remainingGuesses(state: GameState): number;
/**
 * Projects state into the fixed 6 × wordLength grid the board renders (FR-50).
 *
 * Always returns exactly `MAX_GUESSES` rows so the layout never reflows as the
 * game proceeds, and the UI needs no index arithmetic of its own.
 */
export declare function boardRows(state: GameState): readonly RowView[];
