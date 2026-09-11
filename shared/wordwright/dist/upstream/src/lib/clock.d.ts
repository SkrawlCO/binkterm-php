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
/** Current time in epoch milliseconds, monotonic within this session. */
export declare function now(): number;
/**
 * Clamps an elapsed duration into a sane range.
 *
 * Guards a session restored across a device clock change: the baseline came
 * from a previous page load, so the delta can be nonsense in either direction.
 */
export declare function clampDuration(ms: number): number;
