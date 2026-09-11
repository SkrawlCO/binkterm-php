/**
 * A monotonic wall-clock (FR-22, EC-19).
 *
 * `Date.now()` alone is unsafe for timing: NTP corrections, manual clock
 * changes, and daylight-saving jumps can run it backwards, which would produce
 * negative solve times and absurd "fastest solve" records. `performance.now()`
 * is monotonic but measures from page load, so it cannot be persisted across a
 * refresh.
 *
 * We combine them: an epoch baseline captured once at module load, advanced by
 * the monotonic delta. The result is epoch-comparable *and* never goes
 * backwards within a session.
 */
const hasPerformance = typeof performance !== 'undefined' && typeof performance.now === 'function';
const EPOCH_BASELINE = Date.now();
const MONOTONIC_BASELINE = hasPerformance ? performance.now() : 0;
/** Current time in epoch milliseconds, monotonic within this session. */
export function now() {
    if (!hasPerformance)
        return Date.now();
    return EPOCH_BASELINE + (performance.now() - MONOTONIC_BASELINE);
}
/** One day, the ceiling for a plausible solve time (EC-19). */
const MAX_PLAUSIBLE_SOLVE_MS = 24 * 60 * 60 * 1000;
/**
 * Clamps an elapsed duration into a sane range.
 *
 * Guards a session restored across a device clock change: the baseline came
 * from a previous page load, so the delta can be nonsense in either direction.
 */
export function clampDuration(ms) {
    if (!Number.isFinite(ms) || ms < 0)
        return 0;
    return Math.min(ms, MAX_PLAUSIBLE_SOLVE_MS);
}
