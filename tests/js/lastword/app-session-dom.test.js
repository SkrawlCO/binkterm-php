/**
 * DOM-level regression test for js/lastword/app-session.js (M1C), added
 * after a human-found blocker: Submit Solve appeared to do nothing and left
 * the round permanently stuck.
 *
 * Root cause (confirmed via a real-browser repro, not this test): a
 * service-worker cache-first strategy served a stale, pre-M1C copy of
 * round.js (missing `decayPerActionFor`) because public_html/sw.js's
 * CACHE_NAME was not bumped when round.js/lastword.css changed — exactly the
 * scenario CLAUDE.md's "Service Worker Cache" rule exists to prevent. That is
 * fixed by the CACHE_NAME bump in this commit; it is a deployment-cache
 * concern, not something a same-process Node test can exercise.
 *
 * What THIS test guards, so the class of symptom (a thrown exception inside
 * submitSolve permanently disabling the Submit Solve control) is harder to
 * reintroduce even from a different future cause: it drives the REAL
 * app-session.js through a minimal DOM shim (Node's built-in `vm` module —
 * no new dependency) and asserts (a) a normal correct solve completes the
 * round end-to-end through the actual DOM wiring, not just the pure rules
 * engine, and (b) if a dependency the solve path calls throws, the Submit
 * Solve button is NOT left disabled forever and the round is not silently
 * frozen — the try/finally hardening in submitSolve() must re-enable it.
 *
 * Run: node tests/js/lastword/app-session-dom.test.js
 */
'use strict';

const assert = require('assert');
const vm = require('vm');
const fs = require('fs');
const path = require('path');

const ROOT = path.join(__dirname, '../../../public_html/webdoors/hangman');

function fakeClassList() {
    const set = new Set();
    return { add: (c) => set.add(c), remove: (c) => set.delete(c), contains: (c) => set.has(c) };
}

function makeElement(id) {
    return {
        id,
        _className: '',
        _children: [],
        _innerHTML: '',
        textContent: '',
        value: '',
        disabled: false,
        onclick: null,
        get className() { return this._className; },
        set className(v) { this._className = v; },
        get innerHTML() { return this._innerHTML; },
        set innerHTML(v) {
            this._innerHTML = v;
            // Real DOM: assigning innerHTML (typically '' in this codebase,
            // to clear a container before re-rendering) discards existing
            // child nodes. Without this, repeated renders (e.g. across many
            // rounds in a session) would accumulate stale children forever.
            if (v === '') this._children = [];
        },
        classList: fakeClassList(),
        addEventListener() {},
        appendChild(child) { this._children.push(child); return child; },
        focus() {},
        click() { if (typeof this.onclick === 'function') this.onclick(); },
        getContext() {
            return { clearRect() {}, beginPath() {}, moveTo() {}, lineTo() {}, arc() {}, stroke() {}, strokeStyle: '', lineWidth: 0 };
        }
    };
}

const IDS = [
    'category-select', 'categoryList', 'round-offer', 'roundOfferTitle', 'roundOfferList',
    'transition', 'transitionTitle', 'transitionBody', 'transitionContinue',
    'game', 'gallows', 'strikeCount', 'roundLabel', 'categoryLabel',
    'cumulativeScore', 'roundScore', 'solveBonus', 'decayHint',
    'board', 'statusLine', 'letters', 'valuesHint',
    'solveToggle', 'solveForm', 'solveInput', 'solveSubmit',
    'round-result', 'roundResultTitle', 'roundResultBody', 'continueAfterRound',
    'session-complete', 'sessionSummary', 'playAgain'
];

/** Boots a fresh app-session.js instance in an isolated vm context and resolves once its puzzle fetch has settled. */
function bootApp() {
    const elements = {};
    IDS.forEach((id) => { elements[id] = makeElement(id); elements[id].width = 260; elements[id].height = 260; });

    const puzzlesJson = fs.readFileSync(path.join(ROOT, 'lastword/puzzles.json'), 'utf8');

    const sandbox = {
        console,
        Math,
        document: { getElementById: (id) => elements[id], createElement: (tag) => makeElement('(' + tag + ')'), activeElement: null },
        fetch: () => Promise.resolve({ ok: true, json: () => Promise.resolve(JSON.parse(puzzlesJson)) }),
        addEventListener: function () {}
    };
    sandbox.window = sandbox;
    sandbox.self = sandbox;
    const context = vm.createContext(sandbox);

    ['content.js', 'state.js', 'round.js', 'session.js', 'app-session.js'].forEach((f) => {
        const file = f === 'content.js' || f === 'state.js' || f === 'round.js' || f === 'session.js'
            ? path.join(ROOT, 'js/lastword', f)
            : path.join(ROOT, 'js/lastword', f);
        vm.runInContext(fs.readFileSync(file, 'utf8'), context, { filename: f });
    });

    return new Promise((resolve) => setTimeout(() => resolve({ elements, context }), 30));
}

function clickByText(container, text) {
    const button = container._children.find((b) => b.textContent === text || b.textContent.startsWith(text + ' ('));
    assert.ok(button, 'no button found for "' + text + '"');
    button.click();
}

/**
 * A puzzle's "shape" — length plus the position of every non-letter
 * character (space/digit/punctuation), letters collapsed to '_'. With 200
 * curated puzzles, matching a dealt board by LENGTH alone is no longer
 * reliably unique (e.g. "THE GODFATHER" / "JURASSIC PARK" / "THE LION KING"
 * are all 13 characters) — the shape (where the spaces fall) is.
 */
function answerShape(answer) {
    return answer.toUpperCase().replace(/[A-Z]/g, '_');
}

/** Reads the currently-rendered board's shape the same way, from the fake DOM cells. */
function boardShape(boardEl) {
    return boardEl._children.map((cell) => {
        if (cell.className === 'cell space') return ' ';
        if (cell.className === 'cell punct') return cell.textContent;
        return '_'; // 'cell' (revealed letter) or 'cell blank' (unrevealed letter) — a letter position either way
    }).join('');
}

/**
 * Seed puzzles whose shape (length + where the spaces/punctuation fall)
 * matches the currently-dealt board, in `category`. At 200 curated puzzles
 * a shape is not always unique (e.g. "SUPER BOWL" and "GRAND SLAM" are both
 * two five-letter words) — that's a harmless coincidence of English word
 * lengths, not a content defect, so callers try each candidate in turn
 * rather than assuming exactly one match.
 */
function dealtPuzzleCandidates(boardEl, puzzlesDoc, category) {
    const shape = boardShape(boardEl);
    const candidates = puzzlesDoc.puzzles.filter((p) => p.category === category && answerShape(p.answer) === shape);
    assert.ok(candidates.length > 0, 'no seed puzzle matches the dealt board shape for ' + category);
    return candidates;
}

let passed = 0;
async function check(name, fn) {
    await fn();
    passed++;
    console.log('  ok - ' + name);
}

(async () => {
    console.log('app-session-dom.test.js');

    await check('a correct solve completes the round through the real DOM wiring (not just the rules engine)', async () => {
        const { elements } = await bootApp();
        const puzzlesDoc = JSON.parse(fs.readFileSync(path.join(ROOT, 'lastword/puzzles.json'), 'utf8'));
        clickByText(elements.categoryList, 'Movies & TV');
        const candidates = dealtPuzzleCandidates(elements.board, puzzlesDoc, 'Movies & TV');

        // Try each shape-matching candidate (see dealtPuzzleCandidates) until one solves.
        for (const candidate of candidates) {
            elements.solveToggle.click();
            elements.solveInput.value = candidate.answer;
            elements.solveSubmit.click();
            if (elements.game.className === 'layout hidden') break; // solved
        }

        assert.strictEqual(elements.game.className, 'layout hidden');
        assert.strictEqual(elements['round-result'].className, 'panel');
        assert.strictEqual(elements.roundResultTitle.textContent, 'Round 1 — Warm-Up: Solved!');
        assert.ok(elements.roundResultBody._children.some((l) => l.textContent.includes('Round points: 1500')));
    });

    await check('a wrong solve adds exactly 2 strikes and leaves the round playable', async () => {
        const { elements } = await bootApp();
        clickByText(elements.categoryList, 'Movies & TV');

        elements.solveToggle.click();
        elements.solveInput.value = 'Totally wrong guess';
        elements.solveSubmit.click();

        assert.strictEqual(elements.strikeCount.textContent, '2');
        assert.strictEqual(elements.game.className, 'layout'); // still playing, not hidden
        assert.strictEqual(elements.solveSubmit.disabled, false); // re-enabled for another try
    });

    await check('REGRESSION: an exception inside the solve path does not permanently disable Submit Solve', async () => {
        const { elements, context } = await bootApp();
        clickByText(elements.categoryList, 'Movies & TV');

        // Simulate the real defect's proximate cause: a dependency the solve
        // path calls is missing/throws (here: a stale-cache-shaped failure).
        context.LastWordRound.decayPerActionFor = function () { throw new TypeError('decayPerActionFor is not a function'); };

        elements.solveToggle.click();
        elements.solveInput.value = 'The Godfather';
        elements.solveSubmit.click();

        assert.strictEqual(elements.solveSubmit.disabled, false, 'submit must not stay disabled after a thrown exception');
        assert.ok(elements.statusLine.textContent.length > 0, 'the player must see SOME feedback, not silence');
        // The round itself must not be corrupted by the aborted attempt.
        assert.strictEqual(elements.game.className, 'layout');
    });

    await check('REGRESSION: R1 solved, R2 solved, R3 solved, R4 FAILED -> Continue reaches Session Complete (not back to an earlier round)', async () => {
        const { elements } = await bootApp();
        const puzzlesDoc = JSON.parse(fs.readFileSync(path.join(ROOT, 'lastword/puzzles.json'), 'utf8'));

        function currentCategory() { return elements.categoryLabel.textContent; }
        function solveCurrentRound() {
            const candidates = dealtPuzzleCandidates(elements.board, puzzlesDoc, currentCategory());
            for (const candidate of candidates) {
                elements.solveToggle.click();
                elements.solveInput.value = candidate.answer;
                elements.solveSubmit.click();
                if (elements.game.className === 'layout hidden') return; // solved
            }
        }
        function failCurrentRoundByWrongSolves() {
            for (let i = 0; i < 3 && elements.game.className === 'layout'; i++) {
                elements.solveToggle.click(); // toggles open (form starts hidden each fresh round)
                elements.solveInput.value = 'definitely wrong answer ' + i;
                elements.solveSubmit.click();
                elements.solveToggle.click(); // toggle back so the next iteration's open-click actually opens it
            }
        }
        function clickContinue() { elements.continueAfterRound.click(); }
        function clickFirst(container) { container._children[0].click(); }

        // Round 1
        clickFirst(elements.categoryList);
        solveCurrentRound();
        assert.strictEqual(elements.roundResultTitle.textContent.indexOf('Solved!') !== -1, true);
        clickContinue();
        if (elements.transition.className === 'panel') { elements.transitionContinue.click(); }

        // Round 2
        solveCurrentRound();
        clickContinue();
        if (elements['round-offer'].className === 'panel') { clickFirst(elements.roundOfferList); }

        // Round 3
        solveCurrentRound();
        clickContinue();
        if (elements.transition.className === 'panel') { elements.transitionContinue.click(); }

        // Round 4 — fail it on purpose.
        assert.strictEqual(elements.roundLabel.textContent, 'Round 4 — Wildcard');
        failCurrentRoundByWrongSolves();
        assert.strictEqual(elements.game.className, 'layout hidden');
        assert.ok(elements.roundResultTitle.textContent.indexOf('Out of strikes') !== -1);

        // THE REPORTED DEFECT: clicking Continue here must reach Session
        // Complete, never bounce back into an earlier round's screen.
        clickContinue();

        assert.strictEqual(elements['session-complete'].className, 'panel', 'must show Session Complete');
        assert.strictEqual(elements.transition.className, 'panel hidden', 'must NOT show the transition/earlier-round screen');
        assert.strictEqual(elements['round-offer'].className, 'panel hidden');
        assert.strictEqual(elements.game.className, 'layout hidden');
        assert.ok(elements.sessionSummary._children.length > 0);
    });

    await check('Play Another Session resets cleanly to a fresh Round 1 category screen', async () => {
        const { elements } = await bootApp();
        clickByText(elements.categoryList, 'Movies & TV');
        elements.solveToggle.click();
        elements.solveInput.value = 'The Godfather';
        elements.solveSubmit.click(); // round 1 solved, session now mid-flight

        elements.playAgain.click();

        assert.strictEqual(elements['category-select'].className, 'panel');
        assert.strictEqual(elements.game.className, 'layout hidden');
        assert.strictEqual(elements['round-result'].className, 'panel hidden');
        assert.strictEqual(elements['session-complete'].className, 'panel hidden');
    });

    console.log(`app-session-dom.test.js: ${passed} passed`);
})().catch((err) => {
    console.error(err);
    process.exit(1);
});
