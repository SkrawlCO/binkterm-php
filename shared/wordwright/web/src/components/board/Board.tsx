import { memo } from 'react';

import type { RowView } from '@/engine';
import { cn } from '@/lib/cn';

import { Tile } from './Tile';

/** Milliseconds between successive tiles flipping (FR-52). */
export const REVEAL_STAGGER_MS = 250;

export interface BoardProps {
  readonly rows: readonly RowView[];
  readonly wordLength: number;
  /** Row index to shake after an invalid submission, or null. */
  readonly shakeRow: number | null;
  readonly reducedMotion: boolean;
}

export const Board = memo(function Board({
  rows,
  wordLength,
  shakeRow,
  reducedMotion,
}: BoardProps): React.JSX.Element {
  return (
    <div
      role="grid"
      aria-label="Guess board"
      aria-rowcount={rows.length}
      aria-colcount={wordLength}
      className="grid gap-1.5"
    >
      {rows.map((row, rowIndex) => (
        <div
          // Rows are positional and never reordered, so the index is a stable key.
          key={rowIndex}
          role="row"
          aria-rowindex={rowIndex + 1}
          className={cn(
            'grid justify-center gap-1.5',
            shakeRow === rowIndex && !reducedMotion && 'animate-shake',
          )}
          style={{ gridTemplateColumns: `repeat(${String(wordLength)}, minmax(0, auto))` }}
        >
          {row.tiles.map((tile, columnIndex) => (
            <Tile
              key={columnIndex}
              letter={tile.letter}
              state={tile.state}
              row={rowIndex + 1}
              column={columnIndex + 1}
              isRevealing={row.isRevealing}
              revealDelayMs={columnIndex * REVEAL_STAGGER_MS}
              reducedMotion={reducedMotion}
            />
          ))}
        </div>
      ))}
    </div>
  );
});
