import { useEffect, useState } from "react";

/**
 * A boolean that starts false and flips true after `delayMs` once `active`
 * becomes true. If `active` flips back to false before the timer fires, the
 * timer is cancelled and the flag stays false.
 *
 * Useful for "wait a beat before revealing" animations: the board reveal on
 * mount, the result modal on game completion, the win confetti, etc.
 */
export function useDelayedFlag(active: boolean, delayMs: number): boolean {
  const [flag, setFlag] = useState(false);

  useEffect(() => {
    if (!active) return;
    const id = setTimeout(() => setFlag(true), delayMs);
    return () => clearTimeout(id);
  }, [active, delayMs]);

  return flag;
}
