import { type PointerEvent, useCallback, useRef } from "react";
import { cellKey } from "../lib/sudoku.ts";
import type { Board as BoardType, Position } from "../lib/types.ts";

// Pointer must travel this far from the press origin before a press on
// a filled cell converts into a digit drag. Sized above the typical
// finger jitter on a tap (iOS uses ~10pt touch slop) so a quick tap with
// a small wobble still registers as a tap, not a drag.
const CELL_DRAG_THRESHOLD_PX = 12;

// Press on a filled cell must also be held at least this long before it
// can convert into a digit drag. Combined with the px threshold, this
// makes a quick tap always a tap — even if the finger swiped — and
// reserves the drag gesture for a deliberate hold-then-drag.
const CELL_DRAG_HOLD_MS = 180;

type Options = {
  board: BoardType;
  selectedCell: Position | null;
  selectedCells: Set<number> | undefined;
  onSetSelectedCells:
    | ((cells: Set<number>, primary: Position) => void)
    | undefined;
  /**
   * Fires the moment a press on a filled cell crosses the drag
   * threshold, handing the gesture to the parent's digit-drag layer.
   * The hook also disables its own multi-select for the remainder of
   * this press.
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
  /**
   * Tap-to-select for touch pointers. The Board blocks iOS Safari's
   * edge back-swipe with a non-passive touchstart preventDefault, which
   * also suppresses iOS's synthesized click — so the Cell's onClick
   * path can't be relied on for touch taps. Selection from pointerup
   * keeps the tap working on iOS without double-firing on desktop
   * (where mouse pointerup is followed by a real click event).
   */
  onSelectCell?: ((row: number, col: number) => void) | undefined;
};

type DragState = {
  startKey: number;
  primaryPos: Position | null;
  cells: Set<number>;
  moved: boolean;
  shiftClick: boolean;
  // Press tracking for filled-cell drag
  originX: number;
  originY: number;
  originTime: number;
  pointerId: number;
  source: { row: number; col: number; value: number } | null;
  cellDragStarted: boolean;
};

function getCellFromPoint(
  x: number,
  y: number,
): { row: number; col: number } | null {
  const el = document.elementFromPoint(x, y);
  if (!el) return null;
  const btn = el.closest("[data-row]") as HTMLElement | null;
  if (!btn) return null;
  const row = Number(btn.dataset.row);
  const col = Number(btn.dataset.col);
  if (Number.isNaN(row) || Number.isNaN(col)) return null;
  return { row, col };
}

export function useDragSelect({
  board,
  selectedCell,
  selectedCells,
  onSetSelectedCells,
  onStartCellDrag,
  onSelectCell,
}: Options) {
  const dragRef = useRef<DragState | null>(null);
  const suppressClickRef = useRef(false);

  const isEmptyCell = useCallback(
    (pos: Position) => board[pos.row]![pos.col]!.value === null,
    [board],
  );

  const onPointerDown = useCallback(
    (e: PointerEvent<HTMLDivElement>) => {
      if (!onSetSelectedCells) return;
      // iOS Safari skips synthesizing a click after a touch that moved
      // significantly — true of every digit drag and multi-cell select.
      // Without resetting here, the suppress flag set by that prior
      // gesture lingers with no click to consume it and silently eats
      // the user's next tap.
      suppressClickRef.current = false;
      const pos = getCellFromPoint(e.clientX, e.clientY);
      if (!pos) return;
      const key = cellKey(pos.row, pos.col);
      const empty = isEmptyCell(pos);
      const cellValue = board[pos.row]![pos.col]!.value;
      const source =
        !empty && cellValue !== null
          ? { row: pos.row, col: pos.col, value: cellValue }
          : null;

      // Shift+click: add to existing selection (only empty cells)
      if (e.shiftKey && selectedCells && selectedCells.size > 0) {
        if (!empty) return;
        const newCells = new Set(selectedCells);
        newCells.add(key);
        const primary = selectedCell ?? pos;
        onSetSelectedCells(newCells, primary);
        dragRef.current = {
          startKey: key,
          primaryPos: pos,
          cells: newCells,
          moved: false,
          shiftClick: true,
          originX: e.clientX,
          originY: e.clientY,
          originTime: Date.now(),
          pointerId: e.pointerId,
          source: null,
          cellDragStarted: false,
        };
        return;
      }

      dragRef.current = {
        startKey: key,
        primaryPos: empty ? pos : null,
        cells: empty ? new Set([key]) : new Set(),
        moved: false,
        shiftClick: false,
        originX: e.clientX,
        originY: e.clientY,
        originTime: Date.now(),
        pointerId: e.pointerId,
        source,
        cellDragStarted: false,
      };
    },
    [onSetSelectedCells, selectedCells, selectedCell, isEmptyCell, board],
  );

  const onPointerMove = useCallback(
    (e: PointerEvent<HTMLDivElement>) => {
      const drag = dragRef.current;
      if (!drag || !onSetSelectedCells) return;
      if (drag.cellDragStarted) return; // handed off to digit-drag layer

      // Filled-cell press: only two intents are possible — tap-to-select
      // or drag-the-digit. Multi-select doesn't apply (the source cell
      // isn't selectable). Activate the digit drag on the first of:
      //   - pointer crossed into a different cell (clear swipe intent)
      //   - held past the hold window AND moved past the slop threshold
      //     (deliberate press-and-drag from rest)
      // Either gate alone would steal taps: a quick tap can wobble past
      // 12px, and a slow hold without drag intent could trigger on any
      // micro-twitch the moment the threshold is met.
      if (drag.source && onStartCellDrag && e.pointerId === drag.pointerId) {
        const pos = getCellFromPoint(e.clientX, e.clientY);
        const crossedCell =
          pos !== null && cellKey(pos.row, pos.col) !== drag.startKey;
        const dx = e.clientX - drag.originX;
        const dy = e.clientY - drag.originY;
        const elapsedMs = Date.now() - drag.originTime;
        const heldAndMoved =
          elapsedMs >= CELL_DRAG_HOLD_MS &&
          dx * dx + dy * dy >= CELL_DRAG_THRESHOLD_PX * CELL_DRAG_THRESHOLD_PX;
        if (crossedCell || heldAndMoved) {
          drag.cellDragStarted = true;
          // Suppress the trailing click so the drag doesn't also leave
          // a stale selection on the source cell.
          suppressClickRef.current = true;
          onStartCellDrag({
            digit: drag.source.value,
            from: { row: drag.source.row, col: drag.source.col },
            x: e.clientX,
            y: e.clientY,
            pointerId: e.pointerId,
            pointerType: e.pointerType,
          });
        }
        return;
      }

      const pos = getCellFromPoint(e.clientX, e.clientY);
      if (!pos) return;
      const key = cellKey(pos.row, pos.col);
      if (key !== drag.startKey) drag.moved = true;
      if (!isEmptyCell(pos)) return;
      if (!drag.cells.has(key)) {
        drag.cells = new Set(drag.cells);
        drag.cells.add(key);
        if (drag.primaryPos === null) drag.primaryPos = pos;
        onSetSelectedCells(drag.cells, drag.primaryPos);
      }
    },
    [onSetSelectedCells, isEmptyCell, onStartCellDrag],
  );

  const onPointerUp = useCallback(
    (e: PointerEvent<HTMLDivElement>) => {
      const drag = dragRef.current;
      if (!drag) return;
      if (drag.cellDragStarted) {
        // The digit-drag layer owns the rest of this gesture.
        dragRef.current = null;
        return;
      }
      if (drag.shiftClick || (drag.moved && drag.cells.size > 1)) {
        suppressClickRef.current = true;
        if (
          drag.moved &&
          drag.cells.size > 1 &&
          drag.primaryPos &&
          onSetSelectedCells
        ) {
          onSetSelectedCells(drag.cells, drag.primaryPos);
        }
      } else if (e.pointerType !== "mouse" && onSelectCell) {
        // Touch and pen (Apple Pencil taps report "pen" but still ride the
        // suppressed-touch path): the Board.s touchstart preventDefault eats
        // the synthesized click that normally fires Cell.onClick — restore
        // tap-to-select here. Desktop mouse still goes through click.
        const pos = getCellFromPoint(e.clientX, e.clientY);
        if (pos) onSelectCell(pos.row, pos.col);
      }
      dragRef.current = null;
    },
    [onSetSelectedCells, onSelectCell],
  );

  const onClickCapture = useCallback((e: React.MouseEvent<HTMLDivElement>) => {
    if (suppressClickRef.current) {
      e.stopPropagation();
      e.preventDefault();
      suppressClickRef.current = false;
    }
  }, []);

  return { onPointerDown, onPointerMove, onPointerUp, onClickCapture };
}
