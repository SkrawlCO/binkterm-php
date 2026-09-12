/**
 * One running terminal game: owns nothing canonical itself — every mutation
 * goes through the shared facade (`../../facade/index`), which is itself a
 * pass-through to the vendored engine runtime. This module only tracks
 * which seat is human, drains bot turns after each human action, and keeps
 * a small message for the status line.
 *
 * Slice 5 (production): when a `LeasedClient` is supplied (a real
 * `DOOR_USER_NUMBER` was trusted by whatever launched this process — see
 * `persistence.ts`), every mutation also checkpoints through it, and
 * `newSession` becomes `lease.replace()` (one active caller session, per
 * the documented game-switching model) instead of a throwaway in-memory
 * game. Without a lease (local/dev use, unchanged from Slice 3), sessions
 * stay purely ephemeral.
 */
import {
  createGame,
  gameDef,
  legalMovesFor,
  submitAction,
  undo as undoGame,
  completion,
  toSnapshot,
  fromSnapshot,
  type GameId,
  type ParlourGame,
} from '../../facade/index';
import { drainBotTurns } from './botStep';
import { catalogEntry } from './catalog';
import { ParlourLeaseSession, type ParlourMode } from '../../persistence/envelope';
import type { LeasedClient } from '../../persistence/client';

export interface TerminalSession {
  gameId: GameId;
  game: ParlourGame;
  humanSeat: number;
  seats: number;
  message: string;
  lease: LeasedClient | null;
}

function fromLease(lease: LeasedClient, message: string): TerminalSession {
  const s = lease.session;
  return { gameId: s.gameId, game: s.liveGame, humanSeat: s.humanSeat, seats: s.snapshot().seats, message, lease };
}

/** Acquires the caller's lease and either resumes their saved game or deals `defaultGameId` fresh. */
export async function acquirePersistedSession(
  lease: LeasedClient,
  defaultGameId: GameId = 'klondike',
): Promise<TerminalSession> {
  const entry = catalogEntry(defaultGameId);
  const mode: ParlourMode = entry.minSeats > 1 ? 'solo-vs-bots' : 'solo';
  await lease.acquire(() => ParlourLeaseSession.create(defaultGameId, Date.now() & 0xffffffff, {}, entry.minSeats, { mode }));
  const resumed = lease.envelope?.data && Object.keys(lease.envelope.data).length > 0;
  return fromLease(lease, resumed ? 'Resumed your saved Parlour session.' : 'New game dealt.');
}

export function newSession(gameId: GameId, seed: number = Date.now() & 0xffffffff, lease: LeasedClient | null = null): TerminalSession {
  const entry = catalogEntry(gameId);
  if (lease) {
    if (!lease.writable) throw new Error('parlour terminal: storage lease is not writable');
    const mode: ParlourMode = entry.minSeats > 1 ? 'solo-vs-bots' : 'solo';
    lease.checkpoint().catch(() => {}); // best-effort last checkpoint of the old game
    lease.replace(ParlourLeaseSession.create(gameId, seed, {}, entry.minSeats, { mode }));
    lease.checkpoint().catch(() => {});
    return fromLease(lease, 'New game dealt.');
  }
  const game = createGame(gameId, seed, {}, entry.minSeats);
  const humanSeat = 0;
  const botSeats = new Set(Array.from({ length: entry.minSeats }, (_, i) => i).filter((s) => s !== humanSeat));
  const drained = drainBotTurns(gameDef(gameId), game, botSeats, humanSeat);
  return {
    gameId,
    game: drained.game,
    humanSeat,
    seats: entry.minSeats,
    message: drained.steps.length > 0 ? `${drained.steps.length} bot turn(s) played.` : 'New game dealt.',
    lease: null,
  };
}

export function botSeatsFor(session: TerminalSession): Set<number> {
  return new Set(Array.from({ length: session.seats }, (_, i) => i).filter((s) => s !== session.humanSeat));
}

/**
 * Seat-aware — correct even during a simultaneous multi-actor phase (e.g.
 * Hearts' card-passing before play), where the seat-agnostic `legalMoves()`
 * facade call returns empty by design (see its own doc comment). Discovered
 * during Slice 4's persistence work; fixed here too since the terminal has
 * the exact same latent gap.
 */
export function currentLegalMoves(session: TerminalSession) {
  return legalMovesFor(session.game, session.humanSeat);
}

export function applyHumanMove(session: TerminalSession, moveId: string, payload?: unknown): TerminalSession {
  if (session.lease) {
    if (!session.lease.writable) return { ...session, message: 'Storage lease lost — further moves are blocked.' };
    const outcome = session.lease.session.act(moveId, payload);
    if (outcome.rejected) return { ...session, message: `Rejected: ${outcome.rejected.code}` };
    session.lease.checkpoint().catch(() => {});
    const win = session.lease.session.completion();
    const message = win !== null ? `Game over. ${win.rankings.map((r) => `#${r.seat}:${r.rank}`).join(' ')}` : 'Move applied.';
    return fromLease(session.lease, message);
  }
  const def = gameDef(session.gameId);
  const outcome = submitAction(session.game, moveId, payload, session.humanSeat);
  if (outcome.rejected) {
    return { ...session, message: `Rejected: ${outcome.rejected.code}` };
  }
  const drained = drainBotTurns(def, outcome.game, botSeatsFor(session), session.humanSeat);
  const win = completion(drained.game);
  const message =
    win !== null
      ? `Game over. ${win.rankings.map((r) => `#${r.seat}:${r.rank}`).join(' ')}`
      : drained.steps.length > 0
        ? `${drained.steps.length} bot turn(s) played.`
        : 'Move applied.';
  return { ...session, game: drained.game, message };
}

export function undoOnce(session: TerminalSession): TerminalSession {
  if (session.lease) {
    if (!session.lease.writable) return { ...session, message: 'Storage lease lost — further moves are blocked.' };
    try {
      session.lease.session.undo();
      session.lease.checkpoint().catch(() => {});
      return fromLease(session.lease, 'Undid last action.');
    } catch (err) {
      return { ...session, message: `Cannot undo: ${(err as Error).message}` };
    }
  }
  try {
    const undone = undoGame(session.game, 1);
    return { ...session, game: undone, message: 'Undid last action.' };
  } catch (err) {
    return { ...session, message: `Cannot undo: ${(err as Error).message}` };
  }
}

/** Round-trips through the exact snapshot/restore path used for the acceptance proof. */
export function reloadFromSnapshot(session: TerminalSession): TerminalSession {
  if (session.lease) {
    const restored = ParlourLeaseSession.restore(JSON.parse(JSON.stringify(session.lease.session.snapshot())));
    // Local-only re-verify; does not touch storage (no checkpoint here).
    return { ...session, game: restored.liveGame, message: 'Restored from snapshot (fresh replay, verified).' };
  }
  const snap = toSnapshot(session.game, { label: 'terminal-session' });
  const restored = fromSnapshot(JSON.parse(JSON.stringify(snap)), { verify: true });
  return { ...session, game: restored, message: 'Restored from snapshot (fresh replay, verified).' };
}

/** Explicit Save & Return: last checkpoint, then release. Safe to call even without a lease (no-op). */
export async function saveAndRelease(session: TerminalSession): Promise<void> {
  if (!session.lease) return;
  await session.lease.checkpoint().catch(() => {});
  await session.lease.release().catch(() => {});
}
