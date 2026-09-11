import { MAX_GUESSES, RECENT_GAMES_LIMIT, WORD_LENGTHS } from '../engine/constants.js';
/* ------------------------------------------------------------------ *
 * Primitive guards
 * ------------------------------------------------------------------ */
function isObject(value) {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}
function isFiniteNumber(value) {
    return typeof value === 'number' && Number.isFinite(value);
}
function isNonNegative(value) {
    return isFiniteNumber(value) && value >= 0;
}
function isWordLength(value) {
    return WORD_LENGTHS.some((length) => length === value);
}
function isStringArray(value) {
    return Array.isArray(value) && value.every((item) => typeof item === 'string');
}
/* ------------------------------------------------------------------ *
 * Defaults
 * ------------------------------------------------------------------ */
export const DEFAULT_SETTINGS = {
    theme: 'system',
    colorblind: false,
    motion: 'system',
    defaultWordLength: 5,
};
export function emptyBucket() {
    return {
        gamesPlayed: 0,
        gamesWon: 0,
        currentStreak: 0,
        bestStreak: 0,
        distribution: new Array(MAX_GUESSES).fill(0),
        totalGuessesInWins: 0,
        totalScore: 0,
        totalSolveMs: 0,
        fastestSolveMs: null,
    };
}
export function defaultStats() {
    return {
        overall: emptyBucket(),
        perLength: { 4: emptyBucket(), 5: emptyBucket(), 6: emptyBucket() },
        recentGames: [],
        recordedGameIds: [],
    };
}
export function defaultMeta() {
    return {
        schemaVersion: 1,
        recentAnswers: { 4: [], 5: [], 6: [] },
    };
}
/* ------------------------------------------------------------------ *
 * Validators
 * ------------------------------------------------------------------ */
export function validateSettings(value) {
    if (!isObject(value))
        return null;
    const { theme, colorblind, motion, defaultWordLength } = value;
    if (theme !== 'light' && theme !== 'dark' && theme !== 'system')
        return null;
    if (typeof colorblind !== 'boolean')
        return null;
    if (motion !== 'system' && motion !== 'reduced' && motion !== 'full')
        return null;
    if (!isWordLength(defaultWordLength))
        return null;
    return { theme, colorblind, motion, defaultWordLength };
}
function validateBucket(value) {
    if (!isObject(value))
        return null;
    const { gamesPlayed, gamesWon, currentStreak, bestStreak, distribution, totalGuessesInWins, totalScore, totalSolveMs, fastestSolveMs, } = value;
    if (!isNonNegative(gamesPlayed) ||
        !isNonNegative(gamesWon) ||
        !isNonNegative(currentStreak) ||
        !isNonNegative(bestStreak) ||
        !isNonNegative(totalGuessesInWins) ||
        !isNonNegative(totalScore) ||
        !isNonNegative(totalSolveMs)) {
        return null;
    }
    if (fastestSolveMs !== null && !isNonNegative(fastestSolveMs))
        return null;
    if (!Array.isArray(distribution) ||
        distribution.length !== MAX_GUESSES ||
        !distribution.every(isNonNegative)) {
        return null;
    }
    // Cross-field sanity: more wins than games means the record is incoherent.
    if (gamesWon > gamesPlayed)
        return null;
    return {
        gamesPlayed,
        gamesWon,
        currentStreak,
        bestStreak,
        distribution,
        totalGuessesInWins,
        totalScore,
        totalSolveMs,
        fastestSolveMs,
    };
}
function validateGameRecord(value) {
    if (!isObject(value))
        return null;
    const { id, playedAt, wordLength, answer, guessesUsed, won, solveMs, score } = value;
    if (typeof id !== 'string' || id.length === 0)
        return null;
    if (!isFiniteNumber(playedAt))
        return null;
    if (!isWordLength(wordLength))
        return null;
    if (typeof answer !== 'string' || answer.length !== wordLength)
        return null;
    if (!isNonNegative(guessesUsed) || guessesUsed > MAX_GUESSES)
        return null;
    if (typeof won !== 'boolean')
        return null;
    if (!isNonNegative(solveMs))
        return null;
    if (!isNonNegative(score))
        return null;
    return { id, playedAt, wordLength, answer, guessesUsed, won, solveMs, score };
}
export function validateStats(value) {
    if (!isObject(value))
        return null;
    const overall = validateBucket(value.overall);
    if (!overall)
        return null;
    if (!isObject(value.perLength))
        return null;
    const perLength = {};
    for (const length of WORD_LENGTHS) {
        const bucket = validateBucket(value.perLength[String(length)]);
        if (!bucket)
            return null;
        perLength[length] = bucket;
    }
    if (!Array.isArray(value.recentGames))
        return null;
    const recentGames = [];
    for (const entry of value.recentGames) {
        const record = validateGameRecord(entry);
        // Drop individual malformed rows rather than discarding the whole history.
        if (record)
            recentGames.push(record);
    }
    const recordedGameIds = isStringArray(value.recordedGameIds) ? value.recordedGameIds : [];
    return {
        overall,
        perLength,
        recentGames: recentGames.slice(0, RECENT_GAMES_LIMIT),
        recordedGameIds,
    };
}
function validateGuessEntry(value, wordLength) {
    if (!isObject(value))
        return null;
    const { word, evaluation } = value;
    if (typeof word !== 'string' || word.length !== wordLength)
        return null;
    if (!Array.isArray(evaluation) || evaluation.length !== wordLength)
        return null;
    const states = [];
    for (const state of evaluation) {
        if (state !== 'correct' && state !== 'present' && state !== 'absent')
            return null;
        states.push(state);
    }
    return { word, evaluation: states };
}
export function validateSession(value) {
    if (!isObject(value))
        return null;
    const { id, wordLength, answer, guesses, currentInput, status, startedAt, finishedAt } = value;
    if (typeof id !== 'string' || id.length === 0)
        return null;
    if (!isWordLength(wordLength))
        return null;
    if (typeof answer !== 'string' || answer.length !== wordLength)
        return null;
    if (typeof currentInput !== 'string' || currentInput.length > wordLength)
        return null;
    if (status !== 'idle' &&
        status !== 'loading' &&
        status !== 'playing' &&
        status !== 'revealing' &&
        status !== 'won' &&
        status !== 'lost' &&
        status !== 'error') {
        return null;
    }
    if (startedAt !== null && !isFiniteNumber(startedAt))
        return null;
    if (finishedAt !== null && !isFiniteNumber(finishedAt))
        return null;
    if (!Array.isArray(guesses) || guesses.length > MAX_GUESSES)
        return null;
    const parsed = [];
    for (const entry of guesses) {
        const guess = validateGuessEntry(entry, wordLength);
        if (!guess)
            return null;
        parsed.push(guess);
    }
    return {
        id,
        wordLength,
        answer,
        guesses: parsed,
        currentInput,
        status,
        startedAt,
        finishedAt,
    };
}
export function validateMeta(value) {
    if (!isObject(value))
        return null;
    if (!isFiniteNumber(value.schemaVersion))
        return null;
    if (!isObject(value.recentAnswers))
        return null;
    const recentAnswers = {};
    for (const length of WORD_LENGTHS) {
        const entry = value.recentAnswers[String(length)];
        recentAnswers[length] = isStringArray(entry) ? entry : [];
    }
    return { schemaVersion: value.schemaVersion, recentAnswers };
}
