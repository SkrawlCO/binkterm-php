import { useEffect } from "react";
import type { Position } from "../lib/types.ts";

export const KEYBOARD_SHORTCUTS: { keys: string; label: string }[] = [
  { keys: "1–9", label: "Place number" },
  { keys: "←↑→↓", label: "Move cursor" },
  { keys: "N", label: "Toggle notes" },
  { keys: "Backspace", label: "Erase" },
  { keys: "⌘Z", label: "Undo" },
  { keys: "Esc", label: "Deselect" },
];

type UseKeyboardOptions = {
  selectedCell: Position | null;
  onSelectCell: (row: number, col: number) => void;
  onDeselectCell: () => void;
  onPlaceNumber: (value: number) => void;
  onErase: () => void;
  onUndo: () => void;
  onToggleNotes: () => void;
  enabled: boolean;
};

export function useKeyboard({
  selectedCell,
  onSelectCell,
  onDeselectCell,
  onPlaceNumber,
  onErase,
  onUndo,
  onToggleNotes,
  enabled,
}: UseKeyboardOptions) {
  useEffect(() => {
    if (!enabled) return;

    const handler = (e: KeyboardEvent) => {
      if (e.target instanceof HTMLElement && e.target.closest("input, textarea, select, [contenteditable]")) return;
      // Escape to deselect
      if (e.key === "Escape") {
        e.preventDefault();
        onDeselectCell();
        return;
      }

      // Number keys 1-9
      if (e.key >= "1" && e.key <= "9") {
        e.preventDefault();
        onPlaceNumber(Number(e.key));
        return;
      }

      // Arrow keys for navigation
      if (e.key.startsWith("Arrow") && selectedCell) {
        e.preventDefault();
        const { row, col } = selectedCell;
        switch (e.key) {
          case "ArrowUp":
            onSelectCell(Math.max(0, row - 1), col);
            break;
          case "ArrowDown":
            onSelectCell(Math.min(8, row + 1), col);
            break;
          case "ArrowLeft":
            onSelectCell(row, Math.max(0, col - 1));
            break;
          case "ArrowRight":
            onSelectCell(row, Math.min(8, col + 1));
            break;
        }
        return;
      }

      // If no cell selected and arrow pressed, select center
      if (e.key.startsWith("Arrow") && !selectedCell) {
        e.preventDefault();
        onSelectCell(4, 4);
        return;
      }

      // Delete/Backspace to erase
      if (e.key === "Delete" || e.key === "Backspace") {
        e.preventDefault();
        onErase();
        return;
      }

      // N for notes toggle
      if (e.key === "n" || e.key === "N") {
        e.preventDefault();
        onToggleNotes();
        return;
      }

      // Ctrl+Z / Cmd+Z for undo
      if (e.key === "z" && (e.ctrlKey || e.metaKey)) {
        e.preventDefault();
        onUndo();
        return;
      }
    };

    window.addEventListener("keydown", handler);
    return () => window.removeEventListener("keydown", handler);
  }, [
    selectedCell,
    onSelectCell,
    onDeselectCell,
    onPlaceNumber,
    onErase,
    onUndo,
    onToggleNotes,
    enabled,
  ]);
}
