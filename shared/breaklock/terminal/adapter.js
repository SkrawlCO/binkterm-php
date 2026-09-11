import { RoundScheduler } from '../persistence/scheduler.js';
import { BreakLockRound } from '../round.js';

export const WIDTH = 78; // Leave the last two columns free to avoid terminal wrap.
export const HEIGHT = 23;
const PAGE_SIZE = 8;
const modes = ['practice', 'challenge', 'countdown'];
const modeNames = { practice: 'Practice', challenge: 'Challenge', countdown: 'Countdown' };
const difficulties = { 4: 'Easy', 5: 'Medium', 6: 'Hard' };
const keys = sequence => sequence.map(p => p + 1).join('-');
const fit = value => String(value).slice(0, WIDTH);

/** Input/presentation and callback ownership only; Round owns every game transition. */
export class BreakLockTerminal {
    constructor({ onRender = () => {}, onQuit = () => {}, clock = globalThis } = {}) {
        this.onRender = onRender;
        this.onQuit = onQuit;
        this.clock = clock;
        this.round = null;
        this.suspended = false;
        this.scheduler = new RoundScheduler(this, clock);
        this.mode = 'practice';
        this.dotLength = 4;
        this.view = 'menu';
        this.help = false;
        this.page = 0;
        this.message = 'Choose a mode and difficulty, then press Enter.';
        this.closed = false;
        this.interval = null;
        this.resetTimer = null;
    }

    handleKey(input) {
        if (this.closed) return;
        const key = input.toLowerCase();
        if (['q', 'ctrl-c', 'ctrl-d'].includes(key)) return this.quit();
        if (this.suspended) return;
        if (key === '?') { this.help = !this.help; return this.draw(); }
        if (this.help) { this.help = false; return this.draw(); }
        if (this.view === 'menu') {
            if (['1', '2', '3'].includes(key)) this.mode = modes[Number(key) - 1];
            else if (['4', '5', '6'].includes(key)) this.dotLength = Number(key);
            else if (key === 'enter' || key === 'g') {
                if (this.round) this.round.start(this.mode, this.dotLength);
                else this.round = new BreakLockRound({ mode: this.mode, dotLength: this.dotLength });
                this.view = 'game'; this.page = 0; this.message = 'Select dots with keys 1-9.';
            }
            return this.update();
        }
        const state = this.round.snapshot();
        if (key === 'm') {
            this.round.home({ fromSummary: state.summary.visible });
            this.view = 'menu';
            this.message = 'Choose a mode and difficulty, then press Enter.';
        } else if (key === 'n') {
            // New Game is a summary action; outside the overlay use ordinary start.
            if (state.summary.visible) this.round.newGame();
            else this.round.start();
            this.page = 0; this.message = 'New round. Select dots, or C to begin a fresh draft.';
        } else if (key === '[' || key === 'pageup') {
            this.page = Math.min(this.page + 1, Math.max(0, Math.ceil(state.history.length / PAGE_SIZE) - 1));
        } else if (key === ']' || key === 'pagedown') {
            this.page = Math.max(0, this.page - 1);
        } else if (state.summary.visible) {
            if (key === 's') {
                const event = this.round.reveal();
                this.page = 0;
                this.message = event.revealed ? 'Solution added to history. You may continue guessing.'
                    : 'Continue guessing; the recorded result remains unchanged.';
            } else this.message = 'Result: S solution/continue, N new game, M menu, Q quit.';
        } else if (key === 'c') {
            this.round.clearDraft(); this.message = 'Draft cleared.';
        } else if (/^[1-9]$/.test(key)) {
            const event = this.round.select(Number(key) - 1);
            if (event.blocked) this.message = 'Guess displayed briefly. C clears it for a new selection.';
            else if (!event.added.length) this.message = 'Selection unchanged.';
            else {
                this.message = 'Added: ' + keys(event.added);
                if (event.attempt) {
                    this.page = 0;
                    this.message += ' | Feedback (exact/elsewhere/absent): ' + event.attempt.feedback.join('/');
                }
            }
        }
        this.update();
    }

    refresh() { this.update(); }
    update() {
        if (this.closed || this.suspended) return;
        this.scheduler.sync(); this.draw();
    }
    snapshot() { return this.scheduler.snapshot(); }
    suspend() {
        const state = this.snapshot();
        this.suspended = true; this.scheduler.stop();
        if (state) this.round = BreakLockRound.restore(state);
        return state;
    }
    restore(state) {
        this.scheduler.stop();
        this.round = state ? BreakLockRound.restore(state) : null;
        this.suspended = false; this.page = 0;
        if (state) {
            this.mode = state.mode; this.dotLength = state.dotLength; this.view = 'game';
        }
        this.update();
    }

    draw() { if (!this.closed) this.onRender(this.lines()); }

    lines() {
        const lines = Array(HEIGHT).fill('');
        lines[0] = 'BREAKLOCK';
        lines[1] = '='.repeat(WIDTH);
        lines[21] = this.message;
        lines[22] = this.beforeQuit ? '? Help | Q Save & Return' : '? Help | Q Quit (no saved progress in this local slice)';
        if (this.help) {
            Object.assign(lines, {
                3: 'Connect the required number of dots to discover the secret pattern.',
                5: 'Keys 1-9 correspond to the grid, read left to right, top to bottom.',
                6: 'Crossings may add an intermediate dot automatically; watch Current.',
                7: 'A complete guess submits automatically. C begins a fresh draft.',
                9: 'Exact: correct dot and position. Elsewhere: correct dot, wrong position.',
                10: 'Absent: dot is not in the secret. Feedback comes from the shared core.',
                12: '[ / ] or PageUp / PageDown browse older / newer history.',
                13: 'S at a result reveals/continues. N starts a new round. M opens the menu.',
                15: 'Countdown continues in Help and Menu, as in the canonical game.',
                17: this.beforeQuit ? 'Q, Ctrl-C or Ctrl-D save and return.' : 'Q, Ctrl-C or Ctrl-D exit. Nothing is saved by this local adapter.',
                19: 'Press any key to close help; that key will not select a dot.',
            });
        } else if (this.view === 'menu') {
            Object.assign(lines, {
                3: 'Choose mode:       1 Practice     2 Challenge     3 Countdown',
                5: 'Choose difficulty: 4 Easy         5 Medium        6 Hard',
                8: `Selected: ${modeNames[this.mode]} / ${difficulties[this.dotLength]} / ${this.dotLength} dots`,
                11: 'Practice: discover the pattern with unlimited guesses.',
                12: 'Challenge: discover it within ten attempts.',
                13: 'Countdown: discover it before the callback-driven timer reaches zero.',
                16: 'Enter or G starts the selected round.',
            });
            const timer = this.round?.snapshot().timer;
            if (timer?.running) lines[18] = `Previous countdown is still running: ${timer.remainingTicks}`;
        } else {
            const state = this.round.snapshot();
            lines[0] += `                  ${modeNames[state.mode]} / ${difficulties[state.dotLength]} / ${state.dotLength} dots`;
            const counterLabel = state.mode === 'challenge' && state.ended === 'active' ? 'Attempts remaining' : 'Counter';
            lines[2] = state.statusDisplay === 'countdown' ? `Countdown: ${state.timer.remainingTicks} ticks remaining`
                : `${counterLabel}: ${state.counterValue}     Guesses: ${this.round.attempts}`;
            lines[3] = '   1 2 3';
            lines[4] = '   4 5 6       Current: ' + (keys(state.draft) || '_');
            lines[5] = '   7 8 9';
            lines[6] = ' #    Guess                     Exact    Elsewhere    Absent';
            let attempt = 0;
            const history = this.round.history().map(entry => ({ ...entry, label: entry.type === 'guess' ? String(++attempt) : 'SOL' }));
            this.page = Math.min(this.page, Math.max(0, Math.ceil(history.length / PAGE_SIZE) - 1));
            const end = Math.max(0, history.length - this.page * PAGE_SIZE), start = Math.max(0, end - PAGE_SIZE);
            history.slice(start, end).forEach((entry, i) => {
                lines[7 + i] = entry.label.padStart(3) + '   ' + keys(entry.sequence).padEnd(25)
                    + String(entry.feedback[0]).padEnd(9) + String(entry.feedback[1]).padEnd(13) + entry.feedback[2];
            });
            if (!history.length) lines[7] = '      Your completed guesses will appear here.';
            lines[15] = `History ${history.length ? start + 1 : 0}-${end} of ${history.length}    [ Older / ] Newer`;
            if (state.summary.visible) {
                lines[17] = state.summary.success ? 'LOCK OPENED' : 'ROUND LOST';
                lines[18] = `Result recorded after ${state.summary.attemptCount} guesses. S solution/continue.`;
            } else if (state.ended !== 'active') lines[17] = `Continuing after recorded ${state.ended === 'won' ? 'win' : 'loss'}.`;
            lines[19] = '1-9 Select | C Clear | N New round | S Solution/continue | M Menu';
        }
        return lines.map(fit);
    }

    quit() {
        if (this.closed) return;
        if (this.beforeQuit) return this.beforeQuit();
        this.closed = true;
        this.scheduler.stop();
        if (this.interval !== null) this.clock.clearInterval(this.interval);
        if (this.resetTimer !== null) this.clock.clearTimeout(this.resetTimer);
        this.interval = this.resetTimer = null;
        this.onQuit();
    }
}
