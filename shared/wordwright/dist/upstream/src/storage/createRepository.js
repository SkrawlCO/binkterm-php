import { safeStorage } from './safeStorage.js';
/**
 * A typed, fail-safe wrapper over one storage key (ARCHITECTURE §7.2).
 *
 * Components never touch storage directly (NFR-11); they go through a
 * repository so that parsing, validation, migration and error handling live in
 * exactly one place. `read()` is total: it cannot throw and cannot return
 * malformed data.
 */
export function createRepository({ key, defaults, validate, migrate: runMigration, onIssue, storage = safeStorage, }) {
    function read() {
        const raw = storage.getItem(key);
        if (raw === null)
            return defaults();
        let parsed;
        try {
            parsed = JSON.parse(raw);
        }
        catch {
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
    function write(value) {
        try {
            // Serialise before writing so a value containing a cycle or a BigInt
            // fails here rather than leaving a partial record (FR-42).
            const serialised = JSON.stringify(value);
            storage.setItem(key, serialised);
        }
        catch {
            // safeStorage already swallows quota errors; this catches serialisation
            // failures, which indicate a programming error rather than a full disk.
        }
    }
    function clear() {
        storage.removeItem(key);
    }
    function subscribe(listener) {
        if (typeof window === 'undefined')
            return () => undefined;
        const handler = (event) => {
            // key === null means the whole store was cleared.
            if (event.key !== null && event.key !== key)
                return;
            listener(read());
        };
        window.addEventListener('storage', handler);
        return () => {
            window.removeEventListener('storage', handler);
        };
    }
    return { read, write, clear, subscribe };
}
