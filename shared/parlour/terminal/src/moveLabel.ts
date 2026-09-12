/**
 * Turns one canonical `LegalMove` into a short human-readable menu line.
 * Only formats — the move's `id`/`payload` are exactly what
 * `def.flow.legalMoves()` returned; nothing here invents or filters moves.
 */
import type { LegalMove } from '../../vendor/packages/engine/src/index';
import { formatCard, looksLikeCardId } from './cards';

function formatValue(v: unknown): string {
  if (typeof v === 'string') {
    return looksLikeCardId(v) ? formatCard(v) : v;
  }
  if (typeof v === 'number' || typeof v === 'boolean') return String(v);
  if (Array.isArray(v)) return v.map(formatValue).join(',');
  if (v && typeof v === 'object') {
    return Object.entries(v as Record<string, unknown>)
      .map(([k, val]) => `${k}=${formatValue(val)}`)
      .join(' ');
  }
  return String(v);
}

/** e.g. "draw", "play card=10H to=2", "pair a=waste b={row=3 col=1}" */
export function moveLabel(move: LegalMove): string {
  if (move.hint && move.hint.trim().length > 0) return move.hint;
  const id = move.id.replace(/[-.]/g, ' ');
  const payload = move.payload === undefined || move.payload === null ? '' : ` ${formatValue(move.payload)}`;
  return `${id}${payload}`.trim();
}
