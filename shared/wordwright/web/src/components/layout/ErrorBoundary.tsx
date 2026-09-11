import { Component, type ErrorInfo, type ReactNode } from 'react';



interface ErrorBoundaryProps {
  readonly children: ReactNode;
}

interface ErrorBoundaryState {
  readonly hasError: boolean;
}

/**
 * Last line of defence against a render crash (EC-21).
 *
 * A class component because React offers no hook equivalent. The recovery
 * screen never shows a stack trace — that is developer-facing noise which
 * only alarms a player — but it does offer the two actions that actually
 * help: reload, and wipe local data in case a bad persisted value is the
 * cause of the crash loop.
 */
export class ErrorBoundary extends Component<ErrorBoundaryProps, ErrorBoundaryState> {
  override state: ErrorBoundaryState = { hasError: false };

  static getDerivedStateFromError(): ErrorBoundaryState {
    return { hasError: true };
  }

  override componentDidCatch(error: Error, info: ErrorInfo): void {
    // No telemetry (ADR-001); the console is the only sink available offline.
    console.error('Wordwright crashed:', error, info.componentStack);
  }

  private readonly handleReload = (): void => {
    window.location.reload();
  };

  override render(): ReactNode {
    if (!this.state.hasError) return this.props.children;

    return (
      <div
        role="alert"
        className="flex min-h-dvh flex-col items-center justify-center gap-4 bg-surface p-6 text-center text-text"
      >
        <h1 className="text-xl font-bold tracking-wide uppercase">Something went wrong</h1>
        <p className="max-w-sm text-sm text-text-muted">
          The game hit an unexpected problem. Reload to start a fresh local review session.
        </p>
        <div className="flex flex-wrap justify-center gap-2">
          <button
            type="button"
            onClick={this.handleReload}
            className="inline-flex min-h-11 items-center rounded bg-tile-correct px-4 text-sm font-semibold tracking-wide text-tile-text-revealed uppercase"
          >
            Reload
          </button>

        </div>
      </div>
    );
  }
}
