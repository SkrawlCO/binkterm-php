import { MAX_GUESSES } from '@/engine';

/** Placeholder while a dictionary chunk loads (FR-56). */
export function BoardSkeleton({ wordLength }: { wordLength: number }): React.JSX.Element {
  return (
    <div className="flex flex-col items-center gap-4" role="status">
      <div className="grid gap-1.5" aria-hidden="true">
        {Array.from({ length: MAX_GUESSES }, (_row, rowIndex) => (
          <div
            key={rowIndex}
            className="grid justify-center gap-1.5"
            style={{ gridTemplateColumns: `repeat(${String(wordLength)}, minmax(0, auto))` }}
          >
            {Array.from({ length: wordLength }, (_tile, columnIndex) => (
              <div
                key={columnIndex}
                className="size-(--size-tile) animate-pulse rounded-sm border-2 border-tile-empty-border bg-surface-raised"
              />
            ))}
          </div>
        ))}
      </div>
      <span className="text-sm text-text-muted">Loading dictionary…</span>
    </div>
  );
}
