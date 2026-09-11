import { reviewMemory } from "../lib/review-memory.ts";
import { useCallback, useEffect, useState } from "react";

type Theme = "light" | "dark" | "system";

function getSystemPreference(): boolean {
  return window.matchMedia("(prefers-color-scheme: dark)").matches;
}

// Hex matches --color-bg-primary from src/index.css. Kept in sync manually
// because <meta name="theme-color"> needs a parseable color, and JSDOM's
// getComputedStyle does not convert oklch for us to read at runtime.
const THEME_COLOR_LIGHT = "#fdfbf9";
const THEME_COLOR_DARK = "#0b0906";

function syncThemeColorMeta(theme: Theme, isDark: boolean) {
  // System mode is handled by the media-query <meta> tags in index.html.
  // For manual overrides we add a non-media tag, which takes precedence and
  // keeps the iOS browser chrome aligned with the in-app toggle even when it
  // disagrees with prefers-color-scheme.
  const existing = document.querySelector<HTMLMetaElement>(
    'meta[name="theme-color"]:not([media])',
  );
  if (theme === "system") {
    existing?.remove();
    return;
  }
  const color = isDark ? THEME_COLOR_DARK : THEME_COLOR_LIGHT;
  if (existing) {
    existing.content = color;
    return;
  }
  const meta = document.createElement("meta");
  meta.name = "theme-color";
  meta.content = color;
  document.head.appendChild(meta);
}

export function useDarkMode() {
  const [theme, setThemeState] = useState<Theme>(() => {
    try {
      const stored = reviewMemory.getItem("sudoku_theme") as Theme | null;
      return stored || "system";
    } catch {
      // Storage access blocked — fall back to following the OS.
      return "system";
    }
  });
  // The system preference is React state, not a render-time DOM read:
  // isDark must be correct in the SAME render as a toggle (the effect
  // that applies the class runs after), and a mounted toggle must
  // re-render when the OS theme flips.
  const [systemDark, setSystemDark] = useState(getSystemPreference);

  const isDark = theme === "dark" || (theme === "system" && systemDark);

  useEffect(() => {
    document.documentElement.classList.toggle("dark", isDark);
    syncThemeColorMeta(theme, isDark);
  }, [theme, isDark]);

  useEffect(() => {
    const mq = window.matchMedia("(prefers-color-scheme: dark)");
    const handler = () => setSystemDark(mq.matches);
    mq.addEventListener("change", handler);
    return () => mq.removeEventListener("change", handler);
  }, []);

  const setTheme = useCallback((t: Theme) => {
    try {
      reviewMemory.setItem("sudoku_theme", t);
    } catch {
      // Storage unavailable — the in-session choice still applies.
    }
    setThemeState(t);
  }, []);

  const toggle = useCallback(() => {
    setTheme(isDark ? "light" : "dark");
  }, [setTheme, isDark]);

  return { theme, setTheme, toggle, isDark };
}
