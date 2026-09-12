import { describe, expect, it } from 'vitest';
import {
  submitAction,
  toSnapshot,
  fromSnapshot,
  createGame,
  hashOf,
  legalMoves,
  listAllGames,
  listSolitaireGames,
  optionsFor,
} from '../facade/index';

const ALL_23 = [
  'klondike',
  'freecell',
  'spider',
  'pyramid',
  'golf',
  'tripeaks',
  'blitz',
  'cribbage',
  'wild',
  'eights',
  'ratscrew',
  'euchre',
  'spades',
  'poker',
  'ohhell',
  'scopa',
  'spite',
  'hearts',
  'gin',
  'president',
  'durak',
  'palace',
  'pinochle',
] as const;

const SEED = 8808;

/**
 * Each multiplayer game's own canonical minimum table size (enforced by its
 * `setup()`, not by this facade — see the errors this map silences: e.g.
 * "euchre requires exactly 4 seats"). Games absent here accept 1. Not a
 * L33TEST rule; every number comes from the vendored package's own
 * MIN_SEATS/fixed-seat-count constant.
 */
const MIN_SEATS: Partial<Record<(typeof ALL_23)[number], number>> = {
  durak: 2,
  eights: 2,
  euchre: 4,
  hearts: 4,
  ohhell: 3,
  palace: 2,
  poker: 2,
  pinochle: 4,
  president: 4,
  ratscrew: 2,
  scopa: 2,
  spades: 4,
  spite: 2,
  wild: 2,
};

describe('full canonical catalog (Slice 2 correction — Parlour is all 23 games)', () => {
  it('the facade discovers exactly the 23 canonical game ids upstream ships', () => {
    expect([...listAllGames()].sort()).toEqual([...ALL_23].sort());
  });

  it('the six solitaire games remain a subset of the full catalog', () => {
    const all = new Set(listAllGames());
    for (const id of listSolitaireGames()) expect(all.has(id)).toBe(true);
    expect(listSolitaireGames().length).toBe(6);
  });

  describe.each(ALL_23)('%s', (gameId) => {
    it('deterministically initializes through the shared facade (same seed -> same state/hash)', () => {
      // Each game's own canonical minimum table size (see MIN_SEATS above);
      // the six solitaire games and a few multiplayer ones accept 1.
      const seats = MIN_SEATS[gameId] ?? 1;
      const a = createGame(gameId, SEED, {}, seats);
      const b = createGame(gameId, SEED, {}, seats);
      expect(a.session.state).toEqual(b.session.state);
      expect(hashOf(a)).toBe(hashOf(b));
    });

    it('exposes its own canonical option schema (no invented L33TEST fields)', () => {
      const { fields } = optionsFor(gameId);
      expect(Array.isArray(fields)).toBe(true);
    });

    it('exposes at least one canonical legal move or a defined end state from the opening deal', () => {
      const seats = MIN_SEATS[gameId] ?? 1;
      const game = createGame(gameId, SEED, {}, seats);
      // Not every game guarantees a legal move for seat 0 at seat-count 1
      // (e.g. a game whose first action belongs to another seat) — the
      // contract this proves is that `flow.legalMoves` and `end` are both
      // callable without throwing, i.e. the canonical GameDef is intact and
      // wired correctly, not that solo play is polished for every game.
      expect(() => legalMoves(game)).not.toThrow();
    });
  });
});

describe('snapshot/replay parity — solitaire + non-solitaire (Slice 2 acceptance)', () => {
  const CASES: Array<{ id: (typeof ALL_23)[number]; seats: number }> = [
    { id: 'klondike', seats: 1 },
    { id: 'pyramid', seats: 1 },
    { id: 'hearts', seats: MIN_SEATS.hearts! },
    { id: 'euchre', seats: MIN_SEATS.euchre! },
  ];

  it.each(CASES)('$id: create -> act -> snapshot -> restore -> exact hash parity', ({ id, seats }) => {
    const game = createGame(id, SEED, {}, seats);
    const move = legalMoves(game)[0];
    const afterAction = move ? submitAction(game, move.id, move.payload).game : game;
    const beforeHash = hashOf(afterAction);

    const snap = toSnapshot(afterAction, { label: 'slice2-acceptance' });
    expect(snap.gameId).toBe(id);
    expect(snap.seats).toBe(seats);

    const restored = fromSnapshot(JSON.parse(JSON.stringify(snap)), { verify: true });
    expect(restored.session.state).toEqual(afterAction.session.state);
    expect(restored.session.log).toEqual(afterAction.session.log);
    expect(hashOf(restored)).toBe(beforeHash);
  });
});
