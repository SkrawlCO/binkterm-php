/**
 * The local calendar date as YYYY-MM-DD. This — never toISOString(),
 * which reports the UTC date — is the app's notion of "today": the
 * daily puzzle rolls over at the player's local midnight and streak
 * bookkeeping uses the same string.
 */
export declare function todayLocalISO(now?: Date): string;
