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
    'category-select', 'categoryList',
    'round-offer', 'roundOfferTitle', 'roundOfferList',
    'transition', 'transitionTitle', 'transitionBody', 'transitionContinue',
    'game', 'gallows', 'skippyChatterBubble', 'strikeCount', 'roundLabel', 'categoryLabel',
    'cumulativeScore', 'roundScore', 'solveBonus', 'decayHint',
    'board', 'statusLine', 'letters', 'valuesHint',
    'solveToggle', 'solveForm', 'solveInput', 'solveSubmit',
    'buyHintButton', 'hintUnavailableReason',
    'round-result', 'roundResultTitle', 'roundResultBody', 'continueAfterRound',
    'final-intro', 'finalIntroCategory', 'finalIntroScore', 'finalIntroBoard',
    'finalOpeningChoice', 'finalOpeningCountButtons',
    'finalOpeningPicker', 'finalOpeningPickerPrompt', 'finalOpeningLetterGrid',
    'final-play', 'finalGallows', 'finalSkippyChatterBubble', 'finalStrikeCount', 'finalCategoryLabel', 'finalScoreRemaining',
    'finalBoard', 'finalStatusLine', 'finalLetters',
    'finalSolveToggle', 'finalSolveForm', 'finalSolveInput', 'finalSolveSubmit',
    'game-complete', 'gameCompleteTitle', 'gameCompleteBody', 'playAgain'
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

/** Boots a fresh app-final-skippy.js instance in an isolated vm context. */
function bootApp(rngFallback) {
    const elements = {};
    IDS.forEach((id) => { elements[id] = makeElement(id); elements[id].width = 260; elements[id].height = 260; });

    const puzzlesJson = fs.readFileSync(path.join(ROOT, 'lastword/puzzles.json'), 'utf8');
    const clock = makeFakeClock();
    const rng = makeRngQueue(typeof rngFallback === 'number' ? rngFallback : 0.5);

    const fakeMath = Object.create(Math);
    fakeMath.random = rng;

    const sandbox = {
        console,
        Math: fakeMath,
        document: { getElementById: (id) => elements[id], createElement: (tag) => makeElement('(' + tag + ')'), activeElement: null },
        fetch: () => Promise.resolve({ ok: true, json: () => Promise.resolve(JSON.parse(puzzlesJson)) }),
        addEventListener: function () {},
        setTimeout: clock.setTimeout,
        clearTimeout: clock.clearTimeout
    };
    sandbox.window = sandbox;
    sandbox.self = sandbox;
    const context = vm.createContext(sandbox);

    ['content.js', 'state.js', 'round.js', 'final.js', 'session.js', 'gallows-character.js', 'idle-chatter.js', 'hint.js', 'auto-solve.js', 'app-final-skippy.js'].forEach((f) => {
        const file = path.join(ROOT, 'js/lastword', f);
        vm.runInContext(fs.readFileSync(file, 'utf8'), context, { filename: f });
    });

    return new Promise((resolve) => setImmediate(() => setImmediate(() => resolve({ elements, context, clock, rng }))));
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
        const { elements } = await bootApp();
        clickByText(elements.categoryList, 'Movies & TV');
        assert.strictEqual(stateOf(elements.gallows), 'CONFIDENT');
    });

    await check('each wrong-consonant strike advances Skippy through the correct visual state (0-5)', async () => {
        const { elements } = await bootApp();
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
        const { elements, clock } = await bootApp();
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
        const { elements, clock } = await bootApp();
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
        const { elements, clock } = await bootApp();
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
        const { elements, clock } = await bootApp();
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
        const { elements, clock } = await bootApp(0); // rng=0 -> minimum delay each draw
        clickByText(elements.categoryList, 'Movies & TV');

        assert.strictEqual(elements.skippyChatterBubble.hidden, true, 'bubble starts hidden');
        clock.advance(11999); // just under the 12s minimum
        assert.strictEqual(elements.skippyChatterBubble.hidden, true, 'should not chatter before the first-remark window opens');
        clock.advance(2); // cross the 12000ms mark
        assert.strictEqual(elements.skippyChatterBubble.hidden, false, 'should chatter once inside the first-remark window');
        assert.ok(elements.skippyChatterBubble.textContent.length > 0);
    });

    await check('the chatter bubble auto-hides after CHATTER_BUBBLE_DISPLAY_MS = 10000ms (playtest iteration #3: lengthened from 5000ms)', async () => {
        const { elements, clock } = await bootApp(0);
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
        const { elements, clock } = await bootApp(0); // rng=0 -> minimum delay each draw (first=12s, later=20s)
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
        const { elements, clock } = await bootApp(0);
        clickByText(elements.categoryList, 'Movies & TV');
        clock.advance(12001);
        assert.strictEqual(elements.skippyChatterBubble.hidden, false, 'precondition: chatter fired');

        clickByText(elements.letters, 'E'); // a common letter — guaranteed to register as a guess either way
        assert.strictEqual(elements.skippyChatterBubble.hidden, true, 'a real player action should hide the bubble and restart the idle stretch');

        clock.advance(11999);
        assert.strictEqual(elements.skippyChatterBubble.hidden, true, 'the restarted stretch should not fire before its own first-remark window');
    });

    await check('idle chatter never mutates gameplay state (score/strikes/outcome unchanged by a firing)', async () => {
        const { elements, clock } = await bootApp(0);
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
        const { elements, clock } = await bootApp(0);
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
        const { elements, clock } = await bootApp(0.99); // biases the per-stretch remark budget toward its max
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
        const { elements, clock, rng } = await bootApp(0.4);
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
        const { elements } = await bootApp(0); // rng=0 -> pickRandom deals pool[0] deterministically
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
        const { elements } = await bootApp(0); // rng=0 -> pickRandom deals pool[0] deterministically
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
        const { elements } = await bootApp(0);
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
        const { elements } = await bootApp(0);
        const puzzlesDoc = JSON.parse(fs.readFileSync(path.join(ROOT, 'lastword/puzzles.json'), 'utf8'));
        clickByText(elements.categoryList, 'Movies & TV');
        const candidates = dealtPuzzleCandidates(elements.board, puzzlesDoc, 'Movies & TV');
        elements.solveToggle.click();
        elements.solveInput.value = candidates[0].answer;
        elements.solveSubmit.click();
        assert.strictEqual(elements.buyHintButton.disabled, true);
    });

    await check('buying a hint is a meaningful action: it hides any showing chatter bubble and resets the idle stretch', async () => {
        const { elements, clock } = await bootApp(0);
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
        const { elements, clock } = await bootApp(0); // rng=0 -> pickRandom deals pool[0] deterministically
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
        const { elements, clock } = await bootApp(0);
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
        const { elements, clock } = await bootApp(0);
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
        const { elements, clock } = await bootApp(0);
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
        const { elements } = await bootApp(0);
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
        const { elements, clock } = await bootApp(0);
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

    console.log(passed + ' passed');
})().catch((err) => {
    console.error(err);
    process.exit(1);
});
