import type { AssistLevel } from "../lib/types.ts";
import { useLocalStorage } from "./useLocalStorage.ts";

const STORAGE_KEY = "sudoku_assist_level";

function parseAssistLevel(raw: string): AssistLevel | null {
  return raw === "paper" || raw === "standard" || raw === "full" ? raw : null;
}

export function useAssistLevel() {
  const [level, setLevel] = useLocalStorage<AssistLevel>(
    STORAGE_KEY,
    "standard",
    parseAssistLevel,
  );
  return { level, setLevel };
}
