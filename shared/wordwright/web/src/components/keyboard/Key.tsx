import { memo } from 'react';

import type { LetterState } from '@/engine';
import { cn } from '@/lib/cn';

export type KeyValue = (string & {}) | 'ENTER' | 'BACKSPACE';

export interface KeyProps {
  readonly value: KeyValue;
  readonly state: LetterState | undefined;
  readonly disabled: boolean;
  readonly onPress: (value: KeyValue) => void;
}

const STATE_CLASS: Record<LetterState, string> = {
  correct: 'bg-key-correct text-key-text-revealed',
  present: 'bg-key-present text-key-text-revealed',
  absent: 'bg-key-absent text-key-text-revealed',
};

const STATE_LABEL: Record<LetterState, string> = {
  correct: 'correct position',
  present: 'wrong position',
  absent: 'not in word',
};

function accessibleName(value: KeyValue, state: LetterState | undefined): string {
  if (value === 'ENTER') return 'Enter';
  if (value === 'BACKSPACE') return 'Backspace';
  return state ? `${value}, ${STATE_LABEL[state]}` : value;
}

export const Key = memo(function Key({
  value,
  state,
  disabled,
  onPress,
}: KeyProps): React.JSX.Element {
  const isWide = value === 'ENTER' || value === 'BACKSPACE';

  return (
    <button
      type="button"
      // Focus stays on the board for physical typing; keys are reachable by
      // Tab but the game does not steal focus on press.
      onClick={() => {
        onPress(value);
      }}
      disabled={disabled}
      aria-label={accessibleName(value, state)}
      className={cn(
        'flex items-center justify-center rounded font-bold uppercase select-none',
        // FR-58: comfortably above the 44px touch target on phones.
        'h-(--size-key-height) min-w-0 flex-1 px-0.5 text-xs sm:px-2 sm:text-sm',
        'transition-colors duration-150 active:scale-95',
        'disabled:cursor-not-allowed disabled:opacity-50',
        isWide && 'grow-[1.5] text-[0.65rem] sm:text-xs',
        state ? STATE_CLASS[state] : 'bg-key text-key-text',
      )}
    >
      {value === 'BACKSPACE' ? (
        <svg
          aria-hidden="true"
          viewBox="0 0 24 24"
          className="size-5"
          fill="none"
          stroke="currentColor"
          strokeWidth="2"
        >
          <path d="M21 5H8l-6 7 6 7h13a1 1 0 0 0 1-1V6a1 1 0 0 0-1-1Z" />
          <path d="m17 9-6 6M11 9l6 6" />
        </svg>
      ) : (
        value
      )}
    </button>
  );
});
