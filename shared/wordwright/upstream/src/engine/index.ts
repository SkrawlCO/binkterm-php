/**
 * Public surface of the game engine.
 *
 * Pure TypeScript: no React, no DOM, no storage, no timers, no ambient
 * randomness or clock (ADR-003). Every impure dependency is injected as a
 * parameter, which is what makes this layer exhaustively testable and reusable
 * by any future host — a V2 daily-challenge precomputation or a V3 server-side
 * validator alike. The boundary is enforced by ESLint, not just by convention.
 */

export {
  ALPHABET,
  DEFAULT_WORD_LENGTH,
  MAX_GUESSES,
  RECENT_ANSWER_WINDOW,
  RECENT_GAMES_LIMIT,
  SCORE,
  WORD_LENGTHS,
} from './constants';

export { createGame, pickAnswer, type CreateGameOptions } from './createGame';
export { evaluateGuess } from './evaluate';
export { deriveKeyStates } from './keyboardState';
export { pickRandom, seededRandom, systemRandom, type RandomSource } from './random';
export { gameReducer, startGame, type GameAction } from './reducer';
export { scoreGame, type ScoreInput } from './score';
export { boardRows, currentRowIndex, guessesUsed, isGameOver, remainingGuesses } from './selectors';
export { validateGuess } from './validate';

export type {
  Evaluation,
  GameRecord,
  GameState,
  GameStatus,
  Guess,
  GuessError,
  LetterState,
  RowView,
  TileState,
  TileView,
  WordLength,
} from './types';
