import { Button } from '../ui/Button';

/** Dictionary chunk failed to load (EC-14). Always offers a way forward. */
export function DictionaryError({ onRetry }: { onRetry: () => void }): React.JSX.Element {
  return (
    <div
      role="alert"
      className="flex flex-col items-center gap-3 rounded-lg bg-surface-raised px-6 py-8 text-center"
    >
      <p className="font-semibold">Couldn&apos;t load the word list.</p>
      <p className="max-w-xs text-sm text-text-muted">
        The game needs its dictionary before you can play. Check your connection and try again.
      </p>
      <Button onClick={onRetry}>Retry</Button>
    </div>
  );
}
