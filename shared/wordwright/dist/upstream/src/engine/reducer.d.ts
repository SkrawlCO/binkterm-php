import type { GameState, WordLength } from './types.js';
/**
 * Actions the game accepts (ARCHITECTURE §3.2).
 *
 * `now` is passed in rather than read from the clock, keeping the reducer pure
 * and its tests deterministic (ADR-003). `SUBMIT_GUESS` carries an
 * already-validated word: dictionary lookup happens in the provider, because
 * the engine may not depend on the dictionary.
 */
export type GameAction = {
    readonly type: 'START_GAME';
    readonly game: GameState;
} | {
    readonly type: 'ADD_LETTER';
    readonly letter: string;
    readonly now: number;
} | {
    readonly type: 'REMOVE_LETTER';
} | {
    readonly type: 'SUBMIT_GUESS';
    readonly word: string;
    readonly now: number;
} | {
    readonly type: 'REVEAL_COMPLETE';
    readonly now: number;
} | {
    readonly type: 'SET_STATUS';
    readonly status: GameState['status'];
};
/**
 * The game state machine. Pure, synchronous and total.
 *
 * Unknown or illegal actions return the *same reference*, so React skips the
 * re-render and callers can rely on identity comparison.
 */
export declare function gameReducer(state: GameState, action: GameAction): GameState;
/** Convenience for tests and callers building a start action. */
export declare function startGame(game: GameState): Extract<GameAction, {
    type: 'START_GAME';
}>;
export type { WordLength };
