/** Display formatting. Kept together so the UI never hand-rolls a `toFixed`. */

/** Em dash for "no data yet", so empty stats never render NaN (FR-41). */
export const EMPTY_VALUE = '—';

/**
 * Formats a duration as `M:SS`, or `H:MM:SS` past an hour.
 * Returns the em dash for null, so callers can pass an absent average directly.
 */
export function formatDuration(ms: number | null): string {
  if (ms === null || !Number.isFinite(ms) || ms < 0) return EMPTY_VALUE;

  const totalSeconds = Math.floor(ms / 1000);
  const seconds = totalSeconds % 60;
  const minutes = Math.floor(totalSeconds / 60) % 60;
  const hours = Math.floor(totalSeconds / 3600);

  const pad = (value: number): string => String(value).padStart(2, '0');

  if (hours > 0) return `${String(hours)}:${pad(minutes)}:${pad(seconds)}`;
  return `${String(minutes)}:${pad(seconds)}`;
}

/** One decimal place, or the em dash when there is nothing to average (FR-38). */
export function formatAverage(value: number | null): string {
  if (value === null || !Number.isFinite(value)) return EMPTY_VALUE;
  return value.toFixed(1);
}

export function formatPercent(value: number): string {
  if (!Number.isFinite(value)) return EMPTY_VALUE;
  return `${String(Math.round(value))}%`;
}

/** Thousands separators, so a five-figure total score stays readable. */
export function formatNumber(value: number): string {
  if (!Number.isFinite(value)) return EMPTY_VALUE;
  return value.toLocaleString('en-US');
}

/** Short absolute date for the recent-games log. */
export function formatDate(timestamp: number): string {
  if (!Number.isFinite(timestamp)) return EMPTY_VALUE;

  return new Date(timestamp).toLocaleDateString('en-US', {
    month: 'short',
    day: 'numeric',
  });
}

/** Spells out an evaluation for screen readers (A11Y-3). */
export function describeEvaluation(word: string, states: readonly string[]): string {
  const parts = [...word].map((letter, index) => {
    const state = states[index];
    const description =
      state === 'correct' ? 'correct' : state === 'present' ? 'wrong position' : 'not in word';
    return `${letter} ${description}`;
  });

  return parts.join(', ');
}
