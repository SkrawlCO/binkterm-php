/**
 * Terminal-only presentation metadata: which family layout a game uses, its
 * caller-facing label, and its canonical minimum seat count. None of this
 * changes canonical game identity (facade `GameId` stays the source of
 * truth) — it only decides which renderer function draws the table and how
 * the chooser groups 23 entries into a browsable list.
 */
import type { GameId } from '../../facade/index';

export type Family =
  | 'tableau-solitaire' // Klondike, FreeCell, Golf, TriPeaks — column/foundation spatial layout
  | 'pyramid-solitaire' // Pyramid — its own triangular layout
  | 'spider-solitaire' // Spider — tableau layout with panning (tallest columns)
  | 'trick-taking' // Hearts, Spades, Euchre, Oh Hell, Pinochle
  | 'capture' // Scopa — table capture, presented like trick-taking
  | 'shedding' // Crazy Eights, Wild, President, Palace, Durak, Spite & Malice, Rat Screw
  | 'meld' // Gin
  | 'special'; // Cribbage, Blitz, Poker — bespoke structure each

export interface CatalogEntry {
  id: GameId;
  label: string;
  family: Family;
  minSeats: number;
  group: 'Solitaire' | 'Trick-taking' | 'Shedding' | 'Rummy & special';
}

export const CATALOG: readonly CatalogEntry[] = [
  { id: 'klondike', label: 'Klondike', family: 'tableau-solitaire', minSeats: 1, group: 'Solitaire' },
  { id: 'freecell', label: 'FreeCell', family: 'tableau-solitaire', minSeats: 1, group: 'Solitaire' },
  { id: 'spider', label: 'Spider', family: 'spider-solitaire', minSeats: 1, group: 'Solitaire' },
  { id: 'pyramid', label: 'Pyramid', family: 'pyramid-solitaire', minSeats: 1, group: 'Solitaire' },
  { id: 'golf', label: 'Golf', family: 'tableau-solitaire', minSeats: 1, group: 'Solitaire' },
  { id: 'tripeaks', label: 'TriPeaks', family: 'tableau-solitaire', minSeats: 1, group: 'Solitaire' },

  { id: 'hearts', label: 'Hearts', family: 'trick-taking', minSeats: 4, group: 'Trick-taking' },
  { id: 'spades', label: 'Spades', family: 'trick-taking', minSeats: 4, group: 'Trick-taking' },
  { id: 'euchre', label: 'Euchre', family: 'trick-taking', minSeats: 4, group: 'Trick-taking' },
  { id: 'ohhell', label: 'Oh Hell', family: 'trick-taking', minSeats: 3, group: 'Trick-taking' },
  { id: 'pinochle', label: 'Pinochle', family: 'trick-taking', minSeats: 4, group: 'Trick-taking' },
  { id: 'scopa', label: 'Scopa', family: 'capture', minSeats: 2, group: 'Trick-taking' },

  { id: 'eights', label: 'Crazy Eights', family: 'shedding', minSeats: 2, group: 'Shedding' },
  { id: 'wild', label: 'Wild', family: 'shedding', minSeats: 2, group: 'Shedding' },
  { id: 'president', label: 'President', family: 'shedding', minSeats: 4, group: 'Shedding' },
  { id: 'palace', label: 'Palace', family: 'shedding', minSeats: 2, group: 'Shedding' },
  { id: 'durak', label: 'Durak', family: 'shedding', minSeats: 2, group: 'Shedding' },
  { id: 'spite', label: 'Spite & Malice', family: 'shedding', minSeats: 2, group: 'Shedding' },
  { id: 'ratscrew', label: 'Rat Screw', family: 'shedding', minSeats: 2, group: 'Shedding' },

  { id: 'gin', label: 'Gin Rummy', family: 'meld', minSeats: 2, group: 'Rummy & special' },
  { id: 'cribbage', label: 'Cribbage', family: 'special', minSeats: 2, group: 'Rummy & special' },
  { id: 'blitz', label: 'Blitz', family: 'special', minSeats: 2, group: 'Rummy & special' },
  { id: 'poker', label: 'Poker', family: 'special', minSeats: 2, group: 'Rummy & special' },
];

export function catalogEntry(id: GameId): CatalogEntry {
  const entry = CATALOG.find((e) => e.id === id);
  if (!entry) throw new Error(`parlour terminal: no catalog entry for "${id}"`);
  return entry;
}

export function groupedCatalog(): Array<{ group: CatalogEntry['group']; entries: CatalogEntry[] }> {
  const order: CatalogEntry['group'][] = ['Solitaire', 'Trick-taking', 'Shedding', 'Rummy & special'];
  return order.map((group) => ({ group, entries: CATALOG.filter((e) => e.group === group) }));
}
