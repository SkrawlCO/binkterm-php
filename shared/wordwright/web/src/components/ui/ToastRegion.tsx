import type { Toast } from '@/state';

export interface ToastRegionProps {
  readonly toasts: readonly Toast[];
  readonly onDismiss: (id: string) => void;
}

/**
 * Toast stack plus the app's live regions (FR-55, A11Y-4).
 *
 * Two regions, because urgency differs: errors interrupt (`assertive`), while
 * ordinary notices wait their turn (`polite`). Both are always mounted —
 * screen readers only announce changes to a region that already exists.
 */
export function ToastRegion({ toasts, onDismiss }: ToastRegionProps): React.JSX.Element {
  const errors = toasts.filter((toast) => toast.severity === 'error');
  const notices = toasts.filter((toast) => toast.severity !== 'error');

  return (
    <>
      <div
        // Fixed and non-interactive except for the buttons, so a toast can
        // never block a tap on the board (FR-55).
        className="pointer-events-none fixed inset-x-0 top-4 z-50 flex flex-col items-center gap-2 px-4"
      >
        {toasts.map((toast) => (
          <button
            key={toast.id}
            type="button"
            onClick={() => {
              onDismiss(toast.id);
            }}
            className="pointer-events-auto max-w-sm animate-fade-in rounded bg-toast px-4 py-2 text-sm font-semibold text-toast-text shadow-lg"
          >
            {toast.message}
            <span className="sr-only"> (dismiss)</span>
          </button>
        ))}
      </div>

      <div role="status" aria-live="polite" className="sr-only">
        {notices.map((toast) => toast.message).join('. ')}
      </div>
      <div role="alert" aria-live="assertive" className="sr-only">
        {errors.map((toast) => toast.message).join('. ')}
      </div>
    </>
  );
}
