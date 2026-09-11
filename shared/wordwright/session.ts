import { createGame, pickAnswer } from './upstream/src/engine/createGame';
import { gameReducer, type GameAction } from './upstream/src/engine/reducer';
import { validateGuess } from './upstream/src/engine/validate';
import { systemRandom, type RandomSource } from './upstream/src/engine/random';
import { boardRows, remainingGuesses, isGameOver } from './upstream/src/engine/selectors';
import { deriveKeyStates } from './upstream/src/engine/keyboardState';
import { scoreGame } from './upstream/src/engine/score';
import { WORD_LENGTHS } from './upstream/src/engine/constants';
import type { GameState, WordLength } from './upstream/src/engine/types';
import { loadDictionary } from './upstream/src/dictionary/loadDictionary';
import type { Dictionary } from './upstream/src/dictionary/types';
import { rememberAnswer, isSessionUsable } from './upstream/src/storage/sessionRepository';
import { recordGame, resetStatistics, resetHistory } from './upstream/src/storage/statsRepository';
import { defaultMeta, defaultStats, validateMeta, validateStats, validateSession, type StorageMeta, type StatsState } from './upstream/src/storage/schemas';
import { now, clampDuration } from './upstream/src/lib/clock';

export const UPSTREAM_REVISION = '1dcf92d0c6d9e873a3d885920c21ae3ff81f0a33';
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
const copy = <T>(value: T): T => structuredClone(value);
const lengthCheck = (length: WordLength): void => {
    if (!WORD_LENGTHS.includes(length)) throw new Error('Unsupported word length');
};

/** No host storage or presentation. Await construction; all subsequent actions are synchronous. */
export class WordwrightSession {
    private game!: GameState;
    private meta = defaultMeta();
    private stats = defaultStats();
    private selectedLength: WordLength = 5;
    private recordedId: string | null = null;
    private constructor(private dictionaries: Map<WordLength, Dictionary>, private deps: Dependencies) {}

    private static async empty(deps: Dependencies): Promise<WordwrightSession> {
        const dictionaries = await Promise.all(WORD_LENGTHS.map(loadDictionary));
        return new WordwrightSession(new Map(dictionaries.map(d => [d.wordLength, d])), deps);
    }
    static async create(length: WordLength = 5, deps: Dependencies = {}): Promise<WordwrightSession> {
        lengthCheck(length);
        const session = await this.empty(deps);
        session.selectedLength = length;
        session.newGame();
        return session;
    }
    /** Repository-compatible reload, including upstream's mid-reveal -> playing quirk. */
    static async restore(value: unknown, deps: Dependencies = {}): Promise<WordwrightSession> {
        if (!value || typeof value !== 'object') throw new Error('Invalid snapshot');
        const input = copy(value) as Snapshot;
        if (input.schemaVersion !== 1 || input.upstreamRevision !== UPSTREAM_REVISION) throw new Error('Unsupported snapshot');
        lengthCheck(input.selectedLength);
        const game = validateSession(input.game);
        const meta = validateMeta(input.meta);
        const stats = validateStats(input.stats);
        const session = await this.empty(deps);
        const dictionary = session.dictionaries.get(input.selectedLength)!;
        if (!meta || !stats || !isSessionUsable(game, input.selectedLength, word => dictionary.answers.includes(word)) ||
            ['idle', 'loading', 'error'].includes(game.status)) throw new Error('Invalid snapshot state');
        session.selectedLength = input.selectedLength;
        session.meta = meta;
        session.stats = stats;
        // This is the exact normalization in upstream createSessionRepository, not game-over inference.
        session.game = game.status === 'revealing' ? { ...game, status: 'playing' } : game;
        session.recordCompletion();
        return session;
    }
    private time(): number { return (this.deps.now ?? now)(); }
    private dispatch(action: GameAction): boolean {
        const previous = this.game;
        this.game = gameReducer(previous, action);
        this.recordCompletion();
        return this.game !== previous;
    }
    private recordCompletion(): void {
        if (!isGameOver(this.game) || this.recordedId === this.game.id) return;
        this.recordedId = this.game.id;
        const solveMs = clampDuration((this.game.finishedAt ?? 0) - (this.game.startedAt ?? 0));
        const won = this.game.status === 'won';
        const guessesUsed = this.game.guesses.length;
        this.stats = recordGame(this.stats, {
            id: this.game.id, playedAt: this.game.finishedAt ?? this.time(),
            wordLength: this.game.wordLength, answer: this.game.answer, guessesUsed, won, solveMs,
            score: scoreGame({ won, guessesUsed, wordLength: this.game.wordLength, solveMs }),
        });
    }
    newGame(): void {
        const dictionary = this.dictionaries.get(this.selectedLength)!;
        const answer = pickAnswer(dictionary.answers, this.meta.recentAnswers[this.selectedLength], this.deps.random ?? systemRandom);
        const game = createGame({ id: (this.deps.id ?? (() => globalThis.crypto.randomUUID()))(), wordLength: this.selectedLength, answer });
        this.meta = rememberAnswer(this.meta, this.selectedLength, answer);
        this.game = gameReducer(this.game, { type: 'START_GAME', game });
    }
    setLength(length: WordLength): void {
        lengthCheck(length);
        if (length === this.selectedLength) return;
        this.selectedLength = length;
        this.newGame();
    }
    inputLetter(letter: string): boolean { return this.dispatch({ type: 'ADD_LETTER', letter, now: this.time() }); }
    backspace(): boolean { return this.dispatch({ type: 'REMOVE_LETTER' }); }
    submit() {
        if (this.game.status !== 'playing') return { ok: false as const, error: 'not-playing' as const };
        const dictionary = this.dictionaries.get(this.selectedLength)!;
        const result = validateGuess(this.game.currentInput, this.selectedLength, word => dictionary.guesses.has(word));
        if (!result.ok) return result;
        const changed = this.dispatch({ type: 'SUBMIT_GUESS', word: result.value, now: this.time() });
        return changed ? { ok: true as const, value: result.value } : { ok: false as const, error: 'rejected' as const };
    }
    completeReveal(): boolean { return this.dispatch({ type: 'REVEAL_COMPLETE', now: this.time() }); }
    getState(): GameState { return copy(this.game); }
    getStats(): StatsState { return copy(this.stats); }
    getBoardProjection() { return boardRows(this.game); }
    getKeyboardState() { return deriveKeyStates(this.game.guesses); }
    getRemainingAttempts(): number { return remainingGuesses(this.game); }
    resetStatistics(): void { this.stats = resetStatistics(this.stats); }
    resetHistory(): void { this.stats = resetHistory(this.stats); }
    snapshot(): Snapshot {
        return copy({ schemaVersion: 1, upstreamRevision: UPSTREAM_REVISION, selectedLength: this.selectedLength,
            game: this.game, meta: this.meta, stats: this.stats });
    }
    /** Explicit handoff settles animation via the canonical reducer and records stats before one snapshot. */
    prepareHandoff(): Snapshot { this.completeReveal(); return this.snapshot(); }
}
