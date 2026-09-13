/**
 * Last Word — M1C DOM wiring for the four-round session (browser-only).
 *
 * All rules/outcomes live in content.js/state.js/round.js/session.js (pure,
 * DOM-free); this file only renders that state and forwards DOM events —
 * same separation established in M1B's app.js, extended across round
 * transitions instead of one isolated round. Separate from app.js/
 * lastword-m1b.html on purpose, so the already human-accepted M1B build
 * stays exactly as it was tested.
 *
 * M1C scope: four normal rounds, then a temporary session-complete screen.
 * No Final Hangman, no save/resume.
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
        'session-complete', 'sessionSummary', 'playAgain'
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
        ['categorySelect', 'roundOffer', 'transition', 'game', 'roundResult', 'sessionComplete'].forEach(function (key) {
            var target = el[key];
            var isMain = key === 'game';
            target.className = (isMain ? 'layout' : 'panel') + (key === panelKey ? '' : ' hidden');
        });
    }

    function setStatus(msg) {
        el.statusLine.textContent = msg || '';
    }

    function availableScore() {
        return session.cumulativeScore + round.pointsThisRound;
    }

    function drawGallows(strikes) {
        var ctx = el.gallows.getContext('2d');
        ctx.clearRect(0, 0, el.gallows.width, el.gallows.height);
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

    function renderBoard() {
        var cells = LastWordRound.buildDisplayBoard(puzzle, round);
        el.board.innerHTML = '';
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
            el.board.appendChild(span);
        });
    }

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
        drawGallows(round.strikes);
    }

    function renderPlayingState() {
        renderBoard();
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

        // Hardening (found via a real M1C defect: a stale service-worker-cached
        // round.js missing a function this call depends on left the button
        // disabled forever with the round frozen, because the exception was
        // thrown before the reset below ever ran). try/finally guarantees the
        // control is always re-enabled — a future mismatch like that fails
        // loudly and stays playable instead of silently soft-locking the round.
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
            return; // round state is untouched; finally below still re-enables the control
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

        // Round 2 (different category) / Round 4 (wildcard): game auto-picks.
        pendingCategory = LastWordSession.pickAutoCategory(LastWordContent.listCategories(puzzles), session.categoriesUsed);
        el.transitionTitle.textContent = ROUND_LABELS[roundNumber];
        el.transitionBody.textContent = (roundNumber === 2 ? 'Dealer picks: ' : 'Wildcard: ') + pendingCategory;
        el.transitionContinue.onclick = function () {
            beginRoundPlay(LastWordSession.dealRound(session, puzzles, roundNumber, pendingCategory));
        };
        showOnly('transition');
    }

    function renderSessionComplete() {
        el.sessionSummary.innerHTML = '';
        var list = document.createElement('ul');
        list.className = 'sessionHistory';
        session.rounds.forEach(function (r) {
            var item = document.createElement('li');
            var label = ROUND_LABELS[r.round] + ' — ' + r.category + ': ' +
                (r.outcome === 'solved' ? 'Solved' : 'Failed') + ' (' + r.pointsThisRound + ' pts)';
            item.textContent = label;
            list.appendChild(item);
        });
        el.sessionSummary.appendChild(list);
        var total = document.createElement('p');
        total.innerHTML = '<strong>Final cumulative score: ' + session.cumulativeScore + '</strong>';
        el.sessionSummary.appendChild(total);

        showOnly('sessionComplete');
    }

    function startNewSession() {
        session = LastWordState.createSession();
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

        window.addEventListener('keydown', function (e) {
            if (e.ctrlKey || e.metaKey || e.altKey) return;
            if (document.activeElement === el.solveInput) return;
            if (!round || LastWordRound.isRoundOver(round)) return;
            var letter = e.key.toUpperCase();
            if (!/^[A-Z]$/.test(letter)) return;
            handleLetter(letter);
        });

        el.continueAfterRound.onclick = function () {
            if (LastWordSession.isSessionComplete(session)) {
                renderSessionComplete();
            } else {
                // Derived from rounds.length (the same robust, monotonic
                // signal isSessionComplete now uses), not the separate
                // session.round field — belt-and-suspenders against the
                // two ever disagreeing again.
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
