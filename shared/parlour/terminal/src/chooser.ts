/**
 * Pure chooser list model: flattens the grouped 23-game catalog into rows
 * (group headers are non-selectable), and moves a selection index up/down,
 * skipping headers, with wraparound. Kept side-effect free so it is
 * directly unit-testable without a TTY.
 */
import { groupedCatalog, type CatalogEntry } from './catalog';

export type ChooserRow = { kind: 'header'; label: string } | { kind: 'game'; entry: CatalogEntry };

export function buildChooserRows(): ChooserRow[] {
  const rows: ChooserRow[] = [];
  for (const { group, entries } of groupedCatalog()) {
    rows.push({ kind: 'header', label: group });
    for (const entry of entries) rows.push({ kind: 'game', entry });
  }
  return rows;
}

export function firstSelectable(rows: readonly ChooserRow[]): number {
  return rows.findIndex((r) => r.kind === 'game');
}

export function moveSelection(rows: readonly ChooserRow[], current: number, delta: 1 | -1): number {
  const n = rows.length;
  if (n === 0) return current;
  let idx = current;
  for (let i = 0; i < n; i++) {
    idx = (idx + delta + n) % n;
    if (rows[idx]!.kind === 'game') return idx;
  }
  return current;
}

/** Paginates rows into windows of `pageSize`, always keeping `selected` visible. */
export function viewportFor(total: number, selected: number, pageSize: number): { start: number; end: number } {
  if (total <= pageSize) return { start: 0, end: total };
  let start = Math.max(0, selected - Math.floor(pageSize / 2));
  start = Math.min(start, total - pageSize);
  return { start, end: start + pageSize };
}
