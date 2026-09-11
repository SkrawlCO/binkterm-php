import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';

import { ToastContext } from './gameContexts';

export type ToastSeverity = 'info' | 'error';

export interface Toast {
  readonly id: string;
  readonly message: string;
  readonly severity: ToastSeverity;
}

export interface ToastContextValue {
  readonly toasts: readonly Toast[];
  /** Shows a message; returns its id so a caller can dismiss it early. */
  readonly show: (message: string, severity?: ToastSeverity) => string;
  readonly dismiss: (id: string) => void;
}

/** At most three at once, so the board is never buried (FR-55). */
const MAX_TOASTS = 3;
const INFO_DURATION_MS = 2000;
const ERROR_DURATION_MS = 3000;

export function ToastProvider({ children }: { children: ReactNode }): React.JSX.Element {
  const [toasts, setToasts] = useState<readonly Toast[]>([]);
  const timers = useRef(new Map<string, ReturnType<typeof setTimeout>>());
  const counter = useRef(0);

  const dismiss = useCallback((id: string): void => {
    const timer = timers.current.get(id);
    if (timer) {
      clearTimeout(timer);
      timers.current.delete(id);
    }
    setToasts((current) => current.filter((toast) => toast.id !== id));
  }, []);

  const show = useCallback(
    (message: string, severity: ToastSeverity = 'info'): string => {
      counter.current += 1;
      const id = `toast-${String(counter.current)}`;
      const toast: Toast = { id, message, severity };

      setToasts((current) => {
        // Newest last; drop the oldest once we exceed the cap.
        const next = [...current, toast];
        const overflow = next.slice(0, Math.max(0, next.length - MAX_TOASTS));

        for (const evicted of overflow) {
          const timer = timers.current.get(evicted.id);
          if (timer) {
            clearTimeout(timer);
            timers.current.delete(evicted.id);
          }
        }

        return next.slice(-MAX_TOASTS);
      });

      const duration = severity === 'error' ? ERROR_DURATION_MS : INFO_DURATION_MS;
      timers.current.set(
        id,
        setTimeout(() => {
          dismiss(id);
        }, duration),
      );

      return id;
    },
    [dismiss],
  );

  // Clear every pending timer on unmount so nothing fires into a dead tree.
  useEffect(() => {
    const pending = timers.current;
    return () => {
      for (const timer of pending.values()) clearTimeout(timer);
      pending.clear();
    };
  }, []);

  const value = useMemo<ToastContextValue>(
    () => ({ toasts, show, dismiss }),
    [toasts, show, dismiss],
  );

  return <ToastContext.Provider value={value}>{children}</ToastContext.Provider>;
}
