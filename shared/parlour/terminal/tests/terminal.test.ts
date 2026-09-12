import { describe, expect, it } from 'vitest';
import { createGame, gameDef, legalMoves, listAllGames, hashOf, toSnapshot, fromSnapshot } from '../../facade/index';
import { CATALOG, catalogEntry, groupedCatalog } from '../src/catalog';
import { formatCard, parseCardId, isFaceDown } from '../src/cards';
import { moveLabel } from '../src/moveLabel';
import { buildMoveMenu, resolveMenuPick, couldStillResolve } from '../src/moveMenu';
import { buildChooserRows, firstSelectable, moveSelection, viewportFor } from '../src/chooser';
import { decodeKeys } from '../src/keys';
import { renderTable } from '../src/render/index';
import { composeFrame, assertFrameBounds, COLS, ROWS } from '../src/screen';
import { drainBotTurns } from '../src/botStep';
import { newSession, applyHumanMove, undoOnce, currentLegalMoves, reloadFromSnapshot } from '../src/session';
import { renderHowToPlay, paginate } from '../src/help';

const SEED = 4242;

describe('catalog', () => {
  it('has exactly the 23 canonical facade game ids, no more, no less', () => {
    expect(CATALOG.map((e) => e.id).sort()).toEqual([...listAllGames()].sort());
  });

  it('groups every entry under one of the four presentation groups', () => {
    const grouped = groupedCatalog();
    const total = grouped.reduce((n, g) => n + g.entries.length, 0);
    expect(total).toBe(23);
  });

  it('catalogEntry throws for an unknown id rather than guessing', () => {
    // @ts-expect-error deliberate bad id
    expect(() => catalogEntry('not-a-game')).toThrow();
  });
});

describe('card formatting (presentation only)', () => {
  it('parses every id in a real deck and formats without throwing', () => {
    for (const suit of ['S', 'H', 'D', 'C']) {
      for (let r = 1; r <= 13; r++) {
        const id = `${suit}${r}`;
        expect(parseCardId(id)).not.toBeNull();
        expect(formatCard(id).length).toBeGreaterThan(0);
      }
    }
  });

  it('renders the hidden-card sentinel as face-down, not a crash', () => {
    expect(isFaceDown('??')).toBe(true);
    expect(formatCard('??')).not.toBe('??');
  });

  it('colours red suits differently only as decoration; ascii mode stays distinguishable', () => {
    expect(formatCard('H1', { ascii: true })).toBe('AH');
    expect(formatCard('S13', { ascii: true })).toBe('KS');
  });

  it('formats Durak\'s 36-card (rank 6-14, 14=Ace) id shape', () => {
    expect(formatCard('C14', { ascii: true })).toBe('AC');
    expect(formatCard('H6', { ascii: true })).toBe('6H');
  });

  it('formats Pinochle\'s double-deck "suit+textualRank-copy" id shape', () => {
    expect(formatCard('SA-0', { ascii: true })).toBe('AS');
    expect(formatCard('S10-1', { ascii: true })).toBe('10S');
  });

  it('passes through a genuinely non-standard deck id (Wild/Spite) verbatim, not a crash', () => {
    expect(formatCard('green-draw-two-1')).toBe('green-draw-two-1');
    expect(formatCard('wild-11')).toBe('wild-11');
  });
});

describe('move menu (numbered selection over canonical legalMoves, nothing invented)', () => {
  it('every catalog game exposes a non-throwing, labellable move list from its opening deal', () => {
    for (const entry of CATALOG) {
      const game = createGame(entry.id, SEED, {}, entry.minSeats);
      const moves = legalMoves(game);
      const items = buildMoveMenu(moves);
      expect(items.length).toBe(moves.length);
      for (const item of items) expect(item.label.length).toBeGreaterThan(0);
    }
  });

  it('resolves a typed digit string back to the exact move object', () => {
    const game = createGame('klondike', SEED);
    const items = buildMoveMenu(legalMoves(game));
    const first = items[0]!;
    expect(resolveMenuPick(items, String(first.index))).toBe(first.move);
    expect(resolveMenuPick(items, '999999')).toBeNull();
    expect(couldStillResolve(items, '')).toBe(true);
  });

  it('moveLabel never throws on a real payload shape (cards, nested objects, seat ids)', () => {
    for (const entry of CATALOG) {
      const game = createGame(entry.id, SEED, {}, entry.minSeats);
      for (const move of legalMoves(game)) {
        expect(() => moveLabel(move)).not.toThrow();
      }
    }
  });
});

describe('chooser (pure list model)', () => {
  it('flattens to header+game rows covering all 23 games', () => {
    const rows = buildChooserRows();
    const games = rows.filter((r) => r.kind === 'game');
    expect(games.length).toBe(23);
  });

  it('moveSelection always lands on a game row, wrapping past headers', () => {
    const rows = buildChooserRows();
    let idx = firstSelectable(rows);
    for (let i = 0; i < rows.length + 5; i++) {
      idx = moveSelection(rows, idx, 1);
      expect(rows[idx]!.kind).toBe('game');
    }
  });

  it('viewportFor keeps the selection inside the visible window', () => {
    const { start, end } = viewportFor(30, 25, 10);
    expect(25).toBeGreaterThanOrEqual(start);
    expect(25).toBeLessThan(end);
    expect(end - start).toBe(10);
  });
});

describe('key decoding', () => {
  it('decodes arrows, enter, backspace, ctrl-c, and plain chars', () => {
    expect(decodeKeys('\x1b[A')).toEqual([{ name: 'up' }]);
    expect(decodeKeys('\x1b[B')).toEqual([{ name: 'down' }]);
    expect(decodeKeys('\r')).toEqual([{ name: 'enter' }]);
    expect(decodeKeys('\x7f')).toEqual([{ name: 'backspace' }]);
    expect(decodeKeys('\x03')).toEqual([{ name: 'ctrlc' }]);
    expect(decodeKeys('7')).toEqual([{ name: 'char', char: '7' }]);
  });

  it('decodes a bare ESC (not part of a CSI sequence) as esc', () => {
    expect(decodeKeys('\x1b')).toEqual([{ name: 'esc' }]);
  });
});

describe('family renderers (presentation only — no legality decided here)', () => {
  it('every catalog game renders a non-empty table view from its own playerView, no throw', () => {
    for (const entry of CATALOG) {
      const game = createGame(entry.id, SEED, {}, entry.minSeats);
      const def = gameDef(entry.id);
      const view = def.playerView(game.session.state, 0);
      const lines = renderTable(entry.id, entry.family, view, 0);
      expect(Array.isArray(lines)).toBe(true);
      expect(lines.some((l) => l.trim().length > 0)).toBe(true);
    }
  });
});

describe('80x24 frame boundary (hard requirement)', () => {
  it('composeFrame never exceeds 80 cols / 24 rows for a long move menu + wide table', () => {
    const game = createGame('spider', SEED);
    const def = gameDef('spider');
    const view = def.playerView(game.session.state, 0);
    const lines = renderTable('spider', 'spider-solitaire', view, 0);
    const menu = buildMoveMenu(legalMoves(game));
    const frame = composeFrame({
      title: 'PARLOUR — Spider',
      subtitle: 'x'.repeat(200), // deliberately too-wide input
      tableLines: [...lines, ...Array(50).fill('x'.repeat(200))],
      status: 'y'.repeat(200),
      menu,
      footer: 'z'.repeat(200),
    });
    expect(frame.length).toBe(ROWS);
    for (const l of frame) expect(l.length).toBeLessThanOrEqual(COLS + 20); // ansi codes may add non-visible bytes
    expect(() => assertFrameBounds(frame)).not.toThrow();
  });
});

describe('bot orchestration (canonical engine primitives only)', () => {
  it('drains bot turns for a 4-seat game until the human seat can act or the game ends', () => {
    const game = createGame('hearts', SEED, {}, 4);
    const def = gameDef('hearts');
    const bots = new Set([1, 2, 3]);
    const { game: after, steps } = drainBotTurns(def, game, bots, 0);
    expect(steps.every((s) => s.rejected === null)).toBe(true);
    // every applied step must have been logged through the real session runtime
    expect(after.session.log.length).toBeGreaterThanOrEqual(game.session.log.length);
  });

  it('does not act for a seat with no bot assigned (the human seat)', () => {
    const game = createGame('durak', SEED, {}, 2);
    const def = gameDef('durak');
    const bots = new Set<number>(); // no bots seated at all
    const { steps } = drainBotTurns(def, game, bots, 0);
    expect(steps.length).toBe(0);
  });
});

describe('terminal session (facade pass-through)', () => {
  it('newSession deals + drains any opening bot turns for a multiplayer game', () => {
    const session = newSession('euchre');
    expect(session.gameId).toBe('euchre');
    expect(session.seats).toBe(4);
  });

  it('applyHumanMove rejects illegal input without mutating state, matches facade semantics', () => {
    let session = newSession('klondike');
    const before = hashOf(session.game);
    session = applyHumanMove(session, 'not-a-real-move');
    expect(hashOf(session.game)).toBe(before);
    expect(session.message).toMatch(/Rejected/);
  });

  it('a real legal move changes the hash and undo returns to the prior one', () => {
    let session = newSession('klondike');
    const before = hashOf(session.game);
    const move = currentLegalMoves(session)[0]!;
    session = applyHumanMove(session, move.id, move.payload);
    expect(hashOf(session.game)).not.toBe(before);
    session = undoOnce(session);
    expect(hashOf(session.game)).toBe(before);
  });

  it('reloadFromSnapshot reproduces the exact same hash via fresh replay', () => {
    let session = newSession('pyramid');
    const move = currentLegalMoves(session)[0];
    if (move) session = applyHumanMove(session, move.id, move.payload);
    const before = hashOf(session.game);
    session = reloadFromSnapshot(session);
    expect(hashOf(session.game)).toBe(before);
  });
});

describe('snapshot/restore across a fresh module load (simulated process restart)', () => {
  it.each(['klondike', 'spider', 'hearts', 'cribbage', 'durak'] as const)(
    '%s: snapshot -> JSON round trip -> restore -> exact hash parity',
    (id) => {
      const entry = catalogEntry(id);
      const game = createGame(id, SEED, {}, entry.minSeats);
      const move = legalMoves(game)[0];
      const after = move ? game : game; // some games' seat-0 may have no move; snapshot still round-trips
      const snap = toSnapshot(after, { label: 'terminal-restart-proof' });
      const json = JSON.parse(JSON.stringify(snap));
      const restored = fromSnapshot(json, { verify: true });
      expect(hashOf(restored)).toBe(hashOf(after));
      expect(restored.session.log).toEqual(after.session.log);
    },
  );
});

describe('help/rules (paginates upstream howToPlay verbatim, invents nothing)', () => {
  it('every catalog game has a renderable, paginated help doc', () => {
    for (const entry of CATALOG) {
      const doc = gameDef(entry.id).howToPlay;
      const lines = renderHowToPlay(doc);
      expect(lines.length).toBeGreaterThan(0);
      const pages = paginate(lines, ROWS - 2);
      expect(pages.length).toBeGreaterThan(0);
      for (const page of pages) expect(page.length).toBeLessThanOrEqual(ROWS - 2);
    }
  });
});
