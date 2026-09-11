import type { WordLength } from '@/engine';

import { LengthSelector } from '../game/LengthSelector';

export interface HeaderProps {
  readonly wordLength: WordLength;
  readonly lengthDisabled: boolean;
  readonly onLengthChange: (length: WordLength) => void;
  readonly onOpenStats: () => void;
  readonly onOpenSettings: () => void;
}

function IconButton({
  label,
  onClick,
  children,
}: {
  label: string;
  onClick: () => void;
  children: React.ReactNode;
}): React.JSX.Element {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-label={label}
      className="flex size-11 shrink-0 items-center justify-center rounded text-text-muted transition-colors hover:text-text"
    >
      {children}
    </button>
  );
}

export function Header({
  wordLength,
  lengthDisabled,
  onLengthChange,
  onOpenStats,
  onOpenSettings,
}: HeaderProps): React.JSX.Element {
  return (
    <header className="border-b border-border">
      <div className="mx-auto flex w-full max-w-2xl items-center justify-between gap-1 px-1 py-2 sm:gap-2 sm:px-4">
        {/* Truncates rather than pushing the controls off-screen at 320px (AC-22). */}
        <h1 className="min-w-0 truncate text-sm font-bold tracking-[0.1em] uppercase sm:text-xl sm:tracking-[0.15em]">
          Wordwright
        </h1>

        <div className="flex shrink-0 items-center gap-0.5 sm:gap-1">
          <LengthSelector value={wordLength} disabled={lengthDisabled} onChange={onLengthChange} />

          <IconButton label="Statistics" onClick={onOpenStats}>
            <svg
              aria-hidden="true"
              viewBox="0 0 24 24"
              className="size-5"
              fill="none"
              stroke="currentColor"
              strokeWidth="2"
            >
              <path d="M18 20V10M12 20V4M6 20v-6" strokeLinecap="round" />
            </svg>
          </IconButton>

          <IconButton label="Settings" onClick={onOpenSettings}>
            <svg
              aria-hidden="true"
              viewBox="0 0 24 24"
              className="size-5"
              fill="none"
              stroke="currentColor"
              strokeWidth="2"
            >
              <circle cx="12" cy="12" r="3" />
              <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z" />
            </svg>
          </IconButton>
        </div>
      </div>
    </header>
  );
}
