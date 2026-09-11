/**
 * The local calendar date as YYYY-MM-DD. This — never toISOString(),
 * which reports the UTC date — is the app's notion of "today": the
 * daily puzzle rolls over at the player's local midnight and streak
 * bookkeeping uses the same string.
 */
export function todayLocalISO(now: Date = new Date()): string {
  const y = now.getFullYear();
  const m = String(now.getMonth() + 1).padStart(2, "0");
  const d = String(now.getDate()).padStart(2, "0");
  return `${y}-${m}-${d}`;
}
