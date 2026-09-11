/** Surface callback scheduling, including a saved first callback delay. No game rules. */
export class RoundScheduler {
    constructor(surface, clock = globalThis) {
        this.surface = surface; this.clock = clock;
        this.interval = this.first = this.reset = null;
        this.tickDue = this.resetDue = null;
    }
    now() { return this.clock.performance?.now() ?? performance.now(); }
    sync() {
        const s = this.surface.round?.snapshot();
        if (!s) return;
        if (s.timer.running && this.interval === null && this.first === null) {
            const tick = () => {
                if (this.surface.closed || this.surface.suspended) return;
                this.tickDue = this.now() + 1000;
                this.surface.round.tick(); this.surface.refresh();
            };
            const delay = s.timer.nextTickDelayMs;
            this.tickDue = this.now() + delay;
            if (delay === 1000) this.interval = this.clock.setInterval(tick, 1000);
            else this.first = this.clock.setTimeout(() => {
                this.first = null;
                if (this.surface.closed || this.surface.suspended) return;
                // Establish the recurring callback before refresh, preventing a second timer.
                this.interval = this.clock.setInterval(tick, 1000); tick();
            }, delay);
        } else if (!s.timer.running) this.stopTick();
        if (s.pendingGuessResetDelayMs !== null && this.reset === null) {
            this.resetDue = this.now() + s.pendingGuessResetDelayMs;
            this.reset = this.clock.setTimeout(() => {
                this.reset = this.resetDue = null;
                if (this.surface.closed || this.surface.suspended) return;
                this.surface.round.clearDraft(); this.surface.refresh();
            }, s.pendingGuessResetDelayMs);
        } else if (s.pendingGuessResetDelayMs === null && this.reset !== null) {
            this.clock.clearTimeout(this.reset); this.reset = this.resetDue = null;
        }
    }
    snapshot() {
        if (!this.surface.round) return null;
        const residual = due => Math.max(0, Math.min(1000, Math.round(due - this.now())));
        const delays = {};
        if (this.tickDue !== null) delays.nextTickDelayMs = residual(this.tickDue);
        if (this.resetDue !== null) delays.pendingGuessResetDelayMs = residual(this.resetDue);
        return this.surface.round.snapshot(delays);
    }
    stopTick() {
        this.clock.clearInterval(this.interval); this.clock.clearTimeout(this.first);
        this.interval = this.first = this.tickDue = null;
    }
    stop() {
        this.stopTick(); this.clock.clearTimeout(this.reset);
        this.reset = this.resetDue = null;
    }
}
