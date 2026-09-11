import type { Board, Position } from "./types.ts";

/** What the player did to a digit. Widened as gestures are routed. */
export type DigitGesture =
  | { kind: "tap" }
  | { kind: "hold" }
  | {
      kind: "drop";
      mode: "value" | "note";
      target: Position;
      /** The cell the digit was dragged from, or null for the numpad. */
      from: Position | null;
    }
  | { kind: "key"; notesMode: boolean };

/** The selection state the answer depends on. Nothing more is read. */
export type DigitIntentContext = {
  board: Board;
  selectedCell: Position | null;
  selectedCells: Set<number>;
};

/**
 * What lands on the board. `at: null` means "into the current
 * selection" — the engine reads the selection itself, which is what
 * makes the ordering in `after` load-bearing.
 */
export type DigitEffect =
  | { kind: "none" }
  | { kind: "toggleHighlight" }
  | { kind: "value"; at: Position | null }
  | { kind: "note"; at: Position | null };

export type DigitIntent = {
  effect: DigitEffect;
  after: {
    /** Keep the selection, drop it, or move it to a cell. */
    selection: "keep" | "release" | Position;
    /** Spotlight this digit board-wide once the effect has landed. */
    highlight: boolean;
  };
  /** What the numpad legend and key faces say a tap will do. */
  label: "enter" | "note";
};

function hasSelection({ selectedCell, selectedCells }: DigitIntentContext) {
  return selectedCell !== null || selectedCells.size > 0;
}

function intent(
  effect: DigitEffect,
  selection: "keep" | "release" | Position = "keep",
  highlight = false,
): DigitIntent {
  // The legend cannot disagree with the behaviour: both read the effect.
  return { effect, after: { selection, highlight }, label: labelFor(effect) };
}

function labelFor(effect: DigitEffect): "enter" | "note" {
  return effect.kind === "note" ? "note" : "enter";
}

/**
 * The single answer to "what does digit n do right now" — the effect on
 * the board, what happens to the selection and the highlight afterwards,
 * and the label the numpad shows for it.
 *
 * The digit itself is not an input: every rule depends on the gesture
 * and the selection, never on which digit. `applyDigitIntent` supplies
 * the digit when it runs the result.
 */
export function digitIntent(
  gesture: DigitGesture,
  ctx: DigitIntentContext,
): DigitIntent {
  switch (gesture.kind) {
    case "tap":
      return tapIntent(ctx);
    case "hold":
      // Hold is the gesture that KEEPS the selection, for stacking
      // pairs/triples into the same cell or range.
      return hasSelection(ctx)
        ? intent({ kind: "note", at: null })
        : intent({ kind: "none" });
    case "drop":
      return dropIntent(gesture);
    case "key":
      if (!hasSelection(ctx)) return intent({ kind: "none" });
      // A pencilled key releases the selection — but, unlike a range
      // tap, without spotlighting the digit.
      return gesture.notesMode
        ? intent({ kind: "note", at: null }, "release")
        : intent({ kind: "value", at: null });
  }
}

function tapIntent(ctx: DigitIntentContext): DigitIntent {
  if (ctx.selectedCell === null && ctx.selectedCells.size === 0) {
    return intent({ kind: "toggleHighlight" });
  }
  // A range is armed: the only meaningful bulk action is a pencil note.
  // Same semantics as dropping a note from a numpad drag — the note
  // lands, the selection is released, and the board highlights the noted
  // digit, so the NEXT tap toggles another digit's highlight (the
  // scan-the-grid rhythm).
  if (ctx.selectedCells.size > 1) {
    return intent({ kind: "note", at: null }, "release", true);
  }
  const cell = ctx.selectedCell
    ? ctx.board[ctx.selectedCell.row]?.[ctx.selectedCell.col]
    : undefined;
  // A tap cannot overwrite a filled cell, so it is repurposed: drop the
  // selection and spotlight the digit instead.
  if (cell && cell.value !== null) {
    return intent({ kind: "none" }, "release", true);
  }
  return intent({ kind: "value", at: null });
}

function dropIntent(
  gesture: Extract<DigitGesture, { kind: "drop" }>,
): DigitIntent {
  // Selecting the target first is what lets the engine place there.
  if (gesture.mode === "value") {
    return intent({ kind: "value", at: gesture.target });
  }
  // A note drop must not pull the selection onto the drop target — that
  // would yank the board highlight to wherever the note landed.
  // Selection instead follows what was dragged, so pencilling one digit
  // across several cells keeps a stable highlight.
  return gesture.from
    ? intent({ kind: "note", at: gesture.target }, gesture.from)
    : intent({ kind: "note", at: gesture.target }, "release", true);
}

/**
 * The board and selection commands an intent is run against. Split out
 * so the executor can be exercised without a game.
 */
export type DigitIntentOps = {
  /** Place into the CURRENT selection — the engine reads it itself. */
  placeNumber: (value: number, asNote: boolean) => void;
  /** Toggle a note at an explicit cell, whatever is selected. */
  placeNoteAt: (row: number, col: number, value: number) => void;
  selectCell: (row: number, col: number) => void;
  deselectCell: () => void;
  /** Spotlight `digit`, or clear the spotlight if it is already on it. */
  toggleHighlight: (digit: number) => void;
  /** Spotlight `digit` unconditionally. */
  setHighlight: (digit: number) => void;
};

/**
 * Runs an intent for `digit`. The order is the contract: the effect
 * lands first, because placing into "the current selection" means the
 * engine reads the selection — releasing or moving it first would turn
 * the placement into a no-op. A value drop is the mirror case: its
 * target must be selected BEFORE the value is placed, which is why that
 * select lives inside the effect and not in `after`.
 */
export function applyDigitIntent(
  { effect, after }: DigitIntent,
  digit: number,
  ops: DigitIntentOps,
): void {
  switch (effect.kind) {
    case "toggleHighlight":
      ops.toggleHighlight(digit);
      break;
    case "value":
      if (effect.at) ops.selectCell(effect.at.row, effect.at.col);
      ops.placeNumber(digit, false);
      break;
    case "note":
      if (effect.at) ops.placeNoteAt(effect.at.row, effect.at.col, digit);
      else ops.placeNumber(digit, true);
      break;
    case "none":
      break;
  }
  if (after.selection === "release") ops.deselectCell();
  else if (after.selection !== "keep") {
    ops.selectCell(after.selection.row, after.selection.col);
  }
  if (after.highlight) ops.setHighlight(digit);
}
