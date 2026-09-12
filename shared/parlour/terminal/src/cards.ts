/**
 * Card presentation only — parses the canonical `CardId` string format that
 * every vendored game builds via `stdDeck()` (engine/src/types.ts:
 * `${SuitLetter}${rank 1-13}`, e.g. "S12" = Q♠). This is pure formatting: it
 * derives a suit symbol and rank label from the id's own two-part shape,
 * it does not decide which cards exist, what a rank is worth, or which
 * moves are legal — none of that is duplicated here.
 *
 * The facade's `'??'` hidden-card sentinel (HiddenKlondikeCard and its
 * siblings) is rendered face-down; anything else that doesn't parse as a
 * card id is passed through as-is (defensive, should not happen against
 * canonical state).
 */

export type SuitLetter = 'S' | 'H' | 'D' | 'C';

const SUIT_SYMBOL: Record<SuitLetter, string> = { S: '♠', H: '♥', D: '♦', C: '♣' };
const SUIT_ASCII: Record<SuitLetter, string> = { S: 'S', H: 'H', D: 'D', C: 'C' };
const RANK_LABEL = ['A', '2', '3', '4', '5', '6', '7', '8', '9', '10', 'J', 'Q', 'K'];

export interface ParsedCard {
  suit: SuitLetter;
  rank: number; // 1-13
  rankLabel: string;
  red: boolean;
}

/**
 * The base `${suit}${rank}` shape is universal (see stdDeck() above), but
 * Spider builds its own multi-deck stock with a trailing copy-letter suffix
 * to keep, e.g., two 5♥ ids distinct internally ("H5", "H5b", ...) — see
 * game-spider/src/cards.ts `deckFor()`. The suffix is purely an internal
 * disambiguator: a table never distinguishes which physical 5♥ a player
 * holds, so it is accepted here and ignored for display, not treated as a
 * different card.
 */
export function parseCardId(id: string): ParsedCard | null {
  const m = /^([SHDC])(\d{1,2})[a-h]?$/.exec(id);
  if (!m) return null;
  const suit = m[1] as SuitLetter;
  const rank = Number(m[2]);
  if (rank < 1 || rank > 14) return null;
  // Durak's own 36-card deck (game-durak/src/cards.ts) numbers 6..14 with 14
  // as the Ace, rather than the 1..13/Ace-low scheme every stdDeck() game
  // uses — both are the same suit-letter+rank-number CardId shape, just a
  // different upstream rank range. Formatting only: rank 14 always means Ace.
  const rankLabel = rank === 14 ? 'A' : RANK_LABEL[rank - 1]!;
  return { suit, rank, rankLabel, red: suit === 'H' || suit === 'D' };
}

export function isFaceDown(id: string): boolean {
  return id === '??';
}

/**
 * Pinochle's own double-deck id shape: `${suit}${textualRank}-${copy}`, e.g.
 * "SA-0", "S10-1" (see game-pinochle/src/cards.ts `pinochleDeck()`) — the
 * rank is already a printed label (9/J/Q/K/10/A), not a stdDeck() numeric
 * index, so it needs its own (still purely cosmetic) parse.
 */
function parsePinochleStyleCard(id: string): { suit: SuitLetter; rankLabel: string; red: boolean } | null {
  const m = /^([SHDC])(9|10|J|Q|K|A)-\d$/.exec(id);
  if (!m) return null;
  const suit = m[1] as SuitLetter;
  return { suit, rankLabel: m[2]!, red: suit === 'H' || suit === 'D' };
}

/** Compact "10H", "QS" style label with a real suit glyph (or ASCII fallback). */
export function formatCard(id: string, opts: { ascii?: boolean } = {}): string {
  if (isFaceDown(id)) return opts.ascii ? '##' : '░░';
  const parsed = parseCardId(id) ?? parsePinochleStyleCard(id);
  if (!parsed) return id; // unrecognized token (e.g. Wild/Spite's own colour/rank ids), show verbatim
  const suit = opts.ascii ? SUIT_ASCII[parsed.suit] : SUIT_SYMBOL[parsed.suit];
  return `${parsed.rankLabel}${suit}`;
}

/** Fixed-width (3-char) card cell for aligned columns/rows. */
export function formatCardCell(id: string, opts: { ascii?: boolean } = {}): string {
  const s = formatCard(id, opts);
  return s.length >= 3 ? s : s.padEnd(3, ' ');
}

/** True when this card id should render in the "red" ANSI colour. */
/** True for any id this module can format as a real card (any recognized deck shape). */
export function looksLikeCardId(id: string): boolean {
  return parseCardId(id) !== null || parsePinochleStyleCard(id) !== null;
}

export function isRed(id: string): boolean {
  const parsed = parseCardId(id) ?? parsePinochleStyleCard(id);
  return parsed?.red ?? false;
}
