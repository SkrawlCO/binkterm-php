import type { DigitDragState } from "../hooks/useDigitDrag.ts";

const CHIP_SIZE_PX = 17;

type Props = {
  state: DigitDragState | null;
};

/**
 * The dragged digit, rendered as a small chip that tracks the pointer
 * in real-time. On touch it's lifted well clear of the fingertip (so
 * the finger doesn't occlude the cell being aimed at); on mouse/pen it
 * sits right at the cursor.
 *
 * The chip is a transit cursor, not a preview. Once the pointer is
 * over a valid cell, the chip dims to a quiet position marker and
 * sheds its digit — the cell draws its own landing preview, animating
 * the digit toward the note or value slot. The digit decouples from
 * the chip so it is never shown in two places at once. The chip turns
 * red (digit kept) over a cell that can't accept the digit, since no
 * cell preview leads there.
 */
export function DigitDragIndicator({ state }: Props) {
  if (!state) return null;

  const isInvalid = state.target !== null && state.invalidTarget;
  const isOverValid = state.target !== null && !state.invalidTarget;
  const pose: "free" | "invalid" | "dimmed" = isInvalid
    ? "invalid"
    : isOverValid
      ? "dimmed"
      : "free";

  return (
    <div
      data-testid="digit-drag-indicator"
      data-pose={pose}
      aria-hidden="true"
      className={`fixed z-50 pointer-events-none select-none flex items-center justify-center font-bold rounded-md shadow-lg ${
        isInvalid
          ? "bg-cell-conflict-bg text-cell-conflict shadow-cell-conflict/40"
          : "bg-accent text-text-on-accent shadow-accent/40"
      }`}
      style={{
        left: state.x,
        top: state.y - state.lift,
        width: CHIP_SIZE_PX,
        height: CHIP_SIZE_PX,
        fontSize: CHIP_SIZE_PX * 0.62,
        opacity: pose === "dimmed" ? 0.15 : 1,
        transform: "translate(-50%, -50%)",
        transition:
          "opacity 0.15s ease, background-color 0.12s ease, color 0.12s ease",
        lineHeight: 1,
      }}
    >
      <span
        style={{
          opacity: pose === "dimmed" ? 0 : 1,
          transition: "opacity 0.12s ease",
        }}
      >
        {state.digit}
      </span>
    </div>
  );
}
