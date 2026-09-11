import { useEffect, useId, useRef, type ReactNode } from 'react';
import { createPortal } from 'react-dom';

import { useFocusTrap } from '@/hooks/useFocusTrap';

export interface ModalProps {
  readonly isOpen: boolean;
  readonly title: string;
  readonly children: ReactNode;
  readonly onClose: () => void;
}

/**
 * An accessible dialog (FR-57, A11Y-11).
 *
 * Portalled to the body so it escapes any stacking or overflow context,
 * focus-trapped while open, dismissible with Escape or a backdrop click, and
 * labelled by its own heading.
 */
export function Modal({ isOpen, title, children, onClose }: ModalProps): React.JSX.Element | null {
  const panelRef = useRef<HTMLDivElement>(null);
  const titleId = useId();

  useFocusTrap(panelRef, isOpen);

  useEffect(() => {
    if (!isOpen) return;

    const handleKeyDown = (event: KeyboardEvent): void => {
      if (event.key === 'Escape') {
        event.stopPropagation();
        onClose();
      }
    };

    document.addEventListener('keydown', handleKeyDown);
    return () => {
      document.removeEventListener('keydown', handleKeyDown);
    };
  }, [isOpen, onClose]);

  // Prevent the page behind from scrolling while the dialog is up.
  useEffect(() => {
    if (!isOpen) return;

    const previous = document.body.style.overflow;
    document.body.style.overflow = 'hidden';

    return () => {
      document.body.style.overflow = previous;
    };
  }, [isOpen]);

  if (!isOpen) return null;

  return createPortal(
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
      {/*
        A plain div, not a button: the backdrop is a convenience for pointer
        users and must not appear in the tab order or be announced. Escape is
        the keyboard route out, and the close button is inside the panel.
      */}
      <div
        aria-hidden="true"
        onClick={onClose}
        className="absolute inset-0 animate-fade-in bg-overlay"
      />

      <div
        ref={panelRef}
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        className="relative max-h-[85dvh] w-full max-w-md animate-fade-in overflow-y-auto rounded-lg bg-surface p-5 shadow-xl"
      >
        <div className="mb-4 flex items-start justify-between gap-4">
          <h2 id={titleId} className="text-lg font-bold tracking-wide uppercase">
            {title}
          </h2>
          <button
            type="button"
            onClick={onClose}
            aria-label="Close dialog"
            className="-m-2 rounded p-2 text-text-muted hover:text-text"
          >
            <svg
              aria-hidden="true"
              viewBox="0 0 24 24"
              className="size-5"
              fill="none"
              stroke="currentColor"
              strokeWidth="2"
            >
              <path d="M18 6 6 18M6 6l12 12" />
            </svg>
          </button>
        </div>

        {children}
      </div>
    </div>,
    document.body,
  );
}
