/**
 * Composes a full 80x24 frame from a title, table lines, a status message,
 * and a paginated numbered move menu. Enforces the hard frame boundary
 * (<=80 printable columns, <=24 rows) by clamping/truncating rather than
 * ever emitting a wider/taller frame — table overflow degrades to a
 * "+N more" marker instead of corrupting the layout.
 */
import { bold, cyan, gray, clampLine, visibleWidth } from './ansi';
import type { MenuItem } from './moveMenu';

export const COLS = 80;
export const ROWS = 24;

export interface ComposeOptions {
  title: string;
  subtitle?: string;
  tableLines: readonly string[];
  status?: string;
  menu?: readonly MenuItem[];
  menuPage?: number;
  footer: string;
  digits?: string;
}

const MENU_ROWS_PER_PAGE = 6;

export function menuPageCount(menu: readonly MenuItem[] | undefined): number {
  if (!menu || menu.length === 0) return 1;
  return Math.ceil(menu.length / MENU_ROWS_PER_PAGE);
}

export function composeFrame(opts: ComposeOptions): string[] {
  const lines: string[] = [];
  lines.push(clampLine(bold(opts.title), COLS));
  if (opts.subtitle) lines.push(clampLine(gray(opts.subtitle), COLS));
  lines.push(gray('-'.repeat(Math.min(COLS, 78))));

  const menu = opts.menu ?? [];
  const page = opts.menuPage ?? 0;
  const pageStart = page * MENU_ROWS_PER_PAGE;
  const pageItems = menu.slice(pageStart, pageStart + MENU_ROWS_PER_PAGE);
  const pageCount = menuPageCount(menu);

  // Reserve rows: 3 used above, 1 status, pageItems.length menu rows, 1 blank,
  // 1 digit-entry echo, 1 footer = fixed tail; whatever remains goes to the table.
  const tailRows = 1 + pageItems.length + 1 + 1 + 1;
  const tableBudget = Math.max(1, ROWS - lines.length - tailRows);

  const table = opts.tableLines.map((l) => clampLine(l, COLS));
  if (table.length > tableBudget) {
    const shown = table.slice(0, tableBudget - 1);
    lines.push(...shown);
    lines.push(gray(`… +${table.length - shown.length} more line(s) (see [v] full view)`));
  } else {
    lines.push(...table);
    for (let i = table.length; i < tableBudget; i++) lines.push('');
  }

  lines.push(clampLine(opts.status ? cyan(opts.status) : '', COLS));

  for (const item of pageItems) {
    lines.push(clampLine(`  [${item.index}] ${item.label}`, COLS));
  }
  const pagerNote = pageCount > 1 ? `  (menu page ${page + 1}/${pageCount} — [PgDn]/[PgUp])` : '';
  lines.push(clampLine(gray(`> ${opts.digits ?? ''}${pagerNote}`), COLS));
  lines.push(clampLine(gray(opts.footer), COLS));

  // Hard boundary enforcement.
  const clipped = lines.slice(0, ROWS).map((l) => clampLine(l, COLS));
  while (clipped.length < ROWS) clipped.push('');
  return clipped;
}

export function assertFrameBounds(lines: readonly string[]): void {
  if (lines.length > ROWS) throw new Error(`frame has ${lines.length} rows, > ${ROWS}`);
  for (const l of lines) {
    const w = visibleWidth(l);
    if (w > COLS) throw new Error(`frame line exceeds ${COLS} cols (${w}): ${l}`);
  }
}
