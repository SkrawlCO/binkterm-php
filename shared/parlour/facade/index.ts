/**
 * Presentation-neutral Parlour facade — the full 23-game canonical catalog,
 * not a solitaire-only subset (see ./games.ts).
 *
 * This wraps the vendored canonical engine's own session runtime — it does
 * not reimplement RNG, legal-move admission, dealing, completion, undo, or
 * state hashing. Every one of those calls below is a direct pass-through to
 * `vendor/packages/engine/src/{runtime,undo}.ts`. See ../README.md and
 * ../PROVENANCE.md for exactly which upstream files this is and their hashes.
 *
 * Authority model (must not drift): a game is fully described by
 *   { gameId, seed, resolvedOptions, eventLog }
 * — `GameSession.state` is a derived projection, reconstructible by replay.
 * Nothing in this facade treats `state` as authoritative for storage; only
 * `toSnapshot()` is meant to be persisted, and it stores the log, not the
 * state.
 */
import {
  createSession,
  replaySession,
  sessionApply,
  stateHash,
} from '../vendor/packages/engine/src/runtime';
import { undoSession } from '../vendor/packages/engine/src/undo';
import type {
  AppliedEvent,
  GameSession,
  LegalMove,
  MatchResult,
  RuleValues,
} from '../vendor/packages/engine/src/types';
import { gameDef, listAllGames, listSolitaireGames, type GameId } from './games';

export { listSolitaireGames, listAllGames, gameDef } from './games';
export type { GameId } from './games';

/** Canonical pinned upstream revision every vendored file in this slice is from. */
export const PARLOUR_UPSTREAM_REVISION = 'ee9aa7e695be507fb8a0f11968ab73474f8dc7f9';
export const PARLOUR_UPSTREAM_REPO = 'https://github.com/braedonsaunders/parlour';
export const PARLOUR_SNAPSHOT_SCHEMA_VERSION = 1;

/**
 * Default seat count for the six solitaire games (always 1) and a sane
 * fallback for a multiplayer game invoked without one. The other 17 games'
 * bot/host/reconnect/room orchestration is preserved architecturally in the
 * vendored engine (bots.ts, match.ts, seats.ts, teams.ts, veil.ts) and each
 * game's own bot policies — wiring an actual room/session layer over it is
 * later L33TEST integration work, not this facade slice's job. This facade
 * only guarantees the deterministic session contract (seed + options + log
 * -> exact state, for any seat count) generalizes to all 23 games, which it
 * does today because `createSession`/`sessionApply`/`replaySession`/
 * `undoSession` never assumed a seat count of 1.
 */
const DEFAULT_SEATS = 1;

export interface ParlourGame<C extends RuleValues = RuleValues> {
  readonly gameId: GameId;
  readonly session: GameSession<unknown, C>;
}

/** Every canonical option field + preset this game declares, for a future settings UI. */
export function optionsFor(gameId: GameId) {
  const def = gameDef(gameId);
  return { fields: def.configSchema.fields, presets: def.configSchema.presets };
}

/** Resolves partial/unset options against the game's own canonical defaults. */
export function resolveOptions<C extends RuleValues>(
  gameId: GameId,
  options: Partial<C> = {},
): C {
  return gameDef(gameId).configSchema.resolve(options) as C;
}

/** Creates a fresh deterministic game from a seed + (partial) canonical options. */
export function createGame<C extends RuleValues>(
  gameId: GameId,
  seed: number,
  options: Partial<C> = {},
  seats: number = DEFAULT_SEATS,
): ParlourGame<C> {
  const def = gameDef(gameId);
  const config = def.configSchema.resolve(options as Partial<RuleValues>) as C;
  const session = createSession(def, { seed, seats, config }) as GameSession<unknown, C>;
  return { gameId, session };
}

/**
 * Reconstructs a game purely from { seed, options, log } — the authoritative
 * path. No stored `state` is ever trusted; this replays from the deterministic
 * seed through every logged event via the canonical runtime.
 */
export function restoreGame<C extends RuleValues>(
  gameId: GameId,
  seed: number,
  options: Partial<C>,
  log: readonly AppliedEvent[],
  opts: { verify?: boolean; seats?: number } = {},
): ParlourGame<C> {
  const def = gameDef(gameId);
  const config = def.configSchema.resolve(options as Partial<RuleValues>) as C;
  const session = replaySession(def, seed, log, {
    config,
    seats: opts.seats ?? DEFAULT_SEATS,
    verify: opts.verify,
  }) as GameSession<unknown, C>;
  if (opts.verify && session.fault) {
    throw new Error(
      `parlour: ${gameId} replay failed re-validation at log index ${session.fault.index}: ${session.fault.error.code}`,
    );
  }
  return { gameId, session };
}

/**
 * Canonical legal moves for the current state. NOTE: during a simultaneous
 * multi-actor phase (`phase.actors.length > 1`, e.g. Hearts' card-passing
 * before play) this seat-agnostic call correctly returns an empty list —
 * `flow.legalMoves()` has no seat argument, so it cannot say whose turn's
 * moves those would be. Use `legalMovesFor(game, seat)` below for a
 * specific caller's own actions; that is almost always what a real caller
 * surface (terminal, persistence, Web) actually wants.
 */
export function legalMoves<C extends RuleValues>(game: ParlourGame<C>): readonly LegalMove[] {
  const def = gameDef(game.gameId);
  return def.flow.legalMoves(game.session.state, game.session.phase);
}

/**
 * Legal moves for one specific seat — correct even during a simultaneous
 * multi-actor phase (falls back to `legalMoves()` for games whose flow
 * doesn't implement `legalMovesFor`, matching the same fallback
 * `engine/src/bots.ts#runBotGame` and this facade's own bot orchestration
 * (`facade/bots.ts`) already use).
 */
export function legalMovesFor<C extends RuleValues>(
  game: ParlourGame<C>,
  seat: number,
): readonly LegalMove[] {
  const def = gameDef(game.gameId);
  return def.flow.legalMovesFor
    ? def.flow.legalMovesFor(game.session.state, game.session.phase, seat)
    : def.flow.legalMoves(game.session.state, game.session.phase);
}

export interface ActionOutcome<C extends RuleValues> {
  game: ParlourGame<C>;
  rejected: { code: string; message?: string } | null;
}

/** Submits a canonical action. Illegal actions are rejected, not thrown. */
export function submitAction<C extends RuleValues>(
  game: ParlourGame<C>,
  moveId: string,
  payload?: unknown,
  seat = 0,
): ActionOutcome<C> {
  const def = gameDef(game.gameId);
  const outcome = sessionApply(def, game.session, seat, moveId, payload);
  return {
    game: { gameId: game.gameId, session: outcome.session as GameSession<unknown, C> },
    rejected: outcome.rejected ? { code: outcome.rejected.code, message: outcome.rejected.message } : null,
  };
}

/** Undoes the last player action (and its automatic consequences), by replay. */
export function undo<C extends RuleValues>(game: ParlourGame<C>, steps = 1): ParlourGame<C> {
  const def = gameDef(game.gameId);
  const session = undoSession(def, game.session, { steps }) as GameSession<unknown, C>;
  return { gameId: game.gameId, session };
}

export function canUndo<C extends RuleValues>(game: ParlourGame<C>): boolean {
  try {
    // undoPoints isn't exported; cheapest safe probe is a dry undo() catch.
    undoSession(gameDef(game.gameId), game.session, { steps: 1 });
    return true;
  } catch {
    return false;
  }
}

/** Canonical completion/win status — a direct call into the game's own `end()`. */
export function completion<C extends RuleValues>(game: ParlourGame<C>): MatchResult | null {
  return gameDef(game.gameId).end(game.session.state);
}

/** Desync-detector checksum (not tamper-proof; see runtime.ts stateHash doc). */
export function hashOf<C extends RuleValues>(game: ParlourGame<C>): string {
  return stateHash(game.session.state);
}

/**
 * Presentation-neutral projection of a session: everything a future Web or
 * terminal surface needs, nothing that treats React/DOM as authority.
 */
export function project<C extends RuleValues>(game: ParlourGame<C>) {
  const s = game.session;
  return {
    gameId: game.gameId,
    seed: s.seed,
    options: s.config,
    seats: s.seats,
    status: s.status,
    result: s.result,
    phase: s.phase,
    state: s.state,
    log: s.log,
    stateHash: stateHash(s.state),
    legalMoves: legalMoves(game),
  };
}

// ---------------------------------------------------------------------------
// Snapshot / restore — local export/import only, no database in this slice.
// ---------------------------------------------------------------------------

export interface ParlourSnapshot<C extends RuleValues = RuleValues> {
  schemaVersion: typeof PARLOUR_SNAPSHOT_SCHEMA_VERSION;
  upstreamRevision: typeof PARLOUR_UPSTREAM_REVISION;
  gameId: GameId;
  seed: number;
  seats: number;
  options: C;
  log: readonly AppliedEvent[];
  /** Non-derived, non-authoritative convenience fields — never replayed from. */
  meta?: { createdAtMs?: number; label?: string };
}

/** Exports exactly what's needed to reconstruct the game — the log, not the state. */
export function toSnapshot<C extends RuleValues>(
  game: ParlourGame<C>,
  meta?: ParlourSnapshot<C>['meta'],
): ParlourSnapshot<C> {
  return {
    schemaVersion: PARLOUR_SNAPSHOT_SCHEMA_VERSION,
    upstreamRevision: PARLOUR_UPSTREAM_REVISION,
    gameId: game.gameId,
    seed: game.session.seed,
    seats: game.session.seats,
    options: game.session.config,
    log: game.session.log,
    meta,
  };
}

/**
 * Restores from a snapshot by canonical replay, then verifies exact
 * state/hash parity against the game that produced it isn't assumed — the
 * caller should compare `hashOf(restored)` against a hash captured at
 * snapshot time if one was recorded.
 */
export function fromSnapshot<C extends RuleValues>(
  snapshot: ParlourSnapshot<C>,
  opts: { verify?: boolean } = {},
): ParlourGame<C> {
  if (snapshot.schemaVersion !== PARLOUR_SNAPSHOT_SCHEMA_VERSION) {
    throw new Error(
      `parlour: snapshot schema version ${snapshot.schemaVersion} is not the supported ${PARLOUR_SNAPSHOT_SCHEMA_VERSION}`,
    );
  }
  if (snapshot.upstreamRevision !== PARLOUR_UPSTREAM_REVISION) {
    throw new Error(
      `parlour: snapshot was taken against upstream revision ${snapshot.upstreamRevision}, this facade is pinned to ${PARLOUR_UPSTREAM_REVISION}`,
    );
  }
  return restoreGame(snapshot.gameId, snapshot.seed, snapshot.options, snapshot.log, {
    ...opts,
    seats: snapshot.seats,
  }) as ParlourGame<C>;
}

export { listAllGames as SUPPORTED_GAME_IDS };
export { stepBot, drainBotTurns, type BotStepResult } from './bots';
