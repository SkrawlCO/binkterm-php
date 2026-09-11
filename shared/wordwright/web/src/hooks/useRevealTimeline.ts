import { useEffect, useRef } from 'react';

import { REVEAL_STAGGER_MS } from '@/components/board/Board';

/** Duration of a single tile flip, matching --duration-flip in CSS. */
const FLIP_DURATION_MS = 500;

export interface RevealTimelineOptions {
  /** True while a row is flipping. */
  readonly isRevealing: boolean;
  readonly wordLength: number;
  readonly reducedMotion: boolean;
  readonly onComplete: () => void;
}

/**
 * Fires `onComplete` when the staggered flip finishes (FR-52, A11Y-9).
 *
 * The total duration must match the CSS: the last tile starts at
 * `(n - 1) * stagger` and runs for one flip. Under reduced motion the CSS
 * collapses every animation to ~0ms, so this collapses too — the two must
 * agree or input would unlock before the board looks settled.
 *
 * The timer is cleared on unmount and whenever the reveal is superseded, so a
 * fast restart can never dispatch into a dead tree.
 */
export function useRevealTimeline({
  isRevealing,
  wordLength,
  reducedMotion,
  onComplete,
}: RevealTimelineOptions): void {
  // Held in a ref so a changing callback identity does not restart the timer
  // mid-animation.
  const callbackRef = useRef(onComplete);
  useEffect(() => {
    callbackRef.current = onComplete;
  }, [onComplete]);

  useEffect(() => {
    if (!isRevealing) return;

    const duration = reducedMotion ? 0 : (wordLength - 1) * REVEAL_STAGGER_MS + FLIP_DURATION_MS;

    const timer = setTimeout(() => {
      callbackRef.current();
    }, duration);

    return () => {
      clearTimeout(timer);
    };
  }, [isRevealing, wordLength, reducedMotion]);
}
