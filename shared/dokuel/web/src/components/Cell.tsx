import { type CSSProperties, memo } from "react";
import { DIGITS } from "../lib/constants.ts";
import type { AssistLevel, Cell as CellType } from "../lib/types.ts";

// Note sub-cell positions. The 3×3 sub-grid means digit n lands at
// row = floor((n-1)/3), col = (n-1) % 3. Used by the in-cell drop
// preview so the note ghost sits exactly where the placed note
// would render.
const NOTE_GRID_FRACTION = 100 / 3;

// Distance from the cell center to a note's sub-cell center, as a
// percentage of the cell's own width. Centers of the 3x3 sub-cell grid
// sit at 1/6, 1/2, and 5/6 of the cell width — so the offset from the
// cell center for col 0 / 1 / 2 is -33.33% / 0% / +33.33%. Same for rows.
const NOTE_OFFSETS = ["-33.333%", "0%", "33.333%"] as const;

type CellProps = {
  cell: CellType;
  row: number;
  col: number;
  isSelected: boolean;
  isMultiSelected: boolean;
  isHighlighted: boolean;
  isSameNumber: boolean;
  isConflict: boolean;
  isHintRelated?: boolean | undefined;
  isSameNumberRowCol?: boolean | undefined;
  assistLevel?: AssistLevel | undefined;
  onSelect: (row: number, col: number) => void;
  revealDelay?: number | undefined;
  /**
   * When set, render the digit as an overlay that flourishes a freshly
   * held pencil note into place — landing value-size at the cell center,
   * then collapsing into its note-grid slot.
   */
  chargingDigit?: number | undefined;
  /** True when this cell is the source of an in-flight digit drag. */
  isDragSource?: boolean | undefined;
  /** "valid" or "invalid" while the pointer hovers this cell with a drag. */
  dropTargetState?: "valid" | "invalid" | null | undefined;
  /**
   * Which slot in this cell the drop will land in, when the cell is a
   * valid drop target. Drives which of the two landing previews
   * brightens — the user sees both possible outcomes at once, with
   * the active one emphasized.
   */
  dropMode?: "value" | "note" | undefined;
  /** The digit currently being dragged — drawn in both preview slots. */
  dropDigit?: number | undefined;
};

export const Cell = memo(function Cell({
  cell,
  row,
  col,
  isSelected,
  isMultiSelected,
  isHighlighted,
  isSameNumber,
  isConflict,
  isHintRelated,
  isSameNumberRowCol,
  assistLevel = "standard",
  onSelect,
  revealDelay,
  chargingDigit,
  isDragSource,
  dropTargetState,
  dropMode,
  dropDigit,
}: CellProps) {
  const isPaper = assistLevel === "paper";
  const bgClass =
    isSelected || isMultiSelected
      ? isPaper
        ? "bg-cell-bg"
        : "bg-cell-selected"
      : isConflict
        ? "bg-cell-conflict-bg"
        : isHintRelated
          ? "bg-cell-hint"
          : isSameNumber
            ? "bg-cell-same-number"
            : isHighlighted
              ? "bg-cell-highlight"
              : isSameNumberRowCol
                ? "bg-cell-match-row-col"
                : "bg-cell-bg";

  const textClass = cell.isGiven
    ? "text-cell-given font-bold"
    : isConflict
      ? // Wavy underline: a non-color conflict marker for colorblind
        // players — the red alone is ambiguous next to given digits.
        "text-cell-conflict font-semibold underline decoration-wavy decoration-from-font underline-offset-4"
      : "text-cell-user font-semibold";

  // The accessible name carries every state the colors encode: screen
  // readers hear position, value/emptiness, givenness, conflicts, and
  // pencil notes instead of just "row/column".
  const labelParts = [
    `Cell row ${row + 1} column ${col + 1}`,
    cell.value ? `value ${cell.value}` : "empty",
  ];
  if (cell.isGiven) labelParts.push("given");
  if (isConflict && cell.value) labelParts.push("conflict");
  if (!cell.value && cell.notes.size > 0) {
    labelParts.push(`notes ${[...cell.notes].sort((a, b) => a - b).join(" ")}`);
  }

  return (
    <button
      type="button"
      className={`
					relative flex items-center justify-center
					aspect-square w-full
					${bgClass}
					transition-colors duration-100
					select-none touch-none
					outline-none focus-visible:ring-2 focus-visible:ring-accent
					${isSelected || isMultiSelected ? (isPaper ? "ring-2 ring-accent ring-inset" : "cell-selected-glow") : ""}
					${revealDelay !== undefined ? "animate-cell-reveal" : ""}
				`}
      style={
        revealDelay !== undefined
          ? { animationDelay: `${revealDelay}ms` }
          : undefined
      }
      data-row={row}
      data-col={col}
      data-drag-source={isDragSource ? "true" : undefined}
      data-drop-target={dropTargetState ?? undefined}
      data-drop-mode={
        dropTargetState === "valid" ? (dropMode ?? undefined) : undefined
      }
      onClick={() => onSelect(row, col)}
      aria-label={labelParts.join(", ")}
    >
      {cell.value ? (
        <span
          key={cell.value}
          className={`text-[clamp(1.2578125rem,5.75vw,2.15625rem)] leading-none ${textClass} ${!cell.isGiven ? "animate-pop-in" : ""}`}
        >
          {cell.value}
        </span>
      ) : cell.notes.size > 0 ? (
        <div className="absolute inset-0 grid grid-cols-3 grid-rows-3 p-[1px]">
          {DIGITS.map((n) => (
            <span
              key={n}
              className="flex items-center justify-center text-[clamp(0.80859375rem,3.1625vw,1.078125rem)] text-text-secondary font-medium leading-none"
            >
              {cell.notes.has(n) && chargingDigit !== n ? n : ""}
            </span>
          ))}
        </div>
      ) : null}
      {dropTargetState === "valid" && (
        <span
          data-testid="drop-zone"
          data-mode={dropMode}
          aria-hidden="true"
          className="absolute inset-x-0 h-1/2 bg-accent/15 pointer-events-none animate-drop-preview"
          style={{ top: dropMode === "value" ? "0%" : "50%" }}
        />
      )}
      {dropTargetState === "valid" && dropDigit !== undefined && (
        <span
          data-testid="drop-preview"
          data-mode={dropMode}
          aria-hidden="true"
          className="absolute flex items-center justify-center font-bold leading-none text-accent pointer-events-none animate-drop-preview"
          style={
            dropMode === "value"
              ? {
                  // Value pose: fills the cell, value-sized glyph.
                  left: "0%",
                  top: "0%",
                  width: "100%",
                  height: "100%",
                  fontSize: "clamp(1.2578125rem, 5.75vw, 2.15625rem)",
                }
              : {
                  // Note pose: the digit's own sub-cell, note-sized
                  // glyph. Crossing the cell's midline morphs the
                  // single preview between these two poses.
                  left: `${((dropDigit - 1) % 3) * NOTE_GRID_FRACTION}%`,
                  top: `${Math.floor((dropDigit - 1) / 3) * NOTE_GRID_FRACTION}%`,
                  width: `${NOTE_GRID_FRACTION}%`,
                  height: `${NOTE_GRID_FRACTION}%`,
                  fontSize: "clamp(0.80859375rem, 3.1625vw, 1.078125rem)",
                }
          }
        >
          {dropDigit}
        </span>
      )}
      {cell.value === null && chargingDigit !== undefined && (
        <span
          data-testid="note-charge"
          aria-hidden="true"
          className="absolute inset-0 flex items-center justify-center text-[clamp(1.2578125rem,5.75vw,2.15625rem)] font-semibold text-cell-user leading-none pointer-events-none animate-note-charge"
          style={
            {
              "--charge-dx": NOTE_OFFSETS[(chargingDigit - 1) % 3]!,
              "--charge-dy": NOTE_OFFSETS[Math.floor((chargingDigit - 1) / 3)]!,
            } as CSSProperties
          }
        >
          {chargingDigit}
        </span>
      )}
    </button>
  );
});
