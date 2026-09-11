/**
 * A minimal `Result` type for operations that fail in expected ways.
 *
 * Used where a failure is a normal outcome the caller must handle — an invalid
 * guess, a corrupt storage record — rather than a bug. Exceptions stay reserved
 * for programmer error.
 */
export type Result<T, E> =
  { readonly ok: true; readonly value: T } | { readonly ok: false; readonly error: E };

export function ok<T>(value: T): Result<T, never> {
  return { ok: true, value };
}

export function err<E>(error: E): Result<never, E> {
  return { ok: false, error };
}
