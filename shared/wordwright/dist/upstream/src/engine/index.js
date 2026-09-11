/**
 * Public surface of the game engine.
 *
 * Pure TypeScript: no React, no DOM, no storage, no timers, no ambient
 * randomness or clock (ADR-003). Every impure dependency is injected as a
 * parameter, which is what makes this layer exhaustively testable and reusable
 * by any future host — a V2 daily-challenge precomputation or a V3 server-side
 * validator alike. The boundary is enforced by ESLint, not just by convention.
 */
export { ALPHABET, DEFAULT_WORD_LENGTH, MAX_GUESSES, RECENT_ANSWER_WINDOW, RECENT_GAMES_LIMIT, SCORE, WORD_LENGTHS, } from './constants.js';
export { createGame, pickAnswer } from './createGame.js';
export { evaluateGuess } from './evaluate.js';
export { deriveKeyStates } from './keyboardState.js';
export { pickRandom, seededRandom, systemRandom } from './random.js';
export { gameReducer, startGame } from './reducer.js';
export { scoreGame } from './score.js';
export { boardRows, currentRowIndex, guessesUsed, isGameOver, remainingGuesses } from './selectors.js';
export { validateGuess } from './validate.js';
