import { useCallback } from "react";
import type { Board, NumPadGesturePoint, Position } from "../lib/types.ts";
import {
  type DigitDragSource,
  type DigitDropMode,
  useDigitDrag,
} from "./useDigitDrag.ts";

/** A completed drop, in the terms digitIntent asks about. */
export type DigitDrop = {
  digit: number;
  /** Which half of the cell the digit landed in. */
  mode: DigitDropMode;
  target: Position;
  /** The cell the digit was dragged from, or null for the numpad. */
  from: Position | null;
};

type Options = {
  /** Read to decide which cells a digit may land in. */
  board: Board;
  /** When true (paused, game over, ...), drag-starts and drops no-op. */
  disabled?: boolean | undefined;
  /** Runs the drop. What it does to the board is not decided here. */
  onDrop: (drop: DigitDrop) => void;
  /**
   * Forwarded to the drag layer: fires when a numpad drag is brought
   * back over the numpad, so the caller can resume a numpad skim.
   */
  onReturnToNumpad?:
    | ((info: {
        digit: number;
        pointerId: number;
        pointerType: string;
      }) => void)
    | undefined;
};

/**
 * The digit drag-and-drop wiring shared by SoloGame and
 * MultiplayerBoard: which cells accept a digit, where a drag may start,
 * and turning a landed drag into a `DigitDrop`. The drop zone within the
 * cell decides the mode — the top half is a value, the bottom half a
 * note — but what either one does to the board is the caller's answer,
 * via digitIntent.
 */
export function useGameDigitDrag({
  board,
  disabled,
  onDrop,
  onReturnToNumpad,
}: Options) {
  const isDroppable = useCallback(
    (row: number, col: number) => {
      const cell = board[row]?.[col];
      if (!cell) return false;
      return !cell.isGiven && cell.value === null;
    },
    [board],
  );

  const handleDrop = useCallback(
    (
      digit: number,
      source: DigitDragSource,
      target: Position,
      mode: DigitDropMode,
    ) => {
      if (disabled) return;
      onDrop({
        digit,
        mode,
        target,
        from:
          source.kind === "cell" ? { row: source.row, col: source.col } : null,
      });
    },
    [disabled, onDrop],
  );

  const { state: dragState, start } = useDigitDrag({
    onDrop: handleDrop,
    isDroppable,
    onReturnToNumpad,
  });

  const startNumpadDrag = useCallback(
    (args: NumPadGesturePoint) => {
      if (disabled) return;
      start({ ...args, source: { kind: "numpad" } });
    },
    [disabled, start],
  );

  const startCellDrag = useCallback(
    (args: {
      digit: number;
      from: Position;
      x: number;
      y: number;
      pointerId: number;
      pointerType: string;
    }) => {
      if (disabled) return;
      start({
        digit: args.digit,
        source: { kind: "cell", row: args.from.row, col: args.from.col },
        x: args.x,
        y: args.y,
        pointerId: args.pointerId,
        pointerType: args.pointerType,
      });
    },
    [disabled, start],
  );

  return { dragState, startNumpadDrag, startCellDrag };
}
