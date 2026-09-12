import { describe, expect, it } from 'vitest';
import {
  PARLOUR_UPSTREAM_REVISION,
  completion,
  createGame,
  fromSnapshot,
  hashOf,
  legalMoves,
  listSolitaireGames,
  optionsFor,
  project,
  resolveOptions,
  restoreGame,
  submitAction,
  toSnapshot,
  undo,
  type GameId,
} from '../facade/index';

const GAME_IDS: readonly GameId[] = [
  'klondike',
  'freecell',
  'spider',
  'pyramid',
  'golf',
  'tripeaks',
];
const SEED = 8808;

describe('canonical provenance', () => {
  it('is pinned to the reviewed upstream revision', () => {
    expect(PARLOUR_UPSTREAM_REVISION).toBe('ee9aa7e695be507fb8a0f11968ab73474f8dc7f9');
  });

  it('exposes exactly the six solitaire game ids, no more, no less', () => {
    expect([...listSolitaireGames()].sort()).toEqual([...GAME_IDS].sort());
  });
});

describe.each(GAME_IDS)('%s', (gameId) => {
  it('initializes deterministically from a seed', () => {
    const a = createGame(gameId, SEED);
    const b = createGame(gameId, SEED);
    expect(a.session.state).toEqual(b.session.state);
    expect(hashOf(a)).toBe(hashOf(b));
  });

  it('exposes at least one canonical legal move to start', () => {
    const game = createGame(gameId, SEED);
    const moves = legalMoves(game);
    expect(moves.length).toBeGreaterThan(0);
  });

  it('accepts a real legal move and rejects a bogus one canonically', () => {
    const game = createGame(gameId, SEED);
    const move = legalMoves(game)[0]!;
    const after = submitAction(game, move.id, move.payload);
    expect(after.rejected).toBeNull();
    expect(after.game.session.log.length).toBeGreaterThan(0);

    const bad = submitAction(after.game, 'not-a-real-move-id');
    expect(bad.rejected).not.toBeNull();
    expect(bad.rejected?.code).toBe('illegal-move');
    // rejection must not mutate — same session identity/state back
    expect(bad.game.session.state).toEqual(after.game.session.state);
  });

  it('replays the event log to exact state/hash parity', () => {
    const game = createGame(gameId, SEED);
    const move = legalMoves(game)[0]!;
    const after = submitAction(game, move.id, move.payload).game;

    const restored = restoreGame(
      gameId,
      after.session.seed,
      after.session.config,
      JSON.parse(JSON.stringify(after.session.log)),
      { verify: true },
    );

    expect(restored.session.state).toEqual(after.session.state);
    expect(restored.session.phase).toEqual(after.session.phase);
    expect(restored.session.log).toEqual(after.session.log);
    expect(hashOf(restored)).toBe(hashOf(after));
  });

  it('undoes the last action back to the pre-move state, and that state replays clean', () => {
    const game = createGame(gameId, SEED);
    const move = legalMoves(game)[0]!;
    const after = submitAction(game, move.id, move.payload).game;

    const undone = undo(after);
    expect(undone.session.state).toEqual(game.session.state);
    expect(hashOf(undone)).toBe(hashOf(game));

    // and the undone position itself round-trips through replay
    const reReplayed = restoreGame(gameId, undone.session.seed, undone.session.config, undone.session.log);
    expect(reReplayed.session.state).toEqual(undone.session.state);
  });

  it('snapshot -> restore reproduces exact state/hash parity', () => {
    const game = createGame(gameId, SEED);
    const move = legalMoves(game)[0]!;
    const after = submitAction(game, move.id, move.payload).game;
    const beforeHash = hashOf(after);

    const snap = toSnapshot(after, { label: 'facade-test' });
    expect(snap.schemaVersion).toBe(1);
    expect(snap.upstreamRevision).toBe(PARLOUR_UPSTREAM_REVISION);
    expect(snap.gameId).toBe(gameId);

    // round-trip through JSON, as a real persisted snapshot would be
    const restored = fromSnapshot(JSON.parse(JSON.stringify(snap)), { verify: true });
    expect(restored.session.state).toEqual(after.session.state);
    expect(hashOf(restored)).toBe(beforeHash);
  });

  it('projects presentation-neutral state with no React/DOM leakage', () => {
    const game = createGame(gameId, SEED);
    const projection = project(game);
    expect(projection.gameId).toBe(gameId);
    expect(projection.seed).toBe(SEED);
    expect(typeof projection.stateHash).toBe('string');
    expect(Array.isArray(projection.legalMoves)).toBe(true);
    expect(projection.status === 'playing' || projection.status === 'ended').toBe(true);
    // plain-data projection: nothing here should survive a JSON round trip badly
    expect(() => JSON.stringify(projection)).not.toThrow();
  });

  it('reports completion status via the canonical def.end(), not a reimplementation', () => {
    const game = createGame(gameId, SEED);
    // a fresh deal is never complete
    expect(completion(game)).toBeNull();
    expect(game.session.status).toBe('playing');
  });
});

describe('options parity (canonical fields, resolved by the upstream schema)', () => {
  it('klondike: draw one vs draw three both resolve and both initialize', () => {
    const fields = optionsFor('klondike').fields;
    expect(fields.some((f) => f.key === 'drawCount')).toBe(true);
    const drawOne = resolveOptions('klondike', { drawCount: 1 });
    const drawThree = resolveOptions('klondike', { drawCount: 3 });
    expect(drawOne.drawCount).toBe(1);
    expect(drawThree.drawCount).toBe(3);
    expect(createGame('klondike', SEED, { drawCount: 1 }).session.config.drawCount).toBe(1);
    expect(createGame('klondike', SEED, { drawCount: 3 }).session.config.drawCount).toBe(3);
  });

  it('freecell: four vs six free cells', () => {
    const four = resolveOptions('freecell', { freeCells: 4 });
    const six = resolveOptions('freecell', { freeCells: 6 });
    expect(four.freeCells).toBe(4);
    expect(six.freeCells).toBe(6);
    expect(createGame('freecell', SEED, { freeCells: 6 }).session.config.freeCells).toBe(6);
  });

  it('spider: one / two / four suits', () => {
    for (const suitCount of [1, 2, 4]) {
      expect(resolveOptions('spider', { suitCount }).suitCount).toBe(suitCount);
      expect(createGame('spider', SEED, { suitCount }).session.config.suitCount).toBe(suitCount);
    }
  });

  it('pyramid: classic two-recycle vs unlimited (-1) recycle', () => {
    expect(resolveOptions('pyramid', { recyclesLimit: 2 }).recyclesLimit).toBe(2);
    expect(resolveOptions('pyramid', { recyclesLimit: -1 }).recyclesLimit).toBe(-1);
    expect(createGame('pyramid', SEED, { recyclesLimit: -1 }).session.config.recyclesLimit).toBe(-1);
  });

  it('golf: ace/king wrap toggle', () => {
    expect(resolveOptions('golf', { wrap: false }).wrap).toBe(false);
    expect(resolveOptions('golf', { wrap: true }).wrap).toBe(true);
    expect(createGame('golf', SEED, { wrap: true }).session.config.wrap).toBe(true);
  });

  it('tripeaks: wrap + hole-recycle toggles', () => {
    const resolved = resolveOptions('tripeaks', { wrap: true, recycle: true });
    expect(resolved.wrap).toBe(true);
    expect(resolved.recycle).toBe(true);
    const game = createGame('tripeaks', SEED, { wrap: true, recycle: true });
    expect(game.session.config.wrap).toBe(true);
    expect(game.session.config.recycle).toBe(true);
  });
});

describe('audio exclusion', () => {
  it('no vendored canonical source or facade file references any audio asset', async () => {
    const fg = await import('node:fs');
    const path = await import('node:path');
    const root = path.resolve(__dirname, '..');
    const audioPattern = /\.(mp3|wav|m4a)\b|elevenlabs/i;
    const offenders: string[] = [];
    const walk = (dir: string) => {
      for (const entry of fg.readdirSync(dir, { withFileTypes: true })) {
        if (entry.name === 'node_modules' || entry.name.startsWith('.')) continue;
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) walk(full);
        else if (entry.name.endsWith('.ts')) {
          const text = fg.readFileSync(full, 'utf8');
          if (audioPattern.test(text)) offenders.push(full);
        }
      }
    };
    walk(path.join(root, 'facade'));
    walk(path.join(root, 'vendor'));
    expect(offenders).toEqual([]);
  });
});
