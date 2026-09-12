/** Minimal raw-stdin key decoder — no readline/keypress dependency needed. */

export type Key =
  | { name: 'up' | 'down' | 'left' | 'right' | 'enter' | 'esc' | 'backspace' | 'pgup' | 'pgdn' | 'ctrlc' | 'tab' }
  | { name: 'char'; char: string };

/** Decodes one raw stdin chunk into zero or more logical keys. */
export function decodeKeys(chunk: Buffer | string): Key[] {
  const s = typeof chunk === 'string' ? chunk : chunk.toString('utf8');
  const keys: Key[] = [];
  let i = 0;
  while (i < s.length) {
    const c = s[i]!;
    if (c === '\x03') {
      keys.push({ name: 'ctrlc' });
      i++;
    } else if (c === '\r' || c === '\n') {
      keys.push({ name: 'enter' });
      i++;
    } else if (c === '\x7f' || c === '\b') {
      keys.push({ name: 'backspace' });
      i++;
    } else if (c === '\t') {
      keys.push({ name: 'tab' });
      i++;
    } else if (c === '\x1b') {
      // ESC, or an ESC-prefixed CSI sequence (arrows, PgUp/PgDn).
      const rest = s.slice(i);
      const m = /^\x1b(\[|O)([A-Z]|5~|6~)/.exec(rest);
      if (m) {
        const code = m[2];
        if (code === 'A') keys.push({ name: 'up' });
        else if (code === 'B') keys.push({ name: 'down' });
        else if (code === 'C') keys.push({ name: 'right' });
        else if (code === 'D') keys.push({ name: 'left' });
        else if (code === '5~') keys.push({ name: 'pgup' });
        else if (code === '6~') keys.push({ name: 'pgdn' });
        i += m[0].length;
      } else {
        keys.push({ name: 'esc' });
        i++;
      }
    } else {
      keys.push({ name: 'char', char: c });
      i++;
    }
  }
  return keys;
}
