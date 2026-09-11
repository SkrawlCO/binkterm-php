import { type Action, type State } from './upstream/src/lib/board-engine.ts';
import type { Difficulty, AssistLevel, Position } from './upstream/src/lib/types.ts';
export type { Difficulty, AssistLevel } from './upstream/src/lib/types.ts';
export declare const REVISION = "6aad7ccaccb7d354fb7ed88472e48ba2b2cb2f8e";
export type SessionAction = Exclude<Action, {
    type: 'RESET' | 'SET_SELECTED_CELLS';
}> | {
    type: 'SET_SELECTED_CELLS';
    cells: number[];
    primary: Position;
};
export type Identity = {
    kind: 'solo';
    key: string;
} | {
    kind: 'daily';
    date: string;
} | {
    kind: 'imported';
};
export type Snapshot = {
    schema: 1;
    revision: typeof REVISION;
    puzzle: string;
    difficulty: Difficulty;
    identity: Identity;
    assistLevel: AssistLevel;
    actions: SessionAction[];
    elapsedMs: number;
    paused: boolean;
};
/** Pure in-memory session. No clock, storage, UI, network, or global statistics side effects. */
export declare class DokuelSession {
    #private;
    private constructor();
    /** Uses the exact seed expression from upstream useResumableSudoku. */
    static startSolo(level: Difficulty, key: string, assist?: AssistLevel): DokuelSession;
    static startDaily(date?: string, level?: Difficulty, assist?: AssistLevel): DokuelSession;
    /** Starts existing original givens; continuation uses restore(), never a regenerated seed. */
    static fromPuzzle(puzzle: string, level: Difficulty, assist?: AssistLevel): DokuelSession;
    static restore(input: unknown): DokuelSession;
    dispatch(input: SessionAction): void;
    selectCell(row: number, col: number): void;
    enterDigit(value: number, autoEliminateNotes?: boolean, asNote?: boolean): void;
    toggleNoteAt(row: number, col: number, value: number): void;
    toggleNotes(): void;
    erase(): void;
    undo(): void;
    requestHint(): void;
    dismissHint(): void;
    pause(): void;
    resume(): void;
    setAssistLevel(level: AssistLevel): void;
    /** Host supplies active elapsed milliseconds; paused/completed games do not accrue time. Restore adds no downtime. */
    advanceTime(milliseconds: number): void;
    /** Detached canonical state and projections. Mutating the result cannot mutate the session. */
    view(): {
        puzzle: string;
        difficulty: Difficulty;
        identity: Identity;
        assistLevel: AssistLevel;
        elapsedMs: number;
        paused: boolean;
        grade: import("./upstream/src/lib/grader.ts").PuzzleGrade;
        state: State;
        grid: import("./upstream/src/lib/board-engine.ts").SavedBoard;
        projection: import("./upstream/src/lib/board-engine.ts").BoardProjection;
    };
    snapshot(): Snapshot;
    export(): string;
}
