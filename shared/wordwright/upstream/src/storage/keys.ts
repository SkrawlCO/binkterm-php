/**
 * LocalStorage keys (ARCHITECTURE §7.1).
 *
 * One namespace, one key per slice. Slices are independent so a corrupt
 * session can never take statistics down with it.
 */

const NAMESPACE = 'wordwright';

/** Bumped only for breaking layout changes; field additions use migrations. */
export const STORAGE_VERSION = 'v1';

const prefix = `${NAMESPACE}:${STORAGE_VERSION}`;

export const STORAGE_KEYS = {
  settings: `${prefix}:settings`,
  stats: `${prefix}:stats`,
  session: `${prefix}:session`,
  meta: `${prefix}:meta`,
} as const;

export type StorageKey = (typeof STORAGE_KEYS)[keyof typeof STORAGE_KEYS];

/** Every key we own, for the "reset all data" recovery path (EC-21). */
export const ALL_STORAGE_KEYS: readonly string[] = Object.values(STORAGE_KEYS);

/** True for any key in our namespace, including older versions. */
export function isOwnedKey(key: string): boolean {
  return key.startsWith(`${NAMESPACE}:`);
}

/** Current schema version for migrations (EC-13). */
export const CURRENT_SCHEMA_VERSION = 1;
