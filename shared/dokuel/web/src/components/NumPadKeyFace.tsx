import { NumPadNotePreview } from "./NumPadNotePreview.tsx";

/** Accessible name for a numpad key, matching its current face. */
export function numPadKeyLabel({
  digit,
  remaining,
  isSelected,
  showRemainingCounts,
  noteMode,
}: {
  digit: number;
  remaining: number | undefined;
  isSelected: boolean;
  showRemainingCounts: boolean;
  noteMode: boolean;
}): string {
  if (noteMode) return `${digit}, pencil note`;
  const selected = isSelected ? ", selected" : "";
  return showRemainingCounts
    ? `${digit}, ${remaining} remaining${selected}`
    : `${digit}${selected}`;
}

/**
 * The visible face of one numpad key. Enter-mode shows the full-size
 * digit with its remaining count; note-mode swaps in the pencil-mark
 * preview so the key shows exactly what a press will drop into the
 * selected cells.
 */
export function NumPadKeyFace({
  digit,
  remaining,
  isComplete,
  isAccented,
  showRemainingCounts,
  noteMode,
}: {
  digit: number;
  remaining: number | undefined;
  isComplete: boolean;
  isAccented: boolean;
  showRemainingCounts: boolean;
  noteMode: boolean;
}) {
  if (noteMode) {
    return <NumPadNotePreview digit={digit} accented={isAccented} />;
  }
  return (
    <>
      <span className="text-lg lg:text-2xl leading-none">{digit}</span>
      {showRemainingCounts && (
        <span
          className={`text-[0.625rem] lg:text-xs leading-none mt-0.5 lg:mt-1 ${isComplete ? "invisible" : isAccented ? "text-text-on-accent/70" : "text-text-secondary"}`}
        >
          {remaining}
        </span>
      )}
    </>
  );
}
