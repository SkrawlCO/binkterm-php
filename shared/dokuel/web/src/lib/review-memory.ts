const preferences = new Map<string, string>();
export const reviewMemory = {
    getItem: (key: string) => preferences.get(key) ?? null,
    setItem: (key: string, value: string) => { preferences.set(key, value); },
};
