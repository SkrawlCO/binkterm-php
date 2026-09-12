/**
 * The full canonical Parlour catalog — all 23 upstream games, not a
 * six-solitaire subset. Parlour is being integrated as a coherent
 * application; this registry exists so a 24th game (or a narrower
 * presentation surface) is a registry edit, not an architecture change.
 *
 * Each entry resolves to its package's own untouched `GameDef` (or, for the
 * handful of packages that build their def from a canonical
 * `create<Id>Def(options?)` factory — durak, eights, euchre — or a
 * canonical `create<Id>MatchDef(options?)` match wrapper — blitz, cribbage,
 * gin — a direct call to that factory with no arguments, i.e. upstream's own
 * declared defaults. No option value here was invented; every default comes
 * from the vendored package itself.
 */
import { klondikeGame } from '../vendor/packages/game-klondike/src/game';
import { freecellGame } from '../vendor/packages/game-freecell/src/game';
import { spiderGame } from '../vendor/packages/game-spider/src/game';
import { pyramidGame } from '../vendor/packages/game-pyramid/src/game';
import { golfGame } from '../vendor/packages/game-golf/src/game';
import { tripeaksGame } from '../vendor/packages/game-tripeaks/src/game';
import { createBlitzMatchDef } from '../vendor/packages/game-blitz/src/matchGame';
import { createCribbageDef } from '../vendor/packages/game-cribbage/src/rules';
import { createDurakDef } from '../vendor/packages/game-durak/src/game';
import { DURAK_BOTS } from '../vendor/packages/game-durak/src/bots/index';
import { createEightsDef } from '../vendor/packages/game-eights/src/game';
import { EIGHTS_BOTS } from '../vendor/packages/game-eights/src/bots/index';
import { createEuchreDef } from '../vendor/packages/game-euchre/src/rules';
import { createGinMatchDef } from '../vendor/packages/game-gin/src/matchGame';
import { GIN_TIER_BOTS } from '../vendor/packages/game-gin/src/bots/personas';
import { heartsGame } from '../vendor/packages/game-hearts/src/game';
import { ohhellGame } from '../vendor/packages/game-ohhell/src/game';
import { palaceGame } from '../vendor/packages/game-palace/src/game';
import { pinochleGame } from '../vendor/packages/game-pinochle/src/rules';
import { pokerGame } from '../vendor/packages/game-poker/src/game';
import { presidentGame } from '../vendor/packages/game-president/src/game';
import { ratscrewGame } from '../vendor/packages/game-ratscrew/src/game';
import { scopaGame } from '../vendor/packages/game-scopa/src/game';
import { spadesGame } from '../vendor/packages/game-spades/src/game';
import { spiteGame } from '../vendor/packages/game-spite/src/game';
import { wildpileGame } from '../vendor/packages/game-wildpile/src/game';
import type { GameDef, RuleValues } from '../vendor/packages/engine/src/types';

export type GameId =
  | 'klondike'
  | 'freecell'
  | 'spider'
  | 'pyramid'
  | 'golf'
  | 'tripeaks'
  | 'blitz'
  | 'cribbage'
  | 'wild'
  | 'eights'
  | 'ratscrew'
  | 'euchre'
  | 'spades'
  | 'poker'
  | 'ohhell'
  | 'scopa'
  | 'spite'
  | 'hearts'
  | 'gin'
  | 'president'
  | 'durak'
  | 'palace'
  | 'pinochle';

/** Lazy so a 23-way import fan-out only pays for the game actually opened. */
const FACTORIES: Record<GameId, () => GameDef<unknown, RuleValues>> = {
  klondike: () => klondikeGame as GameDef<unknown, RuleValues>,
  freecell: () => freecellGame as GameDef<unknown, RuleValues>,
  spider: () => spiderGame as GameDef<unknown, RuleValues>,
  pyramid: () => pyramidGame as GameDef<unknown, RuleValues>,
  golf: () => golfGame as GameDef<unknown, RuleValues>,
  tripeaks: () => tripeaksGame as GameDef<unknown, RuleValues>,
  hearts: () => heartsGame as GameDef<unknown, RuleValues>,
  ohhell: () => ohhellGame as GameDef<unknown, RuleValues>,
  palace: () => palaceGame as GameDef<unknown, RuleValues>,
  pinochle: () => pinochleGame as GameDef<unknown, RuleValues>,
  poker: () => pokerGame as GameDef<unknown, RuleValues>,
  president: () => presidentGame as GameDef<unknown, RuleValues>,
  ratscrew: () => ratscrewGame as GameDef<unknown, RuleValues>,
  scopa: () => scopaGame as GameDef<unknown, RuleValues>,
  spades: () => spadesGame as GameDef<unknown, RuleValues>,
  spite: () => spiteGame as GameDef<unknown, RuleValues>,
  wild: () => wildpileGame as GameDef<unknown, RuleValues>,
  // durak/eights/gin's own factories default `bots` to `[]` when the caller
  // doesn't pass one (unlike euchre/blitz/cribbage, which default internally
  // to their own TIER_BOTS) — see game-durak/src/game.ts:345,
  // game-eights/src/game.ts:608, game-gin/src/rules.ts:559. Passing each
  // package's own exported bot roster (DURAK_BOTS/EIGHTS_BOTS/GIN_TIER_BOTS)
  // is exactly what the canonical web app's own solo transports do
  // (apps/web/src/lib/solo/{DurakTransport,EightsTransport}.ts) — not a new
  // bot, just wiring the same upstream roster through.
  durak: () => createDurakDef({ bots: DURAK_BOTS }) as unknown as GameDef<unknown, RuleValues>,
  eights: () => createEightsDef({ bots: EIGHTS_BOTS }) as unknown as GameDef<unknown, RuleValues>,
  euchre: () => createEuchreDef() as unknown as GameDef<unknown, RuleValues>,
  blitz: () => createBlitzMatchDef() as unknown as GameDef<unknown, RuleValues>,
  gin: () => createGinMatchDef({ bots: GIN_TIER_BOTS }) as unknown as GameDef<unknown, RuleValues>,
  // Cribbage's own `createCribbageMatchDef` returns an engine `MatchDef`
  // (best-of-N wrapper around repeated rounds via createMatch/matchApply),
  // not a plain `GameDef` — unlike blitz/gin, which build their own match
  // state machine inline and already return a session-compatible GameDef.
  // Using the single round def directly (`createCribbageDef`, exported by
  // the same vendored package) keeps this facade on one session contract
  // for all 23 games; a full best-of-N cribbage match is a `createMatch`
  // integration for later, not a rule this facade invents or duplicates.
  cribbage: () => createCribbageDef() as unknown as GameDef<unknown, RuleValues>,
};

export function listSolitaireGames(): readonly GameId[] {
  return ['klondike', 'freecell', 'spider', 'pyramid', 'golf', 'tripeaks'];
}

export function listAllGames(): readonly GameId[] {
  return Object.keys(FACTORIES) as GameId[];
}

export function gameDef(id: GameId): GameDef<unknown, RuleValues> {
  const factory = FACTORIES[id];
  if (!factory) throw new Error(`parlour: unknown game id "${id}"`);
  return factory();
}
