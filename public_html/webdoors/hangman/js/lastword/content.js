/**
 * Last Word — puzzle content model (M1A foundation)
 *
 * Replaces the old Hangman words.json shape (`{category: [WORD, ...]}`,
 * difficulty derived from length, letters-only via `[^A-Z]` stripping) with a
 * flat, ID-stable puzzle list capable of representing phrases, titles, names,
 * and sayings with their spacing/punctuation intact.
 *
 * Deliberately pure/DOM-free (no `document`, no `fetch`) so it can run inside
 * the WebDoor page or under a plain Node test script. See js/lastword/state.js
 * for the session/round state that consumes puzzles loaded here, and
 * js/lastword/storage.js for the WebDoor storage client.
 */
(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.LastWordContent = factory();
    }
})(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    var SCHEMA_VERSION = 1;
    var ALLOWED_DIFFICULTIES = ['easy', 'normal', 'hard'];

    /**
     * Letters the player can actually guess: unique A-Z characters present in
     * the answer, uppercased. Spaces, apostrophes, and other punctuation are
     * kept in the answer for display but are never guessable letters.
     */
    function getGuessableLetters(answer) {
        var seen = {};
        var letters = [];
        var upper = String(answer).toUpperCase();
        for (var i = 0; i < upper.length; i++) {
            var ch = upper.charAt(i);
            if (ch >= 'A' && ch <= 'Z' && !seen[ch]) {
                seen[ch] = true;
                letters.push(ch);
            }
        }
        return letters;
    }

    function isConsonant(letter) {
        return 'AEIOU'.indexOf(letter) === -1;
    }

    function isVowel(letter) {
        return 'AEIOU'.indexOf(letter) !== -1;
    }

    /**
     * Count how many times a guessed letter occurs in the answer — needed for
     * the "consonant value x occurrences" scoring rule (round scoring itself
     * is out of scope for M1A; this is the shared primitive future rounds
     * will use).
     */
    function countOccurrences(answer, letter) {
        var upper = String(answer).toUpperCase();
        var target = String(letter).toUpperCase();
        var count = 0;
        for (var i = 0; i < upper.length; i++) {
            if (upper.charAt(i) === target) count++;
        }
        return count;
    }

    function assert(condition, message) {
        if (!condition) {
            throw new Error('LastWordContent: ' + message);
        }
    }

    /**
     * Validate and normalize one raw puzzle record. Throws on any structural
     * problem rather than silently coercing bad content, since this is
     * curated shipping content, not user input.
     */
    function normalizePuzzle(raw) {
        assert(raw && typeof raw === 'object', 'puzzle entry must be an object');
        assert(typeof raw.id === 'string' && raw.id.trim() !== '', 'puzzle.id is required');
        assert(typeof raw.category === 'string' && raw.category.trim() !== '', 'puzzle "' + raw.id + '": category is required');
        assert(typeof raw.answer === 'string' && raw.answer.trim() !== '', 'puzzle "' + raw.id + '": answer is required');
        assert(
            ALLOWED_DIFFICULTIES.indexOf(raw.difficulty) !== -1,
            'puzzle "' + raw.id + '": difficulty must be one of ' + ALLOWED_DIFFICULTIES.join('/')
        );
        assert(typeof raw.finalEligible === 'boolean', 'puzzle "' + raw.id + '": finalEligible must be a boolean');

        var answer = raw.answer.toUpperCase();
        var guessableLetters = getGuessableLetters(answer);
        assert(guessableLetters.length > 0, 'puzzle "' + raw.id + '": answer has no guessable A-Z letters');

        return {
            id: raw.id,
            category: raw.category,
            answer: answer,
            difficulty: raw.difficulty,
            finalEligible: raw.finalEligible,
            guessableLetters: guessableLetters
        };
    }

    /**
     * Load and validate a `{version, puzzles: [...]}` document. Returns a
     * flat array of normalized puzzles. Throws on unknown version, duplicate
     * IDs, or any puzzle failing normalizePuzzle().
     */
    function loadPuzzleSet(doc) {
        assert(doc && typeof doc === 'object', 'puzzle document must be an object');
        assert(doc.version === SCHEMA_VERSION, 'unsupported puzzle document version: ' + doc.version);
        assert(Array.isArray(doc.puzzles), 'puzzle document must have a "puzzles" array');

        var puzzles = [];
        var seenIds = {};
        for (var i = 0; i < doc.puzzles.length; i++) {
            var puzzle = normalizePuzzle(doc.puzzles[i]);
            assert(!seenIds[puzzle.id], 'duplicate puzzle id: ' + puzzle.id);
            seenIds[puzzle.id] = true;
            puzzles.push(puzzle);
        }
        return puzzles;
    }

    function byId(puzzles, id) {
        for (var i = 0; i < puzzles.length; i++) {
            if (puzzles[i].id === id) return puzzles[i];
        }
        return null;
    }

    function filterByCategory(puzzles, category) {
        return puzzles.filter(function (p) { return p.category === category; });
    }

    function filterFinalEligible(puzzles) {
        return puzzles.filter(function (p) { return p.finalEligible === true; });
    }

    function listCategories(puzzles) {
        var seen = {};
        var categories = [];
        for (var i = 0; i < puzzles.length; i++) {
            var category = puzzles[i].category;
            if (!seen[category]) {
                seen[category] = true;
                categories.push(category);
            }
        }
        return categories;
    }

    /**
     * Pick a puzzle at random, optionally restricted to a category and/or
     * excluding a set of recently-seen puzzle IDs. Recent-puzzle avoidance
     * itself (persisting what was "recent") is out of scope for M1A — this
     * only makes the selection primitive capable of taking an exclusion list
     * later without changing shape.
     */
    function pickRandom(puzzles, options) {
        options = options || {};
        var pool = puzzles;
        if (options.category) {
            pool = filterByCategory(pool, options.category);
        }
        if (options.finalEligibleOnly) {
            pool = filterFinalEligible(pool);
        }
        if (options.excludeIds && options.excludeIds.length) {
            var excluded = {};
            options.excludeIds.forEach(function (id) { excluded[id] = true; });
            var withoutExcluded = pool.filter(function (p) { return !excluded[p.id]; });
            // If exclusion would empty the pool, fall back to the full pool
            // rather than fail to deal a puzzle at all.
            if (withoutExcluded.length > 0) pool = withoutExcluded;
        }
        if (pool.length === 0) return null;
        return pool[Math.floor(Math.random() * pool.length)];
    }

    return {
        SCHEMA_VERSION: SCHEMA_VERSION,
        ALLOWED_DIFFICULTIES: ALLOWED_DIFFICULTIES,
        getGuessableLetters: getGuessableLetters,
        isConsonant: isConsonant,
        isVowel: isVowel,
        countOccurrences: countOccurrences,
        normalizePuzzle: normalizePuzzle,
        loadPuzzleSet: loadPuzzleSet,
        byId: byId,
        filterByCategory: filterByCategory,
        filterFinalEligible: filterFinalEligible,
        listCategories: listCategories,
        pickRandom: pickRandom
    };
});
