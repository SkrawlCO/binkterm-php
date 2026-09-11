import { type Ref, useImperativeHandle } from "react";
import { useNumPadGesture } from "../hooks/useNumPadGesture.ts";
import { DIGITS } from "../lib/constants.ts";
import type { NumPadGesturePoint, NumPadPosition } from "../lib/types.ts";
import { NumPadKeyFace, numPadKeyLabel } from "./NumPadKeyFace.tsx";
import { NumPadLegend } from "./NumPadLegend.tsx";

type NumPadProps = {
  position: NumPadPosition;
  remainingCounts: Record<number, number>;
  selectedValue?: number | null | undefined;
  showRemainingCounts?: boolean | undefined;
  disableCompleted?: boolean | undefined;
  /** Fires on a quick tap — pointerup before the hold threshold (commits the value / toggles highlight). */
  onTapNumber: (n: number) => void;
  /** Fires after the hold threshold while still pressed (adds a pencil note). */
  onHoldNumber?: ((n: number) => void) | undefined;
  /** Fires when the press ends (pointerup/cancel/leave or post-drag/skim). */
  onPressEnd?: (() => void) | undefined;
  /**
   * Fires once the finger has slid PERPENDICULAR to the numpad axis,
   * handing control to the parent's drag-and-drop layer.
   */
  onStartDrag?: ((args: NumPadGesturePoint) => void) | undefined;
  /** Fires when an ALONG-axis skim crosses into another digit's button. */
  onSkimDigit?: ((n: number) => void) | undefined;
  /** What a tap currently does; drives the legend and the key faces. */
  tapAction?: "enter" | "note" | undefined;
  /** Imperative handle — see NumPadHandle. */
  ref?: Ref<NumPadHandle> | undefined;
};

/**
 * Imperative surface a parent uses to fold a returning digit drag back
 * into a numpad skim — the reverse of the skim-to-drag handoff.
 */
export type NumPadHandle = {
  /**
   * Resumes a skim for a gesture that began as a drag and has been
   * brought back over the digits. Highlights `digit`, restores the
   * press visual, and re-arms document-level skim tracking under the
   * same pointer.
   */
  resumeSkimFromDrag: (info: {
    digit: number;
    pointerId: number;
    pointerType: string;
  }) => void;
};

export function NumPad({
  position,
  remainingCounts,
  selectedValue,
  showRemainingCounts = true,
  disableCompleted = false,
  onTapNumber,
  onHoldNumber,
  onPressEnd,
  onStartDrag,
  onSkimDigit,
  tapAction,
  ref,
}: NumPadProps) {
  const isVertical = position === "left" || position === "right";

  // One press → tap | hold | skim | drag, plus the pressed-digit visual
  // that follows the finger across skim transitions.
  const { keyProps, groupRef, pressedDigit, resumeFromDrag } = useNumPadGesture(
    {
      position,
      onTap: onTapNumber,
      onHold: onHoldNumber,
      onSkim: onSkimDigit,
      onDrag: onStartDrag,
      onEnd: onPressEnd,
    },
  );

  useImperativeHandle(ref, () => ({ resumeSkimFromDrag: resumeFromDrag }), [
    resumeFromDrag,
  ]);

  return (
    <div
      className={`flex flex-col items-center gap-1 lg:gap-2 ${isVertical ? "w-12 lg:w-16" : "w-full lg:w-auto"}`}
    >
      <NumPadLegend isVertical={isVertical} tapAction={tapAction} />
      <div
        ref={groupRef}
        className={`flex gap-1 lg:gap-1.5 ${isVertical ? "flex-col w-12 lg:w-16" : "flex-row justify-center w-full lg:grid lg:grid-cols-3 lg:w-auto lg:gap-2"}`}
        role="group"
        aria-label="Number pad"
      >
        {DIGITS.map((n) => {
          const remaining = remainingCounts[n];
          const isComplete = remaining === 0;
          const isSelected = selectedValue === n;
          // Press state overrides selectedValue so the visual follows
          // the finger across skim transitions.
          const isAccented =
            pressedDigit !== null ? pressedDigit === n : isSelected;

          return (
            <button
              key={n}
              type="button"
              data-numpad-digit={n}
              disabled={(showRemainingCounts || disableCompleted) && isComplete}
              className={`relative flex flex-col items-center justify-center rounded-xl select-none touch-none font-semibold ${isVertical ? "h-11 short:h-10 w-12 lg:h-14 lg:w-16" : "h-14 flex-1 lg:h-16 lg:w-16 lg:flex-none"} ${(showRemainingCounts || disableCompleted) && isComplete ? "invisible" : "press-spring"} ${isAccented ? "bg-accent text-text-on-accent shadow-md shadow-accent/25" : "bg-surface text-text-primary border border-border-default shadow-sm"}`}
              {...keyProps(n)}
              aria-label={numPadKeyLabel({
                digit: n,
                remaining,
                isSelected,
                showRemainingCounts,
                noteMode: tapAction === "note",
              })}
            >
              <NumPadKeyFace
                digit={n}
                remaining={remaining}
                isComplete={isComplete}
                isAccented={isAccented}
                showRemainingCounts={showRemainingCounts}
                noteMode={tapAction === "note"}
              />
            </button>
          );
        })}
      </div>
    </div>
  );
}
