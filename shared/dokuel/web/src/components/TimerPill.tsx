import type { ReactNode } from "react";
import { formatTime } from "../lib/format.ts";

type TimerPillProps = {
  seconds: number;
  /** The small line under the clock (progress count, PB, "Paused"). */
  subline: ReactNode;
  /** Remount key for the inner Timer (e.g. per multiplayer game). */
  /** When set, the pill is a button (solo uses it for pause/resume). */
  onClick?: (() => void) | undefined;
  ariaLabel?: string | undefined;
};

/**
 * The header clock pill shared by solo and multiplayer — identical
 * chrome, so its markup lives once. Solo renders it as a pause button;
 * multiplayer as a plain readout.
 */
export function TimerPill({
  seconds,
  subline,
  onClick,
  ariaLabel,
}: TimerPillProps) {
  const inner = (
    <>
      <span className="font-mono text-lg font-bold tabular-nums text-text-primary leading-none">{formatTime(seconds)}</span>
      <span className="text-[0.6875rem] text-text-muted font-mono tabular-nums mt-0.5">
        {subline}
      </span>
    </>
  );
  const pillClass =
    "flex flex-col items-center px-4 py-1.5 rounded-2xl bg-surface border border-border-default shadow-sm";

  if (onClick) {
    return (
      <button
        type="button"
        className={`${pillClass} press-spring-soft touch-manipulation`}
        onClick={onClick}
        aria-label={ariaLabel}
      >
        {inner}
      </button>
    );
  }
  return <div className={pillClass}>{inner}</div>;
}
