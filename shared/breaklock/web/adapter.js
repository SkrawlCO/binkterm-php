import { RoundScheduler } from '../persistence/scheduler.js';
// MIT: presentation adapted from pinned maxwellito/breaklock. See ../LICENSE.upstream.
import { BreakLockRound } from '../round.js';
import Menu from './upstream/src/controllers/menu/menu.ctrl';
import Lock from './upstream/src/controllers/lock/lock.ctrl';
import StatusBar from './upstream/src/controllers/statusBar/statusBar.ctrl';
import History from './upstream/src/controllers/history/history.ctrl';
import Summary from './upstream/src/controllers/summary/summary.ctrl';
import PatternSVG from './upstream/src/utils/patternSVG';
import dom from './upstream/src/utils/dom';
import color from './upstream/src/utils/color';
import leftPadNum from './upstream/src/utils/leftPadNum';
import config from './upstream/src/config';
import './upstream/src/style.scss';
import './upstream/src/controllers/game/game.scss';

const modes = { 1: 'practice', 2: 'challenge', 3: 'countdown' };

/** Keep upstream gesture geometry/drawing; every state transition goes to Round. */
class SharedLock extends Lock {
    constructor(adapter) {
        super(() => {});
        this.adapter = adapter;
        this.init();
    }
    reset() { this.adapter.change(() => this.adapter.round.clearDraft()); }
    triggerDot(position) {
        const event = this.adapter.change(() => this.adapter.round.select(position));
        if (event?.added?.length && navigator.vibrate) navigator.vibrate(20);
    }
    // Use viewport coordinates for the canonical SVG magnet geometry on scrolled pages.
    mouseUpdate(event) {
        event.preventDefault();
        const box = this.el.getBoundingClientRect();
        this.updatePoint((event.clientX - box.left) * 100 / box.width,
            (event.clientY - box.top) * 100 / box.height);
    }
    touchUpdate(event) {
        event.preventDefault();
        const point = event.targetTouches[0];
        if (!point) return;
        const box = this.el.getBoundingClientRect();
        this.updatePoint((point.clientX - box.left) * 100 / box.width,
            (point.clientY - box.top) * 100 / box.height);
    }
    mouseEnd(event) {
        super.mouseEnd(event);
        window.removeEventListener('mouseleave', this.mouseEndBind);
    }
    paint(state, matched) {
        this.currentLine = null;
        this.patternEl.replaceChildren();
        const svg = new PatternSVG();
        const stroke = state.pendingGuessResetDelayMs === null ? config.COLORS.BRIGHT
            : matched ? config.COLORS.SUCCESS : config.COLORS.ERROR;
        if (state.draft.length) {
            this.patternEl.appendChild(svg.addPattern({ suite: state.draft }, 2, stroke));
        }
        this.bigDotsEl.childNodes.forEach((dot, i) => dot.classList.toggle('active', state.draft.includes(i)));
        this.isPendingReset = state.pendingGuessResetDelayMs !== null;
        if (state.draft.length && !this.isPendingReset) {
            const last = state.draft.at(-1);
            this.startLine((last % 3) * 35 + 15, Math.floor(last / 3) * 35 + 15);
        }
    }
}

/** Browser scheduling and rendering only. No counter, outcome or Pattern rules. */
export class BreakLockWeb {
    constructor(container = document.body) {
        this.round = null;
        this.suspended = false;
        this.scheduler = new RoundScheduler(this);
        this.clock = null;
        this.resetTimer = null;
        this.clockDue = null;
        this.resetDue = null;
        this.menu = new Menu((type, length) => this.start(type, length));
        this.menu.init();
        // This bounded build contains English only. Do not leave broken locale links.
        this.menu.el.querySelector('.lang-button')?.remove();
        this.menu.el.querySelector('.lang-selector')?.remove();
        this.status = new StatusBar(() => this.home(false));
        this.status.init();
        this.history = new History();
        this.summary = new Summary(action => this.action(action));
        this.lock = new SharedLock(this);
        this.game = dom.create('div', 'game-layout view', [
            dom.create('div', 'view-bloc game-layout-dashboard', [
                this.status.el, dom.create('div', 'history-wrap', [this.history.el]),
            ]),
            dom.create('div', 'view-bloc game-layout-lock', [this.lock.el]), this.summary.el,
        ]);
        container.append(this.menu.el, this.game);
        this.game.style.display = 'none';
    }
    start(type, length) {
        if (this.suspended) return;
        if (!this.round) this.round = new BreakLockRound({ mode: modes[type], dotLength: length });
        else this.round.start(modes[type], length);
        this.historyKey = null;
        this.renderedHistory = null;
        this.menu.el.style.display = 'none';
        this.game.style.display = '';
        this.render();
        this.schedule();
    }
    change(action) {
        if (this.suspended) return;
        const result = action();
        if (result?.attempt) this.lastMatched = result.attempt.matched;
        this.render();
        this.schedule();
        return result;
    }
    home(fromSummary) {
        this.change(() => this.round.home({ fromSummary }));
        this.menu.el.style.display = '';
        this.game.style.display = 'none';
    }
    action(action) {
        if (action === config.GAME.ACTIONS.BACK_HOME) return this.home(true);
        this.change(() => action === config.GAME.ACTIONS.NEW_GAME
            ? this.round.newGame() : this.round.reveal());
    }
    refresh() { if (!this.suspended) { this.render(); this.schedule(); } }
    schedule() { if (!this.suspended) this.scheduler.sync(); }
    snapshot() { return this.scheduler.snapshot(); }
    suspend() {
        const state = this.snapshot();
        this.suspended = true; this.scheduler.stop();
        this.menu.el.inert = this.game.inert = true;
        if (state) this.round = BreakLockRound.restore(state);
        return state;
    }
    restore(state) {
        this.scheduler.stop();
        this.round = state ? BreakLockRound.restore(state) : null;
        this.suspended = false; this.menu.el.inert = this.game.inert = false;
        this.historyKey = this.renderedHistory = this.summaryKey = null;
        this.menu.el.style.display = state ? 'none' : '';
        this.game.style.display = state ? '' : 'none';
        if (state) {
            const last = this.round.history().filter(entry => entry.type === 'guess').at(-1);
            this.lastMatched = last?.feedback[0] === state.dotLength;
            this.refresh();
        }
    }
    render() {
        const state = this.round.snapshot();
        this.status.counterEl.textContent = leftPadNum(state.counterValue);
        this.status.counterEl.style.display = state.statusDisplay === 'counter' ? 'inherit' : 'none';
        this.status.countdownEl.style.display = state.statusDisplay === 'countdown' ? 'inherit' : 'none';
        const countdown = this.status.countdown;
        countdown.counterEl.textContent = leftPadNum(state.timer.remainingTicks);
        countdown.barEl.style.width = `${state.timer.remainingTicks / 60 * 100}%`;
        countdown.el.classList.toggle('alert', state.timer.remainingTicks <= 10);
        const historyKey = JSON.stringify(state.history);
        if (historyKey !== this.historyKey) {
            if (!this.renderedHistory || JSON.stringify(state.history.slice(0, this.renderedHistory.length)) !== JSON.stringify(this.renderedHistory)) {
                this.history.clear(`#@connect_first ${state.dotLength} #@connect_second`);
                this.renderedHistory = [];
            }
            for (const entry of this.round.history().slice(this.renderedHistory.length)) {
                const svg = new PatternSVG();
                svg.addDots(1);
                svg.addPattern({ suite: entry.sequence }, 14, color.greydient(
                    config.PATTERN.HEX_COLOR_START, config.PATTERN.HEX_COLOR_END, state.dotLength - 3));
                svg.addCombinaison(...entry.feedback);
                svg.el.classList.toggle('success', entry.feedback[0] === state.dotLength);
                svg.el.dataset.entry = entry.type;
                this.history.stackPattern(svg.el);
            }
            this.historyKey = historyKey;
            this.renderedHistory = state.history;
        }
        const summaryKey = JSON.stringify([state.summary.success, state.summary.attemptCount]);
        if (state.summary.success !== null && summaryKey !== this.summaryKey) {
            this.summary.setContent(state.summary.success, state.summary.attemptCount);
            this.summaryKey = summaryKey;
        }
        this.summary.toggle(state.summary.visible);
        this.lock.paint(state, this.lastMatched);
    }
    dispose() {
        this.scheduler.stop();
        clearInterval(this.clock); clearTimeout(this.resetTimer);
        this.clock = this.resetTimer = null;
        this.clockDue = this.resetDue = null;
        this.lock.el.removeEventListener('mousemove', this.lock.mouseUpdateBind);
        window.removeEventListener('mouseup', this.lock.mouseEndBind);
        window.removeEventListener('mouseleave', this.lock.mouseEndBind);
        this.menu.el.remove(); this.game.remove();
    }
}
