// Controller behavior adapted from maxwellito/breaklock; see LICENSE.upstream.
import Pattern from './upstream/pattern.js';

export const UPSTREAM_REVISION = 'a06fb28a3fa6072a089ca664c66a7bf08c0a3e99';
const RESTORING = Symbol('restoring');
const clone = value => JSON.parse(JSON.stringify(value));
const requireValue = (condition, message) => {
    if (!condition) throw new TypeError(message);
};
const integer = (value, min, max) => Number.isSafeInteger(value) && value >= min && value <= max;
const modeValid = mode => ['practice', 'challenge', 'countdown'].includes(mode);
const delayValid = delay => delay === null || integer(delay, 0, 1000);

/** DOM-free round controller. Callbacks are delivered explicitly, never caught up. */
export class BreakLockRound {
    #secret;
    #draft;
    #state = {
        schemaVersion: 1,
        upstreamRevision: UPSTREAM_REVISION,
        mode: null,
        dotLength: null,
        history: [],
        ended: 'active',
        counterValue: null,
        timer: { remainingTicks: null, running: false, nextTickDelayMs: null },
        summary: { visible: false, success: null, attemptCount: null },
        statusDisplay: 'counter',
        pendingGuessResetDelayMs: null,
    };

    constructor(options = {}) {
        if (options !== RESTORING) this.start(options.mode ?? 'practice', options.dotLength ?? 4);
    }

    get attempts() {
        return this.#state.history.filter(entry => entry.type === 'guess').length;
    }

    /** GameCtrl.start: deliberately retains existing summary/reset/clock lifecycle. */
    start(mode = this.#state.mode, dotLength = this.#state.dotLength) {
        requireValue(modeValid(mode) && [4, 5, 6].includes(dotLength), 'Invalid mode or difficulty');
        const secret = new Pattern(dotLength);
        secret.fillRandomly();
        this.#secret = secret;
        this.#draft = new Pattern(dotLength);
        Object.assign(this.#state, { mode, dotLength, history: [], ended: 'active' });
        if (mode === 'countdown') {
            const timer = this.#state.timer;
            timer.remainingTicks = 60;
            if (!timer.running) timer.nextTickDelayMs = 1000;
            timer.running = true;
            this.#state.statusDisplay = 'countdown';
        } else {
            this.#state.counterValue = mode === 'practice' ? 0 : 10;
            this.#state.statusDisplay = 'counter';
        }
        return { type: 'started' };
    }

    /** Summary NEW_GAME starts the same mode/difficulty, then toggles the overlay. */
    newGame() {
        this.start();
        this.#state.summary.visible = !this.#state.summary.visible;
        return { type: 'started' };
    }

    /** Begin a gesture, cancel a draft, or deliver its pending one-second reset. */
    clearDraft() {
        this.#draft.reset();
        this.#state.pendingGuessResetDelayMs = null;
        return { type: 'draft-reset' };
    }

    /** Releasing an incomplete gesture clears it; submitted feedback remains briefly. */
    endGesture() {
        if (this.#state.pendingGuessResetDelayMs === null) return this.clearDraft();
        return { type: 'pending-reset' };
    }

    /** LockCtrl.updatePoint/triggerDot/checkPattern, with Pattern owning all mechanics. */
    select(position) {
        requireValue(integer(position, 0, 8), 'Invalid position');
        if (this.#state.pendingGuessResetDelayMs !== null) {
            return { type: 'selection', added: [], attempt: null, blocked: 'pending-reset' };
        }
        const added = this.#draft.addDot(position);
        const attempt = added.length && this.#draft.isComplete() ? this.#submit() : null;
        return { type: 'selection', added, attempt };
    }

    #submit() {
        const feedback = this.#secret.compare(this.#draft);
        const matched = feedback[0] === this.#secret.dotLength;
        const count = this.attempts + 1;
        if (this.#state.ended !== 'active') {
            this.#state.counterValue++;
        } else if (matched) {
            if (this.#state.mode === 'countdown') this.#stopClock();
            this.#showResult(true, count);
        } else if (this.#state.mode === 'practice') {
            this.#state.counterValue++;
        } else if (this.#state.mode === 'challenge') {
            if (--this.#state.counterValue === 0) this.#showResult(false, count);
        }
        this.#state.history.push({ type: 'guess', sequence: [...this.#draft.suite] });
        this.#state.pendingGuessResetDelayMs = 1000;
        return { matched, feedback, count, ended: this.#state.ended };
    }

    #showResult(success, count) {
        this.#state.ended = success ? 'won' : 'lost';
        this.#state.summary = { visible: true, success, attemptCount: count };
    }

    #stopClock() {
        this.#state.timer.running = false;
        this.#state.timer.nextTickDelayMs = null;
    }

    /** One delivered interval callback, even when another mode now owns the round. */
    tick() {
        const timer = this.#state.timer;
        if (!timer.running) return { type: 'clock-stopped' };
        timer.remainingTicks = Math.max(0, timer.remainingTicks - 1);
        if (timer.remainingTicks === 0) {
            this.#stopClock();
            this.#showResult(false, this.attempts);
            return { type: 'timeout' };
        }
        timer.nextTickDelayMs = 1000;
        return { type: 'tick', remainingTicks: timer.remainingTicks };
    }

    /** Summary SOLUTION: reveal only on loss, switch counter, toggle summary. */
    reveal() {
        requireValue(this.#state.ended !== 'active', 'No result to reveal');
        const revealed = this.#state.ended === 'lost';
        if (revealed) this.#state.history.push({ type: 'reveal' });
        this.#state.counterValue = this.attempts;
        this.#state.statusDisplay = 'counter';
        this.#state.summary.visible = !this.#state.summary.visible;
        return { type: 'solution', revealed };
    }

    /** Navigation is an effect only. The existing interval deliberately survives. */
    home({ fromSummary = false } = {}) {
        if (fromSummary) this.#state.summary.visible = !this.#state.summary.visible;
        return { type: 'home' };
    }

    /** Derive presentation feedback using Pattern; reveal entries are not attempts. */
    history() {
        return this.#state.history.map(entry => {
            const sequence = entry.type === 'reveal' ? this.#secret.suite : entry.sequence;
            const pattern = new Pattern(this.#state.dotLength);
            sequence.forEach(position => pattern.addDot(position));
            return { type: entry.type, sequence: [...sequence], feedback: this.#secret.compare(pattern) };
        });
    }

    /** Scheduler may supply residual delays at suspension; no elapsed ticks are inferred. */
    snapshot(delays = {}) {
        const state = clone(this.#state);
        state.secret = [...this.#secret.suite];
        state.draft = [...this.#draft.suite];
        if ('nextTickDelayMs' in delays) {
            requireValue(state.timer.running && integer(delays.nextTickDelayMs, 0, 1000), 'Invalid clock delay');
            state.timer.nextTickDelayMs = delays.nextTickDelayMs;
        }
        if ('pendingGuessResetDelayMs' in delays) {
            requireValue(state.pendingGuessResetDelayMs !== null && integer(delays.pendingGuessResetDelayMs, 0, 1000), 'Invalid reset delay');
            state.pendingGuessResetDelayMs = delays.pendingGuessResetDelayMs;
        }
        return state;
    }

    /** Restore JSON state without generating a secret, executing callbacks or owning I/O. */
    static restore(input) {
        const s = clone(input);
        requireValue(s && s.schemaVersion === 1 && s.upstreamRevision === UPSTREAM_REVISION, 'Unsupported snapshot');
        requireValue(modeValid(s.mode) && [4, 5, 6].includes(s.dotLength), 'Invalid round');
        const pattern = (sequence, complete) => {
            requireValue(Array.isArray(sequence) && sequence.every(p => integer(p, 0, 8)), 'Invalid sequence');
            const result = new Pattern(s.dotLength);
            sequence.forEach(p => result.addDot(p));
            requireValue(JSON.stringify(result.suite) === JSON.stringify(sequence), 'Noncanonical sequence');
            requireValue(!complete || result.isComplete(), 'Incomplete pattern');
            return result;
        };
        const secret = pattern(s.secret, true), draft = pattern(s.draft, false);
        requireValue(Array.isArray(s.history), 'Invalid history');
        s.history.forEach(entry => {
            requireValue(entry && ['guess', 'reveal'].includes(entry.type), 'Invalid history entry');
            if (entry.type === 'guess') pattern(entry.sequence, true);
        });
        requireValue(['active', 'won', 'lost'].includes(s.ended), 'Invalid result');
        requireValue(s.counterValue === null || integer(s.counterValue, 0, Number.MAX_SAFE_INTEGER), 'Invalid counter');
        requireValue(s.timer && typeof s.timer.running === 'boolean' && delayValid(s.timer.nextTickDelayMs), 'Invalid timer');
        requireValue(s.timer.remainingTicks === null || integer(s.timer.remainingTicks, 0, 60), 'Invalid ticks');
        requireValue(s.timer.running ? s.timer.remainingTicks > 0 && s.timer.nextTickDelayMs !== null : s.timer.nextTickDelayMs === null, 'Invalid timer lifecycle');
        requireValue(s.summary && typeof s.summary.visible === 'boolean' && [null, true, false].includes(s.summary.success), 'Invalid summary');
        requireValue(s.summary.attemptCount === null || integer(s.summary.attemptCount, 0, Number.MAX_SAFE_INTEGER), 'Invalid summary count');
        requireValue(['counter', 'countdown'].includes(s.statusDisplay) && delayValid(s.pendingGuessResetDelayMs), 'Invalid presentation');
        requireValue(!draft.isComplete() || s.pendingGuessResetDelayMs !== null, 'Completed draft requires pending reset');
        const round = new BreakLockRound(RESTORING);
        // Keep only the versioned schema, not arbitrary properties from the input.
        for (const key of Object.keys(round.#state)) round.#state[key] = s[key];
        round.#secret = secret;
        round.#draft = draft;
        return round;
    }
}
