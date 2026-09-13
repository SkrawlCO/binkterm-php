/**
 * Last Word — M1D DOM wiring: four-round session + Final Hangman (browser-only).
 *
 * All rules/outcomes live in content.js/state.js/round.js/final.js/session.js
 * (pure, DOM-free); this file only renders that state and forwards DOM
 * events. Rounds 1-4 are copied unchanged from M1C's app-session.js (left
 * untouched so the already human-accepted M1C build stays exactly as it was
 * tested) — the only behavioral change is what happens after Round 4:
 * Continue now leads into Final Hangman instead of straight to a session
 * summary.
 *
 * M1D scope: the complete game (4 rounds + Final). No persistence, no
 * stats, no leaderboard.
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
        'game', 'gallows', 'strikeCount', 'roundLabel', 'categoryLabel',
        'cumulativeScore', 'roundScore', 'solveBonus', 'decayHint',
        'board', 'statusLine', 'letters', 'valuesHint',
        'solveToggle', 'solveForm', 'solveInput', 'solveSubmit',
        'round-result', 'roundResultTitle', 'roundResultBody', 'continueAfterRound',
        'final-intro', 'finalIntroCategory', 'finalIntroScore', 'finalIntroBoard',
        'finalOpeningChoice', 'finalOpeningCountButtons',
        'finalOpeningPicker', 'finalOpeningPickerPrompt', 'finalOpeningLetterGrid',
        'final-play', 'finalGallows', 'finalStrikeCount', 'finalCategoryLabel', 'finalScoreRemaining',
        'finalBoard', 'finalStatusLine', 'finalLetters',
        'finalSolveToggle', 'finalSolveForm', 'finalSolveInput', 'finalSolveSubmit',
        'game-complete', 'gameCompleteTitle', 'gameCompleteBody', 'playAgain'
    ].forEach(function (id) {
        var camel = id.replace(/-([a-z])/g, function (_, c) { return c.toUpperCase(); });
        el[camel] = document.getElementById(id);
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

    function drawGallowsOn(canvasEl, strikes) {
        var ctx = canvasEl.getContext('2d');
        ctx.clearRect(0, 0, canvasEl.width, canvasEl.height);
        ctx.lineWidth = 5;
        ctx.strokeStyle = 'rgba(231,238,252,0.9)';

        ctx.beginPath();
        ctx.moveTo(30, 230); ctx.lineTo(130, 230);
        ctx.moveTo(70, 230); ctx.lineTo(70, 30);
        ctx.lineTo(190, 30);
        ctx.lineTo(190, 65);
        ctx.stroke();

        if (strikes >= 1) { ctx.beginPath(); ctx.arc(190, 85, 20, 0, Math.PI * 2); ctx.stroke(); }
        if (strikes >= 2) { ctx.beginPath(); ctx.moveTo(190, 105); ctx.lineTo(190, 165); ctx.stroke(); }
        if (strikes >= 3) { ctx.beginPath(); ctx.moveTo(190, 120); ctx.lineTo(165, 140); ctx.stroke(); }
        if (strikes >= 4) { ctx.beginPath(); ctx.moveTo(190, 120); ctx.lineTo(215, 140); ctx.stroke(); }
        if (strikes >= 5) { ctx.beginPath(); ctx.moveTo(190, 165); ctx.lineTo(168, 200); ctx.stroke(); }
        if (strikes >= 6) { ctx.beginPath(); ctx.moveTo(190, 165); ctx.lineTo(212, 200); ctx.stroke(); }
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
        drawGallowsOn(el.gallows, round.strikes);
    }

    function renderPlayingState() {
        renderBoardInto(el.board, puzzle, round);
        renderLetters();
        renderScoreboard();
    }

    function handleLetter(letter) {
        if (LastWordRound.isRoundOver(round)) return;

        if (LastWordContent.isConsonant(letter)) {
            var result = LastWordRound.guessConsonant(round, puzzle, letter, roundConfig);
            if (!result.changed) return;
            round = result.roundState;
            setStatus(result.correct
                ? letter + ' is in the puzzle! +' + (result.occurrences * roundConfig.consonantValue) + ' points.'
                : letter + ' is not in the puzzle. +1 strike.');
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
        }

        if (LastWordRound.isRoundOver(round)) {
            finishCurrentRound();
            return;
        }
        renderPlayingState();
    }

    function submitSolve() {
        if (solveSubmitting || !round || LastWordRound.isRoundOver(round)) return;
        var text = el.solveInput.value.trim();
        if (!text) return;

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
        renderPlayingState();
    }

    function finishCurrentRound() {
        var finishedRound = round;
        session = LastWordSession.finishRound(session, finishedRound);
        renderRoundResult(finishedRound);
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
        showOnly('game');
        renderPlayingState();
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
        showOnly('finalPlay');
        renderFinalPlayingState();
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
        drawGallowsOn(el.finalGallows, finalState.strikes);
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
        var result = LastWordFinal.purchaseAdditionalLetter(finalState, finalPuzzle, letter, finalAvailableScore());
        if (!result.changed) {
            if (result.reason === 'insufficient-score') el.finalStatusLine.textContent = 'Not enough score remaining to buy a letter (250 needed).';
            return;
        }
        finalState = result.finalState;
        el.finalStatusLine.textContent = result.present
            ? 'Bought ' + letter + ' — it was there!'
            : 'Bought ' + letter + ' — not in the puzzle. (Cost is charged either way, no strike.)';
        renderFinalPlayingState();
    }

    function submitFinalSolve() {
        if (finalSolveSubmitting || LastWordFinal.isFinalOver(finalState)) return;
        var text = el.finalSolveInput.value.trim();
        if (!text) return;

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
        session = LastWordSession.finishFinal(session, finalState);
        renderGameComplete(scoreEnteringFinal, finalState);
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
        session = LastWordState.createSession();
        finalState = null;
        finalPuzzle = null;
        goToRound(1);
    }

    function bindStaticControls() {
        el.solveToggle.onclick = function () {
            var showing = el.solveForm.className.indexOf('hidden') === -1;
            el.solveForm.className = showing ? 'solveForm hidden' : 'solveForm';
            if (!showing) el.solveInput.focus();
        };
        el.solveSubmit.onclick = submitSolve;
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
