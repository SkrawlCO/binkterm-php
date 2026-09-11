import {
  type PointerEvent as ReactPointerEvent,
  useCallback,
  useEffect,
  useRef,
  useState,
} from "react";
import { haptics } from "../lib/haptics.ts";
import {
  classifyPan,
  digitButtonAt,
  HOLD_MS,
  skimDigitOf,
  skimHandoffDigit,
} from "../lib/numpad-gesture.ts";
import type { NumPadGesturePoint, NumPadPosition } from "../lib/types.ts";

type Options = {
  /** Which edge of the screen the numpad sits on; sets the pad's axis. */
  position: NumPadPosition;
  // An absent onSkim/onDrag means that gesture is not recognized at all:
  // every classified pan then resolves to whichever one IS wired.
  /** A quick tap — pointerup before the hold threshold, or an AT click. */
  onTap: (n: number) => void;
  /** Fires after HOLD_MS while still pressed. */
  onHold?: ((n: number) => void) | undefined;
  /** An along-axis skim crossed onto `n`. */
  onSkim?: ((n: number) => void) | undefined;
  /** The gesture left the pad toward the board, carrying a digit. */
  onDrag?: ((point: NumPadGesturePoint) => void) | undefined;
  /** The gesture is over: pointerup/cancel/leave, or a drag handoff. */
  onEnd?: (() => void) | undefined;
};

/** Pointer handlers one digit button must spread onto itself. */
export type NumPadKeyProps = {
  onPointerDown: (e: ReactPointerEvent<HTMLButtonElement>) => void;
  onPointerMove: (e: ReactPointerEvent<HTMLButtonElement>) => void;
  onPointerUp: () => void;
  onPointerLeave: () => void;
  onPointerCancel: () => void;
  onClick: () => void;
};

/**
 * The numpad's gesture recognizer: resolves one press into a tap, a
 * hold, an along-axis skim, or a drag toward the board, and fires the
 * matching callback exactly once per gesture.
 *
 * It owns the whole lifetime of a press, including the parts that
 * outlive the pressed button: a skim leaves the button's bounds, so
 * document-level listeners follow the finger across the row. Sliding off
 * the row toward the board promotes the skim into a drag;
 * `resumeFromDrag` demotes a returning drag back into a skim.
 *
 * Caller contract: spread `keyProps(n)` on digit button `n`, marked
 * `data-numpad-digit={n}` (skim tracking hit-tests that attribute and
 * skips `disabled` buttons); attach `groupRef` to the wrapper around the
 * digit buttons, or the skim-to-drag handoff never fires (its
 * board-facing edge is the promotion boundary); render `pressedDigit` as
 * the press visual — it follows the finger across skim transitions, and
 * is null while a drag carries the digit.
 *
 * Callbacks are read at event time, so they need not be stable; every
 * returned function is.
 */
export function useNumPadGesture({
  position,
  onTap,
  onHold,
  onSkim,
  onDrag,
  onEnd,
}: Options) {
  // Press visual — not CSS :active, which sticks after capture release.
  const [pressedDigit, setPressedDigit] = useState<number | null>(null);
  // The digit-button row; its board-facing edge promotes a skim to a drag.
  const groupRef = useRef<HTMLDivElement>(null);

  const pressRef = useRef<{
    digit: number;
    timer: ReturnType<typeof setTimeout> | null;
    originX: number;
    originY: number;
    pointerId: number;
    button: HTMLButtonElement;
    // No "drag": the handoff forgets the press, so a live one is either
    // still unclassified or skimming.
    gestureMode: "none" | "skim";
    holdFired: boolean;
    /** Whether the press owns the pointer — see beginPress. */
    captured: boolean;
  } | null>(null);
  // Suppresses the synthetic click after pointerup, so onTap fires once.
  const pointerFiredRef = useRef(false);

  // Active skim gesture id; the effect below attaches doc listeners.
  const [skimPointerId, setSkimPointerId] = useState<number | null>(null);
  // Last digit the finger was over while skimming, seeded to the pressed
  // digit so onSkim doesn't re-fire for the digit it started on.
  const skimDigitRef = useRef<number | null>(null);
  // pointerType of the active skim, needed to hand the gesture to a drag.
  const skimPointerTypeRef = useRef<string>("touch");

  // Latest props, so listeners never go stale and every export is stable.
  const cbRef = useRef({ position, onTap, onHold, onSkim, onDrag, onEnd });
  cbRef.current = { position, onTap, onHold, onSkim, onDrag, onEnd };

  const cancelTimer = useCallback(() => {
    if (pressRef.current?.timer) {
      clearTimeout(pressRef.current.timer);
      pressRef.current.timer = null;
    }
  }, []);

  /** Arms document-level skim tracking for `digit` under `pointerId`. */
  const beginSkim = useCallback(
    (digit: number, pointerId: number, pointerType: string) => {
      skimDigitRef.current = digit;
      skimPointerTypeRef.current = pointerType;
      setSkimPointerId(pointerId);
    },
    [],
  );

  const beginPress = useCallback(
    (n: number, e: ReactPointerEvent<HTMLButtonElement>) => {
      if (e.pointerType === "mouse" && e.button !== 0) return;
      cancelTimer();
      pointerFiredRef.current = true;
      setPressedDigit(n);
      const btn = e.currentTarget;
      // Claim the pointer for the whole unclassified phase. A mouse gets
      // no implicit capture, so a press landing near a key's edge would
      // otherwise cross the boundary within the slop, and the button's
      // pointerleave would cancel a gesture that had not been read yet.
      // Classification hands the pointer back so document-level tracking
      // can follow the gesture across the pad.
      let captured = false;
      if (typeof btn.setPointerCapture === "function") {
        try {
          btn.setPointerCapture(e.pointerId);
          captured = true;
        } catch {
          // ignore — the pointer may already be gone
        }
      }
      const originX = e.clientX;
      const originY = e.clientY;
      const timer = cbRef.current.onHold
        ? setTimeout(() => {
            const press = pressRef.current;
            if (press) {
              press.timer = null;
              press.holdFired = true;
            }
            haptics.tap();
            cbRef.current.onHold?.(n);
          }, HOLD_MS)
        : null;
      pressRef.current = {
        digit: n,
        timer,
        originX,
        originY,
        pointerId: e.pointerId,
        button: btn,
        gestureMode: "none",
        holdFired: false,
        captured,
      };
    },
    [cancelTimer],
  );

  const handlePointerMove = useCallback(
    (e: ReactPointerEvent<HTMLButtonElement>) => {
      const press = pressRef.current;
      if (!press || press.gestureMode !== "none") return;
      if (e.pointerId !== press.pointerId) return;
      const mode = classifyPan({
        dx: e.clientX - press.originX,
        dy: e.clientY - press.originY,
        position: cbRef.current.position,
        skimEnabled: cbRef.current.onSkim !== undefined,
        dragEnabled: cbRef.current.onDrag !== undefined,
      });
      if (mode === null) return;

      cancelTimer();
      // pointerFiredRef stays set: a gesture released back over the key
      // it started on still ends with a browser click on that key, and
      // that click is the tail of THIS gesture, not an AT activation —
      // handleClick must swallow it rather than tap.
      // Release pointer capture so document-level listeners can see moves
      // outside this button.
      try {
        press.button.releasePointerCapture(e.pointerId);
      } catch {
        // ignore — some browsers don't capture by default
      }
      haptics.tap();

      if (mode === "drag") {
        // The drag layer owns the gesture from here, and the press is
        // over. Forgetting it now — the same thing endPress does for a
        // skim — keeps the release the button still receives from
        // ending a press that has already ended.
        pressRef.current = null;
        // The drag ghost now shows the digit; the key stops claiming it.
        setPressedDigit(null);
        cbRef.current.onDrag?.({
          digit: press.digit,
          x: e.clientX,
          y: e.clientY,
          pointerId: e.pointerId,
          pointerType: e.pointerType,
        });
        cbRef.current.onEnd?.();
      } else {
        press.gestureMode = "skim";
        beginSkim(press.digit, e.pointerId, e.pointerType);
      }
    },
    [beginSkim, cancelTimer],
  );

  // End-of-press cleanup. `commit` is true only for pointerup: a release
  // that never became a hold, drag, or skim commits the tapped value; a
  // pointerleave/cancel is a cancel. A skim's end is owned by the
  // document listeners below, so we only detach button state here.
  const endPress = useCallback(
    (commit: boolean) => {
      const press = pressRef.current;
      if (!press) return;
      if (press.gestureMode === "skim") {
        pressRef.current = null;
        return;
      }
      cancelTimer();
      if (commit && !press.holdFired && press.gestureMode === "none") {
        cbRef.current.onTap(press.digit);
      }
      pressRef.current = null;
      setPressedDigit(null);
      cbRef.current.onEnd?.();
    },
    [cancelTimer],
  );

  // A capturing browser cannot send this while the press is still
  // unclassified — every move keeps targeting the pressed button until
  // classification releases the pointer. Ignoring it states that
  // invariant; where capture is unavailable the leave still cancels.
  const handlePointerLeave = useCallback(() => {
    const press = pressRef.current;
    if (press?.captured && press.gestureMode === "none") return;
    endPress(false);
  }, [endPress]);

  const handleClick = useCallback((n: number) => {
    if (pointerFiredRef.current) {
      pointerFiredRef.current = false;
      return;
    }
    // Keyboard/AT activation: no pointer events fired, so honor click.
    cbRef.current.onTap(n);
  }, []);

  const keyProps = useCallback(
    (n: number): NumPadKeyProps => ({
      onPointerDown: (e) => beginPress(n, e),
      onPointerMove: handlePointerMove,
      onPointerUp: () => endPress(true),
      onPointerLeave: handlePointerLeave,
      onPointerCancel: () => endPress(false),
      onClick: () => handleClick(n),
    }),
    [beginPress, handlePointerMove, endPress, handlePointerLeave, handleClick],
  );

  /**
   * Folds a gesture that left as a drag, and has been brought back over
   * the digits, into a live skim: highlights `digit`, restores the press
   * visual, and re-arms document-level tracking under the same pointer.
   */
  const resumeFromDrag = useCallback(
    ({
      digit,
      pointerId,
      pointerType,
    }: Omit<NumPadGesturePoint, "x" | "y">) => {
      setPressedDigit(digit);
      cbRef.current.onSkim?.(digit);
      haptics.tap();
      beginSkim(digit, pointerId, pointerType);
    },
    [beginSkim],
  );

  useEffect(() => {
    if (skimPointerId === null) return;
    const ownPointerId = skimPointerId;

    const onMove = (e: PointerEvent) => {
      if (e.pointerId !== ownPointerId) return;
      e.preventDefault();
      const btn = digitButtonAt(e.clientX, e.clientY);

      // Off the digit row toward the board: promote the skim into a
      // drag, so one continuous gesture picks a digit and drops it.
      const handoff = skimHandoffDigit(
        btn !== null,
        groupRef.current,
        skimDigitRef.current,
        cbRef.current.position,
        e.clientX,
        e.clientY,
      );
      if (handoff !== null && cbRef.current.onDrag) {
        setSkimPointerId(null);
        skimDigitRef.current = null;
        setPressedDigit(null);
        haptics.tap();
        cbRef.current.onDrag({
          digit: handoff,
          x: e.clientX,
          y: e.clientY,
          pointerId: ownPointerId,
          pointerType: skimPointerTypeRef.current,
        });
        cbRef.current.onEnd?.();
        return;
      }

      const digit = skimDigitOf(btn);
      if (digit === null || digit === skimDigitRef.current) return;
      skimDigitRef.current = digit;
      setPressedDigit(digit);
      haptics.light();
      cbRef.current.onSkim?.(digit);
    };

    const end = (e: PointerEvent) => {
      if (e.pointerId !== ownPointerId) return;
      setSkimPointerId(null);
      skimDigitRef.current = null;
      setPressedDigit(null);
      cbRef.current.onEnd?.();
    };

    document.addEventListener("pointermove", onMove, { passive: false });
    document.addEventListener("pointerup", end);
    document.addEventListener("pointercancel", end);
    return () => {
      document.removeEventListener("pointermove", onMove);
      document.removeEventListener("pointerup", end);
      document.removeEventListener("pointercancel", end);
    };
  }, [skimPointerId]);

  return { keyProps, groupRef, pressedDigit, resumeFromDrag };
}
