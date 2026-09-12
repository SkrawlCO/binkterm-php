/**
 * Spatial layout for the four "flat tableau" solitaire games: Klondike,
 * FreeCell, Golf, TriPeaks. Spider and Pyramid get their own renderers
 * (spider.ts, pyramid.ts) because their shapes genuinely differ (Spider's
 * columns run far deeper than fit one screen; Pyramid is a triangle, not
 * columns). All fields read below are the games' own canonical state field
 * names (see each vendor game package's src/state.ts) — nothing is invented.
 */
import { pick, rule, cardCell, dim } from './common';

function column(ids: readonly (string | null)[]): string {
  return ids.map((id) => (id === null ? dim(' . ') : cardCell(id))).join(' ');
}

export function renderTableauSolitaire(gameId: string, state: unknown): string[] {
  const lines: string[] = [];
  const stage = pick<string>(state, ['stage']);
  const moves = pick<number>(state, ['moves']) ?? 0;
  const recycles = pick<number>(state, ['recycles']);
  const stock = pick<readonly string[]>(state, ['stock']) ?? [];
  const waste = pick<readonly string[]>(state, ['waste', 'hole']) ?? [];
  const cells = pick<readonly (string | null)[]>(state, ['cells']); // FreeCell only

  lines.push(
    `stage: ${stage ?? 'playing'}   moves: ${moves}` + (recycles !== undefined ? `   recycles: ${recycles}` : ''),
  );
  lines.push('');

  // Stock / waste / free-cells / foundations header row
  const stockCell = stock.length > 0 ? cardCell('??') : dim(' . ');
  const wasteCell = waste.length > 0 ? cardCell(waste[waste.length - 1]!) : dim(' . ');
  let header = `stock [${stock.length.toString().padStart(2, ' ')}] ${stockCell}    waste ${wasteCell}`;
  if (cells) {
    header += `    cells ${cells.map((c) => (c ? cardCell(c) : dim(' . '))).join(' ')}`;
  }
  lines.push(header);

  const foundations = pick<Record<string, readonly string[]> | readonly (readonly string[])[]>(state, [
    'foundations',
  ]);
  if (foundations) {
    const entries = Array.isArray(foundations)
      ? foundations.map((f, i) => [String(i), f] as const)
      : Object.entries(foundations);
    const cellsStr = entries
      .map(([key, pile]) => (pile.length > 0 ? cardCell(pile[pile.length - 1]!) : `${dim('[' + key + ']')}`))
      .join(' ');
    lines.push(`foundations: ${cellsStr}`);
  }
  lines.push('');
  lines.push(rule('tableau'));

  const tableau = pick<readonly unknown[]>(state, ['tableau']);
  if (tableau && tableau.length > 0 && (tableau[0] === null || typeof tableau[0] === 'string')) {
    // TriPeaks: one flat 18-slot array of nullable card ids (no sub-columns).
    // Printed in 3 rows of 6 as a stable, always-visible reading order —
    // still the same 18 slots the canonical `from` index in each move refers
    // to, just wrapped for 80 columns instead of one unreadable long line.
    const flat = tableau as readonly (string | null)[];
    for (let row = 0; row < flat.length; row += 6) {
      const slice = flat.slice(row, row + 6);
      const withIdx = slice.map((id, j) => `${row + j}:${id === null ? '  .' : column([id])}`);
      lines.push(withIdx.join('  '));
    }
  } else if (tableau) {
    // Either CardId[][] columns (Golf) or {down,up} columns (Klondike/FreeCell
    // is actually CardId[][] too; Spider/Klondike are {down,up}).
    tableau.forEach((col, i) => {
      if (Array.isArray(col)) {
        lines.push(`${i}: ${column(col as string[])}`);
      } else if (col && typeof col === 'object' && 'up' in (col as object)) {
        const c = col as { down: readonly string[]; up: readonly string[] };
        const down = c.down.map(() => '??');
        lines.push(`${i}: ${column([...down, ...c.up])}`);
      }
    });
  }

  lines.push('');
  return lines;
}
