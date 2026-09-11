import type { NumPadPosition } from "../lib/types.ts";

type NumPadPositionToggleProps = {
  position: NumPadPosition;
  onChange: (position: NumPadPosition) => void;
};

const OPTIONS: { value: NumPadPosition; label: string }[] = [
  { value: "left", label: "←" },
  { value: "bottom", label: "↓" },
  { value: "right", label: "→" },
];

export function NumPadPositionToggle({
  position,
  onChange,
}: NumPadPositionToggleProps) {
  // Radio keyboard pattern: one Tab stop (the checked option), arrows
  // move the selection. Without this, role="radio" buttons are three
  // Tab stops that lie about their semantics.
  const handleKeyDown = (e: React.KeyboardEvent) => {
    const index = OPTIONS.findIndex((o) => o.value === position);
    let nextIndex: number | null = null;
    if (e.key === "ArrowRight" || e.key === "ArrowDown") {
      nextIndex = (index + 1) % OPTIONS.length;
    } else if (e.key === "ArrowLeft" || e.key === "ArrowUp") {
      nextIndex = (index - 1 + OPTIONS.length) % OPTIONS.length;
    }
    if (nextIndex === null) return;
    e.preventDefault();
    onChange(OPTIONS[nextIndex]!.value);
  };

  return (
    <div className="flex items-center gap-1.5" title="Number pad position">
      <span className="text-[10px] font-medium text-text-muted uppercase tracking-wide">
        Pad
      </span>
      <div
        className="flex gap-1 bg-bg-raised rounded-lg p-1"
        role="radiogroup"
        aria-label="Number pad position"
      >
        {OPTIONS.map((opt) => (
          <button
            key={opt.value}
            type="button"
            role="radio"
            aria-checked={position === opt.value}
            tabIndex={position === opt.value ? 0 : -1}
            onKeyDown={handleKeyDown}
            className={`w-8 h-8 rounded-md flex items-center justify-center text-sm transition-all duration-150 select-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:outline-none ${
              position === opt.value
                ? "bg-bg-overlay shadow-sm text-accent font-bold"
                : "text-text-muted"
            }`}
            onClick={() => onChange(opt.value)}
            aria-label={`Pad ${opt.value}`}
          >
            {opt.label}
          </button>
        ))}
      </div>
    </div>
  );
}
