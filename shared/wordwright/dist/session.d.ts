import { type RandomSource } from './upstream/src/engine/random.js';
import type { GameState, WordLength } from './upstream/src/engine/types.js';
import { type StorageMeta, type StatsState } from './upstream/src/storage/schemas.js';
export declare const UPSTREAM_REVISION = "1dcf92d0c6d9e873a3d885920c21ae3ff81f0a33";
export interface Snapshot {
    schemaVersion: 1;
    upstreamRevision: typeof UPSTREAM_REVISION;
    selectedLength: WordLength;
    game: GameState;
    meta: StorageMeta;
    stats: StatsState;
}
export interface Dependencies {
    now?: () => number;
    random?: RandomSource;
    id?: () => string;
}
/** No host storage or presentation. Await construction; all subsequent actions are synchronous. */
export declare class WordwrightSession {
    private dictionaries;
    private deps;
    private game;
    private meta;
    private stats;
    private selectedLength;
    private recordedId;
    private constructor();
    private static empty;
    static create(length?: WordLength, deps?: Dependencies): Promise<WordwrightSession>;
    /** Repository-compatible reload, including upstream's mid-reveal -> playing quirk. */
    static restore(value: unknown, deps?: Dependencies): Promise<WordwrightSession>;
    private time;
    private dispatch;
    private recordCompletion;
    newGame(): void;
    setLength(length: WordLength): void;
    inputLetter(letter: string): boolean;
    backspace(): boolean;
    submit(): {
        readonly ok: false;
        readonly error: import("./upstream/src/engine.js").GuessError;
    } | {
        ok: false;
        error: "not-playing";
        value?: undefined;
    } | {
        ok: true;
        value: string;
        error?: undefined;
    } | {
        ok: false;
        error: "rejected";
        value?: undefined;
    };
    completeReveal(): boolean;
    getState(): GameState;
    getStats(): StatsState;
    getBoardProjection(): readonly import("./upstream/src/engine.js").RowView[];
    getKeyboardState(): ReadonlyMap<string, import("./upstream/src/engine.js").LetterState>;
    getRemainingAttempts(): number;
    resetStatistics(): void;
    resetHistory(): void;
    snapshot(): Snapshot;
    /** Explicit handoff settles animation via the canonical reducer and records stats before one snapshot. */
    prepareHandoff(): Snapshot;
}
