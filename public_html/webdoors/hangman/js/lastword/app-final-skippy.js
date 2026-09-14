/**
 * Last Word — SKIPPY INTEGRATION build: M1D's four-round session + Final
 * Hangman, with the accepted js/lastword/gallows-character.js (Skippy V1)
 * replacing the generic canvas stick-figure gallows, plus restrained
 * state-aware idle chatter (js/lastword/idle-chatter.js).
 *
 * This is a FORK of js/lastword/app-final.js, not an edit to it — M1D's
 * canonical build stays untouched at lastword-m1d.html/app-final.js so it
 * remains independently revisitable. Everything below that isn't
 * Skippy/chatter-related (rounds 1-4 rules wiring, Final Hangman rules
 * wiring, scoring, category flow) is unchanged from app-final.js on
 * purpose: this transaction is a rendering + flavor-dialogue swap, not a
 * gameplay change.
 *
 * All rules/outcomes still live in content.js/state.js/round.js/final.js/
 * session.js (pure, DOM-free) — untouched. This file only renders that
 * state and forwards DOM events.
 *
 * Skippy rendering: `renderSkippyOn(containerEl, ...)` replaces the old
 * `drawGallowsOn(canvasEl, strikes)` canvas routine. It maps
 * (strikes, solved) -> a gallows-character.js state name via
 * `LastWordGallowsCharacter.pickStateName` and injects that state's SVG
 * markup into a plain `<div>` (the HTML swaps the `<canvas>` element for a
 * div of the same footprint) — no new game rule, purely a different way to
 * draw the same strikes/solved facts the rest of this file already
 * computes. TERRIFIED (Strike 5)'s rotating panic line and COMEDIC_DEFEAT/
 * SAVED are only ever reached at round-end, which M1D's flow used to skip
 * straight past (it jumped directly from the last guess to the round-result
 * screen without ever painting the terminal canvas frame) — this build adds
 * one short, fixed, cosmetic pause (`payoffDelayFor()`, backed by
 * `DEFEAT_PAYOFF_DELAY_MS`/`SAVED_PAYOFF_DELAY_MS`) before that screen
 * switch so the comedic-defeat gag and the SAVED! celebration — both
 * explicitly part of Skippy's accepted design — actually get shown to the
 * player instead of only existing in the renderer's test suite. The pause
 * changes no score, timing-sensitive rule, or outcome; it only delays when
 * the *next* screen appears.
 *
 * Idle chatter: a single js/lastword/idle-chatter.js controller per
 * gameplay surface (rounds 1-4 share one; Final Hangman gets its own).
 * `resetIdle()` is called whenever a gameplay screen begins and on every
 * meaningful player action; `stop()` is called on every transition away
 * from a gameplay screen. The controller only ever calls back into a tiny
 * bubble-presenter pair (`show`/`hide`, see `makeChatterBubble` below) that
 * sets a bubble element's text/visibility — it never touches round/session
 * state, so it is provably incapable of affecting gameplay.
 *
 * PLAYTEST ITERATION #2 (two changes, both scoped to this isolated build):
 *
 * 1. Idle chatter PRESENTATION (behavior/timing unchanged): the chatter
 *    caption used to be a plain always-visible text line beneath Skippy,
 *    easy to miss while focused on the puzzle. `makeChatterBubble()` now
 *    presents each remark as a transient speech-bubble element positioned
 *    ABOVE the whole `.lw-skippy-portrait` stage (see css/lastword-skippy.css)
 *    — never over any part of Skippy's figure, since it isn't drawn on the
 *    SVG surface at all — that auto-hides itself after
 *    `CHATTER_BUBBLE_DISPLAY_MS` and cleanly replaces (never stacks) if a
 *    new remark arrives first. It is a different shape/position from
 *    Skippy's own baked-in event bubbles (PLEADING/TERRIFIED/SAVED, drawn
 *    inside the SVG below his feet) so the two are visually distinct and
 *    never collide.
 *
 * 2. BUY HINT LETTER (new pure module js/lastword/hint.js, not a change to
 *    round.js/state.js): a score-funded guaranteed-hint control for Rounds
 *    1-4 only. See hint.js's own header for why this reuses round.js's
 *    existing roundState fields instead of inventing a parallel score
 *    system. Final Hangman is untouched — it keeps its existing
 *    purchase-a-letter-you-choose flow.
 *
 * CONSCIOUS BOUNDED FORK #1 — "SKIPPY REMEMBERS" (new pure module
 * js/lastword/skippy-memory.js, not a change to round.js/state.js/
 * session.js/final.js): Skippy now carries a small in-memory record of
 * what the caller has done THIS session (hints bought, rounds solved/
 * lost/perfected, previous-round facts) and opens the next round — and
 * once, Final Hangman — with a short reaction line when that history is
 * interesting. See skippy-memory.js's own header for the full contract
 * (deliberately NOT persistence — the object lives only in this file's
 * module-level `skippyMemory` var, created fresh per session). Wiring
 * here is: `skippyMemory`/`hintsPurchasedThisRound` module vars,
 * `recordRoundResult()` called in finishCurrentRound() once a round's
 * outcome/strikes/hint-spend are known, and `pickOpeningReaction()`/
 * `pickFinalReaction()` called in beginRoundPlay()/beginFinalPlay() to
 * show (or not show) a reaction via the SAME chatter-bubble presenter
 * idle chatter already uses — see those functions for why that alone is
 * enough to guarantee precedence-without-collision.
 *
 * CONSCIOUS BOUNDED FORK #2 — "SKIPPY IS WATCHING" (new pure module
 * js/lastword/situational-awareness.js, not a change to skippy-memory.js
 * or round.js/state.js): Skippy now also notices a few high-salience
 * things WHILE a normal round (1-4) is in progress — sitting at 5
 * strikes with an affordable hint unused, conspicuous repeated hint
 * purchases, and repeated wrong SOLVE attempts — and reacts mid-round,
 * not just at the next round's open. See situational-awareness.js's own
 * header for the full contract (round-scoped only, gone at round end,
 * still not persistence) and its salience/priority policy (each
 * situation fires at most once per round; a single deterministic
 * priority order resolves any tie). Wiring here is: `roundAwareness`
 * module var (reset per round in beginRoundPlay(), same as Fork #1's
 * `hintsPurchasedThisRound`), the `canBuyHintNow()`/`checkSituational()`
 * helpers below, and one `checkSituational(...)` call added to each
 * round-mutating handler (handleLetter/handleBuyHint/submitSolve) right
 * before its normal `renderPlayingState()` — reusing the exact same
 * chatter-bubble presenter Fork #1's opening reactions and idle chatter
 * already share, so this can't introduce a second bubble or collide with
 * either. Final Hangman is untouched — this fork is normal-rounds only.
 */
(function () {
    'use strict';

    var PUZZLES_URL = 'lastword/puzzles.json';

    var puzzles = [];
    var session = null;
    var round = null;
    var puzzle = null;
    var roundConfig = null;
    var solveSubmitting = false;
    var pendingCategory = null; // category the transition screen is about to deal for round 2/4

    // SKIPPY REMEMBERS (conscious bounded fork #1): session-scoped memory
    // of what the caller has done so far this session — see
    // js/lastword/skippy-memory.js's own header for the full contract.
    // Created fresh in startNewSession()/boot(); never persisted. Reset
    // per-round in beginRoundPlay() so it counts only hints bought DURING
    // the round about to finish.
    var skippyMemory = LastWordSkippyMemory.createMemory();
    var hintsPurchasedThisRound = 0;

    // SKIPPY IS WATCHING (conscious bounded fork #2): round-scoped
    // situational awareness — "I see what you're doing right now", as
    // opposed to skippyMemory's session-spanning "I remember what you
    // did". See js/lastword/situational-awareness.js's own header for the
    // full contract. Reset per-round in beginRoundPlay(), same as
    // hintsPurchasedThisRound above — gone the instant the round ends.
    var roundAwareness = LastWordSituationalAwareness.createRoundAwareness();

    // CONSCIOUS FORK #6 ("MAKE THE GAME LAND"): restrained score-change
    // feedback (js/lastword/presentation.js) needs the previous rendered
    // value to compute a delta each time renderScoreboard() runs. `null`
    // means "no baseline yet" — renderScoreboard() treats that as no
    // change rather than a delta from 0, so the first render of a fresh
    // round/session never shows a spurious pop. Reset in beginRoundPlay()
    // (round score, right before that round's first render) and in
    // startNewSession()/boot() (cumulative score).
    var prevRoundScore = null;
    var prevCumulativeScore = null;
    // The single letter/purchase a normal round-mutating action just
    // resolved, consumed (and cleared) by the very next renderLetters()
    // so the pop/shake plays exactly once per action, on exactly the
    // button that changed. Never set for hint purchases — see
    // handleBuyHint()'s comment.
    var lastLetterFeedback = null;
    var reducedMotion = LastWordPresentation.prefersReducedMotion(typeof window !== 'undefined' ? window : null);

    // Final Hangman state
    var finalState = null;
    var finalPuzzle = null;
    var finalSolveSubmitting = false;
    var pendingOpeningCount = null;   // count chosen in the opening-help step, before letters are picked
    var pendingOpeningLetters = [];   // letters picked so far toward pendingOpeningCount

    var el = {};
    [
        'category-select', 'categoryList',
        'round-offer', 'roundOfferTitle', 'roundOfferList',
        'transition', 'transitionTitle', 'transitionBody', 'transitionContinue',
        'game', 'gallows', 'skippyChatterBubble', 'strikeCount', 'roundLabel', 'categoryLabel',
        'cumulativeScore', 'cumulativeScoreDelta', 'roundScore', 'roundScoreDelta', 'solveBonus', 'decayHint',
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
    ].forEach(function (id) {
        var camel = id.replace(/-([a-z])/g, function (_, c) { return c.toUpperCase(); });
        el[camel] = document.getElementById(id);
    });

    // Cosmetic-only pause before switching away from a gameplay screen once
    // a round/Final ends, so Skippy's terminal pose (SAVED or
    // COMEDIC_DEFEAT) is actually visible for a beat instead of being
    // skipped straight past. Does not change score, strikes, or outcome —
    // only when the *next* screen (round-result / game-complete) appears.
    // PLAYTEST ITERATION #3: the SAVED celebration specifically gets a
    // longer hold (human feedback: "advances too quickly") — COMEDIC_DEFEAT
    // is deliberately left at the original duration, not touched this pass.
    var DEFEAT_PAYOFF_DELAY_MS = 1400;
    var SAVED_PAYOFF_DELAY_MS = 3400;

    function payoffDelayFor(solved) {
        return solved ? SAVED_PAYOFF_DELAY_MS : DEFEAT_PAYOFF_DELAY_MS;
    }

    // Strike-5 (TERRIFIED) panic line stays stable for as long as the round
    // remains at Strike 5 — re-rolled only on freshly entering Strike 5, per
    // gallows-character.js's pickPanicLine contract. One holder per gameplay
    // surface since rounds 1-4 and Final Hangman never overlap.
    var panicState = { line: null, entered5: false };
    var finalPanicState = { line: null, entered5: false };

    // Latest state name each surface's Skippy render produced — read live by
    // the idle-chatter controllers below at fire time, never snapshotted.
    var currentSkippyState = null;
    var finalCurrentSkippyState = null;

    function skippyStateFor(strikes, solved) {
        return LastWordGallowsCharacter.pickStateName(strikes, solved);
    }

    // SKIPPY'S PREDICAMENT (accepted, PASS): which environment/threat
    // renders around Skippy this session — the accepted gallows (default)
    // or the accepted suspended-safe Predicament
    // (js/lastword/safe-predicament.js). Chosen once at page load via
    // `?predicament=safe` in the URL; defaults to 'gallows' so an ordinary
    // page load is byte-for-byte the original gallows presentation. This
    // is presentation only — it never touches round/session/finalState or
    // any scoring/puzzle/hint/strike rule (see safe-predicament.js's own
    // header). How a real session will eventually choose/vary its
    // Predicament in production is intentionally still an open decision —
    // this is just the mechanism the accepted Predicament plugs into.
    var urlParams = (function () {
        try { return new URLSearchParams(window.location.search); } catch (e) { return null; }
    }());
    var activePredicament = (urlParams && urlParams.get('predicament') === 'safe') ? 'safe' : 'gallows';

    // BOB (human-accepted 2026-09-13 for ROLE/DYNAMIC; artwork remains NOT
    // final — see bob-character.js's own header). `?predicament=safe&bob=1`
    // opts a tiny silent gnome into the Suspended Safe Predicament's own
    // apparatus (see js/lastword/bob-character.js and
    // safe-predicament.js's `makeApparatus()`). Only ever takes effect
    // together with the safe Predicament — Bob has no existence in the
    // accepted gallows. Still not production-default: if a future
    // direction drops Bob, delete bob-character.js, this flag, the one
    // intro-line block below it, and the apparatus-selection line further
    // down — nothing else changes.
    var bobEnabled = !!(urlParams && urlParams.get('bob') === '1' && activePredicament === 'safe');

    // The ONE Skippy acknowledgment the fork description allows ("Oh
    // great. Bob's here." / "BOB.") — shown at most once per round, the
    // first time Bob is actually visible (i.e. the first strike, since
    // Strike 0/CONFIDENT deliberately shows no Bob — see bob-character.js's
    // POSE_BY_STATE). NOT a Bob dialogue system: Bob himself never speaks,
    // this is Skippy reacting to Bob, through the same single shared
    // transient chatter bubble every other reaction already uses.
    var BOB_INTRO_LINE = 'Oh great. Bob\'s here.';
    var bobIntroducedThisRound = false;

    /**
     * Render Skippy into `containerEl` for the given strikes/solved facts.
     * `panicHolder` is one of the two { line, entered5 } trackers above,
     * passed by reference (a plain object) so this function can update it.
     */
    function renderSkippyOn(containerEl, strikes, solved, panicState) {
        var stateName = skippyStateFor(strikes, solved);
        var opts = {};
        if (bobEnabled && stateName !== 'CONFIDENT' && !bobIntroducedThisRound) {
            bobIntroducedThisRound = true;
            var introBubbleEl = containerEl === el.finalGallows ? el.finalSkippyChatterBubble : el.skippyChatterBubble;
            var introBubblePresenter = containerEl === el.finalGallows ? finalSkippyChatterBubble : skippyChatterBubble;
            if (introBubbleEl && introBubbleEl.hidden) {
                introBubblePresenter.show(BOB_INTRO_LINE);
            }
        }
        if (stateName === 'TERRIFIED') {
            var enteringFresh = !panicState.entered5;
            if (enteringFresh) {
                // The one predicament-aware line the suspended-safe adds
                // (see safe-predicament.js's own comment on it) — only
                // used when that Predicament is active; the accepted
                // gallows TERRIFIED pool is untouched otherwise.
                panicState.line = (activePredicament === 'safe')
                    ? LastWordSafePredicament.SAFE_TERRIFIED_LINE
                    : LastWordGallowsCharacter.pickPanicLine(panicState.line);
                panicState.entered5 = true;
            }
            opts.panicLine = panicState.line;
            if (activePredicament === 'safe') {
                // ONE SKIPPY, ONE ACTIVE SPEECH BUBBLE: the accepted
                // gallows draws its Strike-5 panic line baked into the SVG
                // below Skippy; the suspended-safe Predicament instead
                // routes that same line through the ONE shared transient
                // chatter-bubble presenter (the same one idle chatter/
                // SKIPPY REMEMBERS/SKIPPY IS WATCHING already use) — never
                // both at once. Always suppress the baked bubble while the
                // safe is active; only attempt to SHOW the routed line on
                // the fresh strike-5 entry, and only if nothing else (e.g.
                // a situational-awareness reaction from this same action)
                // is already occupying the one bubble slot.
                opts.suppressSpeechBubble = true;
                if (enteringFresh) {
                    var bubbleEl = containerEl === el.finalGallows ? el.finalSkippyChatterBubble : el.skippyChatterBubble;
                    var bubblePresenter = containerEl === el.finalGallows ? finalSkippyChatterBubble : skippyChatterBubble;
                    if (bubbleEl && bubbleEl.hidden) {
                        bubblePresenter.show(panicState.line);
                    }
                }
            }
        } else {
            panicState.entered5 = false;
        }
        if (activePredicament === 'safe') {
            opts.apparatus = bobEnabled ? LastWordSafePredicament.makeApparatus(true) : LastWordSafePredicament.apparatus;
        }
        containerEl.innerHTML = LastWordGallowsCharacter.renderMarkup(stateName, opts);
        return stateName;
    }

    // Idle chatter is shown for CHATTER_BUBBLE_DISPLAY_MS then auto-hides —
    // long enough to comfortably read one of idle-chatter.js's short pool
    // lines, short enough that it reads as a passing remark rather than a
    // permanent fixture. Uses the page's real setTimeout/clearTimeout (the
    // same ones idle-chatter.js itself defaults to), so a DOM test that
    // injects a fake clock for idle-chatter.js's own timers must inject the
    // same fake clock globally to control this too — see
    // app-final-skippy-dom.test.js.
    // PLAYTEST ITERATION #3: raised from 5000 (human feedback: "keep chatter
    // visible longer" — timing/reset/pools/no-repeat behavior are otherwise
    // completely unchanged, this is presentation duration only).
    var CHATTER_BUBBLE_DISPLAY_MS = 10000;

    /**
     * Presents idle-chatter remarks for one gameplay surface as a transient
     * speech bubble: `show(line)` displays it and (re)starts the auto-hide
     * timer — replacing any bubble already showing cleanly, never stacking,
     * since there is only ever the one bubble element and one pending
     * timer; `hide()` removes it immediately and cancels that timer. Purely
     * a presentation helper — it only ever writes to `bubbleEl` and never
     * reads or writes any round/session/finalState.
     */
    function makeChatterBubble(bubbleEl) {
        var hideTimer = null;
        function clearTimer() {
            if (hideTimer !== null) { clearTimeout(hideTimer); hideTimer = null; }
        }
        return {
            show: function (chatterLine) {
                if (!bubbleEl || !chatterLine) return;
                clearTimer();
                bubbleEl.textContent = chatterLine;
                bubbleEl.hidden = false;
                hideTimer = setTimeout(function () {
                    hideTimer = null;
                    bubbleEl.hidden = true;
                    bubbleEl.textContent = '';
                }, CHATTER_BUBBLE_DISPLAY_MS);
            },
            hide: function () {
                if (!bubbleEl) return;
                clearTimer();
                bubbleEl.hidden = true;
                bubbleEl.textContent = '';
            }
        };
    }

    var skippyChatterBubble = makeChatterBubble(el.skippyChatterBubble);
    var finalSkippyChatterBubble = makeChatterBubble(el.finalSkippyChatterBubble);

    // One idle-chatter controller per gameplay surface (rounds 1-4 share
    // one; Final Hangman gets its own, since the two never play
    // simultaneously). Neither controller ever touches round/session/
    // finalState — only `currentSkippyState`/`finalCurrentSkippyState`
    // (read-only to it) and its bubble presenter (purely cosmetic).
    var idleChatter = LastWordIdleChatter.createIdleChatterController({
        getStateName: function () { return currentSkippyState; },
        onChatter: function (chatterLine) { skippyChatterBubble.show(chatterLine); }
    });
    var finalIdleChatter = LastWordIdleChatter.createIdleChatterController({
        getStateName: function () { return finalCurrentSkippyState; },
        onChatter: function (chatterLine) { finalSkippyChatterBubble.show(chatterLine); }
    });

    var ROUND_LABELS = {
        1: 'Round 1 — Warm-Up',
        2: 'Round 2 — Dealer\'s Choice',
        3: 'Round 3 — Player\'s Choice',
        4: 'Round 4 — Wildcard'
    };

    function showOnly(panelKey) {
        ['categorySelect', 'roundOffer', 'transition', 'game', 'roundResult',
            'finalIntro', 'finalPlay', 'gameComplete'].forEach(function (key) {
            var target = el[key];
            var isMain = key === 'game' || key === 'finalPlay';
            target.className = (isMain ? 'layout' : 'panel') + (key === panelKey ? '' : ' hidden');
        });
    }

    function setStatus(msg) {
        el.statusLine.textContent = msg || '';
    }

    function availableScore() {
        return session.cumulativeScore + round.pointsThisRound;
    }

    // Exactly the same affordability+availability check renderHintButton()
    // already uses to enable/disable the HINT button — reused here rather
    // than reinvented, so "you can afford a hint" always means the same
    // thing to the situational-awareness layer as it does on screen.
    function canBuyHintNow() {
        return LastWordHint.hintCandidates(round, puzzle).length > 0 &&
            LastWordHint.canAffordHint(availableScore(), roundConfig);
    }

    /**
     * SKIPPY IS WATCHING: single call site funneling every round-mutating
     * action into js/lastword/situational-awareness.js's `onAction()` —
     * see that module's header for the salience/priority policy. Call
     * this ONLY when the round is still in progress (every call site
     * below already returns early via finishCurrentRound() when it
     * isn't), right before the state is re-rendered, so a reaction shows
     * alongside the fresh board/scoreboard rather than the stale one.
     */
    function checkSituational(actionKind) {
        var result = LastWordSituationalAwareness.onAction(roundAwareness, {
            kind: actionKind,
            strikes: round.strikes,
            isRoundOver: LastWordRound.isRoundOver(round),
            canBuyHint: canBuyHintNow(),
            hintCost: LastWordHint.hintCostFor(roundConfig)
        });
        roundAwareness = result.awareness;
        // Same single bubble/timer idle chatter uses — see
        // beginRoundPlay()'s identical reasoning for Fork #1's opening
        // reaction: showing here can't collide/stack with idle chatter,
        // it just takes the one bubble slot for its own display window.
        if (result.reaction) {
            skippyChatterBubble.show(result.reaction);
        }
    }

    function renderBoardInto(containerEl, puzzleObj, stateObj) {
        var cells = LastWordRound.buildDisplayBoard(puzzleObj, stateObj);
        containerEl.innerHTML = '';
        cells.forEach(function (cell) {
            var span = document.createElement('span');
            if (!cell.isLetter) {
                span.className = cell.char === ' ' ? 'cell space' : 'cell punct';
                span.textContent = cell.char === ' ' ? '  ' : cell.char;
            } else if (cell.revealed) {
                span.className = 'cell';
                span.textContent = cell.char;
            } else {
                span.className = 'cell blank';
                span.textContent = '  ';
            }
            containerEl.appendChild(span);
        });
    }

    // ---------------------------------------------------------------------
    // Rounds 1-4 (unchanged from M1C's app-session.js)
    // ---------------------------------------------------------------------

    function renderLetters() {
        el.letters.innerHTML = '';
        // Consumed once here — see lastLetterFeedback's declaration for why
        // this is the only place it's read, and why it's cleared right
        // after so a later, unrelated re-render never replays it.
        var feedback = lastLetterFeedback;
        lastLetterFeedback = null;
        for (var i = 65; i <= 90; i++) {
            var letter = String.fromCharCode(i);
            var isVowel = LastWordContent.isVowel(letter);
            var button = document.createElement('button');
            button.textContent = isVowel ? letter + ' (' + roundConfig.vowelCost + ')' : letter;
            if (isVowel) button.classList.add('vowel');

            var alreadyUsed = isVowel
                ? round.purchasedVowels.indexOf(letter) !== -1
                : round.consonantsGuessed.indexOf(letter) !== -1;

            if (alreadyUsed) {
                button.disabled = true;
                var wasCorrect = round.revealedLetters.indexOf(letter) !== -1;
                button.classList.add(wasCorrect ? 'correct' : 'wrong');
                // Proof B: a small complementary pop/shake on top of the
                // existing (untouched) correct/wrong coloring, only on the
                // button the just-resolved action touched.
                if (!reducedMotion && feedback && feedback.letter === letter) {
                    button.classList.add(feedback.correct ? 'lw-letter-pop-correct' : 'lw-letter-pop-wrong');
                }
            } else if (LastWordRound.isRoundOver(round)) {
                button.disabled = true;
            } else if (isVowel && !LastWordRound.canAffordVowel(availableScore(), roundConfig)) {
                button.disabled = true;
            }

            button.onclick = function (ltr) {
                return function () { handleLetter(ltr); };
            }(letter);

            el.letters.appendChild(button);
        }
    }

    function renderScoreboard() {
        el.strikeCount.textContent = String(round.strikes);
        el.roundLabel.textContent = ROUND_LABELS[round.round];
        el.categoryLabel.textContent = round.category;
        el.cumulativeScore.textContent = String(session.cumulativeScore);
        el.roundScore.textContent = String(round.pointsThisRound);
        el.solveBonus.textContent = String(LastWordRound.computeSolveBonus(round, roundConfig.maxSolveBonus));
        el.decayHint.textContent = 'Bonus: ' + roundConfig.maxSolveBonus + ' − ' +
            LastWordRound.decayPerActionFor(roundConfig.maxSolveBonus) + ' per letter guessed or bought';
        el.valuesHint.textContent = 'Consonants: free, earn ' + roundConfig.consonantValue +
            ' x occurrences. Vowels: cost ' + roundConfig.vowelCost + ', reveal all occurrences.';
        currentSkippyState = renderSkippyOn(el.gallows, round.strikes, false, panicState);

        // Proof A: score changes should register. Compare against the last
        // value THIS function saw (not against any earlier snapshot) so a
        // delta is only ever shown once per actual change, then update the
        // baseline for next time. See prevRoundScore/prevCumulativeScore's
        // declaration for why `null` suppresses the very first render.
        if (prevRoundScore !== null) {
            var roundDelta = round.pointsThisRound - prevRoundScore;
            var roundMag = LastWordPresentation.classifyScoreDelta(roundDelta, roundConfig);
            if (roundMag) {
                LastWordPresentation.presentScoreDelta(
                    { scoreEl: el.roundScore, deltaEl: el.roundScoreDelta },
                    roundDelta, roundMag, { reducedMotion: reducedMotion });
            }
        }
        prevRoundScore = round.pointsThisRound;

        if (prevCumulativeScore !== null) {
            var cumDelta = session.cumulativeScore - prevCumulativeScore;
            var cumMag = LastWordPresentation.classifyScoreDelta(cumDelta, roundConfig);
            if (cumMag) {
                LastWordPresentation.presentScoreDelta(
                    { scoreEl: el.cumulativeScore, deltaEl: el.cumulativeScoreDelta },
                    cumDelta, cumMag, { reducedMotion: reducedMotion });
            }
        }
        prevCumulativeScore = session.cumulativeScore;
    }

    /**
     * BUY HINT LETTER control (playtest iteration #2): a subordinate escape
     * valve, not the primary way to play — see css/lastword-skippy.css's
     * `.hintButton`/`.hintRow` for how it's kept visually secondary to
     * normal guessing/SOLVE. Disabled (with a reason shown below it) when
     * the round is over, no unrevealed guessable letters remain, or the
     * player cannot afford it; otherwise shows the round-specific cost.
     */
    function renderHintButton() {
        var cost = LastWordHint.hintCostFor(roundConfig);
        // PLAYTEST ITERATION #5: shortened again from "HINT LETTER · <cost>"
        // (itself shortened from "BUY HINT LETTER — <cost>" in iteration #4).
        // Label text only — styling/placement/behavior/price unchanged.
        el.buyHintButton.textContent = 'HINT · ' + cost;
        el.hintUnavailableReason.textContent = '';

        if (LastWordRound.isRoundOver(round)) {
            el.buyHintButton.disabled = true;
            return;
        }
        if (!LastWordHint.hintCandidates(round, puzzle).length) {
            el.buyHintButton.disabled = true;
            el.hintUnavailableReason.textContent = 'No hint available — every letter is already revealed.';
            return;
        }
        if (!LastWordHint.canAffordHint(availableScore(), roundConfig)) {
            el.buyHintButton.disabled = true;
            el.hintUnavailableReason.textContent = 'Not enough score for a hint (' + cost + ' needed).';
            return;
        }
        el.buyHintButton.disabled = false;
    }

    function renderPlayingState() {
        renderBoardInto(el.board, puzzle, round);
        renderLetters();
        renderScoreboard();
        renderHintButton();
    }

    /**
     * Buy one guaranteed hint letter — see js/lastword/hint.js for the pure
     * rules. No strike, no points awarded for the reveal; the cost is
     * charged to `round.pointsThisRound` exactly like a vowel purchase, and
     * the purchase counts as one action toward solve-bonus decay for free
     * (see hint.js's header for why). A meaningful player action, same as
     * a letter guess, so it resets the idle-chatter stretch.
     *
     * FORK #6: deliberately never sets `lastLetterFeedback` — a guaranteed
     * hint reveal isn't a right/wrong guess, so it gets no pop/shake (the
     * round's score-delta feedback still applies to its cost, same as any
     * other spend).
     */
    function handleBuyHint() {
        if (!round || LastWordRound.isRoundOver(round)) return;
        idleChatter.resetIdle();
        skippyChatterBubble.hide();

        var result = LastWordHint.purchaseHintLetter(round, puzzle, availableScore(), roundConfig);
        if (!result.changed) {
            if (result.reason === 'insufficient-score') {
                setStatus('Not enough score for a hint (' + LastWordHint.hintCostFor(roundConfig) + ' needed).');
            } else if (result.reason === 'no-letters-remaining') {
                setStatus('No hint available — every letter is already revealed.');
            }
            return;
        }
        round = result.roundState;
        hintsPurchasedThisRound += 1;
        setStatus('Hint: ' + result.letter + ' revealed! (−' + result.cost + ' points, no strike)');

        // CORE-GAME FIX: if that reveal happened to complete the board (every
        // guessable letter now visible), the round is solved right here —
        // the player must never be required to retype an already-fully-
        // visible answer. See js/lastword/auto-solve.js.
        round = LastWordAutoSolve.applyRoundAutoSolve(round, puzzle, roundConfig);

        if (LastWordRound.isRoundOver(round)) {
            finishCurrentRound();
            return;
        }
        checkSituational('hintPurchased');
        renderPlayingState();
    }

    function handleLetter(letter) {
        if (LastWordRound.isRoundOver(round)) return;
        idleChatter.resetIdle();
        skippyChatterBubble.hide();

        if (LastWordContent.isConsonant(letter)) {
            var result = LastWordRound.guessConsonant(round, puzzle, letter, roundConfig);
            if (!result.changed) return;
            round = result.roundState;
            setStatus(result.correct
                ? letter + ' is in the puzzle! +' + (result.occurrences * roundConfig.consonantValue) + ' points.'
                : letter + ' is not in the puzzle. +1 strike.');
            lastLetterFeedback = { letter: letter, correct: result.correct };
        } else {
            var vResult = LastWordRound.purchaseVowel(round, puzzle, letter, availableScore(), roundConfig);
            if (!vResult.changed) {
                if (vResult.reason === 'insufficient-score') setStatus('Not enough score to buy a vowel (' + roundConfig.vowelCost + ' needed).');
                return;
            }
            round = vResult.roundState;
            setStatus(vResult.present
                ? 'Bought ' + letter + ' — it was there!'
                : 'Bought ' + letter + ' — not in the puzzle. (Cost is charged either way.)');
            lastLetterFeedback = { letter: letter, correct: vResult.present };
        }

        // CORE-GAME FIX: see the identical comment in handleBuyHint() above —
        // a consonant guess or vowel purchase that completes the board must
        // solve the round right here, not merely leave it fully visible.
        round = LastWordAutoSolve.applyRoundAutoSolve(round, puzzle, roundConfig);

        if (LastWordRound.isRoundOver(round)) {
            finishCurrentRound();
            return;
        }
        checkSituational('other');
        renderPlayingState();
    }

    function submitSolve() {
        if (solveSubmitting || !round || LastWordRound.isRoundOver(round)) return;
        var text = el.solveInput.value.trim();
        if (!text) return;

        idleChatter.resetIdle();
        skippyChatterBubble.hide();
        solveSubmitting = true;
        el.solveSubmit.disabled = true;

        try {
            var result = LastWordRound.attemptSolve(round, puzzle, text, {
                maxSolveBonus: roundConfig.maxSolveBonus,
                decayPerAction: LastWordRound.decayPerActionFor(roundConfig.maxSolveBonus)
            });
            round = result.roundState;
            el.solveInput.value = '';

            if (result.correct) {
                setStatus('Correct! Solve bonus: ' + result.bonusAwarded + ' points.');
            } else {
                setStatus('Not quite — that\'s +2 strikes.');
            }
        } catch (err) {
            setStatus('Something went wrong submitting that solve — please reload and try again.');
            if (typeof console !== 'undefined' && console.error) console.error('Last Word solve error:', err);
            return;
        } finally {
            solveSubmitting = false;
            el.solveSubmit.disabled = false;
        }

        if (LastWordRound.isRoundOver(round)) {
            finishCurrentRound();
            return;
        }
        checkSituational(result.correct ? 'other' : 'wrongSolveAttempt');
        renderPlayingState();
    }

    function finishCurrentRound() {
        var finishedRound = round;
        idleChatter.stop();
        skippyChatterBubble.hide();
        var solved = finishedRound.outcome === 'solved';
        // Paint Skippy's terminal pose (SAVED or COMEDIC_DEFEAT) before the
        // screen switches away, and hold it on screen briefly — see
        // payoffDelayFor()/DEFEAT_PAYOFF_DELAY_MS/SAVED_PAYOFF_DELAY_MS above.
        currentSkippyState = renderSkippyOn(el.gallows, finishedRound.strikes, solved, panicState);
        // SKIPPY REMEMBERS: record this round's outcome/strikes/hint spend
        // before the next round's beginRoundPlay() reads it for its
        // opening reaction. Purely additive bookkeeping — does not affect
        // session/score.
        skippyMemory = LastWordSkippyMemory.recordRoundResult(skippyMemory, {
            outcome: finishedRound.outcome,
            strikes: finishedRound.strikes,
            hintsPurchased: hintsPurchasedThisRound
        });
        session = LastWordSession.finishRound(session, finishedRound);
        setTimeout(function () {
            renderRoundResult(finishedRound);
        }, payoffDelayFor(solved));
    }

    function renderRoundResult(finishedRound) {
        var solved = finishedRound.outcome === 'solved';
        el.roundResultTitle.textContent = ROUND_LABELS[finishedRound.round] + ': ' + (solved ? 'Solved!' : 'Out of strikes');

        var lines = [];
        lines.push(line(solved ? 'rrSolved' : 'rrFailed', (solved ? '✅ ' : '❌ ') + 'Answer: ' + puzzle.answer));
        lines.push(line('', 'Round points: ' + finishedRound.pointsThisRound));
        lines.push(line('', 'Cumulative score: ' + session.cumulativeScore));

        el.roundResultBody.innerHTML = '';
        lines.forEach(function (l) { el.roundResultBody.appendChild(l); });

        showOnly('roundResult');
        // Proof C: replace the previous instant hard cut into this panel
        // with one short fade — fires once, right here, right after the
        // accepted payoff hold (finishCurrentRound()'s setTimeout) elapses.
        LastWordPresentation.revealPanelWithFade(el.roundResult, { reducedMotion: reducedMotion });
    }

    function line(cls, text) {
        var span = document.createElement('span');
        span.className = 'rrLine' + (cls ? ' ' + cls : '');
        span.textContent = text;
        return span;
    }

    function beginRoundPlay(dealResult) {
        session = dealResult.session;
        round = session.currentRound;
        puzzle = dealResult.puzzle;
        roundConfig = LastWordState.ROUND_CONFIG[round.round];
        el.solveToggle.disabled = false;
        el.solveForm.className = 'solveForm hidden';
        setStatus('New puzzle dealt. Guess a consonant, buy a vowel, or SOLVE.');
        // Fresh round: Skippy resets to CONFIDENT (strikes 0, not solved) and
        // his panic-line/idle-chatter state resets clean, independent of
        // whatever happened last round.
        panicState.line = null;
        panicState.entered5 = false;
        hintsPurchasedThisRound = 0;
        roundAwareness = LastWordSituationalAwareness.createRoundAwareness();
        bobIntroducedThisRound = false;
        // Proof A: seed both baselines from this fresh round's actual
        // starting values right before its first render, so that render
        // shows no delta pop (there is nothing to compare yet) and every
        // render after it compares against a real prior value.
        prevRoundScore = round.pointsThisRound;
        prevCumulativeScore = session.cumulativeScore;
        showOnly('game');
        renderPlayingState();
        // SKIPPY REMEMBERS: a session-aware opening reaction (referring to
        // what just happened last round) takes precedence over ordinary
        // idle chatter — it's shown immediately here via the same single
        // bubble element/timer idle chatter itself uses (makeChatterBubble
        // above), so there is only ever one bubble on screen; idle
        // chatter's own first remark can't fire for another 12-20s
        // (idle-chatter.js), well after this reaction's own
        // CHATTER_BUBBLE_DISPLAY_MS auto-hide, and any real player action
        // hides it immediately (handleLetter/handleBuyHint/submitSolve
        // already call skippyChatterBubble.hide()) — normal idle behavior
        // resumes right after, unchanged. Returns null (no bubble shown)
        // on a fresh session's Round 1 and on any round that isn't
        // otherwise interesting — see skippy-memory.js.
        var openingReaction = LastWordSkippyMemory.pickOpeningReaction(skippyMemory);
        if (openingReaction) {
            skippyChatterBubble.show(openingReaction);
        } else {
            skippyChatterBubble.hide();
        }
        idleChatter.resetIdle();
    }

    function goToRound(roundNumber) {
        if (roundNumber === 1) {
            showOnly('categorySelect');
            return;
        }
        if (roundNumber === 3) {
            var offered = LastWordSession.offerCategoryChoices(
                LastWordContent.listCategories(puzzles), session.categoriesUsed, 3
            );
            el.roundOfferTitle.textContent = ROUND_LABELS[3] + ': choose a category';
            el.roundOfferList.innerHTML = '';
            offered.forEach(function (category) {
                var button = document.createElement('button');
                button.textContent = category;
                button.onclick = function () {
                    beginRoundPlay(LastWordSession.dealRound(session, puzzles, 3, category));
                };
                el.roundOfferList.appendChild(button);
            });
            showOnly('roundOffer');
            return;
        }

        pendingCategory = LastWordSession.pickAutoCategory(LastWordContent.listCategories(puzzles), session.categoriesUsed);
        el.transitionTitle.textContent = ROUND_LABELS[roundNumber];
        el.transitionBody.textContent = (roundNumber === 2 ? 'Dealer picks: ' : 'Wildcard: ') + pendingCategory;
        el.transitionContinue.onclick = function () {
            beginRoundPlay(LastWordSession.dealRound(session, puzzles, roundNumber, pendingCategory));
        };
        showOnly('transition');
    }

    // ---------------------------------------------------------------------
    // Final Hangman (M1D)
    // ---------------------------------------------------------------------

    function beginFinal() {
        var dealResult = LastWordSession.dealFinal(session, puzzles);
        session = dealResult.session;
        finalPuzzle = dealResult.puzzle;
        finalState = session.finalState;
        pendingOpeningCount = null;
        pendingOpeningLetters = [];

        el.finalIntroCategory.textContent = finalPuzzle.category;
        el.finalIntroScore.textContent = String(session.cumulativeScore);
        renderBoardInto(el.finalIntroBoard, finalPuzzle, finalState); // fully blank — nothing revealed yet

        renderOpeningCountButtons();
        el.finalOpeningPicker.className = 'hidden';
        el.finalOpeningChoice.className = '';

        showOnly('finalIntro');
    }

    function renderOpeningCountButtons() {
        el.finalOpeningCountButtons.innerHTML = '';
        [0, 1, 2, 3].forEach(function (count) {
            var cost = LastWordFinal.openingCostFor(count);
            var button = document.createElement('button');
            button.textContent = count === 0 ? '0 letters (free)' : count + ' letter' + (count > 1 ? 's' : '') + ' (' + cost + ')';
            if (cost > session.cumulativeScore) {
                button.disabled = true;
            }
            button.onclick = function () { chooseOpeningCount(count); };
            el.finalOpeningCountButtons.appendChild(button);
        });
    }

    function chooseOpeningCount(count) {
        pendingOpeningCount = count;
        pendingOpeningLetters = [];

        if (count === 0) {
            commitOpeningChoice();
            return;
        }

        el.finalOpeningPickerPrompt.textContent = 'Choose ' + count + ' letter' + (count > 1 ? 's' : '') + ' (' + LastWordFinal.openingCostFor(count) + ' points)';
        renderOpeningLetterGrid();
        el.finalOpeningPicker.className = '';
    }

    function renderOpeningLetterGrid() {
        el.finalOpeningLetterGrid.innerHTML = '';
        for (var i = 65; i <= 90; i++) {
            var letter = String.fromCharCode(i);
            var button = document.createElement('button');
            button.textContent = letter;
            var alreadyPicked = pendingOpeningLetters.indexOf(letter) !== -1;
            if (alreadyPicked) {
                button.disabled = true;
                button.classList.add('correct');
            } else if (pendingOpeningLetters.length >= pendingOpeningCount) {
                button.disabled = true;
            }
            button.onclick = function (ltr) {
                return function () { pickOpeningLetter(ltr); };
            }(letter);
            el.finalOpeningLetterGrid.appendChild(button);
        }
    }

    function pickOpeningLetter(letter) {
        if (pendingOpeningLetters.length >= pendingOpeningCount) return;
        if (pendingOpeningLetters.indexOf(letter) !== -1) return;
        pendingOpeningLetters.push(letter);
        if (pendingOpeningLetters.length === pendingOpeningCount) {
            commitOpeningChoice();
            return;
        }
        renderOpeningLetterGrid();
    }

    function commitOpeningChoice() {
        var result = LastWordFinal.purchaseOpening(finalState, finalPuzzle, pendingOpeningLetters, session.cumulativeScore);
        if (!result.changed) {
            // Should not happen (the count buttons are pre-disabled when unaffordable), but fail safely.
            renderOpeningCountButtons();
            el.finalOpeningPicker.className = 'hidden';
            return;
        }
        finalState = result.finalState;
        beginFinalPlay();
    }

    function beginFinalPlay() {
        el.finalSolveToggle.disabled = false;
        el.finalSolveForm.className = 'solveForm hidden';
        el.finalStatusLine.textContent = 'Final Hangman — every additional letter costs 250. Solve any time.';
        finalPanicState.line = null;
        finalPanicState.entered5 = false;
        showOnly('finalPlay');
        renderFinalPlayingState();
        // SKIPPY REMEMBERS: ONE restrained Final-aware opening reaction,
        // reflecting the accumulated four-round session — same
        // precedence-over-idle-chatter reasoning as beginRoundPlay()
        // above. Unlike the per-round reaction this always returns a
        // line (see skippy-memory.js's pickFinalReaction header).
        finalSkippyChatterBubble.show(LastWordSkippyMemory.pickFinalReaction(skippyMemory));
        finalIdleChatter.resetIdle();
    }

    function finalAvailableScore() {
        return session.cumulativeScore - finalState.spentThisFinal;
    }

    function renderFinalPlayingState() {
        renderBoardInto(el.finalBoard, finalPuzzle, finalState);
        renderFinalLetters();
        el.finalStrikeCount.textContent = String(finalState.strikes);
        el.finalCategoryLabel.textContent = finalPuzzle.category;
        el.finalScoreRemaining.textContent = String(finalAvailableScore());
        finalCurrentSkippyState = renderSkippyOn(el.finalGallows, finalState.strikes, false, finalPanicState);
    }

    function renderFinalLetters() {
        el.finalLetters.innerHTML = '';
        var used = LastWordFinal.usedLetters(finalState);
        for (var i = 65; i <= 90; i++) {
            var letter = String.fromCharCode(i);
            var button = document.createElement('button');
            button.textContent = letter + ' (250)';

            var alreadyUsed = used.indexOf(letter) !== -1;
            if (alreadyUsed) {
                button.disabled = true;
                var wasCorrect = finalState.revealedLetters.indexOf(letter) !== -1;
                button.classList.add(wasCorrect ? 'correct' : 'wrong');
            } else if (LastWordFinal.isFinalOver(finalState)) {
                button.disabled = true;
            } else if (!LastWordFinal.canAffordAdditionalLetter(finalAvailableScore())) {
                button.disabled = true;
            }

            button.onclick = function (ltr) {
                return function () { handleFinalLetter(ltr); };
            }(letter);

            el.finalLetters.appendChild(button);
        }
    }

    function handleFinalLetter(letter) {
        if (LastWordFinal.isFinalOver(finalState)) return;
        finalIdleChatter.resetIdle();
        finalSkippyChatterBubble.hide();
        var result = LastWordFinal.purchaseAdditionalLetter(finalState, finalPuzzle, letter, finalAvailableScore());
        if (!result.changed) {
            if (result.reason === 'insufficient-score') el.finalStatusLine.textContent = 'Not enough score remaining to buy a letter (250 needed).';
            return;
        }
        finalState = result.finalState;
        el.finalStatusLine.textContent = result.present
            ? 'Bought ' + letter + ' — it was there!'
            : 'Bought ' + letter + ' — not in the puzzle. (Cost is charged either way, no strike.)';

        // CORE-GAME FIX: reported in Final Hangman specifically (GALILEO
        // GALILEI fully revealed but not recognized as solved) — a
        // purchased letter that completes the board must finish Final right
        // here, not leave the player to retype an already-visible answer.
        // See js/lastword/auto-solve.js.
        finalState = LastWordAutoSolve.applyFinalAutoSolve(finalState, finalPuzzle);
        if (LastWordFinal.isFinalOver(finalState)) {
            finishFinalRound();
            return;
        }
        renderFinalPlayingState();
    }

    function submitFinalSolve() {
        if (finalSolveSubmitting || LastWordFinal.isFinalOver(finalState)) return;
        var text = el.finalSolveInput.value.trim();
        if (!text) return;

        finalIdleChatter.resetIdle();
        finalSkippyChatterBubble.hide();
        finalSolveSubmitting = true;
        el.finalSolveSubmit.disabled = true;

        try {
            var result = LastWordFinal.attemptSolveFinal(finalState, finalPuzzle, text);
            finalState = result.finalState;
            el.finalSolveInput.value = '';
            el.finalStatusLine.textContent = result.correct
                ? 'Correct! +5000 for the Final.'
                : 'Not quite — that\'s +2 strikes.';
        } catch (err) {
            el.finalStatusLine.textContent = 'Something went wrong submitting that solve — please reload and try again.';
            if (typeof console !== 'undefined' && console.error) console.error('Last Word Final solve error:', err);
            return;
        } finally {
            finalSolveSubmitting = false;
            el.finalSolveSubmit.disabled = false;
        }

        if (LastWordFinal.isFinalOver(finalState)) {
            finishFinalRound();
            return;
        }
        renderFinalPlayingState();
    }

    function finishFinalRound() {
        var scoreEnteringFinal = session.cumulativeScore;
        finalIdleChatter.stop();
        finalSkippyChatterBubble.hide();
        var finishedFinalState = finalState;
        var solved = finishedFinalState.outcome === 'solved';
        finalCurrentSkippyState = renderSkippyOn(el.finalGallows, finishedFinalState.strikes, solved, finalPanicState);
        session = LastWordSession.finishFinal(session, finalState);
        setTimeout(function () {
            renderGameComplete(scoreEnteringFinal, finishedFinalState);
        }, payoffDelayFor(solved));
    }

    function renderGameComplete(scoreEnteringFinal, finishedFinalState) {
        var solved = finishedFinalState.outcome === 'solved';
        var bonus = solved ? LastWordFinal.FINAL_CONFIG.correctSolveBonus : 0;
        var finalScore = LastWordFinal.computeFinalScore(scoreEnteringFinal, finishedFinalState);

        el.gameCompleteTitle.textContent = solved ? 'Game Complete — Final Solved! 🎉' : 'Game Complete — Final Failed';

        var lines = [];
        lines.push(line(solved ? 'rrSolved' : 'rrFailed', (solved ? '✅ ' : '❌ ') + 'Answer: ' + finalPuzzle.answer));
        lines.push(line('', 'Score entering Final: ' + scoreEnteringFinal));
        lines.push(line('', 'Spent in Final: ' + finishedFinalState.spentThisFinal));
        lines.push(line('', 'Final bonus: ' + bonus));
        lines.push(line('', 'FINAL SCORE: ' + finalScore));

        el.gameCompleteBody.innerHTML = '';
        lines.forEach(function (l) { el.gameCompleteBody.appendChild(l); });

        showOnly('gameComplete');
    }

    // ---------------------------------------------------------------------

    function startNewSession() {
        idleChatter.stop();
        finalIdleChatter.stop();
        skippyChatterBubble.hide();
        finalSkippyChatterBubble.hide();
        session = LastWordState.createSession();
        finalState = null;
        finalPuzzle = null;
        skippyMemory = LastWordSkippyMemory.createMemory();
        hintsPurchasedThisRound = 0;
        roundAwareness = LastWordSituationalAwareness.createRoundAwareness();
        bobIntroducedThisRound = false;
        goToRound(1);
    }

    function bindStaticControls() {
        el.solveToggle.onclick = function () {
            var showing = el.solveForm.className.indexOf('hidden') === -1;
            el.solveForm.className = showing ? 'solveForm hidden' : 'solveForm';
            if (!showing) el.solveInput.focus();
            idleChatter.resetIdle();
        };
        el.solveSubmit.onclick = submitSolve;
        el.buyHintButton.onclick = handleBuyHint;
        el.solveInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                submitSolve();
            }
        });

        el.finalSolveToggle.onclick = function () {
            var showing = el.finalSolveForm.className.indexOf('hidden') === -1;
            el.finalSolveForm.className = showing ? 'solveForm hidden' : 'solveForm';
            if (!showing) el.finalSolveInput.focus();
            finalIdleChatter.resetIdle();
        };
        el.finalSolveSubmit.onclick = submitFinalSolve;
        el.finalSolveInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                submitFinalSolve();
            }
        });

        window.addEventListener('keydown', function (e) {
            if (e.ctrlKey || e.metaKey || e.altKey) return;
            if (document.activeElement === el.solveInput || document.activeElement === el.finalSolveInput) return;

            var letter = e.key.toUpperCase();
            if (!/^[A-Z]$/.test(letter)) return;

            if (finalState && !LastWordFinal.isFinalOver(finalState) && el.finalPlay.className === 'layout') {
                handleFinalLetter(letter);
                return;
            }
            if (round && !LastWordRound.isRoundOver(round)) {
                handleLetter(letter);
            }
        });

        el.continueAfterRound.onclick = function () {
            if (LastWordSession.isSessionComplete(session)) {
                beginFinal();
            } else {
                goToRound(session.rounds.length + 1);
            }
        };

        el.playAgain.onclick = startNewSession;
    }

    function renderCategoryList() {
        var categories = LastWordContent.listCategories(puzzles);
        el.categoryList.innerHTML = '';
        categories.forEach(function (category) {
            var button = document.createElement('button');
            button.textContent = category;
            button.onclick = function () {
                beginRoundPlay(LastWordSession.dealRound(session, puzzles, 1, category));
            };
            el.categoryList.appendChild(button);
        });
    }

    function boot() {
        fetch(PUZZLES_URL, { cache: 'no-store' })
            .then(function (res) {
                if (!res.ok) throw new Error('HTTP ' + res.status);
                return res.json();
            })
            .then(function (doc) {
                puzzles = LastWordContent.loadPuzzleSet(doc);
                session = LastWordState.createSession();
                bindStaticControls();
                renderCategoryList();
                showOnly('categorySelect');
            })
            .catch(function (err) {
                el.categoryList.textContent = 'Failed to load puzzle content: ' + err.message;
            });
    }

    boot();
})();
