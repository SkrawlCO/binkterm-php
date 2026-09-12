/**
 * Shared text-panel helpers reused by every family renderer. Every renderer
 * in this directory reads only the `playerView(state, seat)` projection
 * the canonical engine already produces for that seat (hidden zones already
 * reduced to the `'??'` sentinel) — no renderer decides what a seat is
 * allowed to see; the engine already decided that.
 */
import { formatCardCell, isRed, looksLikeCardId } from '../cards';
import { gray, red, sgr } from '../ansi';

export const WIDTH = 78; // stay inside 80 cols with a 1-col gutter each side

export function rule(label?: string): string {
  if (!label) return gray('─'.repeat(WIDTH));
  const text = ` ${label} `;
  const side = Math.max(0, Math.floor((WIDTH - text.length) / 2));
  return gray('─'.repeat(side) + text + '─'.repeat(WIDTH - side - text.length));
}

/** Card cell coloured red/black by suit; face-down cells render dim. */
export function cardCell(id: string): string {
  if (id === '??') return gray(formatCardCell(id));
  const cell = formatCardCell(id);
  return isRed(id) ? red(cell) : cell;
}

export function renderRow(ids: readonly string[]): string {
  return ids.map(cardCell).join(' ');
}

/** First present field among candidate names, or undefined. */
export function pick<T = unknown>(obj: unknown, names: readonly string[]): T | undefined {
  if (!obj || typeof obj !== 'object') return undefined;
  const rec = obj as Record<string, unknown>;
  for (const name of names) {
    if (name in rec && rec[name] !== undefined) return rec[name] as T;
  }
  return undefined;
}

export function seatLabel(seat: number, humanSeat: number, dealer?: number): string {
  const who = seat === humanSeat ? 'You' : `Bot ${seat}`;
  const mark = seat === dealer ? ' (dealer)' : '';
  return `${who}${mark}`;
}

export function scoreLine(label: string, values: readonly number[] | undefined): string | null {
  if (!values) return null;
  return `${label}: ${values.map((v, i) => `#${i}=${v}`).join('  ')}`;
}

export function suitGlyphLegend(): string {
  return `${red('♥')}${red('♦')} red   ♠♣ black`;
}

export function faceCount(ids: readonly unknown[] | undefined): number {
  return Array.isArray(ids) ? ids.length : 0;
}

export function isCardLike(v: unknown): v is string {
  return typeof v === 'string' && (v === '??' || looksLikeCardId(v));
}

export function dim(s: string): string {
  return sgr(s, 'dim');
}
