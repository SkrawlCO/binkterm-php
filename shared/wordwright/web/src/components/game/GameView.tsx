import { useCallback, useEffect, useRef, useState } from 'react';

import { scoreGame } from '@/engine';
import { usePhysicalKeyboard } from '@/hooks/usePhysicalKeyboard';
import { useRevealTimeline } from '@/hooks/useRevealTimeline';
import { clampDuration } from '@/lib/clock';
import { useGame, useSettings } from '@/state';

import { Board } from '../board/Board';
import { BoardSkeleton } from '../board/BoardSkeleton';
import { Keyboard } from '../keyboard/Keyboard';
import type { KeyValue } from '../keyboard/Key';
import { DictionaryError } from './DictionaryError';
import { ResultPanel } from './ResultPanel';

/** How long the invalid-guess shake stays on, matching --duration-shake. */
const SHAKE_DURATION_MS = 600;

/**
 * The playable area: board, keyboard, and all input routing.
 *
 * Kept separate from `App` so the providers can sit above it and this stays a
 * plain consumer of `useGame`.
 */
export function GameView(): React.JSX.Element {
  const {
    state,
    rows,
    keyStates,
    dictionaryStatus,
    wordLength,
    isRevealing,
    isGameOver,
    announcement,
    addLetter,
    removeLetter,
    submitGuess,
    completeReveal,
    restart,
    retryDictionary,
  } = useGame();
  const { prefersReducedMotion } = useSettings();

  const [shakeRow, setShakeRow] = useState<number | null>(null);
  const shakeTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

  // Input is locked while revealing, while loading, and once the game ends.
  const inputLocked = isRevealing || isGameOver || dictionaryStatus !== 'ready';

  const triggerShake = useCallback((): void => {
    setShakeRow(state.guesses.length);

    if (shakeTimer.current) clearTimeout(shakeTimer.current);
    shakeTimer.current = setTimeout(() => {
      setShakeRow(null);
    }, SHAKE_DURATION_MS);
  }, [state.guesses.length]);

  useEffect(
    () => () => {
      if (shakeTimer.current) clearTimeout(shakeTimer.current);
    },
    [],
  );

  /** Submitting shakes the active row when the guess is rejected (FR-14, FR-15). */
  const handleSubmit = useCallback((): void => {
    if (!submitGuess()) triggerShake();
  }, [submitGuess, triggerShake]);

  useRevealTimeline({
    isRevealing,
    wordLength,
    reducedMotion: prefersReducedMotion,
    onComplete: completeReveal,
  });

  const handleKeyPress = useCallback(
    (value: KeyValue): void => {
      if (inputLocked) return;
      if (value === 'ENTER') handleSubmit();
      else if (value === 'BACKSPACE') removeLetter();
      else addLetter(value);
    },
    [inputLocked, handleSubmit, removeLetter, addLetter],
  );

  const isRowEmpty = useCallback(() => state.currentInput.length === 0, [state.currentInput]);

  usePhysicalKeyboard({
    onLetter: addLetter,
    onBackspace: removeLetter,
    onEnter: handleSubmit,
    isRowEmpty,
    disabled: inputLocked,
  });

  if (dictionaryStatus === 'error') {
    return (
      <main className="mx-auto flex w-full max-w-2xl flex-1 items-center justify-center px-2 py-4">
        <DictionaryError onRetry={retryDictionary} />
      </main>
    );
  }

  const solveMs = clampDuration((state.finishedAt ?? 0) - (state.startedAt ?? 0));

  return (
    <main className="mx-auto flex w-full max-w-2xl flex-1 flex-col items-center justify-between gap-3 px-2 py-3">
      {/* A11Y-3: one polite region for board results and game end. */}
      <div role="status" aria-live="polite" className="sr-only">
        {announcement}
      </div>

      <div className="flex flex-1 flex-col items-center justify-center gap-4">
        {dictionaryStatus === 'ready' ? (
          <Board
            rows={rows}
            wordLength={wordLength}
            shakeRow={shakeRow}
            reducedMotion={prefersReducedMotion}
          />
        ) : (
          <BoardSkeleton wordLength={wordLength} />
        )}

        {isGameOver ? (
          <ResultPanel
            won={state.status === 'won'}
            answer={state.answer}
            guessesUsed={state.guesses.length}
            solveMs={solveMs}
            score={scoreGame({
              won: state.status === 'won',
              guessesUsed: state.guesses.length,
              wordLength,
              solveMs,
            })}
            onPlayAgain={restart}
          />
        ) : null}
      </div>

      <Keyboard keyStates={keyStates} disabled={inputLocked} onKeyPress={handleKeyPress} />
    </main>
  );
}
