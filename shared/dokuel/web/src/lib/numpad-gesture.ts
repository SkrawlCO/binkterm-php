import type { NumPadPosition } from "./types.ts";

/**
 * The geometry of a numpad gesture: when a pan has moved far enough to
 * mean something, which gesture it means, and where the pad's
 * board-facing edge is. Pure — the recognizer hook owns the state
 * machine, this file owns the arithmetic.
 */

/** How long a press must be held before it means "pencil a note". */
export const HOLD_MS = 200;

// Pointer must travel this far FROM THE POINTERDOWN POINT before we
// classify the gesture (skim vs. drag). Never measure from the button
// center: an off-center click would start "past the threshold", and a
// few pixels of mouse wobble then misfired a drag/skim.
const SLOP_PX = 12;
// Half-angle of the drag cone — the wedge pointing perpendicular to the
// numpad, toward the board. A pan within this many degrees of that axis
// reads as a drag-to-place; a wider pan reads as an along-axis skim.
// The 60° cone (±30°) keeps a diagonal flick toward a neighbouring
// digit on the skim side rather than misfiring a drag.
const DRAG_CONE_HALF_ANGLE_DEG = 30;
const DRAG_CONE_SLOPE = Math.tan((DRAG_CONE_HALF_ANGLE_DEG * Math.PI) / 180);

/**
 * What a pan of (dx, dy) from the press point means, or null to keep
 * waiting: the pan is still inside the slop, or neither gesture is
 * wired up.
 *
 * A pan aimed within the drag cone — close to perpendicular to the pad,
 * toward the board — is a drag-to-place; a wider pan is an along-axis
 * skim. Whichever gesture the cone picks falls back to the other one
 * when only that other one is available.
 */
export function classifyPan({
  dx,
  dy,
  position,
  skimEnabled,
  dragEnabled,
}: {
  dx: number;
  dy: number;
  position: NumPadPosition;
  skimEnabled: boolean;
  dragEnabled: boolean;
}): "skim" | "drag" | null {
  if (dx * dx + dy * dy < SLOP_PX * SLOP_PX) return null;
  const isVertical = position === "left" || position === "right";
  const along = isVertical ? Math.abs(dy) : Math.abs(dx);
  const perp = isVertical ? Math.abs(dx) : Math.abs(dy);
  const skim = skimEnabled ? "skim" : null;
  const drag = dragEnabled ? "drag" : null;
  return along < perp * DRAG_CONE_SLOPE ? (drag ?? skim) : (skim ?? drag);
}

/**
 * The digit button under a viewport point, or null when the point is
 * off the digit row. Digit buttons are found by their
 * `data-numpad-digit` attribute, so anything the pad renders inside a
 * key (faces, note previews, counts) still resolves to its button.
 */
export function digitButtonAt(x: number, y: number): HTMLButtonElement | null {
  const el = document.elementFromPoint(x, y);
  if (!el) return null;
  return el.closest("[data-numpad-digit]") as HTMLButtonElement | null;
}

/**
 * The digit a skim should move onto for a button the finger is over, or
 * null to leave the skim where it is. Disabled (completed) digits are
 * visually hidden, so flashing them as the finger drifts past would
 * surprise the player.
 */
export function skimDigitOf(button: HTMLButtonElement | null): number | null {
  if (!button || button.disabled) return null;
  const digit = Number(button.dataset.numpadDigit);
  return Number.isNaN(digit) ? null : digit;
}

/**
 * True once the pointer has crossed the numpad's board-facing edge — the
 * top edge for a bottom numpad, the inner side for a left/right one.
 */
function crossedTowardBoard(
  rect: DOMRect,
  position: NumPadPosition,
  x: number,
  y: number,
): boolean {
  if (position === "left") return x >= rect.right;
  if (position === "right") return x <= rect.left;
  return y <= rect.top;
}

/**
 * The digit a skim should hand off to a drag, or null to keep skimming.
 * The handoff fires once the finger slides off the digit row toward the
 * board, carrying whichever digit it last settled on.
 */
export function skimHandoffDigit(
  overButton: boolean,
  group: HTMLElement | null,
  skimDigit: number | null,
  position: NumPadPosition,
  x: number,
  y: number,
): number | null {
  if (overButton || !group || skimDigit === null) return null;
  return crossedTowardBoard(group.getBoundingClientRect(), position, x, y)
    ? skimDigit
    : null;
}
