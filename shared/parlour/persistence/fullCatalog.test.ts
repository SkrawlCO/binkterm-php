import { describe, expect, it } from 'vitest';
import { listAllGames, type GameId } from '../facade/index';
import { ParlourLeaseSession } from './envelope';
import { CATALOG } from '../terminal/src/catalog';

const SEED = 20260911;

/** Each catalog game's own canonical minimum seat count (see terminal/src/catalog.ts). */
function minSeatsOf(id: GameId): number {
  return CATALOG.find((e) => e.id === id)?.minSeats ?? 1;
}

describe('full-catalog persistence smoke (Slice 4 — all 23 games)', () => {
  it('covers exactly the full 23-game facade catalog', () => {
    expect([...listAllGames()].sort()).toEqual(CATALOG.map((e) => e.id).sort());
  });

  it.each(listAllGames())(
    '%s: create (solo-vs-bots where multiplayer) -> act -> snapshot -> restore -> exact identity/log/hash parity',
    (id) => {
      const seats = minSeatsOf(id);
      const mode = seats > 1 ? 'solo-vs-bots' : 'solo';
      const session = ParlourLeaseSession.create(id, SEED, {}, seats, { mode });

      const move = session.legalMoves()[0];
      if (move) session.act(move.id, move.payload);

      const beforeHash = session.hash();
      const envelope = session.snapshot();

      // Round-trip through JSON exactly as a real webdoor_storage row would.
      const roundTripped = JSON.parse(JSON.stringify(envelope));
      const restored = ParlourLeaseSession.restore(roundTripped);

      expect(restored.gameId).toBe(id);
      expect(restored.snapshot().seed).toBe(SEED);
      expect(restored.snapshot().options).toEqual(envelope.options);
      expect(restored.snapshot().log).toEqual(envelope.log);
      expect(restored.humanSeat).toBe(session.humanSeat);
      expect(restored.botSeats).toEqual(session.botSeats);
      expect(restored.hash()).toBe(beforeHash);
    },
  );
});
