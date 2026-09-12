/**
 * Bot-turn orchestration — shared by the terminal adapter and the
 * persistence envelope layer (bot continuity across a save/restore is just
 * this run again after `fromSnapshot`). Pure wiring over the canonical
 * engine's own exported primitives — `actingSeats`, `def.flow.legalMovesFor`
 * / `legalMoves`, `def.playerView`, `chooseBotMove`, `makeRng`, and
 * `sessionApply` (the same primitives `engine/src/bots.ts#runBotGame` uses
 * internally for headless simulation). This file does not choose a move,
 * evaluate a hand, or decide legality — it only asks "whose turn is it,
 * what can they legally do, and what does their own bot policy pick",
 * exactly as runBotGame does, one step at a time instead of to completion,
 * so a caller surface can redraw/checkpoint between bot turns.
 */
import {
  actingSeats,
  chooseBotMove,
  makeRng,
  sessionApply,
  type GameDef,
  type LegalMove,
  type RuleValues,
  type SeatId,
} from '../vendor/packages/engine/src/index';
import type { ParlourGame } from './index';

export interface BotStepResult<C extends RuleValues> {
  acted: boolean;
  seat: SeatId | null;
  policyId: string | null;
  move: LegalMove | null;
  game: ParlourGame<C>;
  rejected: { code: string; message?: string } | null;
}

/**
 * Finds the first acting seat with a bot policy assigned and a legal move,
 * asks that policy to choose, and applies it through the normal session
 * runtime. Returns `acted: false` (no-op) when no bot-controlled seat is
 * currently able to act, e.g. it is the human's turn.
 */
export function stepBot<C extends RuleValues>(
  def: GameDef<unknown, C>,
  game: ParlourGame<C>,
  botSeats: ReadonlySet<SeatId>,
  humanSeat: SeatId,
): BotStepResult<C> {
  if (game.session.status !== 'playing') {
    return { acted: false, seat: null, policyId: null, move: null, game, rejected: null };
  }
  const acting = actingSeats(game.session.phase);
  let actor: SeatId | null = null;
  let legal: readonly LegalMove[] = [];
  for (const seat of acting) {
    if (seat === humanSeat || !botSeats.has(seat)) continue;
    const forSeat = def.flow.legalMovesFor
      ? def.flow.legalMovesFor(game.session.state, game.session.phase, seat)
      : def.flow.legalMoves(game.session.state, game.session.phase);
    if (forSeat.length > 0) {
      actor = seat;
      legal = forSeat;
      break;
    }
  }
  if (actor === null) {
    return { acted: false, seat: null, policyId: null, move: null, game, rejected: null };
  }

  // Deterministic per-seat, per-log-position fork so replay reproduces the
  // exact same bot choices — matches the fork discipline the rest of the
  // engine (and runBotGame) uses for every derived rng stream.
  const rng = makeRng(game.session.seed).fork(`parlour-bot:${actor}:${game.session.log.length}`);
  const policy = def.bots[actor % Math.max(1, def.bots.length)] ?? def.bots[0];
  if (!policy) {
    return { acted: false, seat: actor, policyId: null, move: null, game, rejected: null };
  }
  const view = def.playerView(game.session.state, actor);
  let choice = chooseBotMove(policy, view, actor, legal, rng);
  choice ??= legal[0] as LegalMove;
  const target =
    legal.find((m) => m.id === choice!.id && JSON.stringify(m.payload ?? null) === JSON.stringify(choice!.payload ?? null)) ??
    legal.find((m) => m.id === choice!.id) ??
    choice!;

  const outcome = sessionApply(def, game.session, actor, target.id, target.payload);
  return {
    acted: !outcome.rejected,
    seat: actor,
    policyId: policy.id,
    move: target,
    game: { gameId: game.gameId, session: outcome.session as typeof game.session },
    rejected: outcome.rejected ? { code: outcome.rejected.code, message: outcome.rejected.message } : null,
  };
}

/** Runs `stepBot` repeatedly until a bot can no longer act (human's turn, or game ended). */
export function drainBotTurns<C extends RuleValues>(
  def: GameDef<unknown, C>,
  game: ParlourGame<C>,
  botSeats: ReadonlySet<SeatId>,
  humanSeat: SeatId,
  maxSteps = 200,
): { game: ParlourGame<C>; steps: BotStepResult<C>[] } {
  let current = game;
  const steps: BotStepResult<C>[] = [];
  for (let i = 0; i < maxSteps; i++) {
    const step = stepBot(def, current, botSeats, humanSeat);
    if (!step.acted) break;
    steps.push(step);
    current = step.game;
  }
  return { game: current, steps };
}
