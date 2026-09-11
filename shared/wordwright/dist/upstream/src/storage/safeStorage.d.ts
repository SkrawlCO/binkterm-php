/**
 * A Storage implementation that never throws (EC-11, NFR-12).
 *
 * `localStorage` is not always available: Safari private mode historically
 * threw on write, embedded webviews can disable it, and a full quota throws on
 * `setItem`. Rather than sprinkle try/catch through the app, we probe once and
 * fall back to an in-memory map with the same interface. The game stays fully
 * playable; only persistence is lost, and the UI says so exactly once.
 */
export interface SafeStorage {
    readonly getItem: (key: string) => string | null;
    readonly setItem: (key: string, value: string) => void;
    readonly removeItem: (key: string) => void;
    /** All keys currently held. */
    readonly keys: () => readonly string[];
    /** False when running on the in-memory fallback. */
    readonly isPersistent: boolean;
}
/**
 * Builds a SafeStorage over the given backend, or an in-memory stand-in when
 * the backend is missing or unusable.
 */
export declare function createSafeStorage(backend: Storage | undefined): SafeStorage;
/** Drops the memoised instance. Used by tests that swap the global. */
export declare function resetSafeStorage(): void;
export declare const safeStorage: SafeStorage;
