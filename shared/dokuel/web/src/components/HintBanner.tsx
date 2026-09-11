import type { ActiveHint } from "../lib/types.ts";

type HintBannerProps = {
  hint: ActiveHint;
  onDismiss: () => void;
};

export function HintBanner({ hint, onDismiss }: HintBannerProps) {
  const TECHNIQUE_LABELS: Record<typeof hint.technique, string> = {
    "naked-single": "Naked Single",
    "hidden-single": "Hidden Single",
    "locked-candidates": "Locked Candidates",
    "naked-pair": "Naked Pair",
    "hidden-pair": "Hidden Pair",
    "naked-triple": "Naked Triple",
    "hidden-triple": "Hidden Triple",
    "naked-quad": "Naked Quad",
    "hidden-quad": "Hidden Quad",
    "x-wing": "X-Wing",
    "xy-wing": "XY-Wing",
    swordfish: "Swordfish",
    mistake: "Mistake",
    reveal: "Reveal",
  };
  const techniqueLabel = TECHNIQUE_LABELS[hint.technique];

  return (
    <div className="w-full max-w-lg rounded-lg bg-hint-bg border border-hint-border px-3 py-2 animate-modal-content">
      <div className="flex items-start gap-2">
        <span className="text-lg leading-none mt-0.5" aria-hidden="true">
          💡
        </span>
        <div className="flex-1 min-w-0">
          <span className="text-xs font-semibold text-hint-title uppercase tracking-wide">
            {techniqueLabel}
          </span>
          <p className="text-sm text-hint-text mt-0.5 leading-snug">
            {hint.explanation}
          </p>
        </div>
        <button
          type="button"
          className="text-hint-muted hover:text-hint-title text-lg leading-none p-0.5 -mt-0.5 -mr-0.5"
          onClick={onDismiss}
          aria-label="Dismiss hint"
        >
          ✕
        </button>
      </div>
    </div>
  );
}
