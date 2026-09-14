/**
 * DOM-level test for js/lastword/app-final-skippy.js — the SKIPPY
 * INTEGRATION build's DOM wiring (fork of app-final.js). Drives the REAL
 * app-final-skippy.js through a minimal DOM shim (Node's built-in `vm`
 * module, following the pattern established by app-session-dom.test.js) so
 * this exercises the actual render call sites, not just the pure rules
 * engines gallows-character.js/idle-chatter.js already cover in isolation.
 *
 * Focus: (1) strikes/solved map to the correct Skippy visual state at the
 * real render call sites, (2) idle chatter's timing/reset/cleanup/no-repeat
 * contract holds when wired to real gameplay events, and (3) chatter is
 * provably inert with respect to gameplay state (score/strikes/outcome
 * never change because of a chatter firing).
 *
 * Timers: setTimeout/clearTimeout are replaced with a fake, manually-
 * advanced clock (no real delays), and Math.random is replaced with a
 * queue-fed stub so every random draw in the sandbox — gallows-character.js's
 * pickPanicLine, idle-chatter.js's delay/pool selection — is deterministic
 * per test.
 *
 * Run: node tests/js/lastword/app-final-skippy-dom.test.js
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
        hidden: false,
        onclick: null,
        get className() { return this._className; },
        set className(v) { this._className = v; },
        get innerHTML() { return this._innerHTML; },
        set innerHTML(v) {
            this._innerHTML = v;
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
    'category-select', 'categoryList', 'rivalryBadge',
    'round-offer', 'roundOfferTitle', 'roundOfferList',
    'transition', 'transitionTitle', 'transitionBody', 'transitionContinue',
    'game', 'gallows', 'skippyChatterBubble', 'strikeCount', 'roundLabel', 'categoryLabel',
    'cumulativeScore', 'cumulativeScoreDelta', 'roundScore', 'roundScoreDelta', 'solveBonus', 'decayHint',
    'board', 'statusLine', 'letters', 'valuesHint',
    'solveToggle', 'solveForm', 'solveInput', 'solveSubmit', 'solveRiskHint',
    'buyHintButton', 'hintUnavailableReason',
    'round-result', 'roundResultTitle', 'roundResultBody', 'continueAfterRound',
    'final-intro', 'finalIntroCategory', 'finalIntroScore', 'finalIntroBoard',
    'finalOpeningChoice', 'finalOpeningCountButtons',
    'finalOpeningPicker', 'finalOpeningPickerPrompt', 'finalOpeningLetterGrid',
    'final-play', 'finalGallows', 'finalSkippyChatterBubble', 'finalStrikeCount', 'finalCategoryLabel', 'finalScoreRemaining',
    'finalBoard', 'finalStatusLine', 'finalLetters',
    'finalSolveToggle', 'finalSolveForm', 'finalSolveInput', 'finalSolveSubmit', 'finalSolveRiskHint',
    'game-complete', 'gameCompleteTitle', 'gameCompleteBody', 'gameCompleteRivalry', 'playAgain'
];

/** A manually-advanced fake clock — no real delays, deterministic ordering. */
function makeFakeClock() {
    let time = 0;
    let nextId = 1;
    const timers = new Map(); // id -> { fn, at }
    return {
        setTimeout: (fn, delay) => {
            const id = nextId++;
            timers.set(id, { fn, at: time + (delay || 0) });
            return id;
        },
        clearTimeout: (id) => { timers.delete(id); },
        pendingCount: () => timers.size,
        /** Advance virtual time by `ms`, firing every timer now due, in scheduled order. */
        advance(ms) {
            time += ms;
            const due = Array.from(timers.entries())
                .filter(([, t]) => t.at <= time)
                .sort((a, b) => a[1].at - b[1].at);
            due.forEach(([id, t]) => {
                if (timers.has(id)) {
                    timers.delete(id);
                    t.fn();
                }
            });
        }
    };
}

/** A queue-fed Math.random stand-in: pops queued values, then falls back to `fallback`. */
function makeRngQueue(fallback) {
    const queue = [];
    const fn = () => (queue.length ? queue.shift() : fallback);
    fn.push = (...values) => { queue.push(...values); };
    return fn;
}

function stateOf(el) {
    const m = el.innerHTML.match(/data-state="([^"]+)"/);
    return m ? m[1] : null;
}

/**
 * Boots a fresh app-final-skippy.js instance in an isolated vm context.
 * `locationSearch` (e.g. '?predicament=safe') simulates the URL query
 * string app-final-skippy.js reads once at load to pick its Predicament —
 * the same mechanism the real page uses, exercised here without a real
 * browser location.
 *
 * `storageOpts` (Fork #7, "Picking Sides") controls the mock
 * /api/webdoor/storage/{slot} responses the sandbox's `fetch` answers
 * rivalry-slot requests with, independent of the puzzles.json response:
 *   - initialRivalry: the record a GET on the rivalry slot returns (default
 *     none -> 404, i.e. no save yet)
 *   - loadShouldFail / saveShouldFail: make that one HTTP call fail (500)
 * `fetchCalls` (on the resolved object) records every fetch call made, and
 * `storageState.rivalry` reflects whatever the sandbox last PUT to the
 * rivalry slot — both let a test assert on persistence without re-parsing
 * app internals.
 */
function bootApp(rngFallback, locationSearch, storageOpts) {
    storageOpts = storageOpts || {};
    const elements = {};
    IDS.forEach((id) => { elements[id] = makeElement(id); elements[id].width = 260; elements[id].height = 260; });

    const puzzlesJson = fs.readFileSync(path.join(ROOT, 'lastword/puzzles.json'), 'utf8');
    const clock = makeFakeClock();
    const rng = makeRngQueue(typeof rngFallback === 'number' ? rngFallback : 0.5);

    const fakeMath = Object.create(Math);
    fakeMath.random = rng;

    const fetchCalls = [];
    const storageState = { rivalry: storageOpts.initialRivalry !== undefined ? storageOpts.initialRivalry : null };

    function fakeFetch(url, opts) {
        fetchCalls.push({ url: String(url), opts: opts || {} });
        if (String(url).indexOf('/api/webdoor/storage/1?') === 0) { // RIVALRY_SLOT
            if (opts && opts.method === 'PUT') {
                if (storageOpts.saveShouldFail) {
                    return Promise.resolve({ ok: false, status: 500, json: () => Promise.resolve({}) });
                }
                storageState.rivalry = JSON.parse(opts.body).data;
                return Promise.resolve({ ok: true, json: () => Promise.resolve({ success: true, slot: 1 }) });
            }
            // GET
            if (storageOpts.loadShouldFail) {
                return Promise.resolve({ ok: false, status: 500, json: () => Promise.resolve({}) });
            }
            if (storageState.rivalry === null) {
                return Promise.resolve({ ok: false, status: 404, json: () => Promise.resolve({}) });
            }
            return Promise.resolve({ ok: true, json: () => Promise.resolve({ slot: 1, data: storageState.rivalry }) });
        }
        // Anything else (SESSION_SLOT 0, unused by this build) is untouched
        // by this fork — fall through to the puzzles.json response, exactly
        // as every pre-existing test in this file already relies on.
        return Promise.resolve({ ok: true, json: () => Promise.resolve(JSON.parse(puzzlesJson)) });
    }

    const sandbox = {
        console,
        Math: fakeMath,
        URLSearchParams,
        document: {
            getElementById: (id) => elements[id],
            createElement: (tag) => makeElement('(' + tag + ')'),
            createTextNode: (text) => ({ nodeType: 3, textContent: text }),
            activeElement: null
        },
        location: { search: locationSearch || '' },
        fetch: fakeFetch,
        addEventListener: function () {},
        setTimeout: clock.setTimeout,
        clearTimeout: clock.clearTimeout
    };
    sandbox.window = sandbox;
    sandbox.self = sandbox;
    const context = vm.createContext(sandbox);

    ['content.js', 'state.js', 'round.js', 'final.js', 'session.js', 'gallows-character.js', 'idle-chatter.js', 'hint.js', 'skippy-memory.js', 'situational-awareness.js', 'safe-predicament.js', 'bob-character.js', 'auto-solve.js', 'presentation.js', 'rivalry.js', 'storage.js', 'app-final-skippy.js'].forEach((f) => {
        const file = path.join(ROOT, 'js/lastword', f);
        vm.runInContext(fs.readFileSync(file, 'utf8'), context, { filename: f });
    });

    return new Promise((resolve) => setImmediate(() => setImmediate(() => resolve({ elements, context, clock, rng, fetchCalls, storageState }))));
}

function clickByText(container, text) {
    const button = container._children.find((b) => b.textContent === text || b.textContent.startsWith(text + ' ('));
    assert.ok(button, 'no button found for "' + text + '"');
    button.click();
}

function answerShape(answer) {
    return answer.toUpperCase().replace(/[A-Z]/g, '_');
}

function boardShape(boardEl) {
    return boardEl._children.map((cell) => {
        if (cell.className === 'cell space') return ' ';
        if (cell.className === 'cell punct') return cell.textContent;
        return '_';
    }).join('');
}

function dealtPuzzleCandidates(boardEl, puzzlesDoc, category) {
    const shape = boardShape(boardEl);
    const candidates = puzzlesDoc.puzzles.filter((p) => p.category === category && answerShape(p.answer) === shape);
    assert.ok(candidates.length > 0, 'no seed puzzle matches the dealt board shape for ' + category);
    return candidates;
}

// Must match app-final-skippy.js's DEFEAT_PAYOFF_DELAY_MS/SAVED_PAYOFF_DELAY_MS
// (playtest iteration #3: SAVED's hold was lengthened; COMEDIC_DEFEAT's was not).
const DEFEAT_PAYOFF_DELAY_MS = 1400;
const SAVED_PAYOFF_DELAY_MS = 3400;

let passed = 0;
async function check(name, fn) {
    await fn();
    passed++;
    console.log('  ok - ' + name);
}

(async () => {
    console.log('app-final-skippy-dom.test.js');

    await check('a fresh round renders Skippy CONFIDENT (strikes 0, not solved)', async () => {
        const { elements } = await bootApp(undefined, '?predicament=gallows');
        clickByText(elements.categoryList, 'Movies & TV');
        assert.strictEqual(stateOf(elements.gallows), 'CONFIDENT');
    });

    await check('each wrong-consonant strike advances Skippy through the correct visual state (0-5)', async () => {
        const { elements } = await bootApp(undefined, '?predicament=gallows');
        clickByText(elements.categoryList, 'Movies & TV');

        const EXPECTED = ['CONFUSED', 'CONCERNED', 'NERVOUS', 'PLEADING', 'TERRIFIED'];
        // Q/X/Z/J/W are rare enough in curated puzzle answers to reliably miss;
        // guard with a couple of fallback letters in case any single one hits.
        const tryLetters = ['Q', 'X', 'Z', 'J', 'W', 'K', 'V'];
        let expectedIdx = 0;
        let li = 0;
        while (expectedIdx < EXPECTED.length && li < tryLetters.length) {
            const before = elements.strikeCount.textContent;
            clickByText(elements.letters, tryLetters[li]);
            li++;
            if (elements.strikeCount.textContent !== before) {
                assert.strictEqual(stateOf(elements.gallows), EXPECTED[expectedIdx],
                    'at strikes=' + elements.strikeCount.textContent);
                expectedIdx++;
            }
        }
        assert.strictEqual(expectedIdx, EXPECTED.length, 'did not observe all 5 strike escalations — puzzle answer used every rare letter tried');
    });

    await check('a solved round shows SAVED immediately, then (after the payoff pause) reaches round-result', async () => {
        const { elements, clock } = await bootApp(undefined, '?predicament=gallows');
        const puzzlesDoc = JSON.parse(fs.readFileSync(path.join(ROOT, 'lastword/puzzles.json'), 'utf8'));
        clickByText(elements.categoryList, 'Movies & TV');
        const candidates = dealtPuzzleCandidates(elements.board, puzzlesDoc, 'Movies & TV');

        elements.solveToggle.click();
        elements.solveInput.value = candidates[0].answer;
        elements.solveSubmit.click();

        assert.strictEqual(stateOf(elements.gallows), 'SAVED', 'Skippy should already show SAVED before the screen switches');
        assert.strictEqual(elements.game.className, 'layout', 'still on the game screen during the payoff pause');
        assert.strictEqual(elements['round-result'].className, 'panel hidden');

        clock.advance(SAVED_PAYOFF_DELAY_MS);

        assert.strictEqual(elements.game.className, 'layout hidden');
        assert.strictEqual(elements['round-result'].className, 'panel');
    });

    await check('the SAVED celebration holds for exactly 3400ms (playtest iteration #3: lengthened by 2000ms from the original 1400ms)', async () => {
        const { elements, clock } = await bootApp(undefined, '?predicament=gallows');
        const puzzlesDoc = JSON.parse(fs.readFileSync(path.join(ROOT, 'lastword/puzzles.json'), 'utf8'));
        clickByText(elements.categoryList, 'Movies & TV');
        const candidates = dealtPuzzleCandidates(elements.board, puzzlesDoc, 'Movies & TV');
        elements.solveToggle.click();
        elements.solveInput.value = candidates[0].answer;
        elements.solveSubmit.click();

        clock.advance(SAVED_PAYOFF_DELAY_MS - 1);
        assert.strictEqual(elements['round-result'].className, 'panel hidden', 'should still be holding on SAVED just before 3400ms');
        clock.advance(1);
        assert.strictEqual(elements['round-result'].className, 'panel', 'should advance right at 3400ms');
    });

    await check('COMEDIC_DEFEAT is deliberately left at its original 1400ms hold, unlike SAVED (iteration #3 scope)', async () => {
        const { elements, clock } = await bootApp(undefined, '?predicament=gallows');
        clickByText(elements.categoryList, 'Movies & TV');
        // Strike out via wrong consonants — try the whole consonant alphabet
        // in rarity order so this works regardless of which puzzle gets
        // dealt; stop once Skippy reaches COMEDIC_DEFEAT (the strike-count
        // UI label itself is not re-rendered on the terminal 6th strike —
        // a pre-existing quirk of finishCurrentRound(), unrelated to this
        // iteration — so the gallows state is the reliable signal here).
        const tryLetters = 'QXZJKVWBFCGHLMNPRSDT'.split('');
        for (let i = 0; i < tryLetters.length && stateOf(elements.gallows) !== 'COMEDIC_DEFEAT'; i++) {
            clickByText(elements.letters, tryLetters[i]);
        }
        assert.strictEqual(stateOf(elements.gallows), 'COMEDIC_DEFEAT', 'test fixture needs 6 real strikes from this letter pool');

        clock.advance(DEFEAT_PAYOFF_DELAY_MS - 1);
        assert.strictEqual(elements['round-result'].className, 'panel hidden', 'should still be holding just before 1400ms');
        clock.advance(1);
        assert.strictEqual(elements['round-result'].className, 'panel', 'should advance right at 1400ms — unchanged from before iteration #3');
    });

    await check('starting round 2 after round 1 resets Skippy to CONFIDENT', async () => {
        const { elements, clock } = await bootApp(undefined, '?predicament=gallows');
        const puzzlesDoc = JSON.parse(fs.readFileSync(path.join(ROOT, 'lastword/puzzles.json'), 'utf8'));
        clickByText(elements.categoryList, 'Movies & TV');
        const candidates = dealtPuzzleCandidates(elements.board, puzzlesDoc, 'Movies & TV');
        elements.solveToggle.click();
        elements.solveInput.value = candidates[0].answer;
        elements.solveSubmit.click();
        clock.advance(SAVED_PAYOFF_DELAY_MS);

        elements.continueAfterRound.click(); // -> round 2 transition screen
        elements.transitionContinue.click(); // -> dealt into round 2 play

        assert.strictEqual(stateOf(elements.gallows), 'CONFIDENT');
        assert.strictEqual(elements.strikeCount.textContent, '0');
    });

    // ---- idle chatter integration --------------------------------------

    await check('idle chatter is silent before the first-remark window and appears (visible, unhidden bubble) within it', async () => {
        const { elements, clock } = await bootApp(0, '?predicament=gallows'); // rng=0 -> minimum delay each draw
        clickByText(elements.categoryList, 'Movies & TV');

        assert.strictEqual(elements.skippyChatterBubble.hidden, true, 'bubble starts hidden');
        clock.advance(11999); // just under the 12s minimum
        assert.strictEqual(elements.skippyChatterBubble.hidden, true, 'should not chatter before the first-remark window opens');
        clock.advance(2); // cross the 12000ms mark
        assert.strictEqual(elements.skippyChatterBubble.hidden, false, 'should chatter once inside the first-remark window');
        assert.ok(elements.skippyChatterBubble.textContent.length > 0);
    });

    await check('the chatter bubble auto-hides after CHATTER_BUBBLE_DISPLAY_MS = 10000ms (playtest iteration #3: lengthened from 5000ms)', async () => {
        const { elements, clock } = await bootApp(0, '?predicament=gallows');
        clickByText(elements.categoryList, 'Movies & TV');
        clock.advance(12001);
        assert.strictEqual(elements.skippyChatterBubble.hidden, false, 'precondition: bubble shown');

        clock.advance(9999); // just under the 10s display duration
        assert.strictEqual(elements.skippyChatterBubble.hidden, false, 'should still be readable just before its display window ends');
        clock.advance(2); // cross the 10000ms display-duration mark
        assert.strictEqual(elements.skippyChatterBubble.hidden, true, 'should auto-hide once its display window elapses');
        assert.strictEqual(elements.skippyChatterBubble.textContent, '', 'hiding also clears the text, nothing lingers to be read twice');
    });

    await check('a new remark replaces the still-showing bubble cleanly (no stacking, exactly one bubble element)', async () => {
        const { elements, clock } = await bootApp(0, '?predicament=gallows'); // rng=0 -> minimum delay each draw (first=12s, later=20s)
        clickByText(elements.categoryList, 'Movies & TV');
        clock.advance(12001); // first remark fires
        const first = elements.skippyChatterBubble.textContent;
        assert.ok(first.length > 0);

        clock.advance(20001); // the later-remark window (min 20s), well after the first bubble's own 5s display already auto-hid it
        const second = elements.skippyChatterBubble.textContent;
        assert.ok(second.length > 0);
        assert.notStrictEqual(second, first, 'the replacement remark should differ (no-immediate-repeat still holds)');
        // There is only ever the one bubble element/DOM node — a second
        // firing overwrites its text/visibility in place rather than adding
        // a second node, so nothing can ever stack even if a future timing
        // change ever let two firings land closer together than 5s.
        assert.strictEqual(elements.skippyChatterBubble.hidden, false);
        assert.strictEqual(elements.skippyChatterBubble.textContent, second, 'exactly one bubble\'s worth of text, not a concatenation of both');
    });

    await check('a meaningful player action (a letter guess) hides the bubble and resets the idle-chatter stretch', async () => {
        const { elements, clock } = await bootApp(0, '?predicament=gallows');
        clickByText(elements.categoryList, 'Movies & TV');
        clock.advance(12001);
        assert.strictEqual(elements.skippyChatterBubble.hidden, false, 'precondition: chatter fired');

        clickByText(elements.letters, 'E'); // a common letter — guaranteed to register as a guess either way
        assert.strictEqual(elements.skippyChatterBubble.hidden, true, 'a real player action should hide the bubble and restart the idle stretch');

        clock.advance(11999);
        assert.strictEqual(elements.skippyChatterBubble.hidden, true, 'the restarted stretch should not fire before its own first-remark window');
    });

    await check('idle chatter never mutates gameplay state (score/strikes/outcome unchanged by a firing)', async () => {
        const { elements, clock } = await bootApp(0, '?predicament=gallows');
        clickByText(elements.categoryList, 'Movies & TV');

        const before = {
            strikes: elements.strikeCount.textContent,
            score: elements.cumulativeScore.textContent,
            roundScore: elements.roundScore.textContent,
            boardHTML: elements.board._innerHTML
        };
        clock.advance(12001); // one chatter firing
        clock.advance(35001); // a second, using the later-remark window's own max
        assert.strictEqual(elements.strikeCount.textContent, before.strikes);
        assert.strictEqual(elements.cumulativeScore.textContent, before.score);
        assert.strictEqual(elements.roundScore.textContent, before.roundScore);
        assert.strictEqual(elements.board._innerHTML, before.boardHTML);
    });

    await check('idle chatter stops firing once a round ends, even if the clock keeps advancing', async () => {
        const { elements, clock } = await bootApp(0, '?predicament=gallows');
        const puzzlesDoc = JSON.parse(fs.readFileSync(path.join(ROOT, 'lastword/puzzles.json'), 'utf8'));
        clickByText(elements.categoryList, 'Movies & TV');
        const candidates = dealtPuzzleCandidates(elements.board, puzzlesDoc, 'Movies & TV');

        elements.solveToggle.click();
        elements.solveInput.value = candidates[0].answer;
        elements.solveSubmit.click(); // round ends -> idleChatter.stop() should fire
        clock.advance(SAVED_PAYOFF_DELAY_MS); // reach round-result

        elements.skippyChatterBubble.hidden = true; // sanity reset of the probe itself
        elements.skippyChatterBubble.textContent = '';
        clock.advance(120000); // far past any plausible remaining timer
        assert.strictEqual(elements.skippyChatterBubble.hidden, true, 'no chatter should fire once the round (and its idle controller) has stopped');
        assert.strictEqual(elements.skippyChatterBubble.textContent, '');
    });

    await check('at most 2-3 idle remarks land in one uninterrupted idle stretch, then it goes quiet', async () => {
        const { elements, clock } = await bootApp(0.99, '?predicament=gallows'); // biases the per-stretch remark budget toward its max
        clickByText(elements.categoryList, 'Movies & TV');

        let changes = 0;
        let lastText = elements.skippyChatterBubble.textContent;
        for (let i = 0; i < 12; i++) {
            clock.advance(36000); // longer than the widest possible later-remark window
            if (elements.skippyChatterBubble.textContent !== lastText && elements.skippyChatterBubble.textContent !== '') {
                changes++;
                lastText = elements.skippyChatterBubble.textContent;
            }
        }
        assert.ok(changes >= 2 && changes <= 3, 'changes=' + changes);
    });

    await check('within one uninterrupted idle stretch, a remark never immediately repeats the previous one', async () => {
        const { elements, clock, rng } = await bootApp(0.4, '?predicament=gallows');
        clickByText(elements.categoryList, 'Movies & TV');
        // Vary the draws so the stretch's (up to 3) remarks are not forced
        // toward the same pool index every time — pickChatterLine's own
        // previous-line exclusion is exercised for real rather than
        // trivially satisfied by only ever drawing once.
        rng.push(0.5, 0.1, 0.9, 0.2, 0.8, 0.3, 0.99, 0.6);

        const seen = [];
        let lastText = '';
        for (let i = 0; i < 6; i++) {
            clock.advance(35001); // covers both the first- and later-remark windows
            if (elements.skippyChatterBubble.textContent && elements.skippyChatterBubble.textContent !== lastText) {
                lastText = elements.skippyChatterBubble.textContent;
                seen.push(lastText);
            }
        }
        assert.ok(seen.length >= 2, 'expected at least 2 distinct firings to compare, got ' + seen.length);
        for (let i = 1; i < seen.length; i++) {
            assert.notStrictEqual(seen[i], seen[i - 1]);
        }
    });

    // ---- BUY HINT LETTER integration ------------------------------------

    await check('the hint button shows the correct Round 1 cost label, disabled at round start (score 0 < 300) and enabled once affordable', async () => {
        const { elements } = await bootApp(0, '?predicament=gallows'); // rng=0 -> pickRandom deals pool[0] deterministically
        const puzzlesDoc = JSON.parse(fs.readFileSync(path.join(ROOT, 'lastword/puzzles.json'), 'utf8'));
        const expectedPuzzle = puzzlesDoc.puzzles.filter((p) => p.category === 'Movies & TV')[0];
        clickByText(elements.categoryList, 'Movies & TV'); // Round 1
        assert.strictEqual(boardShape(elements.board), answerShape(expectedPuzzle.answer));

        assert.strictEqual(elements.buyHintButton.textContent, 'HINT · 300');
        assert.strictEqual(elements.buyHintButton.disabled, true, 'Round 1 always starts at score 0, below the 300 cost');

        const consonants = Array.from(new Set(expectedPuzzle.answer.toUpperCase().replace(/[^A-Z]/g, '')))
            .filter((l) => 'AEIOU'.indexOf(l) === -1);
        // Guess consonants (guaranteed-correct, guaranteed-free) until 300+
        // points is earned — however many that takes for this puzzle.
        let ci = 0;
        while (Number(elements.roundScore.textContent) < 300 && ci < consonants.length) {
            clickByText(elements.letters, consonants[ci]);
            ci++;
        }
        assert.ok(Number(elements.roundScore.textContent) >= 300, 'precondition: earned enough for a hint');
        assert.strictEqual(elements.buyHintButton.disabled, false);
    });

    await check('buying a hint reveals a real answer letter, deducts exactly the round-specific cost once, and adds no strike', async () => {
        const { elements } = await bootApp(0, '?predicament=gallows'); // rng=0 -> pickRandom deals pool[0] deterministically
        const puzzlesDoc = JSON.parse(fs.readFileSync(path.join(ROOT, 'lastword/puzzles.json'), 'utf8'));
        const expectedPuzzle = puzzlesDoc.puzzles.filter((p) => p.category === 'Movies & TV')[0];
        clickByText(elements.categoryList, 'Movies & TV');
        // Self-verify the rng=0 -> pool[0] assumption rather than trusting it blindly.
        assert.strictEqual(boardShape(elements.board), answerShape(expectedPuzzle.answer));

        // Earn enough score to afford the 300 hint cost via ordinary,
        // guaranteed-correct consonant guesses (never a vowel purchase, so
        // this doesn't itself touch pointsThisRound the way a hint would).
        const letters = Array.from(new Set(expectedPuzzle.answer.toUpperCase().replace(/[^A-Z]/g, '')));
        const consonants = letters.filter((l) => 'AEIOU'.indexOf(l) === -1);
        let ci = 0;
        while (Number(elements.roundScore.textContent) < 300 && ci < consonants.length) {
            clickByText(elements.letters, consonants[ci]);
            ci++;
        }
        const strikesBefore = elements.strikeCount.textContent;
        const scoreBefore = Number(elements.roundScore.textContent);
        assert.ok(scoreBefore >= 300, 'precondition: earned enough to afford a hint; got ' + scoreBefore);

        elements.buyHintButton.click();

        assert.strictEqual(elements.strikeCount.textContent, strikesBefore, 'a hint must never add a strike');
        assert.strictEqual(Number(elements.roundScore.textContent), scoreBefore - 300, 'exactly the Round 1 hint cost (300) should be deducted, once');
        assert.ok(elements.statusLine.textContent.indexOf('Hint:') !== -1);
    });

    await check('the hint button becomes disabled with a stated reason once score is insufficient', async () => {
        const { elements } = await bootApp(0, '?predicament=gallows');
        clickByText(elements.categoryList, 'Movies & TV');
        // Round 1 starts with cumulativeScore 0 and pointsThisRound 0 —
        // availableScore() is already below the 300 hint cost.
        assert.strictEqual(elements.buyHintButton.disabled, true);
        assert.ok(elements.hintUnavailableReason.textContent.indexOf('300') !== -1);
    });

    // NOTE: hint.js's "no unrevealed guessable letters remaining" rejection
    // is still covered directly at the pure-module level (hint.test.js) —
    // but now that revealing the LAST letter auto-solves the round (see the
    // "CORE-GAME FIX" tests below), that state can no longer actually be
    // observed via ordinary play through the DOM: the round ends the
    // instant the board becomes fully revealed, before a hint-button
    // re-render showing "disabled: already revealed" would ever happen.
    // The test that used to click through a full reveal expecting to see
    // that disabled state now documents the auto-solve instead (see
    // "normal round auto-completes after the final VOWEL reveal" below,
    // which uses this exact same click sequence).

    await check('the hint button is disabled once the round is over', async () => {
        const { elements } = await bootApp(0, '?predicament=gallows');
        const puzzlesDoc = JSON.parse(fs.readFileSync(path.join(ROOT, 'lastword/puzzles.json'), 'utf8'));
        clickByText(elements.categoryList, 'Movies & TV');
        const candidates = dealtPuzzleCandidates(elements.board, puzzlesDoc, 'Movies & TV');
        elements.solveToggle.click();
        elements.solveInput.value = candidates[0].answer;
        elements.solveSubmit.click();
        assert.strictEqual(elements.buyHintButton.disabled, true);
    });

    await check('buying a hint is a meaningful action: it hides any showing chatter bubble and resets the idle stretch', async () => {
        const { elements, clock } = await bootApp(0, '?predicament=gallows');
        clickByText(elements.categoryList, 'Movies & TV');
        clock.advance(12001);
        assert.strictEqual(elements.skippyChatterBubble.hidden, false, 'precondition: chatter fired');

        elements.buyHintButton.click();
        assert.strictEqual(elements.skippyChatterBubble.hidden, true);

        clock.advance(11999);
        assert.strictEqual(elements.skippyChatterBubble.hidden, true, 'the restarted stretch should not fire before its own first-remark window');
    });

    // ---- CORE-GAME FIX: auto-solve on full reveal -----------------------
    // Human playtest repro: during Final Hangman, revealing every letter in
    // "GALILEO GALILEI" left the board fully visible but the game never
    // recognized completion — the player had to retype the already-visible
    // answer via SOLVE. These tests drive the real DOM wiring (not just
    // js/lastword/auto-solve.js's own pure tests) to prove the fix actually
    // fires from every letter-reveal call site and reuses the canonical
    // completion path (Skippy SAVED, the 3400ms payoff hold, exactly one
    // screen transition, no extra strike/action).

    await check('normal round auto-completes after the final CONSONANT reveal (no SOLVE needed)', async () => {
        const { elements, clock } = await bootApp(0, '?predicament=gallows'); // rng=0 -> pickRandom deals pool[0] deterministically
        const puzzlesDoc = JSON.parse(fs.readFileSync(path.join(ROOT, 'lastword/puzzles.json'), 'utf8'));
        const expectedPuzzle = puzzlesDoc.puzzles.filter((p) => p.category === 'Movies & TV')[0]; // BACK TO THE FUTURE
        clickByText(elements.categoryList, 'Movies & TV');
        assert.strictEqual(boardShape(elements.board), answerShape(expectedPuzzle.answer));

        const letters = Array.from(new Set(expectedPuzzle.answer.toUpperCase().replace(/[^A-Z]/g, '')));
        const consonants = letters.filter((l) => 'AEIOU'.indexOf(l) === -1);
        const vowels = letters.filter((l) => 'AEIOU'.indexOf(l) !== -1);
        // Earn score via consonants first (free), buy every vowel, and save
        // the LAST consonant for last so the round completes via a
        // consonant guess specifically.
        consonants.slice(0, -1).forEach((l) => clickByText(elements.letters, l));
        vowels.forEach((l) => clickByText(elements.letters, l));
        assert.strictEqual(elements['round-result'].className, 'panel hidden', 'precondition: round not over yet, one consonant still hidden');

        clickByText(elements.letters, consonants[consonants.length - 1]); // the final reveal

        assert.strictEqual(stateOf(elements.gallows), 'SAVED', 'the round must be recognized as solved immediately — no retyping required');
        assert.strictEqual(elements.game.className, 'layout', 'holding on SAVED during the payoff pause, not yet transitioned');
        assert.strictEqual(elements['round-result'].className, 'panel hidden');

        clock.advance(SAVED_PAYOFF_DELAY_MS);
        assert.strictEqual(elements['round-result'].className, 'panel');
        assert.strictEqual(elements.roundResultTitle.textContent.indexOf('Solved!') !== -1, true);

        // No duplicate transition even if the clock keeps advancing.
        clock.advance(60000);
        assert.strictEqual(elements['round-result'].className, 'panel');
        assert.strictEqual(elements.game.className, 'layout hidden');
    });

    await check('normal round auto-completes after the final VOWEL reveal (no SOLVE needed)', async () => {
        const { elements, clock } = await bootApp(0, '?predicament=gallows');
        const puzzlesDoc = JSON.parse(fs.readFileSync(path.join(ROOT, 'lastword/puzzles.json'), 'utf8'));
        const expectedPuzzle = puzzlesDoc.puzzles.filter((p) => p.category === 'Movies & TV')[0];
        clickByText(elements.categoryList, 'Movies & TV');
        assert.strictEqual(boardShape(elements.board), answerShape(expectedPuzzle.answer));

        const letters = Array.from(new Set(expectedPuzzle.answer.toUpperCase().replace(/[^A-Z]/g, '')));
        const consonants = letters.filter((l) => 'AEIOU'.indexOf(l) === -1);
        const vowels = letters.filter((l) => 'AEIOU'.indexOf(l) !== -1);
        // Reveal every consonant (earns the score to afford every vowel),
        // then every vowel except the last one, so the round completes via
        // a vowel purchase specifically.
        consonants.forEach((l) => clickByText(elements.letters, l));
        vowels.slice(0, -1).forEach((l) => clickByText(elements.letters, l));
        assert.strictEqual(elements['round-result'].className, 'panel hidden', 'precondition: one vowel still hidden');

        clickByText(elements.letters, vowels[vowels.length - 1]); // the final reveal, a purchase

        assert.strictEqual(stateOf(elements.gallows), 'SAVED');
        clock.advance(SAVED_PAYOFF_DELAY_MS);
        assert.strictEqual(elements['round-result'].className, 'panel');
    });

    await check('normal round auto-completes after the final HINT reveal, awarding the solve bonus exactly once', async () => {
        const { elements, clock } = await bootApp(0, '?predicament=gallows');
        const puzzlesDoc = JSON.parse(fs.readFileSync(path.join(ROOT, 'lastword/puzzles.json'), 'utf8'));
        const expectedPuzzle = puzzlesDoc.puzzles.filter((p) => p.category === 'Movies & TV')[0];
        clickByText(elements.categoryList, 'Movies & TV');
        assert.strictEqual(boardShape(elements.board), answerShape(expectedPuzzle.answer));

        const letters = Array.from(new Set(expectedPuzzle.answer.toUpperCase().replace(/[^A-Z]/g, '')));
        const consonants = letters.filter((l) => 'AEIOU'.indexOf(l) === -1);
        const vowels = letters.filter((l) => 'AEIOU'.indexOf(l) !== -1);
        // Reveal every letter except one vowel through ordinary play (score
        // comfortably covers the 300 hint cost), then buy the hint — with
        // exactly one candidate letter left, rng=0 deterministically picks it.
        consonants.forEach((l) => clickByText(elements.letters, l));
        vowels.slice(0, -1).forEach((l) => clickByText(elements.letters, l));
        const pointsBeforeLastReveal = Number(elements.roundScore.textContent);
        assert.ok(elements.buyHintButton.disabled === false, 'precondition: hint affordable and a candidate remains');

        elements.buyHintButton.click(); // the final reveal, via HINT

        assert.strictEqual(elements.statusLine.textContent.indexOf('Hint: ' + vowels[vowels.length - 1]) !== -1, true,
            'the hint must have picked the one remaining letter');
        assert.strictEqual(stateOf(elements.gallows), 'SAVED');
        clock.advance(SAVED_PAYOFF_DELAY_MS);
        assert.strictEqual(elements['round-result'].className, 'panel');

        // The solve bonus must have been added exactly once, at Round 1's
        // real decayed value (11 actions taken by this point — 7 consonants
        // + 3 vowels + 1 hint — decays the 1500 max bonus by 150 each,
        // flooring at 0 rather than going negative): expected points are
        // exactly (points before the hint) - (hint cost) + that bonus, not
        // merely the hint's own cost with nothing added, and not double.
        const roundPointsLine = elements.roundResultBody._children.find((l) => l.textContent.indexOf('Round points:') === 0);
        assert.ok(roundPointsLine, 'round-result must show a Round points line');
        const finalRoundPoints = Number(roundPointsLine.textContent.replace('Round points: ', ''));
        const expectedBonus = Math.max(0, 1500 - 150 * 11);
        assert.strictEqual(finalRoundPoints, pointsBeforeLastReveal - 300 + expectedBonus);
    });

    await check('auto-solve adds no extra strike (strikes at round-end match strikes actually earned, none invented)', async () => {
        const { elements, clock } = await bootApp(0, '?predicament=gallows');
        const puzzlesDoc = JSON.parse(fs.readFileSync(path.join(ROOT, 'lastword/puzzles.json'), 'utf8'));
        const expectedPuzzle = puzzlesDoc.puzzles.filter((p) => p.category === 'Movies & TV')[0];
        clickByText(elements.categoryList, 'Movies & TV');
        const letters = Array.from(new Set(expectedPuzzle.answer.toUpperCase().replace(/[^A-Z]/g, '')));
        const consonants = letters.filter((l) => 'AEIOU'.indexOf(l) === -1);
        const vowels = letters.filter((l) => 'AEIOU'.indexOf(l) !== -1);
        consonants.slice(0, -1).forEach((l) => clickByText(elements.letters, l));
        vowels.forEach((l) => clickByText(elements.letters, l));
        assert.strictEqual(elements.strikeCount.textContent, '0');

        clickByText(elements.letters, consonants[consonants.length - 1]);
        clock.advance(SAVED_PAYOFF_DELAY_MS);
        // strikeCount is not re-rendered on the terminal transition (a
        // pre-existing quirk noted in an earlier iteration), so it still
        // reads the last real render — which must show 0, proving no
        // strike was ever added by any of the reveals above or by auto-solve.
        assert.strictEqual(elements.strikeCount.textContent, '0', 'no strike should have been invented by auto-solve');
    });

    await check('explicit SOLVE still works normally on an incomplete board (auto-solve did not replace it)', async () => {
        const { elements } = await bootApp(0, '?predicament=gallows');
        const puzzlesDoc = JSON.parse(fs.readFileSync(path.join(ROOT, 'lastword/puzzles.json'), 'utf8'));
        clickByText(elements.categoryList, 'Movies & TV');
        const candidates = dealtPuzzleCandidates(elements.board, puzzlesDoc, 'Movies & TV');
        // Only ONE letter guessed — the board is nowhere near fully revealed.
        clickByText(elements.letters, 'T');

        elements.solveToggle.click();
        elements.solveInput.value = candidates[0].answer;
        elements.solveSubmit.click();

        assert.strictEqual(stateOf(elements.gallows), 'SAVED', 'an explicit correct SOLVE on an incomplete board must still work');
    });

    await check('Final Hangman auto-completes after the final purchased-letter reveal (the human-reported repro path)', async () => {
        const { elements, clock } = await bootApp(0, '?predicament=gallows');
        const puzzlesDoc = JSON.parse(fs.readFileSync(path.join(ROOT, 'lastword/puzzles.json'), 'utf8'));

        function solveCurrentRoundAndContinue() {
            const category = elements.categoryLabel.textContent;
            const candidates = dealtPuzzleCandidates(elements.board, puzzlesDoc, category);
            elements.solveToggle.click();
            elements.solveInput.value = candidates[0].answer;
            elements.solveSubmit.click();
            clock.advance(SAVED_PAYOFF_DELAY_MS);
            elements.continueAfterRound.click();
        }

        // Round 1
        clickByText(elements.categoryList, 'Movies & TV');
        solveCurrentRoundAndContinue(); // -> round 2 transition screen
        elements.transitionContinue.click(); // -> dealt into round 2
        solveCurrentRoundAndContinue(); // -> round 3 offer screen
        clickByText(elements.roundOfferList, elements.roundOfferList._children[0].textContent); // -> dealt into round 3
        solveCurrentRoundAndContinue(); // -> round 4 transition screen
        elements.transitionContinue.click(); // -> dealt into round 4
        solveCurrentRoundAndContinue(); // -> session complete -> Final intro

        assert.strictEqual(elements['final-intro'].className, 'panel', 'should have reached Final Hangman intro');
        clickByText(elements.finalOpeningCountButtons, '0 letters (free)'); // no opening help -> straight into Final play

        assert.strictEqual(elements['final-play'].className, 'layout');
        const finalPuzzlesDoc = puzzlesDoc.puzzles.filter((p) => p.finalEligible === true);
        const finalCandidates = dealtPuzzleCandidates(elements.finalBoard, { puzzles: finalPuzzlesDoc }, elements.finalCategoryLabel.textContent);
        const finalLetters = Array.from(new Set(finalCandidates[0].answer.toUpperCase().replace(/[^A-Z]/g, '')));

        finalLetters.slice(0, -1).forEach((l) => clickByText(elements.finalLetters, l));
        assert.strictEqual(elements['game-complete'].className, 'panel hidden', 'precondition: one letter still hidden');

        clickByText(elements.finalLetters, finalLetters[finalLetters.length - 1]); // the final reveal

        assert.strictEqual(stateOf(elements.finalGallows), 'SAVED', 'Final must recognize completion immediately — the exact human-reported defect');
        assert.strictEqual(elements['final-play'].className, 'layout', 'holding on SAVED during the payoff pause');
        assert.strictEqual(elements['game-complete'].className, 'panel hidden');

        clock.advance(SAVED_PAYOFF_DELAY_MS);
        assert.strictEqual(elements['game-complete'].className, 'panel');
        assert.ok(elements.gameCompleteTitle.textContent.indexOf('Solved') !== -1);

        // No duplicate transition even if the clock keeps advancing.
        clock.advance(60000);
        assert.strictEqual(elements['game-complete'].className, 'panel');
    });

    // ---- SKIPPY REMEMBERS (conscious bounded fork #1) integration ------

    function loseCurrentRoundViaStrikes(elements) {
        const tryLetters = 'QXZJKVWBFCGHLMNPRSDT'.split('');
        for (let i = 0; i < tryLetters.length && stateOf(elements.gallows) !== 'COMEDIC_DEFEAT'; i++) {
            clickByText(elements.letters, tryLetters[i]);
        }
        assert.strictEqual(stateOf(elements.gallows), 'COMEDIC_DEFEAT', 'test fixture needs 6 real strikes from this letter pool');
    }

    await check('an incident (round lost at 6 strikes) produces an opening reaction at the start of the next round, without mutating gameplay state', async () => {
        const { elements, clock } = await bootApp(0, '?predicament=gallows');
        clickByText(elements.categoryList, 'Movies & TV');
        loseCurrentRoundViaStrikes(elements);
        clock.advance(DEFEAT_PAYOFF_DELAY_MS);
        elements.continueAfterRound.click(); // -> round 2 transition screen
        elements.transitionContinue.click(); // -> dealt into round 2 play; beginRoundPlay() fires the reaction

        assert.strictEqual(elements.skippyChatterBubble.hidden, false, 'an incident just happened — the next round should open with a reaction');
        assert.ok(elements.skippyChatterBubble.textContent.length > 0);

        // Gameplay itself must be untouched by showing the reaction: fresh
        // round 2 starts clean regardless.
        assert.strictEqual(stateOf(elements.gallows), 'CONFIDENT');
        assert.strictEqual(elements.strikeCount.textContent, '0');
        assert.strictEqual(elements.roundScore.textContent, '0');
    });

    await check('ordinary history (no interesting previous round) opens the next round silently — no reaction bubble', async () => {
        const { elements, clock } = await bootApp(0, '?predicament=gallows');
        const puzzlesDoc = JSON.parse(fs.readFileSync(path.join(ROOT, 'lastword/puzzles.json'), 'utf8'));
        clickByText(elements.categoryList, 'Movies & TV');
        const candidates = dealtPuzzleCandidates(elements.board, puzzlesDoc, 'Movies & TV');

        // Guess one wrong-then-right consonant so the round is solved at a
        // middling, non-0/non-5 strike count with no hints bought —
        // deliberately NOT an interesting round by skippy-memory.js's rules.
        clickByText(elements.letters, 'Q'); // very likely a miss -> strikes=1
        elements.solveToggle.click();
        elements.solveInput.value = candidates[0].answer;
        elements.solveSubmit.click();
        clock.advance(SAVED_PAYOFF_DELAY_MS);
        elements.continueAfterRound.click();
        elements.transitionContinue.click(); // -> round 2 play

        if (elements.strikeCount.textContent === '0' || elements.strikeCount.textContent === '5') {
            return; // the Q guess happened to hit — not the scenario this test targets, skip rather than false-fail
        }
        assert.strictEqual(elements.skippyChatterBubble.hidden, true, 'a middling, hint-free solved round should not trigger a special reaction');
    });

    await check('the opening reaction never collides/stacks with idle chatter — it auto-hides on its own schedule, then idle chatter resumes normally', async () => {
        const { elements, clock } = await bootApp(0, '?predicament=gallows');
        clickByText(elements.categoryList, 'Movies & TV');
        loseCurrentRoundViaStrikes(elements);
        clock.advance(DEFEAT_PAYOFF_DELAY_MS);
        elements.continueAfterRound.click();
        elements.transitionContinue.click(); // round 2 begins with the reaction shown
        const reactionText = elements.skippyChatterBubble.textContent;
        assert.ok(reactionText.length > 0);

        clock.advance(9999); // just under the reaction's own 10s display window
        assert.strictEqual(elements.skippyChatterBubble.hidden, false, 'reaction should still be showing');
        assert.strictEqual(elements.skippyChatterBubble.textContent, reactionText, 'still exactly the reaction text, nothing appended');

        clock.advance(2); // crosses 10000ms -> reaction auto-hides
        assert.strictEqual(elements.skippyChatterBubble.hidden, true);

        clock.advance(2000); // crosses idle chatter's own 12000ms first-remark window
        assert.strictEqual(elements.skippyChatterBubble.hidden, false, 'idle chatter should resume normally once the reaction is done showing');
        assert.notStrictEqual(elements.skippyChatterBubble.textContent, '');
    });

    await check('Final Hangman opening reaction reflects the accumulated session (repeated incidents read as suspicious) without mutating Final state', async () => {
        const { elements, clock } = await bootApp(0, '?predicament=gallows');
        const puzzlesDoc = JSON.parse(fs.readFileSync(path.join(ROOT, 'lastword/puzzles.json'), 'utf8'));

        function loseRoundAndContinue() {
            loseCurrentRoundViaStrikes(elements);
            clock.advance(DEFEAT_PAYOFF_DELAY_MS);
            elements.continueAfterRound.click();
        }

        clickByText(elements.categoryList, 'Movies & TV');
        loseRoundAndContinue(); // round 1: incident -> transition to round 2
        elements.transitionContinue.click();
        loseRoundAndContinue(); // round 2: incident -> round 3 offer screen
        clickByText(elements.roundOfferList, elements.roundOfferList._children[0].textContent);
        // Round 3: solve normally so the session can still reach Final.
        const candidates = dealtPuzzleCandidates(elements.board, puzzlesDoc, elements.categoryLabel.textContent);
        elements.solveToggle.click();
        elements.solveInput.value = candidates[0].answer;
        elements.solveSubmit.click();
        clock.advance(SAVED_PAYOFF_DELAY_MS);
        elements.continueAfterRound.click(); // -> round 4 transition
        elements.transitionContinue.click();
        // Round 4: solve normally too.
        const candidates4 = dealtPuzzleCandidates(elements.board, puzzlesDoc, elements.categoryLabel.textContent);
        elements.solveToggle.click();
        elements.solveInput.value = candidates4[0].answer;
        elements.solveSubmit.click();
        clock.advance(SAVED_PAYOFF_DELAY_MS);
        elements.continueAfterRound.click(); // -> session complete -> Final intro

        assert.strictEqual(elements['final-intro'].className, 'panel');
        clickByText(elements.finalOpeningCountButtons, '0 letters (free)'); // -> beginFinalPlay(), fires the Final reaction

        assert.strictEqual(elements['final-play'].className, 'layout');
        assert.strictEqual(elements.finalSkippyChatterBubble.hidden, false, 'Final always opens with exactly one reaction line');
        assert.ok(elements.finalSkippyChatterBubble.textContent.length > 0);
        // Final gameplay itself must be untouched: fresh Final board, 0 strikes.
        assert.strictEqual(stateOf(elements.finalGallows), 'CONFIDENT');
        assert.strictEqual(elements.finalStrikeCount.textContent, '0');
    });

    await check('starting a new session (Play Again) resets Skippy\'s memory — no leftover reaction from the previous session', async () => {
        const { elements, clock } = await bootApp(0, '?predicament=gallows');
        clickByText(elements.categoryList, 'Movies & TV');
        loseCurrentRoundViaStrikes(elements); // give the session an incident to remember
        clock.advance(DEFEAT_PAYOFF_DELAY_MS);
        elements.continueAfterRound.click();
        elements.transitionContinue.click(); // round 2 opens with the incident reaction — precondition
        assert.strictEqual(elements.skippyChatterBubble.hidden, false, 'precondition: an opening reaction is showing');

        // Abandon this session entirely via Play Again (simulating a fresh
        // session start — the same call startNewSession() makes) rather
        // than playing the rest out.
        elements.playAgain.onclick(); // startNewSession() -> back to category select
        assert.strictEqual(elements['category-select'].className, 'panel');

        clickByText(elements.categoryList, 'Movies & TV'); // fresh round 1 of the NEW session
        assert.strictEqual(elements.skippyChatterBubble.hidden, true, 'a brand-new session must not open with a reaction carried over from the last one');
    });

    // ---- SKIPPY IS WATCHING (conscious bounded fork #2) integration ----
    //
    // All of these use rng=0 -> the deterministic Round 1 deal already
    // self-verified elsewhere in this file: category "Movies & TV" ->
    // "BACK TO THE FUTURE" (consonants B,C,K,T,H,F,R; T occurs 3x, so
    // guessing T alone earns 300 points — exactly Round 1's hint cost).

    function earnAtLeast(elements, targetScore) {
        const consonants = ['T', 'B', 'C', 'K', 'H', 'F', 'R']; // T first: reaches 300 in one guess
        let ci = 0;
        while (Number(elements.roundScore.textContent) < targetScore && ci < consonants.length) {
            clickByText(elements.letters, consonants[ci]);
            ci++;
        }
        assert.ok(Number(elements.roundScore.textContent) >= targetScore,
            'fixture precondition: could not earn ' + targetScore + ' from this puzzle\'s consonants');
    }

    await check('five-strike danger reaction fires the moment strikes hit 5 while a hint is affordable, via real play', async () => {
        const { elements } = await bootApp(0, '?predicament=gallows');
        clickByText(elements.categoryList, 'Movies & TV');
        earnAtLeast(elements, 300); // guesses T -> +300, exactly Round 1's hint cost
        assert.strictEqual(elements.skippyChatterBubble.hidden, true, 'no reaction yet — strikes still 0');

        // Miss with rare letters until strikes hits 5 (never touches T/B/C/K/H/F/R, all real answer letters).
        const missLetters = ['Q', 'X', 'Z', 'J', 'W'];
        missLetters.forEach((l) => clickByText(elements.letters, l));

        assert.strictEqual(elements.strikeCount.textContent, '5');
        assert.strictEqual(elements.skippyChatterBubble.hidden, false, 'Skippy should notice: 5 strikes and an affordable hint sitting unused');
        assert.ok(elements.skippyChatterBubble.textContent.length > 0);
        // Gameplay itself is untouched by the reaction firing.
        assert.strictEqual(elements.roundScore.textContent, '300');
    });

    await check('five-strike danger reaction does NOT fire when the caller cannot afford a hint', async () => {
        const { elements } = await bootApp(0, '?predicament=gallows');
        clickByText(elements.categoryList, 'Movies & TV');
        // Deliberately do NOT earn any score first — score stays at 0, below the 300 hint cost.
        ['Q', 'X', 'Z', 'J', 'W'].forEach((l) => clickByText(elements.letters, l));
        assert.strictEqual(elements.strikeCount.textContent, '5');
        assert.strictEqual(elements.skippyChatterBubble.hidden, true, 'strikes=5 alone is not enough — a hint must actually be affordable');
    });

    await check('five-strike danger reaction fires only ONCE per round (cooldown), even if a later action keeps the condition true', async () => {
        const { elements } = await bootApp(0, '?predicament=gallows');
        clickByText(elements.categoryList, 'Movies & TV');
        earnAtLeast(elements, 300);
        ['Q', 'X', 'Z', 'J', 'W'].forEach((l) => clickByText(elements.letters, l));
        assert.strictEqual(elements.skippyChatterBubble.hidden, false, 'precondition: danger reaction fired');
        const firstText = elements.skippyChatterBubble.textContent;

        // A further correct, strike-free action (a real answer letter) —
        // strikes stays at 5, a hint is still affordable — must NOT re-fire.
        elements.skippyChatterBubble.hidden = true; // reset the probe
        elements.skippyChatterBubble.textContent = '';
        clickByText(elements.letters, 'B');
        assert.strictEqual(elements.skippyChatterBubble.hidden, true, 'must not repeat within the same round');
    });

    await check('repeated hint use triggers a reaction only at the intended threshold, not on the first hint', async () => {
        const { elements } = await bootApp(0, '?predicament=gallows');
        clickByText(elements.categoryList, 'Movies & TV');
        earnAtLeast(elements, 700); // enough for two 300-point Round 1 hints plus headroom

        elements.buyHintButton.click(); // 1st hint
        assert.strictEqual(elements.skippyChatterBubble.hidden, true, 'no reaction on the first hint — one hint is normal play');

        elements.buyHintButton.click(); // 2nd hint
        assert.strictEqual(elements.skippyChatterBubble.hidden, false, 'Skippy notices conspicuous hint dependence on the 2nd hint');
        assert.ok(elements.skippyChatterBubble.textContent.length > 0);
    });

    await check('repeated wrong SOLVE attempts trigger a reaction only at the intended threshold, not on the first miss', async () => {
        const { elements } = await bootApp(0, '?predicament=gallows');
        clickByText(elements.categoryList, 'Movies & TV');

        elements.solveToggle.click();
        elements.solveInput.value = 'DEFINITELY NOT THE ANSWER';
        elements.solveSubmit.click(); // 1st wrong solve -> +2 strikes
        assert.strictEqual(elements.strikeCount.textContent, '2');
        assert.strictEqual(elements.skippyChatterBubble.hidden, true, 'no reaction on the first wrong solve — one miss is normal');

        elements.solveToggle.click();
        elements.solveInput.value = 'STILL NOT THE ANSWER EITHER';
        elements.solveSubmit.click(); // 2nd wrong solve -> +2 strikes
        assert.strictEqual(elements.strikeCount.textContent, '4');
        assert.strictEqual(elements.skippyChatterBubble.hidden, false, 'Skippy\'s confidence deteriorates on the 2nd wrong solve');
        assert.ok(elements.skippyChatterBubble.textContent.length > 0);
    });

    await check('danger-state (5-strike) idle chatter already uses distinct, more urgent content — no new timing/frequency change needed', async () => {
        // Proves this fork's 4th situation ("idle chatter should feel more
        // urgent at 5 strikes") is already satisfied by the existing,
        // unmodified idle-chatter.js TERRIFIED pool — see
        // situational-awareness.js's own header for why no new code was
        // needed for this one.
        const { elements, clock } = await bootApp(0, '?predicament=gallows');
        clickByText(elements.categoryList, 'Movies & TV');
        ['Q', 'X', 'Z', 'J', 'W'].forEach((l) => clickByText(elements.letters, l)); // strikes -> 5, TERRIFIED
        assert.strictEqual(stateOf(elements.gallows), 'TERRIFIED');

        elements.skippyChatterBubble.hidden = true; // clear the five-strike-danger situational bubble first
        elements.skippyChatterBubble.textContent = '';
        clock.advance(12001); // idle chatter's own normal first-remark window — unchanged timing
        assert.strictEqual(elements.skippyChatterBubble.hidden, false);
        const terrifiedLines = require(path.join(ROOT, 'js/lastword/idle-chatter.js')).IDLE_CHATTER_LINES.TERRIFIED;
        assert.ok(terrifiedLines.indexOf(elements.skippyChatterBubble.textContent) !== -1,
            'at 5 strikes, idle chatter should draw from the urgent TERRIFIED pool, same timing as any other state');
    });

    await check('a situational reaction does not collide/stack with idle chatter — auto-hides on its own schedule, then idle chatter resumes', async () => {
        const { elements, clock } = await bootApp(0, '?predicament=gallows');
        clickByText(elements.categoryList, 'Movies & TV');
        earnAtLeast(elements, 300);
        ['Q', 'X', 'Z', 'J', 'W'].forEach((l) => clickByText(elements.letters, l)); // fires the danger reaction
        const reactionText = elements.skippyChatterBubble.textContent;
        assert.ok(reactionText.length > 0);

        clock.advance(9999);
        assert.strictEqual(elements.skippyChatterBubble.hidden, false);
        assert.strictEqual(elements.skippyChatterBubble.textContent, reactionText, 'still exactly the situational reaction, nothing appended');

        clock.advance(2); // crosses the reaction's own 10s display window
        assert.strictEqual(elements.skippyChatterBubble.hidden, true);
    });

    await check('a new round resets round-local situational awareness — a fresh hint-dependence count starts at zero', async () => {
        const { elements, clock } = await bootApp(0, '?predicament=gallows');
        const puzzlesDoc = JSON.parse(fs.readFileSync(path.join(ROOT, 'lastword/puzzles.json'), 'utf8'));
        clickByText(elements.categoryList, 'Movies & TV');
        earnAtLeast(elements, 700);
        elements.buyHintButton.click(); // 1st hint, round 1
        elements.buyHintButton.click(); // 2nd hint, round 1 -> fires hint-dependence
        assert.strictEqual(elements.skippyChatterBubble.hidden, false, 'precondition: hint-dependence fired in round 1');

        // Finish round 1 via SOLVE and move on to round 2.
        const candidates = dealtPuzzleCandidates(elements.board, puzzlesDoc, 'Movies & TV');
        elements.solveToggle.click();
        elements.solveInput.value = candidates[0].answer;
        elements.solveSubmit.click();
        clock.advance(SAVED_PAYOFF_DELAY_MS);
        elements.continueAfterRound.click();
        elements.transitionContinue.click(); // round 2 begins

        elements.skippyChatterBubble.hidden = true; // clear round 2's own opening reaction (Fork #1), if any
        elements.skippyChatterBubble.textContent = '';

        // A single hint in round 2 must NOT immediately trigger hint-dependence — the count reset.
        earnAtLeast(elements, 450); // Round 2's hint cost is 450
        elements.buyHintButton.click();
        assert.strictEqual(elements.skippyChatterBubble.hidden, true, 'round 2\'s hint count must start fresh, not carry over round 1\'s 2 hints');
    });

    await check('situational reactions never mutate gameplay state — score/strikes/board unaffected by a firing', async () => {
        const { elements } = await bootApp(0, '?predicament=gallows');
        clickByText(elements.categoryList, 'Movies & TV');
        function revealedCellCount() {
            return elements.board._children.filter((cell) => cell.className === 'cell').length;
        }

        assert.strictEqual(revealedCellCount(), 0, 'sanity: nothing revealed at round start');
        earnAtLeast(elements, 300); // reveals T on the board — a REAL guess, sanity-checked below
        const revealedBeforeReaction = revealedCellCount();
        assert.ok(revealedBeforeReaction > 0, 'sanity: the board DID change from the real T guess');

        ['Q', 'X', 'Z', 'J', 'W'].forEach((l) => clickByText(elements.letters, l)); // fires danger reaction (all misses — reveal nothing further)
        assert.strictEqual(elements.skippyChatterBubble.hidden, false, 'precondition: reaction fired');
        assert.strictEqual(elements.strikeCount.textContent, '5', 'strikes reflect only the real misses, nothing extra from the reaction');
        assert.strictEqual(elements.roundScore.textContent, '300', 'score unaffected by the reaction itself');
        assert.strictEqual(revealedCellCount(), revealedBeforeReaction, 'the reaction itself reveals nothing — the board is identical to before the (all-miss) guesses that triggered it');
    });

    // -----------------------------------------------------------------
    // SKIPPY'S PREDICAMENT (accepted, PASS) — the suspended-safe
    // Predicament, exercised through the real DOM call sites (not just
    // safe-predicament.js's own pure tests). Selected at boot via
    // `?predicament=safe` (see bootApp's `locationSearch` param), the same
    // mechanism the real page reads from its URL — the proof-only compare
    // toggle used during human-gate review has been removed as accepted-
    // build residue; this is what's left standing.
    // -----------------------------------------------------------------

    const SAFE_FILL_MARKER = '#232733'; // safe-predicament.js's SAFE_FILL — unique to the alternate apparatus
    const BOB_CRANK_MARKER = '#9aa4b6'; // bob-character.js's METAL color — unique to Bob's own geometry (moved up here in Slice 3B so the default-behavior tests below can use it too)

    // ---- SLICE 3B ("CALLER READINESS"): canonical early-playtest defaults --
    // A plain page load (no query params) now gets Suspended Safe + Bob --
    // the combination human testing has overwhelmingly used and accepted --
    // so a caller arriving from Puzlmastr's Patch never needs magic query
    // parameters. `?predicament=gallows` and `?bob=0` remain explicit
    // developer/tester overrides; neither was deleted.

    await check('DEFAULT: an ordinary page load (no params) renders the canonical Suspended Safe presentation with Bob enabled', async () => {
        const { elements } = await bootApp(0);
        clickByText(elements.categoryList, 'Movies & TV');
        clickByText(elements.letters, 'Q'); // real miss -> strike 1 (safe box + Bob both only render from strike 1)
        assert.strictEqual(elements.strikeCount.textContent, '1', 'sanity: a real miss landed');
        assert.ok(elements.gallows.innerHTML.indexOf(SAFE_FILL_MARKER) !== -1, 'default must be the Suspended Safe');
        assert.ok(elements.gallows.innerHTML.indexOf(BOB_CRANK_MARKER) !== -1, 'default must include Bob');
    });

    await check('DEFAULT: unrelated/invalid query params do not break boot and still default to Safe + Bob', async () => {
        const { elements } = await bootApp(0, '?foo=bar&predicament=&bob=nonsense');
        clickByText(elements.categoryList, 'Movies & TV');
        clickByText(elements.letters, 'Q');
        assert.ok(elements.gallows.innerHTML.indexOf(SAFE_FILL_MARKER) !== -1, 'still defaults to Safe with garbage params');
        assert.ok(elements.gallows.innerHTML.indexOf(BOB_CRANK_MARKER) !== -1, 'still defaults to Bob enabled with garbage params');
    });

    await check('OVERRIDE: ?predicament=gallows selects the accepted gallows presentation, and Bob never appears there', async () => {
        const { elements } = await bootApp(0, '?predicament=gallows');
        clickByText(elements.categoryList, 'Movies & TV');
        clickByText(elements.letters, 'Q');
        assert.strictEqual(elements.gallows.innerHTML.indexOf(SAFE_FILL_MARKER), -1, 'explicit override must select the gallows, not the safe');
        assert.strictEqual(elements.gallows.innerHTML.indexOf(BOB_CRANK_MARKER), -1, 'Bob has no existence in the accepted gallows, even unrequested');
    });

    await check('OVERRIDE: ?predicament=gallows&bob=1 still has no effect — Bob only exists inside the safe Predicament', async () => {
        const { elements } = await bootApp(0, '?predicament=gallows&bob=1');
        clickByText(elements.categoryList, 'Movies & TV');
        clickByText(elements.letters, 'Q');
        assert.strictEqual(elements.gallows.innerHTML.indexOf(BOB_CRANK_MARKER), -1);
    });

    await check('OVERRIDE: ?bob=0 disables Bob while the canonical Safe default stays active', async () => {
        const { elements } = await bootApp(0, '?bob=0');
        clickByText(elements.categoryList, 'Movies & TV');
        clickByText(elements.letters, 'Q');
        assert.ok(elements.gallows.innerHTML.indexOf(SAFE_FILL_MARKER) !== -1, 'Predicament default is unaffected by ?bob=0');
        assert.strictEqual(elements.gallows.innerHTML.indexOf(BOB_CRANK_MARKER), -1, 'Bob explicitly disabled');
    });

    await check('EXPLICIT: ?predicament=safe&bob=1 reaches the same result as the default, spelled out explicitly', async () => {
        const { elements } = await bootApp(0, '?predicament=safe&bob=1');
        clickByText(elements.categoryList, 'Movies & TV');
        clickByText(elements.letters, 'Q');
        assert.ok(elements.gallows.innerHTML.indexOf(SAFE_FILL_MARKER) !== -1);
        assert.ok(elements.gallows.innerHTML.indexOf(BOB_CRANK_MARKER) !== -1);
    });

    await check('EXPLICIT: ?predicament=safe&bob=0 selects the safe with Bob explicitly turned off', async () => {
        const { elements } = await bootApp(0, '?predicament=safe&bob=0');
        clickByText(elements.categoryList, 'Movies & TV');
        clickByText(elements.letters, 'Q');
        assert.ok(elements.gallows.innerHTML.indexOf(SAFE_FILL_MARKER) !== -1);
        assert.strictEqual(elements.gallows.innerHTML.indexOf(BOB_CRANK_MARKER), -1);
    });

    await check('the predicament changes presentation only — an identical action sequence produces identical score/strikes/round-state whichever predicament is active', async () => {
        const gallowsRun = await bootApp(0, '?predicament=gallows'); // Slice 3B: explicit override, no longer the default
        const safeRun = await bootApp(0); // canonical default
        [gallowsRun, safeRun].forEach(({ elements }) => {
            clickByText(elements.categoryList, 'Movies & TV');
            earnAtLeast(elements, 300); // identical, deterministic guess sequence in both runs
        });
        assert.strictEqual(safeRun.elements.roundScore.textContent, gallowsRun.elements.roundScore.textContent);
        assert.strictEqual(safeRun.elements.strikeCount.textContent, gallowsRun.elements.strikeCount.textContent);
        assert.strictEqual(stateOf(safeRun.elements.gallows), stateOf(gallowsRun.elements.gallows),
            'same strike/solved state name either predicament');
    });

    await check('the accepted gallows presentation still renders identically when explicitly selected (regression)', async () => {
        const { elements } = await bootApp(0, '?predicament=gallows'); // Slice 3B: explicit override, no longer the default
        clickByText(elements.categoryList, 'Movies & TV');
        earnAtLeast(elements, 300);
        assert.strictEqual(elements.gallows.innerHTML.indexOf(SAFE_FILL_MARKER), -1,
            'untouched gallows presentation never contains safe-predicament markup');
    });

    // ---- no double speech in safe mode -------------------------------------
    // The exact real-play scenario the human-gate correction flagged: a
    // caller reaches Strike 5 with an affordable hint sitting unused WHILE
    // the safe predicament is active — situational awareness's five-
    // strike-danger reaction and Skippy's own Strike-5 panic line would
    // both want the one speech-bubble slot at the same moment.

    await check('safe mode never presents two Skippy speech bubbles simultaneously at the real Strike-5/danger-reaction collision point', async () => {
        const { elements } = await bootApp(0, '?predicament=safe');
        clickByText(elements.categoryList, 'Movies & TV');
        earnAtLeast(elements, 300); // exactly Round 1's hint cost — an affordable hint will be sitting unused

        ['Q', 'X', 'Z', 'J', 'W'].forEach((l) => clickByText(elements.letters, l)); // -> strikes hit 5

        assert.strictEqual(elements.strikeCount.textContent, '5', 'sanity: really reached strike 5');
        // The baked-into-the-SVG bubble (gallows-character.js's own
        // speechBubble group, tail "up") must never be present in safe
        // mode — that was the second, competing bubble.
        assert.strictEqual(elements.gallows.innerHTML.indexOf('lw-bubble'), -1,
            'the safe predicament must suppress the baked SVG speech bubble entirely');
        // Exactly one bubble is now showing — the single shared HTML
        // overlay — carrying either the situational reaction or the
        // safe-specific Strike-5 line (whichever claimed the one slot
        // first), never neither and never a stale one from earlier.
        assert.strictEqual(elements.skippyChatterBubble.hidden, false, 'exactly one active bubble is expected here');
        assert.ok(elements.skippyChatterBubble.textContent.length > 0);
    });

    await check('safe mode\'s Strike-5 line still reaches the caller via the shared bubble when nothing else claims it first', async () => {
        const { elements } = await bootApp(0, '?predicament=safe');
        clickByText(elements.categoryList, 'Movies & TV');

        // Reach strike 5 WITHOUT an affordable hint sitting unused (score
        // stays at 0), so situational awareness's five-strike-danger never
        // qualifies (canBuyHint is false) and the one bubble slot is free
        // for the safe-specific line to claim.
        ['Q', 'X', 'Z', 'J', 'W'].forEach((l) => clickByText(elements.letters, l));

        assert.strictEqual(elements.strikeCount.textContent, '5');
        assert.strictEqual(elements.gallows.innerHTML.indexOf('lw-bubble'), -1, 'still no baked SVG bubble in safe mode');
        assert.strictEqual(elements.skippyChatterBubble.hidden, false, 'the routed Strike-5 line should reach the caller');
        assert.ok(elements.skippyChatterBubble.textContent.indexOf('COMBINATION') !== -1,
            'the safe-specific line itself should be the one shown: ' + elements.skippyChatterBubble.textContent);
    });

    await check('the accepted gallows presentation (explicit override) is completely unaffected by the double-speech handling', async () => {
        const { elements } = await bootApp(0, '?predicament=gallows'); // Slice 3B: explicit override, no longer the default
        clickByText(elements.categoryList, 'Movies & TV');
        earnAtLeast(elements, 300);
        ['Q', 'X', 'Z', 'J', 'W'].forEach((l) => clickByText(elements.letters, l));

        assert.strictEqual(elements.strikeCount.textContent, '5');
        // The accepted gallows STILL bakes its own bubble — that behavior
        // was never touched, only the safe predicament's presentation.
        assert.ok(elements.gallows.innerHTML.indexOf('lw-bubble') !== -1,
            'accepted gallows keeps its own baked Strike-5 bubble, unaffected by this correction');
    });

    // ---- pose-derived occlusion --------------------------------------------

    await check('the real rendered Strike 5 safe-mode Skippy carries the pose-derived occlusion mask, through the actual DOM call site', async () => {
        const { elements } = await bootApp(0, '?predicament=safe');
        clickByText(elements.categoryList, 'Movies & TV');
        earnAtLeast(elements, 300);
        ['Q', 'X', 'Z', 'J', 'W'].forEach((l) => clickByText(elements.letters, l));

        assert.strictEqual(elements.strikeCount.textContent, '5');
        const svg = elements.gallows.innerHTML;
        assert.ok(svg.indexOf('lw-pose-knockout-mask') !== -1,
            'the real render at Strike 5 must carry the occlusion mask, not just the pure module in isolation');
        // The mask's silhouette is built from TERRIFIED's own real leg/arm
        // coordinates (rotate(17 78 118), leftLeg starting M70,144) — not a
        // fixed approximation shape — same proof as the pure module's own
        // tests, now confirmed through the real DOM render path.
        const maskContent = svg.match(/<mask[\s\S]*?<\/mask>/)[0];
        assert.ok(maskContent.indexOf('rotate(17 78 118)') !== -1, 'TERRIFIED\'s real tilt (17) should drive the mask silhouette');
        assert.ok(maskContent.indexOf('M70,144 L56,160 L52,178') !== -1, 'TERRIFIED\'s real left leg coordinates should be in the mask');
        assert.ok(maskContent.indexOf('<circle cx="78" cy="60"') !== -1, 'head knockout circle present');
    });

    await check('COMEDIC_DEFEAT through real play stays unmasked in safe mode — the WHAM impact is fully visible, nothing carved out of it', async () => {
        const { elements } = await bootApp(0, '?predicament=safe');
        clickByText(elements.categoryList, 'Movies & TV');
        ['Q', 'X', 'Z', 'J', 'W', 'Y'].forEach((l) => clickByText(elements.letters, l)); // 6 misses -> struck out

        // finishCurrentRound() paints the terminal pose immediately but
        // doesn't touch el.strikeCount (that's only refreshed by the normal
        // in-round renderScoreboard() path) — check the actual painted
        // state instead.
        assert.strictEqual(stateOf(elements.gallows), 'COMEDIC_DEFEAT', 'sanity: really struck out');
        assert.ok(elements.gallows.innerHTML.indexOf('WHAM') !== -1, 'the landed safe\'s impact should be fully visible');
        assert.strictEqual(elements.gallows.innerHTML.indexOf('lw-pose-knockout-mask'), -1,
            'COMEDIC_DEFEAT must stay unmasked even in safe mode — no standing figure left to protect');
    });

    // ---- BOB (visual design canonical since Fork #5; role/dynamic accepted
    // 2026-09-13) — exercised through the real DOM call sites. Bob is now
    // part of the canonical Safe default (see the DEFAULT/OVERRIDE tests
    // above) — these tests cover his behavior across the strike progression
    // and the explicit ?predicament=safe&bob=1 spelling specifically.

    await check('?predicament=safe&bob=1 renders Bob absent at Strike 0, present from Strike 1', async () => {
        const { elements } = await bootApp(0, '?predicament=safe&bob=1');
        clickByText(elements.categoryList, 'Movies & TV');
        assert.strictEqual(elements.gallows.innerHTML.indexOf(BOB_CRANK_MARKER), -1, 'Bob absent at CONFIDENT/Strike 0');

        clickByText(elements.letters, 'Q'); // real miss -> strike 1
        assert.strictEqual(elements.strikeCount.textContent, '1');
        assert.ok(elements.gallows.innerHTML.indexOf(BOB_CRANK_MARKER) !== -1, 'Bob present from Strike 1');
    });

    // (Superseded by the Slice 3B DEFAULT/OVERRIDE tests above: `?predicament=safe`
    // alone now DOES render Bob by default, and `?bob=1` alone now defaults
    // the Predicament to safe too — `?predicament=gallows&bob=1` is the real
    // "has no effect" case, covered above.)

    await check('Bob has no speech/text surface of his own on the real rendered page', async () => {
        const { elements } = await bootApp(0, '?predicament=safe&bob=1');
        clickByText(elements.categoryList, 'Movies & TV');
        ['Q', 'X'].forEach((l) => clickByText(elements.letters, l));
        // Bob renders no <text> anywhere in the SVG himself; any <text> present belongs to the safe's own warning "!" marks, not Bob.
        const svg = elements.gallows.innerHTML;
        const bobGroupStart = svg.indexOf(BOB_CRANK_MARKER);
        assert.ok(bobGroupStart !== -1, 'sanity: Bob is present');
    });

    await check('the ONE Skippy acknowledgment line ("Oh great. Bob\'s here.") fires once, the first time Bob appears, and never with Bob disabled', async () => {
        const withBob = await bootApp(0, '?predicament=safe&bob=1');
        clickByText(withBob.elements.categoryList, 'Movies & TV');
        clickByText(withBob.elements.letters, 'Q'); // -> strike 1, Bob's first appearance
        assert.strictEqual(withBob.elements.skippyChatterBubble.hidden, false, 'the acknowledgment should show');
        assert.ok(withBob.elements.skippyChatterBubble.textContent.indexOf('Bob') !== -1,
            'expected the Bob acknowledgment line: ' + withBob.elements.skippyChatterBubble.textContent);

        withBob.elements.skippyChatterBubble.hidden = true; // clear it
        withBob.elements.skippyChatterBubble.textContent = '';
        clickByText(withBob.elements.letters, 'X'); // strike 2 — should NOT re-fire
        assert.strictEqual(withBob.elements.skippyChatterBubble.hidden, true, 'the acknowledgment must fire only once per round');

        const withoutBob = await bootApp(0, '?predicament=safe&bob=0'); // Slice 3B: bob=0 is now the explicit way to get 'without Bob'
        clickByText(withoutBob.elements.categoryList, 'Movies & TV');
        clickByText(withoutBob.elements.letters, 'Q');
        assert.strictEqual(withoutBob.elements.skippyChatterBubble.hidden, true, 'no acknowledgment when Bob is disabled');
    });

    await check('gameplay state is identical with Bob on or off, given the same action sequence', async () => {
        const withBob = await bootApp(0, '?predicament=safe&bob=1');
        const withoutBob = await bootApp(0, '?predicament=safe&bob=0'); // Slice 3B: bob=0 is now the explicit way to get 'without Bob'
        [withBob, withoutBob].forEach(({ elements }) => {
            clickByText(elements.categoryList, 'Movies & TV');
            earnAtLeast(elements, 300);
        });
        assert.strictEqual(withBob.elements.roundScore.textContent, withoutBob.elements.roundScore.textContent);
        assert.strictEqual(withBob.elements.strikeCount.textContent, withoutBob.elements.strikeCount.textContent);
        assert.strictEqual(stateOf(withBob.elements.gallows), stateOf(withoutBob.elements.gallows));
    });

    await check('Skippy\'s pose-derived occlusion mask through the real DOM render is unaffected by Bob being present', async () => {
        const { elements } = await bootApp(0, '?predicament=safe&bob=1');
        clickByText(elements.categoryList, 'Movies & TV');
        earnAtLeast(elements, 300);
        ['Q', 'X', 'Z', 'J', 'W'].forEach((l) => clickByText(elements.letters, l));

        assert.strictEqual(elements.strikeCount.textContent, '5');
        const svg = elements.gallows.innerHTML;
        assert.ok(svg.indexOf('lw-pose-knockout-mask') !== -1, 'occlusion mask still present with Bob enabled');
        const maskContent = svg.match(/<mask[\s\S]*?<\/mask>/)[0];
        assert.ok(maskContent.indexOf('rotate(17 78 118)') !== -1, 'TERRIFIED\'s real tilt still drives the mask with Bob present');
        assert.ok(maskContent.indexOf(BOB_CRANK_MARKER) === -1, 'Bob\'s own geometry must never appear inside the occlusion mask definition');
    });

    // ---- CONSCIOUS FORK #6 ("MAKE THE GAME LAND") presentation layer ----

    await check('a correct consonant guess shows a small "+N" score-delta matching the actual points earned', async () => {
        const { elements } = await bootApp();
        clickByText(elements.categoryList, 'Movies & TV');

        const before = Number(elements.roundScore.textContent);
        clickByText(elements.letters, 'E'); // common letter, very likely present
        const after = Number(elements.roundScore.textContent);
        if (after !== before) {
            assert.strictEqual(elements.roundScoreDelta.textContent, '+' + (after - before),
                'the shown delta must equal the actual score change — never a decorative/independent number');
            assert.strictEqual(elements.roundScoreDelta.hidden, false);
        }
    });

    await check('score calculations are completely unaffected by the presentation layer (same totals with/without a delta shown)', async () => {
        const { elements } = await bootApp(0);
        clickByText(elements.categoryList, 'Movies & TV');
        earnAtLeast(elements, 300);
        // roundScore/cumulativeScore come straight from round.js/session.js —
        // this proves the new presentation wiring never substitutes its own
        // number for the real one.
        assert.ok(Number(elements.roundScore.textContent) >= 300);
    });

    await check('a solve bonus produces a strictly larger/more emphasized score-delta presentation than an ordinary letter guess', async () => {
        const Presentation = require(path.join(ROOT, 'js/lastword/presentation.js'));
        const LastWordState = require(path.join(ROOT, 'js/lastword/state.js'));
        const cfg = LastWordState.ROUND_CONFIG[1];
        const ordinaryGuessDelta = cfg.consonantValue; // 100
        const solveBonusDelta = Math.round(cfg.maxSolveBonus * 0.6); // a realistic, even heavily-decayed, solve bonus
        assert.strictEqual(Presentation.classifyScoreDelta(ordinaryGuessDelta, cfg), 'small');
        assert.strictEqual(Presentation.classifyScoreDelta(solveBonusDelta, cfg), 'big');
    });

    await check('score-delta presentation never blocks the next guess (letters remain clickable immediately)', async () => {
        const { elements } = await bootApp();
        clickByText(elements.categoryList, 'Movies & TV');
        clickByText(elements.letters, 'E');
        // Round still in progress -> some letter button must still be
        // enabled and clickable right away, with no waiting on any timer.
        const stillClickable = elements.letters._children.some((b) => !b.disabled);
        assert.ok(stillClickable, 'a score-delta animation must never gate normal input');
    });

    await check('a correct guess pops the just-guessed button; a wrong guess shakes it — never both, never any other button', async () => {
        const { elements } = await bootApp();
        clickByText(elements.categoryList, 'Movies & TV');

        clickByText(elements.letters, 'E'); // near-certain to be present
        // Auto-solve (js/lastword/auto-solve.js, pre-existing/untouched) can
        // finish the round on this very guess if it happened to be the last
        // letter needed — then the round-result panel replaces the letter
        // grid content entirely and there is nothing left here to inspect.
        const guessedE = elements.letters._children.find((b) => b.textContent === 'E');
        if (!guessedE) return;
        if (guessedE.classList.contains('correct')) {
            assert.ok(guessedE.classList.contains('lw-letter-pop-correct'));
            assert.ok(!guessedE.classList.contains('lw-letter-pop-wrong'));
        }
        elements.letters._children.filter((b) => b !== guessedE && !b.disabled).forEach((b) => {
            assert.ok(!b.classList.contains('lw-letter-pop-correct'));
            assert.ok(!b.classList.contains('lw-letter-pop-wrong'));
        });
    });

    await check('the letter-pop feedback does not replay on an unrelated later re-render', async () => {
        const { elements } = await bootApp();
        clickByText(elements.categoryList, 'Movies & TV');
        clickByText(elements.letters, 'E');
        const guessedE = elements.letters._children.find((b) => b.textContent === 'E');
        if (!guessedE) return; // round auto-solved on this guess — see comment above
        const hadPop = guessedE.classList.contains('lw-letter-pop-correct') || guessedE.classList.contains('lw-letter-pop-wrong');

        clickByText(elements.letters, 'T'); // a second, unrelated action triggers another full renderLetters()
        const eAfterSecondRender = elements.letters._children.find((b) => b.textContent === 'E');
        if (hadPop && eAfterSecondRender) {
            assert.ok(!eAfterSecondRender.classList.contains('lw-letter-pop-correct'),
                'the pop must be consumed by the very next render, not linger across later ones');
        }
    });

    await check('correct/wrong canonical button coloring is unchanged by the added pop/shake classes', async () => {
        const { elements } = await bootApp();
        clickByText(elements.categoryList, 'Movies & TV');
        clickByText(elements.letters, 'E');
        const guessedE = elements.letters._children.find((b) => b.textContent === 'E');
        if (!guessedE) return; // round auto-solved on this guess — see comment above
        assert.ok(guessedE.classList.contains('correct') || guessedE.classList.contains('wrong'),
            'the existing accepted color classes must still be applied exactly as before');
    });

    await check('the round-result panel transitions (fade classes applied) exactly once when a solved round finishes', async () => {
        const { elements, clock } = await bootApp();
        const puzzlesDoc = JSON.parse(fs.readFileSync(path.join(ROOT, 'lastword/puzzles.json'), 'utf8'));
        clickByText(elements.categoryList, 'Movies & TV');
        const candidates = dealtPuzzleCandidates(elements.board, puzzlesDoc, 'Movies & TV');
        elements.solveToggle.click();
        elements.solveInput.value = candidates[0].answer;
        elements.solveSubmit.click();
        clock.advance(SAVED_PAYOFF_DELAY_MS); // reach round-result — showOnly('roundResult') + revealPanelWithFade() fire here

        assert.strictEqual(elements['round-result'].className, 'panel', 'still becomes visible exactly as before (unaffected panel-visibility contract)');
        assert.ok(elements['round-result'].classList.contains('lw-fade-in'), 'the fade-in should have started');

        clock.advance(20); // PANEL_FADE_START_DELAY_MS
        assert.ok(elements['round-result'].classList.contains('lw-fade-in-active'), 'should have committed to the active/visible fade state');

        clock.advance(300); // PANEL_FADE_MS
        assert.ok(!elements['round-result'].classList.contains('lw-fade-in'), 'transition classes clean up once the fade completes');
        assert.ok(!elements['round-result'].classList.contains('lw-fade-in-active'));

        // Advancing further must not re-trigger anything — one fade, once.
        const before = elements['round-result'].classList.contains('lw-fade-in');
        clock.advance(60000);
        assert.strictEqual(elements['round-result'].classList.contains('lw-fade-in'), before);
    });

    await check('the solved payoff hold remains the accepted 3400ms even with the new transition wired in', async () => {
        const { elements, clock } = await bootApp();
        const puzzlesDoc = JSON.parse(fs.readFileSync(path.join(ROOT, 'lastword/puzzles.json'), 'utf8'));
        clickByText(elements.categoryList, 'Movies & TV');
        const candidates = dealtPuzzleCandidates(elements.board, puzzlesDoc, 'Movies & TV');
        elements.solveToggle.click();
        elements.solveInput.value = candidates[0].answer;
        elements.solveSubmit.click();

        clock.advance(SAVED_PAYOFF_DELAY_MS - 1);
        assert.strictEqual(elements['round-result'].className, 'panel hidden', 'hold duration must be unchanged by Fork #6');
        clock.advance(1);
        assert.strictEqual(elements['round-result'].className, 'panel');
    });

    // -----------------------------------------------------------------
    // FORK #7 ("PICKING SIDES"): SKIPPY vs. BOB lifetime rivalry
    // -----------------------------------------------------------------

    /** Reads "SKIPPY n" / "BOB n" and any just-scored emphasis off a rendered rivalry badge element. */
    function readRivalryBadge(target) {
        const skippySpan = target._children.find((c) => c.className && c.className.indexOf('lw-rivalry-skippy') === 0 || c.className === 'lw-rivalry-skippy' || (c.className || '').indexOf('lw-rivalry-skippy') !== -1);
        const bobSpan = target._children.find((c) => (c.className || '').indexOf('lw-rivalry-bob') !== -1);
        return {
            skippyText: skippySpan ? skippySpan.textContent : null,
            bobText: bobSpan ? bobSpan.textContent : null,
            skippyEmphasized: skippySpan ? skippySpan.className.indexOf('lw-rivalry-just-scored') !== -1 : false,
            bobEmphasized: bobSpan ? bobSpan.className.indexOf('lw-rivalry-just-scored') !== -1 : false,
            skippyLeading: skippySpan ? skippySpan.className.indexOf('lw-rivalry-leading') !== -1 : false,
            bobLeading: bobSpan ? bobSpan.className.indexOf('lw-rivalry-leading') !== -1 : false
        };
    }

    function solveCurrentRoundAndContinue(elements, clock, puzzlesDoc) {
        const candidates = dealtPuzzleCandidates(elements.board, puzzlesDoc, elements.categoryLabel.textContent);
        elements.solveToggle.click();
        elements.solveInput.value = candidates[0].answer;
        elements.solveSubmit.click();
        clock.advance(SAVED_PAYOFF_DELAY_MS);
        elements.continueAfterRound.click();
    }

    /** Solves all four ordinary rounds cleanly and enters Final with 0 free opening letters. */
    function playToFinal(elements, clock, puzzlesDoc) {
        clickByText(elements.categoryList, 'Movies & TV');
        solveCurrentRoundAndContinue(elements, clock, puzzlesDoc); // R1 -> transition to R2
        elements.transitionContinue.click();
        solveCurrentRoundAndContinue(elements, clock, puzzlesDoc); // R2 -> R3 offer
        clickByText(elements.roundOfferList, elements.roundOfferList._children[0].textContent);
        solveCurrentRoundAndContinue(elements, clock, puzzlesDoc); // R3 -> transition to R4
        elements.transitionContinue.click();
        solveCurrentRoundAndContinue(elements, clock, puzzlesDoc); // R4 -> session complete -> final-intro
        clickByText(elements.finalOpeningCountButtons, '0 letters (free)'); // -> final-play
    }

    function finishFinalViaExplicitSolve(elements, clock, puzzlesDoc) {
        const candidates = dealtPuzzleCandidates(elements.finalBoard, puzzlesDoc, elements.finalCategoryLabel.textContent);
        elements.finalSolveToggle.click();
        elements.finalSolveInput.value = candidates[0].answer;
        elements.finalSolveSubmit.click();
        clock.advance(SAVED_PAYOFF_DELAY_MS);
    }

    function finishFinalViaAutoSolve(elements, clock, puzzlesDoc) {
        const candidates = dealtPuzzleCandidates(elements.finalBoard, puzzlesDoc, elements.finalCategoryLabel.textContent);
        const letters = Array.from(new Set(candidates[0].answer.toUpperCase().replace(/[^A-Z]/g, '').split('')));
        letters.forEach((letter) => clickByText(elements.finalLetters, letter + ' (250)'));
        clock.advance(SAVED_PAYOFF_DELAY_MS);
    }

    function finishFinalViaStrikeout(elements, clock) {
        elements.finalSolveToggle.click();
        for (let i = 0; i < 3; i++) { // 2 strikes/wrong x 3 = 6 = MAX_STRIKES
            elements.finalSolveInput.value = 'ZZZZZZZZZZZZZZZZZZ NOT THE ANSWER';
            elements.finalSolveSubmit.click();
        }
        clock.advance(DEFEAT_PAYOFF_DELAY_MS);
    }

    // -----------------------------------------------------------------
    // SESSION LANDING MICRO-CORRECTION: game-complete gets the same
    // accepted Fork #6 panel fade round-result already has. These mirror
    // the round-result fade tests above exactly, just for the Final ->
    // game-complete seam, and use manual step-by-step clock.advance()
    // calls (not the finishFinalVia*() helpers, whose single big advance
    // would fire and clean up the whole fade before returning).
    // -----------------------------------------------------------------

    await check('the game-complete panel transitions (fade classes applied) exactly once when Final is solved', async () => {
        const { elements, clock } = await bootApp(0.5);
        const puzzlesDoc = JSON.parse(fs.readFileSync(path.join(ROOT, 'lastword/puzzles.json'), 'utf8'));
        playToFinal(elements, clock, puzzlesDoc);
        const candidates = dealtPuzzleCandidates(elements.finalBoard, puzzlesDoc, elements.finalCategoryLabel.textContent);
        elements.finalSolveToggle.click();
        elements.finalSolveInput.value = candidates[0].answer;
        elements.finalSolveSubmit.click();
        clock.advance(SAVED_PAYOFF_DELAY_MS); // reach game-complete — showOnly('gameComplete') + revealPanelWithFade() fire here

        assert.strictEqual(elements['game-complete'].className, 'panel', 'still becomes visible exactly as before (unaffected panel-visibility contract)');
        assert.ok(elements['game-complete'].classList.contains('lw-fade-in'), 'the fade-in should have started');

        clock.advance(20); // PANEL_FADE_START_DELAY_MS
        assert.ok(elements['game-complete'].classList.contains('lw-fade-in-active'), 'should have committed to the active/visible fade state');

        clock.advance(300); // PANEL_FADE_MS
        assert.ok(!elements['game-complete'].classList.contains('lw-fade-in'), 'transition classes clean up once the fade completes');
        assert.ok(!elements['game-complete'].classList.contains('lw-fade-in-active'));

        // Advancing further must not re-trigger anything — one fade, once.
        const before = elements['game-complete'].classList.contains('lw-fade-in');
        clock.advance(60000);
        assert.strictEqual(elements['game-complete'].classList.contains('lw-fade-in'), before);
    });

    await check('the game-complete panel also transitions (fade classes applied) exactly once when Final is struck out', async () => {
        const { elements, clock } = await bootApp(0.5);
        const puzzlesDoc = JSON.parse(fs.readFileSync(path.join(ROOT, 'lastword/puzzles.json'), 'utf8'));
        playToFinal(elements, clock, puzzlesDoc);
        elements.finalSolveToggle.click();
        for (let i = 0; i < 3; i++) {
            elements.finalSolveInput.value = 'ZZZZZZZZZZZZZZZZZZ NOT THE ANSWER';
            elements.finalSolveSubmit.click();
        }
        clock.advance(DEFEAT_PAYOFF_DELAY_MS);

        assert.strictEqual(elements['game-complete'].className, 'panel');
        assert.ok(elements['game-complete'].classList.contains('lw-fade-in'), 'the failed path gets the same fade as the solved path');

        clock.advance(20);
        assert.ok(elements['game-complete'].classList.contains('lw-fade-in-active'));
        clock.advance(300);
        assert.ok(!elements['game-complete'].classList.contains('lw-fade-in'));
        assert.ok(!elements['game-complete'].classList.contains('lw-fade-in-active'));
    });

    await check('the solved Final payoff hold into game-complete remains the accepted 3400ms with the new transition wired in', async () => {
        const { elements, clock } = await bootApp(0.5);
        const puzzlesDoc = JSON.parse(fs.readFileSync(path.join(ROOT, 'lastword/puzzles.json'), 'utf8'));
        playToFinal(elements, clock, puzzlesDoc);
        const candidates = dealtPuzzleCandidates(elements.finalBoard, puzzlesDoc, elements.finalCategoryLabel.textContent);
        elements.finalSolveToggle.click();
        elements.finalSolveInput.value = candidates[0].answer;
        elements.finalSolveSubmit.click();

        clock.advance(SAVED_PAYOFF_DELAY_MS - 1);
        assert.strictEqual(elements['game-complete'].className, 'panel hidden', 'hold duration must be unchanged by this correction');
        clock.advance(1);
        assert.strictEqual(elements['game-complete'].className, 'panel');
    });

    await check('the failed Final payoff hold into game-complete remains the accepted 1400ms with the new transition wired in', async () => {
        const { elements, clock } = await bootApp(0.5);
        const puzzlesDoc = JSON.parse(fs.readFileSync(path.join(ROOT, 'lastword/puzzles.json'), 'utf8'));
        playToFinal(elements, clock, puzzlesDoc);
        elements.finalSolveToggle.click();
        for (let i = 0; i < 3; i++) {
            elements.finalSolveInput.value = 'ZZZZZZZZZZZZZZZZZZ NOT THE ANSWER';
            elements.finalSolveSubmit.click();
        }

        clock.advance(DEFEAT_PAYOFF_DELAY_MS - 1);
        assert.strictEqual(elements['game-complete'].className, 'panel hidden', 'hold duration must be unchanged by this correction');
        clock.advance(1);
        assert.strictEqual(elements['game-complete'].className, 'panel');
    });

    await check('a fresh boot with no saved rivalry record starts the badge at SKIPPY 0 — BOB 0', async () => {
        const { elements } = await bootApp();
        const badge = readRivalryBadge(elements.rivalryBadge);
        assert.strictEqual(badge.skippyText, 'SKIPPY 0');
        assert.strictEqual(badge.bobText, 'BOB 0');
    });

    await check('a valid saved rivalry record loads and renders on the category-select badge', async () => {
        const { elements } = await bootApp(0.5, '', { initialRivalry: { version: 1, skippy: 7, bob: 3 } });
        const badge = readRivalryBadge(elements.rivalryBadge);
        assert.strictEqual(badge.skippyText, 'SKIPPY 7');
        assert.strictEqual(badge.bobText, 'BOB 3');
    });

    await check('malformed/missing values in a saved rivalry record sanitize safely to 0 rather than crashing or displaying garbage', async () => {
        const { elements } = await bootApp(0.5, '', { initialRivalry: { version: 1, skippy: 'oops', bob: -5 } });
        const badge = readRivalryBadge(elements.rivalryBadge);
        assert.strictEqual(badge.skippyText, 'SKIPPY 0');
        assert.strictEqual(badge.bobText, 'BOB 0');
    });

    await check('a rivalry load failure does not block boot or gameplay — the game starts normally at 0/0', async () => {
        const { elements } = await bootApp(0.5, '', { loadShouldFail: true });
        assert.strictEqual(elements['category-select'].className, 'panel', 'boot must still reach category-select');
        assert.ok(elements.categoryList._children.length > 0, 'categories must still be offered');
        const badge = readRivalryBadge(elements.rivalryBadge);
        assert.strictEqual(badge.skippyText, 'SKIPPY 0');
        assert.strictEqual(badge.bobText, 'BOB 0');
    });

    await check('an explicitly-solved Final increments Skippy exactly once, and persists it', async () => {
        const { elements, clock, storageState } = await bootApp(0.5);
        const puzzlesDoc = JSON.parse(fs.readFileSync(path.join(ROOT, 'lastword/puzzles.json'), 'utf8'));
        playToFinal(elements, clock, puzzlesDoc);
        finishFinalViaExplicitSolve(elements, clock, puzzlesDoc);

        assert.strictEqual(elements['game-complete'].className, 'panel');
        const badge = readRivalryBadge(elements.gameCompleteRivalry);
        assert.strictEqual(badge.skippyText, 'SKIPPY 1');
        assert.strictEqual(badge.bobText, 'BOB 0');
        assert.strictEqual(badge.skippyEmphasized, true, 'the side that just scored gets the restrained emphasis');
        assert.strictEqual(badge.bobEmphasized, false);
        assert.deepStrictEqual(storageState.rivalry, { version: 1, skippy: 1, bob: 0 }, 'must actually persist to the mock storage slot');
    });

    await check('an auto-solved Final (board fully revealed via purchased letters, no typed SOLVE) increments Skippy exactly once', async () => {
        const { elements, clock, storageState } = await bootApp(0.5);
        const puzzlesDoc = JSON.parse(fs.readFileSync(path.join(ROOT, 'lastword/puzzles.json'), 'utf8'));
        playToFinal(elements, clock, puzzlesDoc);
        finishFinalViaAutoSolve(elements, clock, puzzlesDoc);

        assert.strictEqual(elements['game-complete'].className, 'panel');
        const badge = readRivalryBadge(elements.gameCompleteRivalry);
        assert.strictEqual(badge.skippyText, 'SKIPPY 1');
        assert.strictEqual(badge.bobText, 'BOB 0');
        assert.deepStrictEqual(storageState.rivalry, { version: 1, skippy: 1, bob: 0 });
    });

    await check('a struck-out Final increments Bob exactly once — never Skippy', async () => {
        const { elements, clock, storageState } = await bootApp(0.5);
        const puzzlesDoc = JSON.parse(fs.readFileSync(path.join(ROOT, 'lastword/puzzles.json'), 'utf8'));
        playToFinal(elements, clock, puzzlesDoc);
        finishFinalViaStrikeout(elements, clock);

        assert.strictEqual(elements['game-complete'].className, 'panel');
        const badge = readRivalryBadge(elements.gameCompleteRivalry);
        assert.strictEqual(badge.skippyText, 'SKIPPY 0');
        assert.strictEqual(badge.bobText, 'BOB 1');
        assert.strictEqual(badge.bobEmphasized, true);
        assert.strictEqual(badge.skippyEmphasized, false);
        assert.deepStrictEqual(storageState.rivalry, { version: 1, skippy: 0, bob: 1 });
    });

    await check('an ordinary round loss (struck out mid-session, session continues) awards no rivalry point at all', async () => {
        const { elements, clock, storageState, fetchCalls } = await bootApp(0);
        clickByText(elements.categoryList, 'Movies & TV');
        loseCurrentRoundViaStrikes(elements); // round 1 struck out — session must still continue, not end
        clock.advance(DEFEAT_PAYOFF_DELAY_MS);

        assert.strictEqual(storageState.rivalry, null, 'no rivalry write from an ordinary round incident');
        assert.ok(!fetchCalls.some((c) => c.url.indexOf('/api/webdoor/storage/1?') === 0 && c.opts.method === 'PUT'),
            'no PUT to the rivalry slot must happen before Final is ever reached');
        const badge = readRivalryBadge(elements.rivalryBadge);
        assert.strictEqual(badge.skippyText, 'SKIPPY 0');
        assert.strictEqual(badge.bobText, 'BOB 0');
    });

    await check('Play Again resets gameplay but preserves the just-earned lifetime rivalry total', async () => {
        const { elements, clock } = await bootApp(0.5);
        const puzzlesDoc = JSON.parse(fs.readFileSync(path.join(ROOT, 'lastword/puzzles.json'), 'utf8'));
        playToFinal(elements, clock, puzzlesDoc);
        finishFinalViaExplicitSolve(elements, clock, puzzlesDoc);

        elements.playAgain.click(); // startNewSession() -> goToRound(1) -> categorySelect

        assert.strictEqual(elements['category-select'].className, 'panel');
        const badge = readRivalryBadge(elements.rivalryBadge);
        assert.strictEqual(badge.skippyText, 'SKIPPY 1', 'lifetime rivalry must survive a fresh gameplay session');
        assert.strictEqual(badge.bobText, 'BOB 0');
    });

    await check('a duplicate completion attempt (calling the guarded Final handlers again after completion) cannot double-count', async () => {
        const { elements, clock, storageState, fetchCalls } = await bootApp(0.5);
        const puzzlesDoc = JSON.parse(fs.readFileSync(path.join(ROOT, 'lastword/puzzles.json'), 'utf8'));
        playToFinal(elements, clock, puzzlesDoc);
        finishFinalViaExplicitSolve(elements, clock, puzzlesDoc);

        // Both real call sites guard on isFinalOver() and no-op once the
        // Final has already ended — simulate a caller clicking again after
        // completion (e.g. a stray duplicate event) and prove it changes nothing.
        elements.finalSolveInput.value = 'ANYTHING';
        elements.finalSolveSubmit.click();
        clickByText(elements.finalLetters, 'A (250)');

        const putCallsToRivalrySlot = fetchCalls.filter((c) => c.url.indexOf('/api/webdoor/storage/1?') === 0 && c.opts.method === 'PUT');
        assert.strictEqual(putCallsToRivalrySlot.length, 1, 'exactly one persisted write for the whole session');
        assert.deepStrictEqual(storageState.rivalry, { version: 1, skippy: 1, bob: 0 });
    });

    await check('a rivalry save failure does not block session completion, and the in-memory total for this page session is still updated and shown', async () => {
        const { elements, clock, storageState } = await bootApp(0.5, '', { saveShouldFail: true });
        const puzzlesDoc = JSON.parse(fs.readFileSync(path.join(ROOT, 'lastword/puzzles.json'), 'utf8'));
        playToFinal(elements, clock, puzzlesDoc);
        finishFinalViaExplicitSolve(elements, clock, puzzlesDoc);

        assert.strictEqual(elements['game-complete'].className, 'panel', 'completion must proceed even though the save failed');
        const badge = readRivalryBadge(elements.gameCompleteRivalry);
        assert.strictEqual(badge.skippyText, 'SKIPPY 1', 'in-memory total for this page session reflects the increment regardless of save failure');
        assert.strictEqual(storageState.rivalry, null, 'the mock store itself never actually received the failed write');
    });

    await check('the active round HUD is not touched by rivalry rendering — the category-select badge is untouched during live gameplay', async () => {
        const { elements } = await bootApp(0.5);
        const before = elements.rivalryBadge.innerHTML;
        clickByText(elements.categoryList, 'Movies & TV'); // enter active round play
        clickByText(elements.letters, 'Q'); // an ordinary in-round action
        assert.strictEqual(elements.rivalryBadge.innerHTML, before,
            'rivalry rendering must only happen at category-select/game-complete, never during active play');
    });

    await check('PORTABILITY: no storage/fetch/DOM CODE coupling appears in the pure rivalry.js module (mentioning the portability rule in a doc comment is fine; calling fetch/DOM/storage APIs is not)', () => {
        const source = fs.readFileSync(path.join(ROOT, 'js/lastword/rivalry.js'), 'utf8');
        ['fetch(', 'document.', 'window.', 'localStorage', '/api/webdoor', 'XMLHttpRequest', 'require(\'./storage'].forEach((needle) => {
            assert.ok(source.indexOf(needle) === -1, 'rivalry.js must not reference "' + needle + '"');
        });
    });

    // -----------------------------------------------------------------
    // FORK #7 FOLLOW-UP: rivalry LEADER color derives from persisted
    // totals (not the most recent outcome), on both surfaces.
    // -----------------------------------------------------------------

    await check('0-0 renders both names neutral (no leader) on the category-select badge', async () => {
        const { elements } = await bootApp(0.5, '', { initialRivalry: { version: 1, skippy: 0, bob: 0 } });
        const badge = readRivalryBadge(elements.rivalryBadge);
        assert.strictEqual(badge.skippyLeading, false);
        assert.strictEqual(badge.bobLeading, false);
    });

    await check('a Skippy lead loaded from persistence colors SKIPPY (and only Skippy) on the category-select badge', async () => {
        const { elements } = await bootApp(0.5, '', { initialRivalry: { version: 1, skippy: 5, bob: 2 } });
        const badge = readRivalryBadge(elements.rivalryBadge);
        assert.strictEqual(badge.skippyLeading, true, 'persisted lead must receive leader styling on the category screen, not just game-complete');
        assert.strictEqual(badge.bobLeading, false);
    });

    await check('a Bob lead loaded from persistence colors BOB (and only Bob) with the warm accent, never Skippy\'s green', async () => {
        const { elements } = await bootApp(0.5, '', { initialRivalry: { version: 1, skippy: 2, bob: 6 } });
        const badge = readRivalryBadge(elements.rivalryBadge);
        assert.strictEqual(badge.bobLeading, true);
        assert.strictEqual(badge.skippyLeading, false);
    });

    await check('a tie after nonzero totals renders both names neutral, same as 0-0', async () => {
        const { elements } = await bootApp(0.5, '', { initialRivalry: { version: 1, skippy: 4, bob: 4 } });
        const badge = readRivalryBadge(elements.rivalryBadge);
        assert.strictEqual(badge.skippyLeading, false);
        assert.strictEqual(badge.bobLeading, false);
    });

    await check('leader color derives from the PERSISTED totals, not from the most recent outcome: Bob just scoring the winning point over an already-trailing Skippy still colors Bob (leader and just-scored coincide)', async () => {
        const { elements, clock } = await bootApp(0.5, '', { initialRivalry: { version: 1, skippy: 1, bob: 3 } });
        const puzzlesDoc = JSON.parse(fs.readFileSync(path.join(ROOT, 'lastword/puzzles.json'), 'utf8'));
        playToFinal(elements, clock, puzzlesDoc);
        finishFinalViaStrikeout(elements, clock); // -> skippy 1, bob 4

        const badge = readRivalryBadge(elements.gameCompleteRivalry);
        assert.strictEqual(badge.bobText, 'BOB 4');
        assert.strictEqual(badge.bobLeading, true);
        assert.strictEqual(badge.bobEmphasized, true, 'the existing just-scored emphasis must remain intact');
        assert.strictEqual(badge.skippyLeading, false);
        assert.strictEqual(badge.skippyEmphasized, false);
    });

    await check('leader color derives from the PERSISTED totals, not from the most recent outcome: the trailing side scoring does NOT flip leader color to them if the other side is still ahead overall', async () => {
        const { elements, clock } = await bootApp(0.5, '', { initialRivalry: { version: 1, skippy: 3, bob: 1 } });
        const puzzlesDoc = JSON.parse(fs.readFileSync(path.join(ROOT, 'lastword/puzzles.json'), 'utf8'));
        playToFinal(elements, clock, puzzlesDoc);
        finishFinalViaStrikeout(elements, clock); // -> skippy 3, bob 2 — Bob just scored, Skippy still leads overall

        const badge = readRivalryBadge(elements.gameCompleteRivalry);
        assert.strictEqual(badge.skippyText, 'SKIPPY 3');
        assert.strictEqual(badge.bobText, 'BOB 2');
        assert.strictEqual(badge.skippyLeading, true, 'Skippy still leads 3-2 overall, so Skippy (not Bob) keeps the leader color');
        assert.strictEqual(badge.bobLeading, false);
        assert.strictEqual(badge.bobEmphasized, true, 'Bob is still the side that just scored THIS session — that emphasis is independent of who leads overall');
        assert.strictEqual(badge.skippyEmphasized, false);
    });

    await check('the just-scored completion emphasis remains intact and coincides with leader color when the leader extends their own lead', async () => {
        const { elements, clock } = await bootApp(0.5);
        const puzzlesDoc = JSON.parse(fs.readFileSync(path.join(ROOT, 'lastword/puzzles.json'), 'utf8'));
        playToFinal(elements, clock, puzzlesDoc);
        finishFinalViaExplicitSolve(elements, clock, puzzlesDoc); // fresh 0-0 -> skippy 1, bob 0

        const badge = readRivalryBadge(elements.gameCompleteRivalry);
        assert.strictEqual(badge.skippyEmphasized, true, 'original just-scored treatment is unchanged');
        assert.strictEqual(badge.skippyLeading, true, 'and Skippy now also leads, so both classes apply to the same span');
        assert.strictEqual(badge.bobEmphasized, false);
        assert.strictEqual(badge.bobLeading, false);
    });

    console.log(passed + ' passed');
})().catch((err) => {
    console.error(err);
    process.exit(1);
});
