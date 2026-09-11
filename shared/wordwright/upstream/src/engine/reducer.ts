import { MAX_GUESSES } from './constants';
import { evaluateGuess } from './evaluate';
import type { GameState, Guess, WordLength } from './types';

/**
 * Actions the game accepts (ARCHITECTURE §3.2).
 *
 * `now` is passed in rather than read from the clock, keeping the reducer pure
 * and its tests deterministic (ADR-003). `SUBMIT_GUESS` carries an
 * already-validated word: dictionary lookup happens in the provider, because
 * the engine may not depend on the dictionary.
 */
export type GameAction =
  | { readonly type: 'START_GAME'; readonly game: GameState }
  | { readonly type: 'ADD_LETTER'; readonly letter: string; readonly now: number }
  | { readonly type: 'REMOVE_LETTER' }
  | { readonly type: 'SUBMIT_GUESS'; readonly word: string; readonly now: number }
  | { readonly type: 'REVEAL_COMPLETE'; readonly now: number }
  | { readonly type: 'SET_STATUS'; readonly status: GameState['status'] };

/** True when the game accepts input: playing, and not mid-reveal (FR-11). */
function acceptsInput(state: GameState): boolean {
  return state.status === 'playing';
}

/**
 * The game state machine. Pure, synchronous and total.
 *
 * Unknown or illegal actions return the *same reference*, so React skips the
 * re-render and callers can rely on identity comparison.
 */
export function gameReducer(state: GameState, action: GameAction): GameState {
  switch (action.type) {
    case 'START_GAME':
      return action.game;

    case 'ADD_LETTER': {
      if (!acceptsInput(state)) return state;
      // Typing past the row length is ignored silently (FR-7, AC-3).
      if (state.currentInput.length >= state.wordLength) return state;

      const letter = action.letter.toUpperCase();
      if (!/^[A-Z]$/.test(letter)) return state;

      return {
        ...state,
        currentInput: state.currentInput + letter,
        // The clock starts on the first keystroke of the game (FR-22) and is
        // never restarted, so a paused-then-resumed game still measures
        // total wall-clock time.
        startedAt: state.startedAt ?? action.now,
      };
    }

    case 'REMOVE_LETTER': {
      if (!acceptsInput(state)) return state;
      if (state.currentInput.length === 0) return state;

      return { ...state, currentInput: state.currentInput.slice(0, -1) };
    }

    case 'SUBMIT_GUESS': {
      if (!acceptsInput(state)) return state;

      const word = action.word.toUpperCase();
      if (word.length !== state.wordLength) return state;
      if (state.guesses.length >= MAX_GUESSES) return state;

      const guess: Guess = { word, evaluation: evaluateGuess(word, state.answer) };
      const guesses = [...state.guesses, guess];

      // The outcome is decided here, but stays hidden behind `revealing` until
      // the flip animation finishes, so the UI never spoils the result (FR-52).
      return {
        ...state,
        guesses,
        currentInput: '',
        status: 'revealing',
        startedAt: state.startedAt ?? action.now,
      };
    }

    case 'REVEAL_COMPLETE': {
      if (state.status !== 'revealing') return state;

      const lastGuess = state.guesses[state.guesses.length - 1];
      const won = lastGuess?.word === state.answer;
      const exhausted = state.guesses.length >= MAX_GUESSES;

      if (won) return { ...state, status: 'won', finishedAt: action.now };
      if (exhausted) return { ...state, status: 'lost', finishedAt: action.now };

      return { ...state, status: 'playing' };
    }

    case 'SET_STATUS': {
      if (state.status === action.status) return state;
      return { ...state, status: action.status };
    }

    /* v8 ignore next 5 -- unreachable: TypeScript proves the switch is
       exhaustive, so this exists only to fail the build if an action is added
       without a case. There is no runtime input that reaches it. */
    default: {
      const exhaustive: never = action;
      return exhaustive;
    }
  }
}

/** Convenience for tests and callers building a start action. */
export function startGame(game: GameState): Extract<GameAction, { type: 'START_GAME' }> {
  return { type: 'START_GAME', game };
}

export type { WordLength };
