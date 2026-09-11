import type { ReactNode } from 'react';

/**
 * Visible to screen readers, invisible on screen.
 *
 * The clip-rect technique rather than `display: none`, which would remove the
 * content from the accessibility tree entirely.
 */
export function VisuallyHidden({ children }: { children: ReactNode }): React.JSX.Element {
  return <span className="sr-only">{children}</span>;
}
