/**
 * Renders a game's own canonical `HowToPlayDoc` (every vendored package
 * ships one — see `def.howToPlay`, e.g. game-wildpile/src/howto.ts). No new
 * rules text is authored here; this only paginates upstream's own content
 * for an 80x24 screen.
 */
import type { HowToPlayDoc } from '../../vendor/packages/engine/src/index';
import { bold, gray } from './ansi';

export function renderHowToPlay(doc: HowToPlayDoc, width = 76): string[] {
  const lines: string[] = [];
  lines.push(bold('How to play'));
  for (const wrapped of wrap(doc.summary, width)) lines.push(wrapped);
  lines.push('');
  lines.push(bold('Objective'));
  for (const wrapped of wrap(doc.objective, width)) lines.push(wrapped);
  for (const section of doc.sections) {
    lines.push('');
    lines.push(bold(section.heading));
    for (const p of section.body ?? []) {
      for (const wrapped of wrap(p, width)) lines.push(wrapped);
    }
    for (const b of section.bullets ?? []) {
      for (const wrapped of wrap(`- ${b.label}: ${b.text}`, width)) lines.push(wrapped);
    }
  }
  lines.push('');
  lines.push(gray('[PgUp/PgDn or b/space] page   [q/Esc] back'));
  return lines;
}

function wrap(text: string, width: number): string[] {
  const words = text.split(/\s+/);
  const out: string[] = [];
  let cur = '';
  for (const w of words) {
    if ((cur + ' ' + w).trim().length > width) {
      out.push(cur.trim());
      cur = w;
    } else {
      cur = (cur + ' ' + w).trim();
    }
  }
  if (cur) out.push(cur);
  return out.length ? out : [''];
}

/** Splits pre-rendered lines into fixed-size pages for a 24-row screen. */
export function paginate(lines: readonly string[], pageSize: number): string[][] {
  const pages: string[][] = [];
  for (let i = 0; i < lines.length; i += pageSize) pages.push(lines.slice(i, i + pageSize));
  return pages.length ? pages : [[]];
}
