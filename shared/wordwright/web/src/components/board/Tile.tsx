import { memo } from 'react';

import type { TileState } from '@/engine';
import { cn } from '@/lib/cn';

export interface TileProps {
  readonly letter: string;
  readonly state: TileState;
  /** 1-based, for the accessible label. */
  readonly row: number;
  readonly column: number;
  /** True while this row is flipping. */
  readonly isRevealing: boolean;
  /** Position in the reveal stagger; drives the animation delay. */
  readonly revealDelayMs: number;
  readonly reducedMotion: boolean;
}

const STATE_CLASS: Record<TileState, string> = {
  empty: 'border-tile-empty-border bg-transparent text-tile-text',
  filled: 'border-tile-filled-border bg-transparent text-tile-text',
  correct: 'border-tile-correct bg-tile-correct text-tile-text-revealed',
  present: 'border-tile-present bg-tile-present text-tile-text-revealed',
  absent: 'border-tile-absent bg-tile-absent text-tile-text-revealed',
};

const STATE_LABEL: Record<TileState, string> = {
  empty: 'empty',
  filled: 'entered',
  correct: 'correct position',
  present: 'wrong position',
  absent: 'not in word',
};

/**
 * Non-colour markers for colourblind mode (A11Y-8).
 *
 * Colour alone never conveys state. These sit in a corner of the tile and are
 * hidden from assistive tech, which reads the aria-label instead.
 */
const STATE_MARKER: Partial<Record<TileState, string>> = {
  correct: '✓',
  present: '◐',
};

/**
 * One board cell (FR-51).
 *
 * Memoised because a 6x6 board re-renders on every keystroke and each tile
 * takes only primitives.
 */
export const Tile = memo(function Tile({
  letter,
  state,
  row,
  column,
  isRevealing,
  revealDelayMs,
  reducedMotion,
}: TileProps): React.JSX.Element {
  const isRevealed = state === 'correct' || state === 'present' || state === 'absent';
  const marker = STATE_MARKER[state];

  return (
    <div
      role="gridcell"
      aria-label={`Row ${String(row)}, letter ${String(column)}: ${letter || 'empty'}, ${STATE_LABEL[state]}`}
      className={cn(
        'relative flex items-center justify-center border-2 font-bold uppercase select-none',
        'size-(--size-tile) text-[calc(var(--size-tile)*0.5)]',
        'transition-colors duration-150',
        STATE_CLASS[state],
        state === 'filled' && !reducedMotion && 'animate-pop',
        isRevealing && !reducedMotion && 'animate-flip',
      )}
      style={
        isRevealing && !reducedMotion ? { animationDelay: `${String(revealDelayMs)}ms` } : undefined
      }
    >
      <span aria-hidden="true">{letter}</span>
      {isRevealed && marker ? (
        <span
          aria-hidden="true"
          className="absolute top-0.5 right-1 text-[calc(var(--size-tile)*0.2)] leading-none opacity-0 in-data-[palette=cb]:opacity-100"
        >
          {marker}
        </span>
      ) : null}
    </div>
  );
});
