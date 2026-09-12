/**
 * Shared renderer for every non-solitaire family: trick-taking, capture,
 * shedding, meld, and the bespoke "special" games (Cribbage/Blitz/Poker).
 * These 17 games all name their fields the same way across the vendored
 * catalog (see the state.ts survey this file is built from: `hands`,
 * `stock`, `discard`/`pile`/`center`, `turn`, `dealer`, `trick`, `scores`/
 * `totals`, `trump`/`trumpSuit`, `bids`, `outcome`/`summary`). This renderer
 * reads only those known field names from the seat's own `playerView`
 * projection — it never infers a legal move or a rule, it only prints what
 * is already there.
 */
import { pick, rule, cardCell, dim, seatLabel } from './common';

/**
 * A few games wrap one live round/hand inside a match-level state (Crazy
 * Eights: `EightsState.round`; Gin's match def: `GinMatchState.hand`) —
 * cumulative scores live on the outer object, but hands/stock/discard/turn
 * live on the inner one. This merges the inner round on top of the outer
 * match state for field lookups below (outer `scores`/`dealer` stay
 * visible; the round's own `hands`/`stock`/etc. take precedence) — still
 * only reading known canonical field names, never inventing one.
 */
function unwrapRound(state: unknown): unknown {
  const round = pick<Record<string, unknown>>(state, ['round', 'hand']);
  if (round && typeof round === 'object' && ('hands' in round || 'piles' in round)) {
    return { ...(state as Record<string, unknown>), ...round };
  }
  return state;
}

export function renderGeneric(rawState: unknown, humanSeat: number): string[] {
  const lines: string[] = [];
  const state = unwrapRound(rawState);

  const seats = pick<number>(state, ['seats']) ?? undefined;
  const dealer = pick<number>(state, ['dealer']);
  const turn = pick<number | null>(state, ['turn']);
  const stage = pick<string>(state, ['stage', 'street']);
  const veiled = pick<boolean>(state, ['veiled']);

  const headerBits = [
    stage ? `stage: ${stage}` : null,
    seats !== undefined ? `seats: ${seats}` : null,
    dealer !== undefined ? `dealer: seat ${dealer}` : null,
    turn !== undefined && turn !== null ? `turn: ${seatLabel(turn, humanSeat)}` : null,
    veiled ? 'veiled' : null,
  ].filter(Boolean);
  if (headerBits.length) lines.push(headerBits.join('   '));

  const trump = pick<string>(state, ['trumpSuit', 'trump']);
  const trumpCard = pick<string>(state, ['trumpCard', 'upcard']);
  if (trump || trumpCard) {
    lines.push(`trump: ${trump ?? ''}${trumpCard ? `  (turned: ${cardCell(trumpCard)})` : ''}`);
  }

  const bids = pick<readonly unknown[]>(state, ['bids']);
  if (bids && bids.length) {
    lines.push(
      `bids: ${bids
        .map((b, i) => (b === null || b === undefined ? `#${i}=-` : `#${i}=${JSON.stringify(b)}`))
        .join('  ')}`,
    );
  }

  lines.push('');
  lines.push(rule('table'));

  const trick = pick<{ plays?: readonly { seat: number; card: string }[] } | readonly { seat: number; card: string }[]>(
    state,
    ['trick', 'pile', 'table'],
  );
  const looksLikeTrickPlay = (v: unknown): v is { seat: number; card: string } =>
    !!v && typeof v === 'object' && 'card' in (v as object) && 'seat' in (v as object);
  const trickPlaysRaw = Array.isArray(trick) ? trick : (trick as { plays?: readonly { seat: number; card: string }[] })?.plays;
  const trickPlays = trickPlaysRaw && trickPlaysRaw.every(looksLikeTrickPlay) ? trickPlaysRaw : undefined;
  if (trickPlays && trickPlays.length) {
    lines.push(`trick: ${trickPlays.map((p) => `#${p.seat}:${cardCell(p.card)}`).join('  ')}`);
  }

  // Shedding/rummy discard-style single pile (Eights/Wild/Gin/Blitz).
  const discard = pick<readonly string[]>(state, ['discard']);
  if (discard) {
    lines.push(`discard [${discard.length}]: top ${discard.length ? cardCell(discard[discard.length - 1]!) : dim('(empty)')}`);
  }
  // Rat Screw's shared center flip pile.
  const center = pick<readonly string[]>(state, ['center']);
  if (center) {
    lines.push(`center [${center.length}]: top ${center.length ? cardCell(center[center.length - 1]!) : dim('(empty)')}`);
  }
  // Palace/President's played-to pile.
  const pilePlain = !trickPlays ? pick<readonly string[]>(state, ['pile']) : undefined;
  if (pilePlain && pilePlain.every((v) => typeof v === 'string')) {
    lines.push(`pile [${pilePlain.length}]: top ${pilePlain.length ? cardCell(pilePlain[pilePlain.length - 1]!) : dim('(empty)')}`);
  }
  // Durak's attack/defend table.
  const durakTableRaw = pick<readonly { attack: string; defend: string | null }[]>(state, ['table']);
  const durakTable =
    durakTableRaw && durakTableRaw.every((v) => v && typeof v === 'object' && 'attack' in v) ? durakTableRaw : undefined;
  if (durakTable) {
    lines.push(
      `table: ${durakTable.map((p) => `${cardCell(p.attack)}${p.defend ? '/' + cardCell(p.defend) : ''}`).join('  ') || dim('(empty)')}`,
    );
  }
  // Poker board / Scopa table cards.
  const board = pick<readonly string[]>(state, ['board']);
  if (board) lines.push(`board: ${board.map(cardCell).join(' ') || dim('(none yet)')}`);
  const tableCards = pick<readonly string[]>(state, ['tableCards']);
  if (tableCards) lines.push(`table cards: ${tableCards.map(cardCell).join(' ') || dim('(empty)')}`);

  const stock = pick<readonly string[]>(state, ['stock']);
  if (stock) lines.push(`stock: ${stock.length} card(s) face down`);
  const crib = pick<readonly string[]>(state, ['crib']);
  if (crib) lines.push(`crib [${crib.length}]${humanSeat === dealer ? ` : ${crib.map(cardCell).join(' ')}` : ' (dealer’s)'}`);

  lines.push('');
  lines.push(rule('scores'));
  const scores = pick<readonly number[]>(state, ['scores', 'totals']);
  if (scores) lines.push(scores.map((v, i) => `${seatLabel(i, humanSeat)}=${v}`).join('   '));
  const stacks = pick<readonly number[]>(state, ['stacks']); // poker chip stacks
  if (stacks) lines.push('chips: ' + stacks.map((v, i) => `${seatLabel(i, humanSeat)}=${v}`).join('   '));
  const tricksBySeat = pick<readonly number[]>(state, ['tricksBySeat', 'tricksWon']);
  if (tricksBySeat) lines.push('tricks: ' + tricksBySeat.map((v, i) => `${seatLabel(i, humanSeat)}=${v}`).join('   '));

  const outcome = pick<unknown>(state, ['outcome', 'summary', 'lastHand']);
  if (outcome) {
    lines.push('');
    lines.push(rule('last result'));
    lines.push(dim(JSON.stringify(outcome)));
  }

  lines.push('');
  lines.push(rule('your hand'));
  const hands = pick<readonly (readonly string[])[]>(state, ['hands', 'piles', 'hole']);
  const yourHand = hands?.[humanSeat] ?? [];
  lines.push(yourHand.length ? yourHand.map(cardCell).join(' ') : dim('(no cards)'));

  return lines;
}
