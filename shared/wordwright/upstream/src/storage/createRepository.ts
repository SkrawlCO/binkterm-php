import { safeStorage, type SafeStorage } from './safeStorage';

/**
 * Reported when stored data could not be used, so the UI can warn once (EC-12).
 */
export type StorageIssue = 'corrupt' | 'unsupported-version';

export interface Repository<T> {
  /** Always returns usable data; falls back to defaults on any failure. */
  readonly read: () => T;
  readonly write: (value: T) => void;
  readonly clear: () => void;
  /** Fires on writes from *other* tabs (EC-16). Returns an unsubscribe. */
  readonly subscribe: (listener: (value: T) => void) => () => void;
}

export interface RepositoryOptions<T> {
  readonly key: string;
  /** Fresh defaults per call, so callers cannot mutate a shared object. */
  readonly defaults: () => T;
  /** Turns untrusted parsed JSON into a valid value, or null to reject it. */
  readonly validate: (value: unknown) => T | null;
  /** Optional pre-validation upgrade step. */
  readonly migrate?: (value: unknown) => { data: unknown; ok: boolean };
  /** Notified when stored data is rejected, for a one-time user warning. */
  readonly onIssue?: (issue: StorageIssue, key: string) => void;
  readonly storage?: SafeStorage;
}

/**
 * A typed, fail-safe wrapper over one storage key (ARCHITECTURE §7.2).
 *
 * Components never touch storage directly (NFR-11); they go through a
 * repository so that parsing, validation, migration and error handling live in
 * exactly one place. `read()` is total: it cannot throw and cannot return
 * malformed data.
 */
export function createRepository<T>({
  key,
  defaults,
  validate,
  migrate: runMigration,
  onIssue,
  storage = safeStorage,
}: RepositoryOptions<T>): Repository<T> {
  function read(): T {
    const raw = storage.getItem(key);
    if (raw === null) return defaults();

    let parsed: unknown;
    try {
      parsed = JSON.parse(raw);
    } catch {
      onIssue?.('corrupt', key);
      return defaults();
    }

    if (runMigration) {
      const result = runMigration(parsed);
      if (!result.ok) {
        onIssue?.('unsupported-version', key);
        return defaults();
      }
      parsed = result.data;
    }

    const valid = validate(parsed);
    if (!valid) {
      onIssue?.('corrupt', key);
      return defaults();
    }

    return valid;
  }

  function write(value: T): void {
    try {
      // Serialise before writing so a value containing a cycle or a BigInt
      // fails here rather than leaving a partial record (FR-42).
      const serialised = JSON.stringify(value);
      storage.setItem(key, serialised);
    } catch {
      // safeStorage already swallows quota errors; this catches serialisation
      // failures, which indicate a programming error rather than a full disk.
    }
  }

  function clear(): void {
    storage.removeItem(key);
  }

  function subscribe(listener: (value: T) => void): () => void {
    if (typeof window === 'undefined') return () => undefined;

    const handler = (event: StorageEvent): void => {
      // key === null means the whole store was cleared.
      if (event.key !== null && event.key !== key) return;
      listener(read());
    };

    window.addEventListener('storage', handler);
    return () => {
      window.removeEventListener('storage', handler);
    };
  }

  return { read, write, clear, subscribe };
}
