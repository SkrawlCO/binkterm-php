import { DIGITS } from "../lib/constants.ts";

/**
 * Note-mode face of a numpad key: the digit rendered as a small pencil
 * mark sitting in its 3×3 note-grid slot, with the other slots shown
 * as faint dots — a miniature of the note grid a press will write into
 * every selected cell. Shown while a multi-cell selection is armed, so
 * the numpad previews the outcome instead of captioning it.
 */
export function NumPadNotePreview({
  digit,
  accented,
}: {
  digit: number;
  accented: boolean;
}) {
  return (
    <span
      data-note-preview
      aria-hidden="true"
      className="absolute inset-1.5 grid grid-cols-3 grid-rows-3"
    >
      {DIGITS.map((slot) => (
        <span
          key={slot}
          className="flex items-center justify-center text-xs lg:text-sm font-semibold leading-none"
        >
          {slot === digit ? (
            <span
              className={`animate-pop-in ${accented ? "text-text-on-accent" : "text-accent"}`}
            >
              {digit}
            </span>
          ) : (
            <span
              className={`h-[3px] w-[3px] rounded-full ${accented ? "bg-text-on-accent/30" : "bg-text-muted/30"}`}
            />
          )}
        </span>
      ))}
    </span>
  );
}
