/**
 * Minimal ANSI helpers. No third-party terminal framework — the brief asks
 * to avoid one "unless genuinely necessary", and a 23-game numbered-menu +
 * text-panel shell does not need more than raw escape codes.
 *
 * Every colour distinction here is redundant with a text/shape distinction
 * elsewhere (red-suit glyphs already differ from black-suit glyphs, focus is
 * always also marked with `>`/brackets, etc.) so colour is a bonus, not load
 * bearing — verified by the `--no-color` acceptance path.
 */

export const ESC = '\x1b';
export const ALT_SCREEN_ON = `${ESC}[?1049h`;
export const ALT_SCREEN_OFF = `${ESC}[?1049l`;
export const CURSOR_HIDE = `${ESC}[?25l`;
export const CURSOR_SHOW = `${ESC}[?25h`;
export const CLEAR_SCREEN = `${ESC}[2J${ESC}[H`;
export const HOME = `${ESC}[H`;

let colorEnabled = true;
export function setColorEnabled(on: boolean): void {
  colorEnabled = on;
}
export function colorIsEnabled(): boolean {
  return colorEnabled;
}

const CODES = {
  reset: 0,
  bold: 1,
  dim: 2,
  underline: 4,
  reverse: 7,
  red: 31,
  green: 32,
  yellow: 33,
  blue: 34,
  magenta: 35,
  cyan: 36,
  white: 37,
  gray: 90,
  brightWhite: 97,
} as const;

export function sgr(text: string, ...codes: (keyof typeof CODES)[]): string {
  if (!colorEnabled || codes.length === 0) return text;
  const prefix = codes.map((c) => `${ESC}[${CODES[c]}m`).join('');
  return `${prefix}${text}${ESC}[${CODES.reset}m`;
}

export const red = (s: string) => sgr(s, 'red', 'bold');
export const green = (s: string) => sgr(s, 'green');
export const yellow = (s: string) => sgr(s, 'yellow');
export const cyan = (s: string) => sgr(s, 'cyan');
export const gray = (s: string) => sgr(s, 'gray');
export const bold = (s: string) => sgr(s, 'bold');
export const reverse = (s: string) => sgr(s, 'reverse');
export const brightWhite = (s: string) => sgr(s, 'brightWhite');

/** Visible width, stripping ANSI escapes — used to keep rows <= 80 cols. */
export function visibleWidth(s: string): number {
  // eslint-disable-next-line no-control-regex
  return s.replace(/\x1b\[[0-9;]*m/g, '').length;
}

export function padVisible(s: string, width: number): string {
  const w = visibleWidth(s);
  return w >= width ? s : s + ' '.repeat(width - w);
}

export function clampLine(s: string, width: number): string {
  if (visibleWidth(s) <= width) return s;
  // strip ansi for a safe hard clamp; colour on an overlong line is rare here
  const stripped = s.replace(/\x1b\[[0-9;]*m/g, '');
  return stripped.slice(0, width);
}
