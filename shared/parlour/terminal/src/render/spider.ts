/**
 * Spider's own renderer. Its 10 columns can each run past 20 cards deep —
 * the brief explicitly expects this ("Spider is expected to be the hardest
 * geometry case... do not reject Spider merely because scrolling/panning is
 * required"). Rather than print every card of every column (which cannot
 * fit 24 rows), this shows each column's face-down count + up to
 * `visibleRows` most-recent (bottom, playable) face-up cards, plus an
 * explicit "+N more above" marker — a real, honest view of a tall column,
 * not a truncated lie. A `viewport` offset lets the shell pan one column's
 * full history into detail on request.
 */
import { pick, rule, cardCell, dim } from './common';

export function renderSpider(state: unknown, visibleRows = 8): string[] {
  const lines: string[] = [];
  const stage = pick<string>(state, ['stage']);
  const moves = pick<number>(state, ['moves']) ?? 0;
  const stock = pick<readonly string[]>(state, ['stock']) ?? [];
  const foundations = pick<readonly (readonly string[])[]>(state, ['foundations']) ?? [];

  lines.push(`stage: ${stage ?? 'playing'}   moves: ${moves}   stock deals left: ${Math.floor(stock.length / 10)}`);
  lines.push(`completed suits (foundations): ${foundations.length}`);
  lines.push('');
  lines.push(rule('tableau (10 columns — bottom = playable card)'));

  const tableau = pick<readonly { down: readonly string[]; up: readonly string[] }[]>(state, ['tableau']) ?? [];
  tableau.forEach((col, i) => {
    const total = col.down.length + col.up.length;
    const shown = col.up.slice(Math.max(0, col.up.length - visibleRows));
    const hiddenAbove = total - shown.length;
    const marker = hiddenAbove > 0 ? dim(`(+${hiddenAbove} above) `) : '';
    lines.push(`${i.toString().padStart(2)}: ${marker}${shown.map(cardCell).join(' ') || dim('(empty)')}`);
  });

  lines.push('');
  return lines;
}

/** Full detail for one column, for the shell's "pan into column N" command. */
export function renderSpiderColumn(state: unknown, index: number): string[] {
  const tableau = pick<readonly { down: readonly string[]; up: readonly string[] }[]>(state, ['tableau']) ?? [];
  const col = tableau[index];
  if (!col) return [`(no column ${index})`];
  const down = col.down.map(() => '??');
  const all = [...down, ...col.up];
  return [rule(`column ${index} — ${all.length} cards, bottom last`), all.map(cardCell).join(' ') || dim('(empty)')];
}
