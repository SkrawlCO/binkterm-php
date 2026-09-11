import { initState, reducer, projectBoard, serializeBoard, type Action, type State } from './upstream/src/lib/board-engine.ts';
import { generatePuzzleWithSolution, solvePuzzle } from './upstream/src/lib/sudoku.ts';
import { countSolutions } from './upstream/src/lib/solver.ts';
import { gradePuzzle } from './upstream/src/lib/grader.ts';
import { getDailyPuzzle, hashCode, seededRandom } from './upstream/src/lib/daily.ts';
import { todayLocalISO } from './upstream/src/lib/date.ts';
import type { Difficulty, AssistLevel, Position } from './upstream/src/lib/types.ts';

export type { Difficulty, AssistLevel } from './upstream/src/lib/types.ts';
export const REVISION = '6aad7ccaccb7d354fb7ed88472e48ba2b2cb2f8e';
export type SessionAction = Exclude<Action, { type: 'RESET' | 'SET_SELECTED_CELLS' }> |
    { type: 'SET_SELECTED_CELLS'; cells: number[]; primary: Position };
export type Identity = { kind: 'solo'; key: string } | { kind: 'daily'; date: string } | { kind: 'imported' };
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

// Wire-shape validation only. All Sudoku decisions belong to upstream.
function object(value: unknown): Record<string, unknown> {
    if (!value || typeof value !== 'object' || Array.isArray(value)) throw new Error('Expected object');
    return value as Record<string, unknown>;
}
function integer(value: unknown, min: number, max: number): number {
    if (typeof value !== 'number' || !Number.isSafeInteger(value) || value < min || value > max) throw new Error('Invalid integer');
    return value;
}
function boolean(value: unknown): boolean {
    if (typeof value !== 'boolean') throw new Error('Invalid boolean');
    return value;
}
function difficulty(value: unknown): Difficulty {
    if (!['easy', 'medium', 'hard', 'expert'].includes(value as string)) throw new Error('Invalid difficulty');
    return value as Difficulty;
}
function assistance(value: unknown): AssistLevel {
    if (!['paper', 'standard', 'full'].includes(value as string)) throw new Error('Invalid assistance');
    return value as AssistLevel;
}
function position(value: unknown): Position {
    const p = object(value);
    return { row: integer(p.row, 0, 8), col: integer(p.col, 0, 8) };
}
function dailyDate(value: unknown): string {
    if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value) ||
        !Number.isFinite(Date.parse(value)) || new Date(value).toISOString().slice(0, 10) !== value) throw new Error('Invalid daily date');
    return value;
}
function identity(value: unknown): Identity {
    const id = object(value);
    if (id.kind === 'imported') return { kind: 'imported' };
    if (id.kind === 'daily') return { kind: 'daily', date: dailyDate(id.date) };
    if (id.kind === 'solo' && typeof id.key === 'string' && id.key.length > 0 && id.key.length <= 256) return { kind: 'solo', key: id.key };
    throw new Error('Invalid puzzle identity');
}
function action(value: unknown): SessionAction {
    const a = object(value);
    switch (a.type) {
        case 'SELECT_CELL': return { type: a.type, ...position(a) };
        case 'PLACE_NOTE_AT': return { type: a.type, ...position(a), value: integer(a.value, 1, 9) };
        case 'PLACE_NUMBER': return {
            type: a.type, value: integer(a.value, 1, 9), autoEliminateNotes: boolean(a.autoEliminateNotes),
            ...(a.asNote === undefined ? {} : { asNote: boolean(a.asNote) }),
        };
        case 'SET_SELECTED_CELLS': {
            if (!Array.isArray(a.cells) || a.cells.length < 1 || a.cells.length > 81) throw new Error('Invalid selection');
            const cells = a.cells.map(c => integer(c, 0, 80));
            const primary = position(a.primary);
            if (new Set(cells).size !== cells.length || !cells.includes(primary.row * 9 + primary.col)) throw new Error('Invalid primary selection');
            return { type: a.type, cells, primary };
        }
        case 'DESELECT_CELL': case 'ERASE': case 'UNDO': case 'HINT': case 'DISMISS_HINT': case 'TOGGLE_NOTES':
            return { type: a.type };
        default: throw new Error('Unsupported action');
    }
}
function canonicalAction(a: SessionAction): Action {
    return a.type === 'SET_SELECTED_CELLS' ? { ...a, cells: new Set(a.cells) } : a;
}

/** Pure in-memory session. No clock, storage, UI, network, or global statistics side effects. */
export class DokuelSession {
    #state: State;
    #snapshot: Snapshot;
    #grade: ReturnType<typeof gradePuzzle>;

    private constructor(puzzle: string, level: Difficulty, id: Identity, assist: AssistLevel) {
        if (typeof puzzle !== 'string' || countSolutions(puzzle) !== 1) throw new Error('Puzzle must have exactly one solution');
        const solution = solvePuzzle(puzzle);
        if (!solution) throw new Error('Puzzle is unsolvable');
        this.#state = initState({ puzzle, solution });
        this.#grade = gradePuzzle(puzzle);
        this.#snapshot = { schema: 1, revision: REVISION, puzzle, difficulty: difficulty(level), identity: identity(id),
            assistLevel: assistance(assist), actions: [], elapsedMs: 0, paused: false };
    }

    /** Uses the exact seed expression from upstream useResumableSudoku. */
    static startSolo(level: Difficulty, key: string, assist: AssistLevel = 'standard'): DokuelSession {
        const id = identity({ kind: 'solo', key });
        const { puzzle } = generatePuzzleWithSolution(difficulty(level), seededRandom(hashCode(`sudoku-solo-${key}`)));
        return new DokuelSession(puzzle, level, id, assist);
    }
    static startDaily(date = todayLocalISO(), level: Difficulty = 'medium', assist: AssistLevel = 'standard'): DokuelSession {
        const { puzzle } = getDailyPuzzle(dailyDate(date), difficulty(level));
        return new DokuelSession(puzzle, level, { kind: 'daily', date }, assist);
    }
    /** Starts existing original givens; continuation uses restore(), never a regenerated seed. */
    static fromPuzzle(puzzle: string, level: Difficulty, assist: AssistLevel = 'standard'): DokuelSession {
        return new DokuelSession(puzzle, level, { kind: 'imported' }, assist);
    }
    static restore(input: unknown): DokuelSession {
        const s = object(typeof input === 'string' ? JSON.parse(input) : input);
        if (s.schema !== 1 || s.revision !== REVISION) throw new Error('Unsupported snapshot schema or engine revision');
        if (!Array.isArray(s.actions)) throw new Error('Invalid action journal');
        const actions = s.actions.map(action);
        const session = new DokuelSession(s.puzzle as string, difficulty(s.difficulty), identity(s.identity), assistance(s.assistLevel));
        // Reconstruct history, hints, selections, notes and completion through public canonical actions.
        for (const a of actions) session.#state = reducer(session.#state, canonicalAction(a));
        session.#snapshot.actions = actions;
        session.#snapshot.elapsedMs = integer(s.elapsedMs, 0, Number.MAX_SAFE_INTEGER);
        session.#snapshot.paused = boolean(s.paused);
        return session;
    }
    dispatch(input: SessionAction): void {
        const a = action(input);
        if (this.#snapshot.paused) return;
        this.#state = reducer(this.#state, canonicalAction(a));
        this.#snapshot.actions.push(a);
    }
    selectCell(row: number, col: number): void { this.dispatch({ type: 'SELECT_CELL', row, col }); }
    enterDigit(value: number, autoEliminateNotes = true, asNote?: boolean): void {
        this.dispatch({ type: 'PLACE_NUMBER', value, autoEliminateNotes, ...(asNote === undefined ? {} : { asNote }) });
    }
    toggleNoteAt(row: number, col: number, value: number): void { this.dispatch({ type: 'PLACE_NOTE_AT', row, col, value }); }
    toggleNotes(): void { this.dispatch({ type: 'TOGGLE_NOTES' }); }
    erase(): void { this.dispatch({ type: 'ERASE' }); }
    undo(): void { this.dispatch({ type: 'UNDO' }); }
    requestHint(): void { this.dispatch({ type: 'HINT' }); }
    dismissHint(): void { this.dispatch({ type: 'DISMISS_HINT' }); }
    pause(): void { this.#snapshot.paused = true; }
    resume(): void { this.#snapshot.paused = false; }
    setAssistLevel(level: AssistLevel): void { this.#snapshot.assistLevel = assistance(level); }
    /** Host supplies active elapsed milliseconds; paused/completed games do not accrue time. Restore adds no downtime. */
    advanceTime(milliseconds: number): void {
        integer(milliseconds, 0, Number.MAX_SAFE_INTEGER);
        if (this.#snapshot.paused || this.#state.status !== 'playing') return;
        this.#snapshot.elapsedMs = integer(this.#snapshot.elapsedMs + milliseconds, 0, Number.MAX_SAFE_INTEGER);
    }
    /** Detached canonical state and projections. Mutating the result cannot mutate the session. */
    view() {
        return structuredClone({
            puzzle: this.#snapshot.puzzle, difficulty: this.#snapshot.difficulty, identity: this.#snapshot.identity,
            assistLevel: this.#snapshot.assistLevel, elapsedMs: this.#snapshot.elapsedMs, paused: this.#snapshot.paused,
            grade: this.#grade, state: this.#state,
            grid: serializeBoard(this.#state.board), projection: projectBoard(this.#state.board, this.#state.solution),
        });
    }
    snapshot(): Snapshot { return structuredClone(this.#snapshot); }
    export(): string { return JSON.stringify(this.#snapshot); }
}
