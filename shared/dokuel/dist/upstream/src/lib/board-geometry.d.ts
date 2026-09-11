/**
 * Precomputed 9x9 board geometry shared by candidate-based reasoning.
 * Cells are 0-80 indices into the row-major 81-char board format.
 */
export declare const ALL_DIGITS = 1022;
export declare function boxIndex(cell: number): number;
export declare function popcount(mask: number): number;
/** The digits (1-9) set in a candidate bitmask, ascending. */
export declare function maskDigits(mask: number): number[];
/** All size-k subsets of items, in ascending index order. */
export declare function kCombinations<T>(items: T[], k: number): T[][];
export declare const UNITS: number[][];
export declare const PEERS: number[][];
