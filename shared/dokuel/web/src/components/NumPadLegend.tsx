/**
 * The numpad's gesture cheat-sheet: a one-liner above the horizontal
 * pad, stacked words beside a vertical rail. `tapAction` tracks what a
 * tap currently does — with a multi-cell selection active, a tap
 * pencils notes (same as hold), and the legend saying so doubles as
 * feedback that a range is selected.
 */
export function NumPadLegend({
  isVertical,
  tapAction = "enter",
}: {
  isVertical: boolean;
  tapAction?: "enter" | "note" | undefined;
}) {
  const tapWord = tapAction === "note" ? "note" : "enter";
  return (
    <p
      className={`text-[0.625rem] lg:text-xs text-text-muted leading-tight select-none ${isVertical ? "text-center whitespace-pre-line short:hidden" : ""}`}
      aria-hidden="true"
    >
      {isVertical
        ? `tap\n${tapWord}\n· · ·\nhold\nnote\n· · ·\ndrag\nplace`
        : `tap = ${tapWord} · hold = note · drag = place`}
    </p>
  );
}
