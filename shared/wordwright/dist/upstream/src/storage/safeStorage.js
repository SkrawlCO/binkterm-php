/**
 * A Storage implementation that never throws (EC-11, NFR-12).
 *
 * `localStorage` is not always available: Safari private mode historically
 * threw on write, embedded webviews can disable it, and a full quota throws on
 * `setItem`. Rather than sprinkle try/catch through the app, we probe once and
 * fall back to an in-memory map with the same interface. The game stays fully
 * playable; only persistence is lost, and the UI says so exactly once.
 */
/** Writes, reads back and deletes a sentinel to prove storage actually works. */
function probe(storage) {
    const sentinel = '__wordwright_probe__';
    try {
        storage.setItem(sentinel, '1');
        const readBack = storage.getItem(sentinel);
        storage.removeItem(sentinel);
        return readBack === '1';
    }
    catch {
        return false;
    }
}
function createMemoryStorage() {
    const store = new Map();
    return {
        getItem: (key) => store.get(key) ?? null,
        setItem: (key, value) => {
            store.set(key, value);
        },
        removeItem: (key) => {
            store.delete(key);
        },
        keys: () => [...store.keys()],
        isPersistent: false,
    };
}
function createBrowserStorage(storage) {
    return {
        getItem: (key) => {
            try {
                return storage.getItem(key);
            }
            catch {
                return null;
            }
        },
        setItem: (key, value) => {
            try {
                storage.setItem(key, value);
            }
            catch {
                // Quota exceeded or storage revoked mid-session. Dropping the write is
                // the right call: the game continues, and the next successful write
                // will carry the current state anyway.
            }
        },
        removeItem: (key) => {
            try {
                storage.removeItem(key);
            }
            catch {
                // Nothing useful to do; the value is unreachable either way.
            }
        },
        keys: () => {
            try {
                return Object.keys(storage);
            }
            catch {
                return [];
            }
        },
        isPersistent: true,
    };
}
/**
 * Builds a SafeStorage over the given backend, or an in-memory stand-in when
 * the backend is missing or unusable.
 */
export function createSafeStorage(backend) {
    if (!backend || !probe(backend))
        return createMemoryStorage();
    return createBrowserStorage(backend);
}
function resolveBackend() {
    try {
        return typeof localStorage === 'undefined' ? undefined : localStorage;
    }
    catch {
        // Accessing the property itself can throw when cookies are blocked.
        return undefined;
    }
}
/**
 * The app-wide storage instance, resolved on first use rather than at import.
 *
 * Binding the backend at module load would capture whatever `localStorage` was
 * at that instant. That is wrong in two situations: a test that swaps the
 * global afterwards, and a browser that revokes storage access mid-session.
 * Deferring means we always probe the live object.
 */
let instance = null;
function current() {
    instance ??= createSafeStorage(resolveBackend());
    return instance;
}
/** Drops the memoised instance. Used by tests that swap the global. */
export function resetSafeStorage() {
    instance = null;
}
export const safeStorage = {
    getItem: (key) => current().getItem(key),
    setItem: (key, value) => {
        current().setItem(key, value);
    },
    removeItem: (key) => {
        current().removeItem(key);
    },
    keys: () => current().keys(),
    get isPersistent() {
        return current().isPersistent;
    },
};
