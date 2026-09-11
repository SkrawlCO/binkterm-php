import { type SafeStorage } from './safeStorage.js';
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
    readonly migrate?: (value: unknown) => {
        data: unknown;
        ok: boolean;
    };
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
export declare function createRepository<T>({ key, defaults, validate, migrate: runMigration, onIssue, storage, }: RepositoryOptions<T>): Repository<T>;
