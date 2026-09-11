import { createGame, pickAnswer } from './upstream/src/engine/createGame.js';
import { gameReducer } from './upstream/src/engine/reducer.js';
import { validateGuess } from './upstream/src/engine/validate.js';
import { systemRandom } from './upstream/src/engine/random.js';
import { boardRows, remainingGuesses, isGameOver } from './upstream/src/engine/selectors.js';
import { deriveKeyStates } from './upstream/src/engine/keyboardState.js';
import { scoreGame } from './upstream/src/engine/score.js';
import { WORD_LENGTHS } from './upstream/src/engine/constants.js';
import { loadDictionary } from './upstream/src/dictionary/loadDictionary.js';
import { rememberAnswer, isSessionUsable } from './upstream/src/storage/sessionRepository.js';
import { recordGame, resetStatistics, resetHistory } from './upstream/src/storage/statsRepository.js';
import { defaultMeta, defaultStats, validateMeta, validateStats, validateSession } from './upstream/src/storage/schemas.js';
import { now, clampDuration } from './upstream/src/lib/clock.js';
export const UPSTREAM_REVISION = '1dcf92d0c6d9e873a3d885920c21ae3ff81f0a33';
const copy = (value) => structuredClone(value);
const lengthCheck = (length) => {
    if (!WORD_LENGTHS.includes(length))
        throw new Error('Unsupported word length');
};
/** No host storage or presentation. Await construction; all subsequent actions are synchronous. */
export class WordwrightSession {
    dictionaries;
    deps;
    game;
    meta = defaultMeta();
    stats = defaultStats();
    selectedLength = 5;
    recordedId = null;
    constructor(dictionaries, deps) {
        this.dictionaries = dictionaries;
        this.deps = deps;
    }
    static async empty(deps) {
        const dictionaries = await Promise.all(WORD_LENGTHS.map(loadDictionary));
        return new WordwrightSession(new Map(dictionaries.map(d => [d.wordLength, d])), deps);
    }
    static async create(length = 5, deps = {}) {
        lengthCheck(length);
        const session = await this.empty(deps);
        session.selectedLength = length;
        session.newGame();
        return session;
    }
    /** Repository-compatible reload, including upstream's mid-reveal -> playing quirk. */
    static async restore(value, deps = {}) {
        if (!value || typeof value !== 'object')
            throw new Error('Invalid snapshot');
        const input = copy(value);
        if (input.schemaVersion !== 1 || input.upstreamRevision !== UPSTREAM_REVISION)
            throw new Error('Unsupported snapshot');
        lengthCheck(input.selectedLength);
        const game = validateSession(input.game);
        const meta = validateMeta(input.meta);
        const stats = validateStats(input.stats);
        const session = await this.empty(deps);
        const dictionary = session.dictionaries.get(input.selectedLength);
        if (!meta || !stats || !isSessionUsable(game, input.selectedLength, word => dictionary.answers.includes(word)) ||
            ['idle', 'loading', 'error'].includes(game.status))
            throw new Error('Invalid snapshot state');
        session.selectedLength = input.selectedLength;
        session.meta = meta;
        session.stats = stats;
        // This is the exact normalization in upstream createSessionRepository, not game-over inference.
        session.game = game.status === 'revealing' ? { ...game, status: 'playing' } : game;
        session.recordCompletion();
        return session;
    }
    time() { return (this.deps.now ?? now)(); }
    dispatch(action) {
        const previous = this.game;
        this.game = gameReducer(previous, action);
        this.recordCompletion();
        return this.game !== previous;
    }
    recordCompletion() {
        if (!isGameOver(this.game) || this.recordedId === this.game.id)
            return;
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
    newGame() {
        const dictionary = this.dictionaries.get(this.selectedLength);
        const answer = pickAnswer(dictionary.answers, this.meta.recentAnswers[this.selectedLength], this.deps.random ?? systemRandom);
        const game = createGame({ id: (this.deps.id ?? (() => globalThis.crypto.randomUUID()))(), wordLength: this.selectedLength, answer });
        this.meta = rememberAnswer(this.meta, this.selectedLength, answer);
        this.game = gameReducer(this.game, { type: 'START_GAME', game });
    }
    setLength(length) {
        lengthCheck(length);
        if (length === this.selectedLength)
            return;
        this.selectedLength = length;
        this.newGame();
    }
    inputLetter(letter) { return this.dispatch({ type: 'ADD_LETTER', letter, now: this.time() }); }
    backspace() { return this.dispatch({ type: 'REMOVE_LETTER' }); }
    submit() {
        if (this.game.status !== 'playing')
            return { ok: false, error: 'not-playing' };
        const dictionary = this.dictionaries.get(this.selectedLength);
        const result = validateGuess(this.game.currentInput, this.selectedLength, word => dictionary.guesses.has(word));
        if (!result.ok)
            return result;
        const changed = this.dispatch({ type: 'SUBMIT_GUESS', word: result.value, now: this.time() });
        return changed ? { ok: true, value: result.value } : { ok: false, error: 'rejected' };
    }
    completeReveal() { return this.dispatch({ type: 'REVEAL_COMPLETE', now: this.time() }); }
    getState() { return copy(this.game); }
    getStats() { return copy(this.stats); }
    getBoardProjection() { return boardRows(this.game); }
    getKeyboardState() { return deriveKeyStates(this.game.guesses); }
    getRemainingAttempts() { return remainingGuesses(this.game); }
    resetStatistics() { this.stats = resetStatistics(this.stats); }
    resetHistory() { this.stats = resetHistory(this.stats); }
    snapshot() {
        return copy({ schemaVersion: 1, upstreamRevision: UPSTREAM_REVISION, selectedLength: this.selectedLength,
            game: this.game, meta: this.meta, stats: this.stats });
    }
    /** Explicit handoff settles animation via the canonical reducer and records stats before one snapshot. */
    prepareHandoff() { this.completeReveal(); return this.snapshot(); }
}
