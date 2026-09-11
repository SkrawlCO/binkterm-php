import { useCallback, useEffect, useMemo, useState, type ReactNode } from 'react';

import type { WordLength } from '@/engine/types';
import {
  DEFAULT_SETTINGS,
  type MotionPreference,
  type Settings,
  type Theme,
} from '@/storage';



import { SettingsContext } from './gameContexts';

export interface SettingsContextValue {
  readonly settings: Settings;
  readonly setTheme: (theme: Theme) => void;
  readonly setColorblind: (enabled: boolean) => void;
  readonly setMotion: (motion: MotionPreference) => void;
  readonly setDefaultWordLength: (length: WordLength) => void;
  /** True when the OS asks for reduced motion or the player has (A11Y-9). */
  readonly prefersReducedMotion: boolean;
  /** The theme actually applied, with `system` already resolved. */
  readonly resolvedTheme: 'light' | 'dark';
}

const DARK_QUERY = '(prefers-color-scheme: dark)';
const MOTION_QUERY = '(prefers-reduced-motion: reduce)';

function matches(query: string): boolean {
  if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') return false;
  return window.matchMedia(query).matches;
}

/** Subscribes to a media query and re-renders on change (EC-17). */
function useMediaQuery(query: string): boolean {
  const [value, setValue] = useState(() => matches(query));

  useEffect(() => {
    if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') return;

    const list = window.matchMedia(query);
    const handler = (event: MediaQueryListEvent): void => {
      setValue(event.matches);
    };

    // The initial value comes from useState's initialiser; setting it again
    // here would be a redundant synchronous render.
    list.addEventListener('change', handler);

    return () => {
      list.removeEventListener('change', handler);
    };
  }, [query]);

  return value;
}

/**
 * One repository for the module, not one per provider instance.
 *
 * It holds no state of its own — it is a typed view over a storage key — so a
 * singleton avoids both re-creating it each render and reading a ref during
 * render, which React 19 rightly flags.
 */


export function SettingsProvider({ children }: { children: ReactNode }): React.JSX.Element {
  const [settings, setSettings] = useState<Settings>(() => ({ ...DEFAULT_SETTINGS }));

  const systemPrefersDark = useMediaQuery(DARK_QUERY);
  const systemPrefersReducedMotion = useMediaQuery(MOTION_QUERY);

  // Another tab changed settings (EC-16).


  const update = useCallback((patch: Partial<Settings>): void => {
    setSettings((previous) => {
      const next = { ...previous, ...patch };

      return next;
    });
  }, []);

  const resolvedTheme: 'light' | 'dark' =
    settings.theme === 'system' ? (systemPrefersDark ? 'dark' : 'light') : settings.theme;

  const prefersReducedMotion =
    settings.motion === 'reduced' || (settings.motion === 'system' && systemPrefersReducedMotion);

  // Apply to <html> so CSS tokens and the reduced-motion overrides pick it up.
  // An inline script in index.html does the same before first paint, which is
  // what prevents a flash of the wrong theme (FR-43).
  useEffect(() => {
    const root = document.documentElement;

    root.classList.toggle('dark', resolvedTheme === 'dark');

    if (settings.colorblind) root.dataset.palette = 'cb';
    else delete root.dataset.palette;

    if (settings.motion === 'system') delete root.dataset.motion;
    else root.dataset.motion = settings.motion;
  }, [resolvedTheme, settings.colorblind, settings.motion]);

  const value = useMemo<SettingsContextValue>(
    () => ({
      settings,
      setTheme: (theme) => {
        update({ theme });
      },
      setColorblind: (colorblind) => {
        update({ colorblind });
      },
      setMotion: (motion) => {
        update({ motion });
      },
      setDefaultWordLength: (defaultWordLength) => {
        update({ defaultWordLength });
      },
      prefersReducedMotion,
      resolvedTheme,
    }),
    [settings, update, prefersReducedMotion, resolvedTheme],
  );

  return <SettingsContext.Provider value={value}>{children}</SettingsContext.Provider>;
}

export { DEFAULT_SETTINGS };
