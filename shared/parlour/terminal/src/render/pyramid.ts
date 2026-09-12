/**
 * Pyramid's own triangular layout — 7 rows, row r holding r+1 cells
 * (`PyramidState.pyramid: (CardId | null)[][]`, see game-pyramid/src/state.ts).
 * A flat-column renderer cannot represent this shape at all, hence the
 * dedicated file (per the brief's "one reusable shell + family/game
 * presentation adapters" instruction).
 */
import { pick, rule, cardCell, dim } from './common';

export function renderPyramid(state: unknown): string[] {
  const lines: string[] = [];
  const stage = pick<string>(state, ['stage']);
  const moves = pick<number>(state, ['moves']) ?? 0;
  const recycles = pick<number>(state, ['recycles']) ?? 0;
  lines.push(`stage: ${stage ?? 'playing'}   moves: ${moves}   recycles: ${recycles}`);
  lines.push('');
  lines.push(rule('pyramid'));

  const pyramid = pick<readonly (string | null)[][]>(state, ['pyramid']) ?? [];
  const maxRowLen = pyramid.length; // 7 rows -> widest row has 7 cells
  pyramid.forEach((row, r) => {
    const pad = ' '.repeat((maxRowLen - row.length) * 2);
    const cells = row.map((id) => (id === null ? dim(' . ') : cardCell(id))).join(' ');
    lines.push(`${pad}row ${r}: ${cells}`);
  });

  lines.push('');
  const stock = pick<readonly string[]>(state, ['stock']) ?? [];
  const waste = pick<readonly string[]>(state, ['waste']) ?? [];
  const stockCell = stock.length > 0 ? cardCell('??') : dim(' . ');
  const wasteCell = waste.length > 0 ? cardCell(waste[waste.length - 1]!) : dim(' . ');
  lines.push(`stock [${stock.length.toString().padStart(2, ' ')}] ${stockCell}    waste ${wasteCell}`);
  lines.push('');
  return lines;
}
