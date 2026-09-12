/**
 * The authoritative caller-scoped persistence envelope (Slice 4) — the
 * thing that actually gets saved into `webdoor_storage` (namespace
 * 'parlour', slot 0). Presentation-neutral: both the Web adapter and the
 * terminal adapter import this same class, so there is exactly one
 * definition of "what a Parlour save is", not one per surface.
 *
 * Deliberately NOT stored: derived card-table state (rebuildable by replay
 * from seed+options+log — see `facade/index.ts`'s own doc comment on this),
 * and completion/result (rebuildable via `def.end(state)`, i.e. `completion()`
 * below). What IS stored beyond the plain solitaire `ParlourSnapshot`
 * (schemaVersion/upstreamRevision/gameId/seed/seats/options/log) is exactly
 * the caller-owned metadata the canonical engine has no field for at all:
 * which seat is the human, which seats are bot-driven, and solo vs
 * solo-vs-bots mode — see facade/index.ts's own note that `botsEnabled`
 * defaults to "every seat", so which seats actually get a bot turn driven
 * is an application decision, not something replay alone recovers.
 */
import {
  createGame,
  gameDef,
  hashOf,
  legalMovesFor,
  restoreGame,
  submitAction,
  toSnapshot,
  undo as undoGame,
  completion as completionOf,
  drainBotTurns,
  PARLOUR_UPSTREAM_REVISION,
  type GameId,
  type ParlourGame,
  type ParlourSnapshot,
} from '../facade/index';
import type { LegalMove, RuleValues } from '../vendor/packages/engine/src/types';

export const PARLOUR_LEASE_SCHEMA_VERSION = 1 as const;

export type ParlourMode = 'solo' | 'solo-vs-bots';

/**
 * Everything persisted for one caller's active Parlour session. A plain
 * JSON-shaped object (this is exactly the `data` payload `Storage.php`
 * stores opaquely) — never a class instance.
 */
export interface ParlourLeaseEnvelope<C extends RuleValues = RuleValues> {
  schemaVersion: typeof PARLOUR_LEASE_SCHEMA_VERSION;
  upstreamRevision: typeof PARLOUR_UPSTREAM_REVISION;
  gameId: GameId;
  seed: number;
  seats: number;
  options: C;
  log: ParlourSnapshot<C>['log'];
  mode: ParlourMode;
  /** Which seat this caller plays. Bot seats act through `drainBotTurns` after each human action. */
  humanSeat: number;
  /** Bot-controlled seats — application metadata; the canonical engine has no such field. */
  botSeats: readonly number[];
  meta?: { createdAtMs?: number; updatedAtMs?: number; label?: string };
}

/**
 * Thrown when something that is NOT a caller-scoped solo/solo-vs-bots
 * envelope (a shared multiplayer room, in particular) is handed to this
 * layer. This is the explicit multiplayer-room safety guard the brief asks
 * for: a type check, not room support. A real room object would carry a
 * `roomId`/`peers`/`host` shape a caller-scoped envelope never has; any of
 * those markers, or an unrecognized `mode`, refuses immediately rather than
 * quietly persisting shared state as if one caller owned it.
 */
export class ParlourRoomModeGuardError extends Error {}

const KNOWN_MODES: readonly ParlourMode[] = ['solo', 'solo-vs-bots'];
const ROOM_MARKER_KEYS = ['roomId', 'room_id', 'peers', 'host', 'sharedRoom'] as const;

export function assertCallerScopedEnvelope(candidate: unknown): asserts candidate is ParlourLeaseEnvelope {
  if (!candidate || typeof candidate !== 'object') {
    throw new ParlourRoomModeGuardError('Parlour envelope must be an object');
  }
  const rec = candidate as Record<string, unknown>;
  for (const key of ROOM_MARKER_KEYS) {
    if (key in rec) {
      throw new ParlourRoomModeGuardError(
        `Parlour caller-scoped persistence refuses an envelope carrying "${key}" — shared multiplayer-room ` +
          'state must never be serialized as one caller\'s authoritative game. Room persistence is separate, future work.',
      );
    }
  }
  if (!KNOWN_MODES.includes(rec.mode as ParlourMode)) {
    throw new ParlourRoomModeGuardError(`Parlour envelope has an unrecognized mode "${String(rec.mode)}"`);
  }
}

export interface ParlourLeaseSessionOptions {
  mode: ParlourMode;
  humanSeat?: number;
  botSeats?: readonly number[];
}

/**
 * The live, in-memory counterpart of one `ParlourLeaseEnvelope` — the object
 * both the Web adapter's and the terminal's persistence clients actually
 * play against. Every mutation is a direct facade call; this class adds
 * only seat/mode bookkeeping and bot draining on top.
 */
export class ParlourLeaseSession<C extends RuleValues = RuleValues> {
  private constructor(
    private game: ParlourGame<C>,
    public readonly mode: ParlourMode,
    public readonly humanSeat: number,
    public readonly botSeats: readonly number[],
    private meta: ParlourLeaseEnvelope<C>['meta'],
  ) {}

  static create<C extends RuleValues = RuleValues>(
    gameId: GameId,
    seed: number,
    options: Partial<C>,
    seats: number,
    opts: ParlourLeaseSessionOptions,
  ): ParlourLeaseSession<C> {
    const humanSeat = opts.humanSeat ?? 0;
    const botSeats = opts.botSeats ?? (opts.mode === 'solo-vs-bots' ? allSeatsExcept(seats, humanSeat) : []);
    let game = createGame<C>(gameId, seed, options, seats);
    if (opts.mode === 'solo-vs-bots' && botSeats.length > 0) {
      game = drainBotTurns(gameDef(gameId), game, new Set(botSeats), humanSeat).game as ParlourGame<C>;
    }
    return new ParlourLeaseSession(game, opts.mode, humanSeat, botSeats, {
      createdAtMs: Date.now(),
      updatedAtMs: Date.now(),
    });
  }

  /** Restores by canonical replay ONLY — never trusts a stored `state`. Rejects incompatible schema/revision. */
  static restore<C extends RuleValues = RuleValues>(envelope: unknown): ParlourLeaseSession<C> {
    assertCallerScopedEnvelope(envelope);
    const env = envelope as ParlourLeaseEnvelope<C>;
    if (env.schemaVersion !== PARLOUR_LEASE_SCHEMA_VERSION) {
      throw new Error(
        `parlour lease: envelope schema ${String(env.schemaVersion)} is not the supported ${PARLOUR_LEASE_SCHEMA_VERSION}`,
      );
    }
    if (env.upstreamRevision !== PARLOUR_UPSTREAM_REVISION) {
      throw new Error(
        `parlour lease: envelope was taken against upstream revision ${env.upstreamRevision}, this build is pinned to ${PARLOUR_UPSTREAM_REVISION}`,
      );
    }
    const game = restoreGame<C>(env.gameId, env.seed, env.options, env.log, { verify: true, seats: env.seats });
    return new ParlourLeaseSession(game, env.mode, env.humanSeat, env.botSeats, env.meta);
  }

  snapshot(): ParlourLeaseEnvelope<C> {
    const base = toSnapshot(this.game, { label: this.meta?.label });
    this.meta = { ...this.meta, updatedAtMs: Date.now() };
    return {
      schemaVersion: PARLOUR_LEASE_SCHEMA_VERSION,
      upstreamRevision: base.upstreamRevision,
      gameId: base.gameId,
      seed: base.seed,
      seats: base.seats,
      options: base.options,
      log: base.log,
      mode: this.mode,
      humanSeat: this.humanSeat,
      botSeats: this.botSeats,
      meta: this.meta,
    };
  }

  /** This caller's own legal moves — seat-aware, correct even mid simultaneous phase (e.g. Hearts passing). */
  legalMoves(): readonly LegalMove[] {
    return legalMovesFor(this.game, this.humanSeat);
  }

  /** The live canonical game, for a caller surface's own presentation (terminal render, Web `PlayingCard`s). */
  get liveGame(): ParlourGame<C> {
    return this.game;
  }

  hash(): string {
    return hashOf(this.game);
  }

  completion() {
    return completionOf(this.game);
  }

  get gameId(): GameId {
    return this.game.gameId;
  }

  /** Applies one human action, then drains bot turns (if solo-vs-bots) — same order every surface uses. */
  act(moveId: string, payload?: unknown): { rejected: { code: string; message?: string } | null } {
    if (completionOf(this.game) !== null) {
      throw new Error('parlour lease: cannot act after canonical completion');
    }
    const outcome = submitAction(this.game, moveId, payload, this.humanSeat);
    if (outcome.rejected) return { rejected: outcome.rejected };
    this.game = outcome.game;
    if (this.mode === 'solo-vs-bots' && this.botSeats.length > 0) {
      this.game = drainBotTurns(gameDef(this.gameId), this.game, new Set(this.botSeats), this.humanSeat)
        .game as ParlourGame<C>;
    }
    return { rejected: null };
  }

  undo(steps = 1): void {
    this.game = undoGame(this.game, steps);
  }
}

function allSeatsExcept(seats: number, exclude: number): number[] {
  return Array.from({ length: seats }, (_, i) => i).filter((s) => s !== exclude);
}
