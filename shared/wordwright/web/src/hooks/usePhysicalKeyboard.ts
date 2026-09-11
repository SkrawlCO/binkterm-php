import { useEffect, useRef } from 'react';

export interface PhysicalKeyboardHandlers {
  readonly onLetter: (letter: string) => void;
  readonly onBackspace: () => void;
  readonly onEnter: () => void;
  /** True when the active row has no letters yet; see the Enter handling. */
  readonly isRowEmpty: () => boolean;
  /** When true, keystrokes are ignored (modal open, reveal in flight). */
  readonly disabled: boolean;
}

/**
 * Routes physical keystrokes into the game (FR-10, EC-3, EC-4, EC-7).
 *
 * A single window listener rather than a focused input: the board is not a
 * text field, and a hidden input would fight the on-screen keyboard on mobile.
 *
 * Deliberately ignored:
 *   - modifier combos, so Ctrl+R, Cmd+C and friends keep working (EC-4)
 *   - keystrokes while a modal or another text field has focus (EC-7)
 *   - anything that is not a single A-Z letter, Enter or Backspace (EC-3)
 */
export function usePhysicalKeyboard({
  onLetter,
  onBackspace,
  onEnter,
  isRowEmpty,
  disabled,
}: PhysicalKeyboardHandlers): void {
  /*
   * Handlers live in a ref so the window listener is attached once per
   * enabled/disabled transition. Re-attaching on every keystroke would be
   * wasteful, and closing over the first render's state would make
   * `isRowEmpty` permanently stale.
   */
  const handlers = useRef({ onLetter, onBackspace, onEnter, isRowEmpty });

  useEffect(() => {
    handlers.current = { onLetter, onBackspace, onEnter, isRowEmpty };
  }, [onLetter, onBackspace, onEnter, isRowEmpty]);

  useEffect(() => {
    if (disabled) return;

    const handler = (event: KeyboardEvent): void => {
      // Never intercept browser and OS shortcuts (EC-4).
      if (event.metaKey || event.ctrlKey || event.altKey) return;

      // Let a real input, textarea or open dialog have the keystroke (EC-7).
      const target = event.target;
      if (target instanceof HTMLElement) {
        if (target.isContentEditable) return;
        const tag = target.tagName;
        if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
        if (target.closest('[role="dialog"]')) return;
      }

      const { key } = event;

      if (key === 'Enter') {
        /*
         * Enter submits the current guess, except while the player is part-way
         * through typing nothing — in which case a focused control should act.
         *
         * The distinction is the row, not the focus. Closing a modal restores
         * focus to its trigger (FR-57), so keying off "is a button focused"
         * silently broke submission for the rest of the session. Instead, a
         * focused control only wins when the row is empty; once letters are on
         * the board, Enter belongs to the game.
         */
        const controlFocused = target instanceof HTMLElement && target.tagName === 'BUTTON';
        if (controlFocused && handlers.current.isRowEmpty()) return;

        event.preventDefault();
        handlers.current.onEnter();
        return;
      }

      if (key === 'Backspace' || key === 'Delete') {
        event.preventDefault();
        handlers.current.onBackspace();
        return;
      }

      if (/^[a-zA-Z]$/.test(key)) {
        event.preventDefault();
        handlers.current.onLetter(key.toUpperCase());
      }
      // Everything else — digits, punctuation, arrows, function keys — is
      // silently ignored, with no toast (EC-3).
    };

    window.addEventListener('keydown', handler);
    return () => {
      window.removeEventListener('keydown', handler);
    };
  }, [disabled]);
}
