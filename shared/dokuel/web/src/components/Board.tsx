import { useEffect, useLayoutEffect, useRef, useState } from "react";
import type { DigitDragState } from "../hooks/useDigitDrag.ts";
import { useDragSelect } from "../hooks/useDragSelect.ts";
import { cellKey } from "../lib/sudoku.ts";
import type {
  AssistLevel,
  Board as BoardType,
  Position,
} from "../lib/types.ts";
import { Cell } from "./Cell.tsx";

type BoardProps = {
  board: BoardType;
  selectedCell: Position | null;
  selectedCells?: Set<number> | undefined;
  conflicts: Set<number>;
  hintCells?: Set<number> | undefined;
  /**
   * When set and no cell is selected, drives same-number highlighting
   * as if a cell containing this digit were selected. Lets the numpad
   * act as a "filter chip" — tap a digit to spotlight every cell that
   * holds it. Ignored while a cell is selected so the selection's own
   * value takes precedence.
   */
  highlightedDigit?: number | null | undefined;
  onSelectCell: (row: number, col: number) => void;
  onSetSelectedCells?:
    | ((cells: Set<number>, primary: Position) => void)
    | undefined;
  animateReveal?: boolean;
  assistLevel?: AssistLevel;
  /**
   * The digit currently being long-pressed on the numpad — forwarded to
   * the selected cell so the in-cell hold animation can play there.
   */
  chargingDigit?: number | null | undefined;
  /**
   * Optional digit-drag state. When non-null, cells render drop hints
   * — empty cells pulse softly as candidates, the hovered target gets
   * a stronger accent ring, and the source cell (if any) fades.
   */
  dragState?: DigitDragState | null | undefined;
  /**
   * Fires when a long-press on a filled cell becomes a digit drag.
   * The Board passes this through to its useDragSelect hook.
   */
  onStartCellDrag?:
    | ((args: {
        digit: number;
        from: Position;
        x: number;
        y: number;
        pointerId: number;
        pointerType: string;
      }) => void)
    | undefined;
};

export function Board({
  board,
  selectedCell,
  selectedCells,
  conflicts,
  hintCells,
  highlightedDigit,
  onSelectCell,
  onSetSelectedCells,
  animateReveal,
  assistLevel = "standard",
  chargingDigit,
  dragState,
  onStartCellDrag,
}: BoardProps) {
  const isPaper = assistLevel === "paper";
  const isFull = assistLevel === "full";
  const selectedValue =
    selectedCell !== null
      ? board[selectedCell.row]![selectedCell.col]!.value
      : (highlightedDigit ?? null);

  // In full assist mode, collect rows/cols/boxes of all cells matching the
  // active value (the selected cell's value, or the numpad-highlighted digit)
  // for the "where this digit can't go" cross-highlight. The selected cell
  // itself is excluded so its own row/col/box don't double-up over the
  // selection halo.
  const matchRowColSet = (() => {
    if (!isFull || selectedValue === null) return null;
    const rows = new Set<number>();
    const cols = new Set<number>();
    const boxes = new Set<number>();
    for (let r = 0; r < 9; r++) {
      for (let c = 0; c < 9; c++) {
        if (
          board[r]![c]!.value === selectedValue &&
          !(selectedCell?.row === r && selectedCell?.col === c)
        ) {
          rows.add(r);
          cols.add(c);
          boxes.add(Math.floor(r / 3) * 3 + Math.floor(c / 3));
        }
      }
    }
    return rows.size > 0 || cols.size > 0 || boxes.size > 0
      ? { rows, cols, boxes }
      : null;
  })();

  const dragHandlers = useDragSelect({
    board,
    selectedCell,
    selectedCells,
    onSetSelectedCells,
    onStartCellDrag,
    onSelectCell,
  });

  // Snap the board to an integer-pixel size so every cell and every gap
  // renders at exact device pixels. Sub-pixel cell widths cause adjacent
  // gaps to anti-alias to different widths (some 1px, some 2px); flooring
  // to an integer cell size makes that impossible.
  // Total board = 9 cells + 6 thin gaps (1px) + 2 thick gaps (2px) + 2 outer
  // pads (2px) = 9 * cellPx + 14.
  const containerRef = useRef<HTMLDivElement>(null);
  const [cellPx, setCellPx] = useState(32);
  useLayoutEffect(() => {
    const el = containerRef.current;
    if (!el) return;
    const update = () => {
      const w = el.clientWidth;
      if (w === 0) return;
      setCellPx(Math.max(20, Math.floor((w - 14) / 9)));
    };
    update();
    if (typeof ResizeObserver === "undefined") return;
    const observer = new ResizeObserver(update);
    observer.observe(el);
    return () => observer.disconnect();
  }, []);
  const boxPx = cellPx * 3 + 2;
  const boardPx = cellPx * 9 + 14;

  // Block iOS Safari's swipe-from-edge back gesture for drags that
  // originate inside the board. touch-action: none on the cell isn't
  // reliable at the screen edge — Safari often ignores it for the
  // system back-swipe. A non-passive touchstart preventDefault is the
  // only block that works across iOS versions. React's onTouchStart is
  // passive (preventDefault is a no-op), so we attach it natively.
  const gridRef = useRef<HTMLDivElement>(null);
  useEffect(() => {
    const el = gridRef.current;
    if (!el) return;
    const handler = (e: TouchEvent) => e.preventDefault();
    el.addEventListener("touchstart", handler, { passive: false });
    return () => el.removeEventListener("touchstart", handler);
  }, []);

  return (
    <div
      ref={containerRef}
      className="w-full max-w-none lg:max-w-lg aspect-square flex items-center justify-center"
    >
      <div
        ref={gridRef}
        style={{
          width: boardPx,
          height: boardPx,
          gridTemplateColumns: `repeat(3, ${boxPx}px)`,
          gridTemplateRows: `repeat(3, ${boxPx}px)`,
        }}
        className="grid gap-[2px] bg-board-border p-[2px] shadow-lg shadow-black/8 dark:shadow-black/25 touch-none"
        role="region"
        aria-label="Sudoku board"
        data-board-glow
        onPointerDown={
          onSetSelectedCells ? dragHandlers.onPointerDown : undefined
        }
        onPointerMove={
          onSetSelectedCells ? dragHandlers.onPointerMove : undefined
        }
        onPointerUp={onSetSelectedCells ? dragHandlers.onPointerUp : undefined}
        onClickCapture={
          onSetSelectedCells ? dragHandlers.onClickCapture : undefined
        }
      >
        {Array.from({ length: 9 }, (_, boxIdx) => {
          const boxRow = Math.floor(boxIdx / 3);
          const boxCol = boxIdx % 3;
          return (
            <div
              key={boxIdx}
              style={{
                gridTemplateColumns: `repeat(3, ${cellPx}px)`,
                gridTemplateRows: `repeat(3, ${cellPx}px)`,
              }}
              className="grid gap-px bg-border-default"
            >
              {Array.from({ length: 9 }, (_, cellIdx) => {
                const rowIdx = boxRow * 3 + Math.floor(cellIdx / 3);
                const colIdx = boxCol * 3 + (cellIdx % 3);
                const cell = board[rowIdx]![colIdx]!;
                const isSelected =
                  selectedCell?.row === rowIdx && selectedCell?.col === colIdx;
                const isHighlighted =
                  !isPaper &&
                  selectedCell !== null &&
                  (selectedCell.row === rowIdx ||
                    selectedCell.col === colIdx ||
                    (Math.floor(selectedCell.row / 3) ===
                      Math.floor(rowIdx / 3) &&
                      Math.floor(selectedCell.col / 3) ===
                        Math.floor(colIdx / 3)));
                const isSameNumber =
                  !isPaper &&
                  !isSelected &&
                  selectedValue !== null &&
                  cell.value !== null &&
                  cell.value === selectedValue;
                const isConflict = conflicts.has(cellKey(rowIdx, colIdx));
                const isMultiSelected =
                  !isSelected &&
                  (selectedCells?.size ?? 0) > 1 &&
                  (selectedCells?.has(cellKey(rowIdx, colIdx)) ?? false);
                const isHintRelated =
                  !isSelected &&
                  (hintCells?.has(cellKey(rowIdx, colIdx)) ?? false);
                const isSameNumberRowCol =
                  matchRowColSet !== null &&
                  !isSelected &&
                  !isSameNumber &&
                  (matchRowColSet.rows.has(rowIdx) ||
                    matchRowColSet.cols.has(colIdx) ||
                    matchRowColSet.boxes.has(
                      Math.floor(rowIdx / 3) * 3 + Math.floor(colIdx / 3),
                    ));

                // Drag-related render flags
                const isDragSource =
                  dragState?.source.kind === "cell" &&
                  dragState.source.row === rowIdx &&
                  dragState.source.col === colIdx;
                const isDropTarget =
                  dragState?.target?.row === rowIdx &&
                  dragState?.target?.col === colIdx;
                const dropTargetState =
                  isDropTarget && dragState
                    ? dragState.invalidTarget
                      ? "invalid"
                      : "valid"
                    : null;
                const dropMode =
                  dropTargetState === "valid" ? dragState?.mode : undefined;
                const dropDigit =
                  dropTargetState === "valid" ? dragState?.digit : undefined;

                return (
                  <Cell
                    key={cellKey(rowIdx, colIdx)}
                    cell={cell}
                    row={rowIdx}
                    col={colIdx}
                    isSelected={isSelected}
                    isMultiSelected={isMultiSelected}
                    isHighlighted={isHighlighted && !isSelected}
                    isSameNumber={isSameNumber}
                    isConflict={isConflict}
                    isHintRelated={isHintRelated}
                    isSameNumberRowCol={isSameNumberRowCol}
                    assistLevel={assistLevel}
                    onSelect={onSelectCell}
                    revealDelay={
                      animateReveal && cell.isGiven
                        ? (rowIdx * 9 + colIdx) * 6
                        : undefined
                    }
                    chargingDigit={
                      isSelected && chargingDigit != null
                        ? chargingDigit
                        : undefined
                    }
                    isDragSource={isDragSource}
                    dropTargetState={dropTargetState}
                    dropMode={dropMode}
                    dropDigit={dropDigit}
                  />
                );
              })}
            </div>
          );
        })}
      </div>
    </div>
  );
}
