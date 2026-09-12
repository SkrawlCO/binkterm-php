/**
 * Pure numbered-menu logic over canonical `LegalMove[]`: labels each move
 * (moveLabel.ts), and resolves a typed digit string back to the exact move
 * object the engine returned — never a reconstructed/guessed one. This is
 * the terminal's universal "source/destination selection" input model for
 * every non-solitaire game (and, via the same list, undo-safe fallback for
 * the tableau games too).
 */
import type { LegalMove } from '../../vendor/packages/engine/src/index';
import { moveLabel } from './moveLabel';

export interface MenuItem {
  index: number;
  label: string;
  move: LegalMove;
}

export function buildMoveMenu(moves: readonly LegalMove[]): MenuItem[] {
  return moves.map((move, i) => ({ index: i + 1, label: moveLabel(move), move }));
}

/** Resolves digits typed so far (e.g. "12") to a move, or null if not a complete valid pick yet. */
export function resolveMenuPick(items: readonly MenuItem[], digits: string): LegalMove | null {
  if (!/^\d+$/.test(digits)) return null;
  const n = Number(digits);
  const found = items.find((it) => it.index === n);
  return found ? found.move : null;
}

/** True while `digits` could still become a valid pick by typing more (avoids premature reject). */
export function couldStillResolve(items: readonly MenuItem[], digits: string): boolean {
  if (digits === '') return true;
  return items.some((it) => String(it.index).startsWith(digits));
}
