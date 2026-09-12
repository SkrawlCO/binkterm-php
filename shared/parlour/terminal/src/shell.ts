/**
 * The interactive Parlour terminal shell: chooser -> game -> help, wired to
 * real stdin/stdout. Owns only presentation/input — every state change goes
 * through `session.ts`, which is a pass-through to the shared facade.
 */
import { gameDef, hashOf, PARLOUR_UPSTREAM_REVISION } from '../../facade/index';
import type { GameId } from '../../facade/index';
import { ALT_SCREEN_ON, ALT_SCREEN_OFF, CURSOR_HIDE, CURSOR_SHOW, CLEAR_SCREEN, setColorEnabled } from './ansi';
import { buildChooserRows, firstSelectable, moveSelection, viewportFor, type ChooserRow } from './chooser';
import { catalogEntry } from './catalog';
import { decodeKeys, type Key } from './keys';
import { renderTable, renderSpiderColumn } from './render/index';
import { renderHowToPlay, paginate } from './help';
import { buildMoveMenu, resolveMenuPick, couldStillResolve } from './moveMenu';
import { composeFrame, menuPageCount, COLS, ROWS } from './screen';
import {
  newSession,
  applyHumanMove,
  undoOnce,
  currentLegalMoves,
  reloadFromSnapshot,
  acquirePersistedSession,
  saveAndRelease,
  type TerminalSession,
} from './session';
import type { LeasedClient } from '../../persistence/client';

type Mode =
  | { kind: 'chooser'; selected: number }
  | { kind: 'game'; session: TerminalSession; digits: string; menuPage: number; spiderColumn: number | null }
  | { kind: 'help'; page: number; returnTo: Mode };

export interface ShellOptions {
  input: NodeJS.ReadableStream;
  output: NodeJS.WritableStream;
  color?: boolean;
  /** A trusted caller's storage lease (production/NativeDoor use), or null/undefined for ephemeral local play. */
  lease?: LeasedClient | null;
  onExit?: () => void;
}

export class ParlourShell {
  private mode: Mode = { kind: 'chooser', selected: 0 };
  private readonly rows: ChooserRow[] = buildChooserRows();
  private readonly out: NodeJS.WritableStream;
  private readonly lease: LeasedClient | null;
  private readonly onExit?: () => void;
  private stopped = false;
  private stopping: Promise<void> | null = null;

  constructor(private readonly opts: ShellOptions) {
    setColorEnabled(opts.color ?? true);
    this.out = opts.output;
    this.lease = opts.lease ?? null;
    this.onExit = opts.onExit;
    const first = firstSelectable(this.rows);
    this.mode = { kind: 'chooser', selected: first < 0 ? 0 : first };
  }

  async start(): Promise<void> {
    this.out.write(ALT_SCREEN_ON + CURSOR_HIDE);
    if (this.lease) {
      try {
        const session = await acquirePersistedSession(this.lease);
        this.mode = { kind: 'game', session, digits: '', menuPage: 0, spiderColumn: null };
      } catch (err) {
        // Fail open to ephemeral local play rather than refusing the door
        // entirely — a storage outage should degrade, not lock the caller out.
        this.mode = { kind: 'chooser', selected: firstSelectable(this.rows) };
        this.pendingStorageError = err instanceof Error ? err.message : 'storage unavailable';
      }
    }
    this.draw();
    this.opts.input.on('data', (chunk: Buffer | string) => this.handleInput(decodeKeys(chunk)));
  }

  private pendingStorageError: string | null = null;

  /** Best-effort last checkpoint + release, then restores the terminal. Safe to call more than once. */
  stop(): Promise<void> {
    if (this.stopping) return this.stopping;
    this.stopping = (async () => {
      if (this.mode.kind === 'game' && this.mode.session.lease) {
        await saveAndRelease(this.mode.session).catch(() => {});
      } else if (this.lease?.writable) {
        await this.lease.release().catch(() => {});
      }
      this.stopped = true;
      this.out.write(CURSOR_SHOW + ALT_SCREEN_OFF);
      this.onExit?.();
    })();
    return this.stopping;
  }

  private handleInput(keys: Key[]): void {
    for (const key of keys) {
      if (key.name === 'ctrlc') {
        void this.stop();
        return;
      }
      this.handleKey(key);
    }
    if (!this.stopped) this.draw();
  }

  private handleKey(key: Key): void {
    if (this.mode.kind === 'chooser') return this.handleChooserKey(key);
    if (this.mode.kind === 'help') return this.handleHelpKey(key);
    return this.handleGameKey(key);
  }

  // ---------------------------------------------------------------- chooser
  private handleChooserKey(key: Key): void {
    const mode = this.mode as Extract<Mode, { kind: 'chooser' }>;
    if (key.name === 'up' || (key.name === 'char' && (key.char === 'k' || key.char === 'w'))) {
      this.mode = { kind: 'chooser', selected: moveSelection(this.rows, mode.selected, -1) };
    } else if (key.name === 'down' || (key.name === 'char' && (key.char === 'j' || key.char === 's'))) {
      this.mode = { kind: 'chooser', selected: moveSelection(this.rows, mode.selected, 1) };
    } else if (key.name === 'enter') {
      const row = this.rows[mode.selected];
      if (row && row.kind === 'game') {
        const session = newSession(row.entry.id, undefined, this.lease);
        this.mode = { kind: 'game', session, digits: '', menuPage: 0, spiderColumn: null };
      }
    } else if (key.name === 'char' && key.char === '?') {
      const row = this.rows[mode.selected];
      if (row && row.kind === 'game') {
        this.mode = { kind: 'help', page: 0, returnTo: this.mode };
      }
    } else if (key.name === 'esc' || (key.name === 'char' && key.char === 'q')) {
      void this.stop();
    }
  }

  // ------------------------------------------------------------------- game
  private handleGameKey(key: Key): void {
    const mode = this.mode as Extract<Mode, { kind: 'game' }>;
    const items = buildMoveMenu(currentLegalMoves(mode.session));
    const pageCount = menuPageCount(items);

    if (key.name === 'esc') {
      this.mode = { kind: 'chooser', selected: firstSelectable(this.rows) };
      return;
    }
    if (key.name === 'char' && key.char === 'q') {
      void this.stop();
      return;
    }
    if (key.name === 'char' && key.char === '?') {
      this.mode = { kind: 'help', page: 0, returnTo: mode };
      return;
    }
    if (key.name === 'char' && key.char === 'n') {
      this.mode = { kind: 'game', session: newSession(mode.session.gameId, undefined, this.lease), digits: '', menuPage: 0, spiderColumn: null };
      return;
    }
    if (key.name === 'char' && key.char === 'u') {
      this.mode = { ...mode, session: undoOnce(mode.session), digits: '' };
      return;
    }
    if (key.name === 'char' && key.char === 'r') {
      this.mode = { ...mode, session: reloadFromSnapshot(mode.session), digits: '' };
      return;
    }
    if ((key.name === 'pgdn' || (key.name === 'char' && key.char === ']')) ) {
      this.mode = { ...mode, menuPage: Math.min(pageCount - 1, mode.menuPage + 1) };
      return;
    }
    if ((key.name === 'pgup' || (key.name === 'char' && key.char === '['))) {
      this.mode = { ...mode, menuPage: Math.max(0, mode.menuPage - 1) };
      return;
    }
    if (key.name === 'char' && /^[0-9]$/.test(key.char) ) {
      const digits = mode.digits + key.char;
      if (couldStillResolve(items, digits)) {
        this.mode = { ...mode, digits };
      }
      return;
    }
    if (key.name === 'enter') {
      const move = resolveMenuPick(items, mode.digits);
      if (move) {
        this.mode = { ...mode, session: applyHumanMove(mode.session, move.id, move.payload), digits: '', menuPage: 0 };
      } else {
        this.mode = { ...mode, digits: '' };
      }
      return;
    }
    if (key.name === 'backspace') {
      this.mode = { ...mode, digits: mode.digits.slice(0, -1) };
      return;
    }
    if (key.name === 'char' && key.char === 'v' && catalogEntry(mode.session.gameId).family === 'spider-solitaire') {
      const next = mode.spiderColumn === null ? 0 : (mode.spiderColumn + 1) % 10;
      this.mode = { ...mode, spiderColumn: next };
      return;
    }
  }

  // ------------------------------------------------------------------- help
  private handleHelpKey(key: Key): void {
    const mode = this.mode as Extract<Mode, { kind: 'help' }>;
    const doc = mode.returnTo.kind === 'game' ? gameDef(mode.returnTo.session.gameId).howToPlay : null;
    const pages = doc ? paginate(renderHowToPlay(doc), ROWS - 2) : [[]];
    if (key.name === 'char' && (key.char === 'b' || key.char === ' ')) {
      this.mode = { ...mode, page: Math.min(pages.length - 1, mode.page + 1) };
    } else if (key.name === 'pgdn') {
      this.mode = { ...mode, page: Math.min(pages.length - 1, mode.page + 1) };
    } else if (key.name === 'pgup') {
      this.mode = { ...mode, page: Math.max(0, mode.page - 1) };
    } else if (key.name === 'esc' || (key.name === 'char' && key.char === 'q')) {
      this.mode = mode.returnTo;
    }
  }

  // ------------------------------------------------------------------ draw
  render(): string[] {
    if (this.mode.kind === 'chooser') return this.renderChooser(this.mode);
    if (this.mode.kind === 'help') return this.renderHelp(this.mode);
    return this.renderGame(this.mode);
  }

  private renderChooser(mode: Extract<Mode, { kind: 'chooser' }>): string[] {
    const lines: string[] = [];
    lines.push('PARLOUR — the full 23-game canonical card room');
    lines.push(`upstream ${PARLOUR_UPSTREAM_REVISION.slice(0, 12)}   [Enter] play  [?] help  [q] quit`);
    if (this.pendingStorageError) {
      lines.push(`(save/resume unavailable this session: ${this.pendingStorageError} — playing unsaved)`);
    }
    lines.push('-'.repeat(76));
    const pageSize = ROWS - 5;
    const { start, end } = viewportFor(this.rows.length, mode.selected, pageSize);
    for (let i = start; i < end; i++) {
      const row = this.rows[i]!;
      if (row.kind === 'header') {
        lines.push(`-- ${row.label} --`);
      } else {
        const cursor = i === mode.selected ? '>' : ' ';
        lines.push(`${cursor} ${row.entry.label}`);
      }
    }
    while (lines.length < ROWS - 1) lines.push('');
    lines.push('arrows/jk move  Enter select  ? rules  q quit');
    return lines.slice(0, ROWS).map((l) => l.slice(0, COLS));
  }

  private renderGame(mode: Extract<Mode, { kind: 'game' }>): string[] {
    const entry = catalogEntry(mode.session.gameId);
    const def = gameDef(mode.session.gameId);
    const view = def.playerView(mode.session.game.session.state, mode.session.humanSeat);
    const tableLines =
      mode.spiderColumn !== null ? renderSpiderColumn(view, mode.spiderColumn) : renderTable(entry.id, entry.family, view, mode.session.humanSeat);
    const items = buildMoveMenu(currentLegalMoves(mode.session));
    return composeFrame({
      title: `PARLOUR — ${entry.label}`,
      subtitle: `hash ${hashOf(mode.session.game).slice(0, 12)}   seat ${mode.session.humanSeat}/${mode.session.seats}`,
      tableLines,
      status: mode.session.message,
      menu: items,
      menuPage: mode.menuPage,
      digits: mode.digits,
      footer: '[digits+Enter] move  [u] undo  [n] new  [r] reload-snapshot  [?] help  [Esc] chooser  [q] quit',
    });
  }

  private renderHelp(mode: Extract<Mode, { kind: 'help' }>): string[] {
    const doc = mode.returnTo.kind === 'game' ? gameDef(mode.returnTo.session.gameId).howToPlay : null;
    if (!doc) return ['(no help available)'];
    const pages = paginate(renderHowToPlay(doc), ROWS - 2);
    const page = pages[Math.min(mode.page, pages.length - 1)] ?? [];
    const lines = [`Help (${mode.page + 1}/${pages.length})`, ...page];
    while (lines.length < ROWS) lines.push('');
    return lines.slice(0, ROWS).map((l) => l.slice(0, COLS));
  }

  private draw(): void {
    const frame = this.render();
    this.out.write(CLEAR_SCREEN + frame.join('\r\n'));
  }
}

export type { GameId };
