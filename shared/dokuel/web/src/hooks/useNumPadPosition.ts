import type { NumPadPosition } from "../lib/types.ts";
import { useLocalStorage } from "./useLocalStorage.ts";

const STORAGE_KEY = "sudoku-numpad-position";

function parseNumPadPosition(raw: string): NumPadPosition | null {
  return raw === "left" || raw === "right" || raw === "bottom" ? raw : null;
}

export function useNumPadPosition() {
  const [position, setPosition] = useLocalStorage<NumPadPosition>(
    STORAGE_KEY,
    "bottom",
    parseNumPadPosition,
  );
  return { position, setPosition };
}
