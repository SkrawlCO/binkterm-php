import { useContext } from 'react';

import type { GameContextValue } from './GameProvider';
import type { SettingsContextValue } from './SettingsProvider';
import type { StatsContextValue } from './StatsProvider';
import type { ToastContextValue } from './ToastProvider';
import { GameContext, SettingsContext, StatsContext, ToastContext } from './gameContexts';

/**
 * Typed context hooks.
 *
 * Each throws rather than returning null, so a component rendered outside its
 * provider fails immediately with a clear message instead of a downstream
 * "cannot read property of null".
 */

export function useSettings(): SettingsContextValue {
  const value = useContext(SettingsContext);
  if (!value) throw new Error('useSettings must be used within a SettingsProvider');
  return value;
}

export function useToast(): ToastContextValue {
  const value = useContext(ToastContext);
  if (!value) throw new Error('useToast must be used within a ToastProvider');
  return value;
}

export function useStats(): StatsContextValue {
  const value = useContext(StatsContext);
  if (!value) throw new Error('useStats must be used within a StatsProvider');
  return value;
}

export function useGame(): GameContextValue {
  const value = useContext(GameContext);
  if (!value) throw new Error('useGame must be used within a GameProvider');
  return value;
}
