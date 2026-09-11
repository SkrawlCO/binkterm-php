import { useCallback, useState } from "react";
import type { Position } from "../lib/types.ts";

type Handlers = {
  selectCell: (row: number, col: number) => void;
  setSelectedCells: (cells: Set<number>, primary: Position) => void;
  deselectCell: () => void;
};

/**
 * Tracks the digit (1-9) spotlighted board-wide for same-number
 * highlighting.
 *
 * What a numpad tap does with that spotlight is not decided here — see
 * digitIntent, which answers it for every gesture at once. This hook
 * owns the state and returns wrapped versions of the game's select
 * handlers, so callers don't have to remember to clear the spotlight at
 * every selection entry point.
 */
export function useDigitHighlight({
  selectCell,
  setSelectedCells,
  deselectCell,
}: Handlers) {
  const [highlightedDigit, setHighlightedDigit] = useState<number | null>(null);

  const toggle = useCallback((n: number) => {
    setHighlightedDigit((prev) => (prev === n ? null : n));
  }, []);

  const setDigit = useCallback((n: number) => {
    setHighlightedDigit(n);
  }, []);

  const wrappedSelectCell = useCallback(
    (row: number, col: number) => {
      setHighlightedDigit(null);
      selectCell(row, col);
    },
    [selectCell],
  );

  const wrappedSetSelectedCells = useCallback(
    (cells: Set<number>, primary: Position) => {
      setHighlightedDigit(null);
      setSelectedCells(cells, primary);
    },
    [setSelectedCells],
  );

  const wrappedDeselectCell = useCallback(() => {
    setHighlightedDigit(null);
    deselectCell();
  }, [deselectCell]);

  // Skim across the numpad: make `digit` the active highlight and drop
  // any cell selection, so the board follows the finger instead of the
  // selected cell's own value.
  const skimToDigit = useCallback(
    (digit: number) => {
      setHighlightedDigit(digit);
      deselectCell();
    },
    [deselectCell],
  );

  return {
    highlightedDigit,
    toggle,
    setDigit,
    selectCell: wrappedSelectCell,
    setSelectedCells: wrappedSetSelectedCells,
    deselectCell: wrappedDeselectCell,
    skimToDigit,
  };
}
