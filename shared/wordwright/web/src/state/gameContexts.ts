import { createContext } from 'react';

import type { GameContextValue } from './GameProvider';
import type { SettingsContextValue } from './SettingsProvider';
import type { StatsContextValue } from './StatsProvider';
import type { ToastContextValue } from './ToastProvider';

/**
 * Context objects, separated from their providers.
 *
 * Two reasons: React Fast Refresh only works when a module exports components
 * exclusively, and keeping the contexts here breaks the import cycle that
 * would otherwise exist between each provider and the shared `useX` hooks.
 */

export const SettingsContext = createContext<SettingsContextValue | null>(null);
export const ToastContext = createContext<ToastContextValue | null>(null);
export const StatsContext = createContext<StatsContextValue | null>(null);
export const GameContext = createContext<GameContextValue | null>(null);
