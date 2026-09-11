import type { WORD_LENGTHS } from './constants';

/** A supported word length (FR-1). */
export type WordLength = (typeof WORD_LENGTHS)[number];

/** The evaluated state of a single letter within a submitted guess. */
export type LetterState = 'correct' | 'present' | 'absent';

/** What a board tile can show, including pre-submission states (FR-51). */
export type TileState = LetterState | 'empty' | 'filled';

/** One `LetterState` per letter of a guess, in order. */
export type Evaluation = readonly LetterState[];

/** Lifecycle of a single game (ARCHITECTURE §3.2). */
export type GameStatus = 'idle' | 'loading' | 'playing' | 'revealing' | 'won' | 'lost' | 'error';

/** Why a submitted guess was rejected (FR-14, FR-15). */
export type GuessError = 'too-short' | 'not-a-word';

/** A submitted guess and its evaluation. */
export interface Guess {
  /** Uppercase, exactly `wordLength` characters. */
  readonly word: string;
  readonly evaluation: Evaluation;
}

/**
 * The complete state of one game.
 *
 * Serialised verbatim into LocalStorage for session restore (FR-32), which is
 * why every field is a plain, JSON-safe value.
 */
export interface GameState {
  /** Stable id; the idempotency key for recording statistics (FR-20). */
  readonly id: string;
  readonly wordLength: WordLength;
  /** The solution. Uppercase. */
  readonly answer: string;
  readonly guesses: readonly Guess[];
  /** Letters typed into the active row but not yet submitted. */
  readonly currentInput: string;
  readonly status: GameStatus;
  /** Epoch ms of the first keystroke; null until the player starts typing (FR-22). */
  readonly startedAt: number | null;
  /** Epoch ms when the game reached `won` or `lost`. */
  readonly finishedAt: number | null;
}

/** A tile as the board renders it (FR-50, FR-51). */
export interface TileView {
  readonly letter: string;
  readonly state: TileState;
}

/** One board row of `wordLength` tiles. */
export interface RowView {
  readonly tiles: readonly TileView[];
  /** True for the row the player is currently typing into. */
  readonly isActive: boolean;
  /** True for a row that has just been submitted and is mid-reveal. */
  readonly isRevealing: boolean;
}

/** A completed game, as recorded into statistics (FR-39). */
export interface GameRecord {
  readonly id: string;
  readonly playedAt: number;
  readonly wordLength: WordLength;
  readonly answer: string;
  readonly guessesUsed: number;
  readonly won: boolean;
  /** Wall-clock milliseconds from first keystroke to the final guess (FR-22). */
  readonly solveMs: number;
  readonly score: number;
}
