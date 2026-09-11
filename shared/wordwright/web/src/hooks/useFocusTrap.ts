import { useEffect, type RefObject } from 'react';

const FOCUSABLE = [
  'a[href]',
  'button:not([disabled])',
  'input:not([disabled])',
  'select:not([disabled])',
  'textarea:not([disabled])',
  '[tabindex]:not([tabindex="-1"])',
].join(',');

/**
 * Traps focus inside a container while it is open (FR-57, A11Y-11).
 *
 * On open: focuses the first focusable element. While open: Tab and Shift+Tab
 * cycle within the container. On close: returns focus to whatever was focused
 * before, so a keyboard user is not dumped back at the top of the page.
 */
export function useFocusTrap(ref: RefObject<HTMLElement | null>, isOpen: boolean): void {
  useEffect(() => {
    if (!isOpen) return;

    const container = ref.current;
    if (!container) return;

    const previouslyFocused = document.activeElement as HTMLElement | null;

    /*
     * `offsetParent` is null both for genuinely hidden elements and for
     * anything inside a `position: fixed` ancestor — which describes our own
     * modal — and jsdom reports it as null unconditionally. Checking
     * `hidden`/`display` directly is accurate in both environments.
     */
    const isVisible = (element: HTMLElement): boolean => {
      if (element.hidden) return false;
      if (element.getAttribute('aria-hidden') === 'true') return false;
      const style = globalThis.getComputedStyle(element);
      return style.display !== 'none' && style.visibility !== 'hidden';
    };

    const focusable = (): readonly HTMLElement[] =>
      [...container.querySelectorAll<HTMLElement>(FOCUSABLE)].filter(isVisible);

    focusable()[0]?.focus();

    const handleKeyDown = (event: KeyboardEvent): void => {
      if (event.key !== 'Tab') return;

      const elements = focusable();
      if (elements.length === 0) {
        event.preventDefault();
        return;
      }

      const first = elements[0];
      const last = elements[elements.length - 1];
      const active = document.activeElement;

      if (event.shiftKey && active === first) {
        event.preventDefault();
        last?.focus();
      } else if (!event.shiftKey && active === last) {
        event.preventDefault();
        first?.focus();
      }
    };

    document.addEventListener('keydown', handleKeyDown);

    return () => {
      document.removeEventListener('keydown', handleKeyDown);
      previouslyFocused?.focus();
    };
  }, [ref, isOpen]);
}
