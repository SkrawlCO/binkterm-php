import { ArrowLeft, Settings, X } from "lucide-react";
import {
  type PointerEvent,
  type ReactNode,
  useEffect,
  useRef,
  useState,
} from "react";
import { useDarkMode } from "../hooks/useDarkMode.ts";
import { KEYBOARD_SHORTCUTS } from "../hooks/useKeyboard.ts";
import { getSoundEnabled, setSoundEnabled } from "../lib/sounds.ts";
import type { NumPadPosition } from "../lib/types.ts";
import { DarkModeToggle } from "./DarkModeToggle.tsx";
import { NumPadPositionToggle } from "./NumPadPositionToggle.tsx";
import { SoundToggle } from "./SoundToggle.tsx";

type GameLayoutProps = {
  onBack: () => void;
  timer: ReactNode;
  numPad: ReactNode;
  board: ReactNode;
  controls: ReactNode;
  position: NumPadPosition;
  onPositionChange: (position: NumPadPosition) => void;
  title?: string | undefined;
  headerExtra?: ReactNode | undefined;
  footer?: ReactNode | undefined;
  boardClassName?: string | undefined;
  headerClassName?: string | undefined;
  onDeselectCell?: (() => void) | undefined;
  settingsExtra?: ReactNode | undefined;
};

export function GameLayout({
  onBack,
  timer,
  numPad,
  board,
  controls,
  position,
  onPositionChange,
  title,
  headerExtra,
  footer,
  boardClassName = "",
  headerClassName = "max-w-lg",
  onDeselectCell,
  settingsExtra,
}: GameLayoutProps) {
  const handleBackgroundPointerDown = (e: PointerEvent<HTMLDivElement>) => {
    if (!onDeselectCell) return;
    const target = e.target as HTMLElement;
    if (target.closest("button, [role='region']")) return;
    onDeselectCell();
  };

  return (
    <div
      className="flex flex-col items-center min-h-dvh bg-bg-primary py-4 px-4 animate-screen-enter"
      onPointerDown={handleBackgroundPointerDown}
    >
      {title && (
        <p className="text-sm font-medium text-text-secondary mb-1">{title}</p>
      )}

      {/* Header */}
      <div
        className={`flex items-center justify-between w-full ${headerClassName} mb-4`}
      >
        <button
          type="button"
          className="icon-btn w-10 h-10 touch-manipulation"
          onClick={onBack}
          aria-label="Back"
        >
          <ArrowLeft size={18} aria-hidden="true" />
        </button>
        {timer}
        <SettingsButton
          position={position}
          onPositionChange={onPositionChange}
          extra={settingsExtra}
        />
      </div>

      {headerExtra}

      {/* Main game area — the numpad sits in its chosen position at every
          breakpoint: "bottom" stacks a full-width digit row under the board,
          "left"/"right" place a vertical column beside it. Keeping the layout
          identical on mobile and desktop means dragging a digit toward the
          board is always a perpendicular gesture. */}
      <div
        className={`
          flex gap-3 w-full justify-center flex-1
          ${position === "left" ? "flex-row items-end lg:items-center" : ""}
          ${position === "right" ? "flex-row-reverse items-end lg:items-center" : ""}
          ${position === "bottom" ? "flex-col items-center lg:flex-row lg:items-center lg:justify-center lg:gap-10" : ""}
        `}
      >
        {/* Side numpad (left / right positions) */}
        {position !== "bottom" && numPad}
        <div
          className={`flex flex-col items-center gap-3 lg:max-w-lg ${position === "bottom" ? "w-full lg:w-[32rem] flex-1 lg:flex-none justify-end lg:justify-center" : "flex-1 min-w-0 lg:flex-none lg:w-[32rem]"} ${boardClassName}`}
        >
          <div className="flex flex-col items-center gap-3 w-full">
            {controls}
          </div>
          <div
            style={{
              width:
                position === "bottom"
                  ? "calc(100% + 1rem)"
                  : "calc(100% + 0.5rem)",
            }}
            className={`flex justify-center lg:!w-full lg:mx-0 ${
              position === "bottom"
                ? "-mx-2"
                : position === "left"
                  ? "-mr-2"
                  : "-ml-2"
            }`}
          >
            {board}
          </div>
        </div>
        {/* Bottom numpad — one instance, repositioned by the row's flex
            direction: under the board on mobile (full-width digit row,
            widened to span the edge-to-edge board), beside it at lg+
            where the row turns horizontal and the spec's side-by-side
            desktop layout applies (NumPad renders 3-wide there). */}
        {position === "bottom" && (
          <div className="flex justify-center items-center -mx-2 w-[calc(100%+1rem)] lg:mx-0 lg:w-auto">
            {numPad}
          </div>
        )}
      </div>

      {footer}
    </div>
  );
}

function SettingsButton({
  position,
  onPositionChange,
  extra,
}: {
  position: NumPadPosition;
  onPositionChange: (position: NumPadPosition) => void;
  extra?: ReactNode;
}) {
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);
  const triggerRef = useRef<HTMLButtonElement>(null);
  const darkMode = useDarkMode();
  // Sound was only reachable from the landing screen; mid-game is
  // where players actually decide they want silence.
  const [soundOn, setSoundOn] = useState(getSoundEnabled);

  useEffect(() => {
    if (!open) return;
    function handleClick(e: MouseEvent) {
      if (ref.current && !ref.current.contains(e.target as Node)) {
        setOpen(false);
      }
    }
    // Keyboard users need a way out too; hand focus back to the
    // trigger so it doesn't fall to <body>.
    function handleKey(e: KeyboardEvent) {
      if (e.key !== "Escape") return;
      setOpen(false);
      triggerRef.current?.focus();
    }
    document.addEventListener("pointerdown", handleClick);
    document.addEventListener("keydown", handleKey);
    return () => {
      document.removeEventListener("pointerdown", handleClick);
      document.removeEventListener("keydown", handleKey);
    };
  }, [open]);

  return (
    <div className="relative" ref={ref}>
      <button
        ref={triggerRef}
        type="button"
        className="icon-btn w-10 h-10 touch-manipulation"
        onClick={() => setOpen((v) => !v)}
        aria-label="Settings"
        aria-expanded={open}
        aria-haspopup="dialog"
      >
        <Settings size={18} aria-hidden="true" />
      </button>
      {open && (
        <div className="absolute right-0 top-full mt-2 bg-surface border border-border-default rounded-2xl shadow-xl p-3.5 z-50 animate-fade-in w-72 max-w-[calc(100vw-2rem)]">
          <div className="flex items-center justify-between mb-2">
            <p className="text-xs text-text-muted font-medium">
              Numpad position
            </p>
            <button
              type="button"
              className="w-6 h-6 flex items-center justify-center rounded text-text-muted hover:text-text-primary transition-colors"
              onClick={() => setOpen(false)}
              aria-label="Close settings"
            >
              <X size={14} aria-hidden="true" />
            </button>
          </div>
          <NumPadPositionToggle
            position={position}
            onChange={onPositionChange}
          />
          <div className="mt-3 pt-3 border-t border-border-default flex items-center justify-between">
            <p className="text-xs text-text-muted font-medium">Dark mode</p>
            <DarkModeToggle
              isDark={darkMode.isDark}
              onToggle={darkMode.toggle}
            />
          </div>
          <div className="mt-3 pt-3 border-t border-border-default flex items-center justify-between">
            <p className="text-xs text-text-muted font-medium">Sound</p>
            <SoundToggle
              enabled={soundOn}
              onToggle={() => {
                const next = !soundOn;
                setSoundOn(next);
                setSoundEnabled(next);
              }}
            />
          </div>
          {extra && (
            <div className="mt-3 pt-3 border-t border-border-default">
              {extra}
            </div>
          )}
          <div className="hidden lg:block mt-3 pt-3 border-t border-border-default">
            <p className="text-xs text-text-muted mb-2 font-medium">
              Keyboard shortcuts
            </p>
            <div className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-xs">
              {KEYBOARD_SHORTCUTS.map((s) => (
                <Shortcut key={s.label} keys={s.keys} label={s.label} />
              ))}
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

function Shortcut({ keys, label }: { keys: string; label: string }) {
  return (
    <>
      <kbd className="font-mono text-text-primary bg-bg-raised px-1 rounded text-center">
        {keys}
      </kbd>
      <span className="text-text-muted">{label}</span>
    </>
  );
}
