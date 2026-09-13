/**
 * Last Word — M1B DOM wiring (browser-only).
 *
 * Everything actually deciding game outcomes lives in content.js/state.js/
 * round.js (pure, DOM-free). This file only renders that state and forwards
 * DOM events into the rules engine — kept intentionally thin so a future
 * Telnet client could drive the same rules engine without this file.
 *
 * M1B scope: ONE playable Round 1. No four-round progression, no Final
 * Hangman, no save/resume wiring (deferred — see the M1B report).
 */
(function () {
    'use strict';

    var PUZZLES_URL = 'lastword/puzzles.json';

    var puzzles = [];
    var session = null;   // LastWordState session (round/cumulativeScore tracked even though M1B only plays round 1)
    var round = null;     // current RoundState
    var puzzle = null;    // current puzzle object
    var solveSubmitting = false; // debounce guard against double-submit

    var el = {
        categorySelect: document.getElementById('category-select'),
        categoryList: document.getElementById('categoryList'),
        game: document.getElementById('game'),
        gallows: document.getElementById('gallows'),
        strikeCount: document.getElementById('strikeCount'),
        categoryLabel: document.getElementById('categoryLabel'),
        score: document.getElementById('score'),
        solveBonus: document.getElementById('solveBonus'),
        board: document.getElementById('board'),
        statusLine: document.getElementById('statusLine'),
        letters: document.getElementById('letters'),
        solveToggle: document.getElementById('solveToggle'),
        solveForm: document.getElementById('solveForm'),
        solveInput: document.getElementById('solveInput'),
        solveSubmit: document.getElementById('solveSubmit'),
        result: document.getElementById('result'),
        postRound: document.getElementById('postRound'),
        newPuzzleSameCategory: document.getElementById('newPuzzleSameCategory'),
        changeCategory: document.getElementById('changeCategory')
    };

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
                span.textContent = cell.char === ' ' ? '  ' : cell.char;
            } else if (cell.revealed) {
                span.className = 'cell';
                span.textContent = cell.char;
            } else {
                span.className = 'cell blank';
                span.textContent = '  ';
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
            button.textContent = isVowel ? letter + ' (100)' : letter;
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
            } else if (isVowel && !LastWordRound.canAffordVowel(availableScore())) {
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
        el.score.textContent = String(availableScore());
        el.solveBonus.textContent = String(LastWordRound.computeSolveBonus(round));
        drawGallows(round.strikes);
    }

    function renderRoundEndIfAny() {
        if (!LastWordRound.isRoundOver(round)) {
            el.result.className = 'result hidden';
            el.postRound.className = 'postRound hidden';
            return;
        }

        el.solveToggle.disabled = true;
        el.solveForm.className = 'solveForm hidden';

        if (round.outcome === 'solved') {
            el.result.className = 'result win';
            el.result.textContent = '✅ Solved! "' + puzzle.answer + '" — round score: ' + round.pointsThisRound;
        } else {
            el.result.className = 'result lose';
            el.result.textContent = '💀 Out of strikes. The answer was: ' + puzzle.answer;
        }
        el.postRound.className = 'postRound';
    }

    function renderAll() {
        renderBoard();
        renderLetters();
        renderScoreboard();
        renderRoundEndIfAny();
    }

    function handleLetter(letter) {
        if (LastWordRound.isRoundOver(round)) return;

        if (LastWordContent.isConsonant(letter)) {
            var result = LastWordRound.guessConsonant(round, puzzle, letter);
            if (!result.changed) return;
            round = result.roundState;
            setStatus(result.correct
                ? letter + ' is in the puzzle! +' + (result.occurrences * LastWordRound.ROUND1_CONFIG.consonantValue) + ' points.'
                : letter + ' is not in the puzzle. +1 strike.');
        } else {
            var vResult = LastWordRound.purchaseVowel(round, puzzle, letter, availableScore());
            if (!vResult.changed) {
                if (vResult.reason === 'insufficient-score') setStatus('Not enough score to buy a vowel (100 needed).');
                return;
            }
            round = vResult.roundState;
            setStatus(vResult.present
                ? 'Bought ' + letter + ' — it was there!'
                : 'Bought ' + letter + ' — not in the puzzle. (Cost is charged either way.)');
        }

        renderAll();
    }

    function submitSolve() {
        if (solveSubmitting || LastWordRound.isRoundOver(round)) return;
        var text = el.solveInput.value.trim();
        if (!text) return;

        solveSubmitting = true;
        el.solveSubmit.disabled = true;

        var result = LastWordRound.attemptSolve(round, puzzle, text);
        round = result.roundState;
        el.solveInput.value = '';

        if (result.correct) {
            setStatus('Correct! Solve bonus: ' + result.bonusAwarded + ' points.');
        } else {
            setStatus('Not quite — that’s +2 strikes.');
        }

        renderAll();
        solveSubmitting = false;
        el.solveSubmit.disabled = false;
    }

    function bindGameControls() {
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
            if (document.activeElement === el.solveInput) return; // typing a solve guess
            var letter = e.key.toUpperCase();
            if (!/^[A-Z]$/.test(letter)) return;
            handleLetter(letter);
        });

        el.newPuzzleSameCategory.onclick = function () {
            dealPuzzle(puzzle.category);
        };
        el.changeCategory.onclick = function () {
            el.game.className = 'layout hidden';
            el.categorySelect.className = 'panel';
        };
    }

    function dealPuzzle(category) {
        puzzle = LastWordContent.pickRandom(puzzles, { category: category });
        round = LastWordState.createRoundState(1, puzzle.id, puzzle.category);
        el.categoryLabel.textContent = puzzle.category;
        el.categorySelect.className = 'panel hidden';
        el.game.className = 'layout';
        setStatus('New Round 1 puzzle dealt. Guess a consonant, buy a vowel, or SOLVE.');
        renderAll();
    }

    function renderCategoryList() {
        var categories = LastWordContent.listCategories(puzzles);
        el.categoryList.innerHTML = '';
        categories.forEach(function (category) {
            var button = document.createElement('button');
            button.textContent = category;
            button.onclick = function () { dealPuzzle(category); };
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
                renderCategoryList();
                bindGameControls();
            })
            .catch(function (err) {
                el.categoryList.textContent = 'Failed to load puzzle content: ' + err.message;
            });
    }

    boot();
})();
