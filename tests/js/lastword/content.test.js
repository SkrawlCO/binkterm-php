/**
 * Plain Node assertions for js/lastword/content.js (M1A foundation).
 *
 * No JS test framework exists in this repo yet (package.json only wires up
 * Playwright e2e); rather than add a new dependency for this small a
 * surface, this runs as a plain script: `node tests/js/lastword/content.test.js`.
 * Exits non-zero on any failed assertion.
 */
'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');

const LastWordContent = require('../../../public_html/webdoors/hangman/js/lastword/content.js');

const puzzlesPath = path.join(
    __dirname, '../../../public_html/webdoors/hangman/lastword/puzzles.json'
);
const doc = JSON.parse(fs.readFileSync(puzzlesPath, 'utf8'));

let passed = 0;
function check(name, fn) {
    fn();
    passed++;
    console.log('  ok - ' + name);
}

console.log('content.test.js');

check('seed puzzle set loads without throwing', () => {
    const puzzles = LastWordContent.loadPuzzleSet(doc);
    assert.ok(Array.isArray(puzzles));
});

const puzzles = LastWordContent.loadPuzzleSet(doc);

check('curated corpus is at least at the ~200-puzzle content-expansion target', () => {
    assert.ok(puzzles.length >= 150, `expected >=150 (targeting ~200), got ${puzzles.length}`);
});

check('every puzzle id is unique', () => {
    const ids = puzzles.map((p) => p.id);
    assert.strictEqual(new Set(ids).size, ids.length);
});

check('loadPuzzleSet rejects a duplicate id', () => {
    const dup = {
        version: 1,
        puzzles: [
            { id: 'x-0001', category: 'Places', answer: 'PARIS', difficulty: 'easy', finalEligible: false },
            { id: 'x-0001', category: 'Places', answer: 'ROME', difficulty: 'easy', finalEligible: false }
        ]
    };
    assert.throws(() => LastWordContent.loadPuzzleSet(dup), /duplicate puzzle id/);
});

check('loadPuzzleSet rejects an unknown difficulty', () => {
    const bad = {
        version: 1,
        puzzles: [{ id: 'x-0002', category: 'Places', answer: 'PARIS', difficulty: 'extreme', finalEligible: false }]
    };
    assert.throws(() => LastWordContent.loadPuzzleSet(bad), /difficulty must be one of/);
});

check('multi-word phrases preserve spaces in the answer', () => {
    const p = LastWordContent.byId(puzzles, 'movies-0001');
    assert.strictEqual(p.answer, 'BACK TO THE FUTURE');
    assert.ok(p.answer.includes(' '));
});

check('punctuation is preserved in the answer but excluded from guessable letters', () => {
    const p = LastWordContent.byId(puzzles, 'sayings-0001');
    assert.strictEqual(p.answer, "DON'T CRY OVER SPILLED MILK");
    assert.ok(p.answer.includes("'"));
    assert.ok(!p.guessableLetters.includes("'"));
    assert.ok(p.guessableLetters.every((ch) => /^[A-Z]$/.test(ch)));
});

check('guessable letters are deduplicated and uppercase', () => {
    const letters = LastWordContent.getGuessableLetters('mississippi');
    assert.deepStrictEqual(letters.slice().sort(), ['I', 'M', 'P', 'S']);
});

check('countOccurrences counts case-insensitively', () => {
    assert.strictEqual(LastWordContent.countOccurrences('MISSISSIPPI', 'S'), 4);
    assert.strictEqual(LastWordContent.countOccurrences('MISSISSIPPI', 'i'), 4);
});

check('at least one Final-eligible and one non-Final-eligible puzzle exist', () => {
    const eligible = LastWordContent.filterFinalEligible(puzzles);
    assert.ok(eligible.length > 0);
    assert.ok(eligible.length < puzzles.length);
});

check('at least two difficulties are represented in the seed set', () => {
    const difficulties = new Set(puzzles.map((p) => p.difficulty));
    assert.ok(difficulties.size >= 2);
});

check('category filtering returns only that category', () => {
    const category = puzzles[0].category;
    const filtered = LastWordContent.filterByCategory(puzzles, category);
    assert.ok(filtered.length > 0);
    assert.ok(filtered.every((p) => p.category === category));
});

check('listCategories has no duplicates and covers several categories', () => {
    const categories = LastWordContent.listCategories(puzzles);
    assert.strictEqual(new Set(categories).size, categories.length);
    assert.ok(categories.length >= 5, `expected >=5 categories, got ${categories.length}`);
});

check('pickRandom respects a category restriction', () => {
    const category = puzzles[0].category;
    for (let i = 0; i < 20; i++) {
        const picked = LastWordContent.pickRandom(puzzles, { category });
        assert.strictEqual(picked.category, category);
    }
});

check('pickRandom respects an exclude list unless it would empty the pool', () => {
    const category = puzzles[0].category;
    const inCategory = LastWordContent.filterByCategory(puzzles, category);
    const excludeAllButOne = inCategory.slice(1).map((p) => p.id);
    const picked = LastWordContent.pickRandom(puzzles, { category, excludeIds: excludeAllButOne });
    assert.strictEqual(picked.id, inCategory[0].id);

    // Excluding the whole category falls back to the full (unfiltered-by-exclusion)
    // pool rather than returning null, so the game is never left without a puzzle.
    const excludeAll = inCategory.map((p) => p.id);
    const fallback = LastWordContent.pickRandom(puzzles, { category, excludeIds: excludeAll });
    assert.strictEqual(fallback.category, category);
});

// --- content-expansion audit (durable — re-run this after every content edit) ---

check('every category has at least 10 curated puzzles', () => {
    const categories = LastWordContent.listCategories(puzzles);
    categories.forEach((category) => {
        const count = LastWordContent.filterByCategory(puzzles, category).length;
        assert.ok(count >= 10, `category "${category}" has only ${count} puzzles`);
    });
});

check('no two puzzles share the same answer once punctuation/case/spacing is stripped', () => {
    const seen = new Map();
    puzzles.forEach((p) => {
        const normalized = p.answer.toUpperCase().replace(/[^A-Z]/g, '');
        const prior = seen.get(normalized);
        assert.ok(!prior, `"${p.answer}" (${p.id}) duplicates "${prior}" once normalized`);
        seen.set(normalized, p.id + ' (' + p.answer + ')');
    });
});

check('every puzzle has enough distinct guessable letters for a real Hangman round (>=4)', () => {
    puzzles.forEach((p) => {
        assert.ok(p.guessableLetters.length >= 4,
            `"${p.answer}" (${p.id}) has only ${p.guessableLetters.length} distinct guessable letters`);
    });
});

check('every answer contains only letters, spaces, digits, apostrophes, or hyphens (no stray punctuation/typos)', () => {
    puzzles.forEach((p) => {
        const stray = p.answer.replace(/[A-Z0-9 '\-]/gi, '');
        assert.strictEqual(stray, '', `"${p.answer}" (${p.id}) has unexpected character(s): "${stray}"`);
    });
});

check('no answer has leading/trailing whitespace or doubled spaces', () => {
    puzzles.forEach((p) => {
        assert.strictEqual(p.answer, p.answer.trim(), `"${p.answer}" (${p.id}) has leading/trailing whitespace`);
        assert.ok(!/ {2,}/.test(p.answer), `"${p.answer}" (${p.id}) has a doubled space`);
    });
});

check('at least three difficulties and a substantial Final-eligible pool exist across the whole corpus', () => {
    const difficulties = new Set(puzzles.map((p) => p.difficulty));
    assert.strictEqual(difficulties.size, 3);
    const eligible = LastWordContent.filterFinalEligible(puzzles);
    assert.ok(eligible.length >= puzzles.length * 0.5,
        `expected at least half the corpus Final-eligible, got ${eligible.length}/${puzzles.length}`);
});

console.log(`content.test.js: ${passed} passed`);
