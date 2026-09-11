/**
 * LocalStorage keys (ARCHITECTURE §7.1).
 *
 * One namespace, one key per slice. Slices are independent so a corrupt
 * session can never take statistics down with it.
 */
/** Bumped only for breaking layout changes; field additions use migrations. */
export declare const STORAGE_VERSION = "v1";
export declare const STORAGE_KEYS: {
    readonly settings: "wordwright:v1:settings";
    readonly stats: "wordwright:v1:stats";
    readonly session: "wordwright:v1:session";
    readonly meta: "wordwright:v1:meta";
};
export type StorageKey = (typeof STORAGE_KEYS)[keyof typeof STORAGE_KEYS];
/** Every key we own, for the "reset all data" recovery path (EC-21). */
export declare const ALL_STORAGE_KEYS: readonly string[];
/** True for any key in our namespace, including older versions. */
export declare function isOwnedKey(key: string): boolean;
/** Current schema version for migrations (EC-13). */
export declare const CURRENT_SCHEMA_VERSION = 1;
