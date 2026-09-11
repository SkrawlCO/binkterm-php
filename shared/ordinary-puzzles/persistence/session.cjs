const { OrdinaryPuzzlesSession } = require('../session.cjs');
/** Serialized leased writes. Surface owns input suspension; canonical replay owns restore. */
class LeasedSession {
    constructor(surface, request, { onError = () => {}, intervalMs = 10000, timeoutMs = 8000 } = {}) {
        this.surface = surface; this.onError = onError; this.intervalMs = intervalMs;
        this.active = false; this.tail = Promise.resolve(); this.exiting = null;
        this.request = body => new Promise((resolve, reject) => {
            const timer = setTimeout(() => reject(Error('Storage timeout')), timeoutMs);
            Promise.resolve().then(() => request(body)).then(resolve, reject).finally(() => clearTimeout(timer));
        });
        surface.suspend();
    }
    async acquire() {
        if (this.envelope || this.acquiring) throw Error('Session already acquired');
        this.acquiring = true;
        try {
            const result = await this.request({ action: 'acquire' });
            if (!result.success) throw Error(result.reason || 'Acquire failed');
            this.envelope = result;
            const snapshot = Object.keys(result.data || {}).length ? OrdinaryPuzzlesSession.restore(result.data).export() : null;
            this.surface.restore(snapshot); this.active = true;
            this.heartbeat = setInterval(() => this.checkpoint().catch(() => {}), this.intervalMs);
            this.heartbeat.unref?.();
            return snapshot;
        } catch (error) {
            if (this.envelope) await this.request({ action: 'release', owner_token: this.envelope.owner_token }).catch(() => {});
            this.fail(error); throw error;
        }
    }
    fail(error) {
        clearInterval(this.heartbeat); this.active = false;
        this.surface.suspend(); this.onError(error);
    }
    queue(operation) {
        const next = this.tail.then(() => { if (!this.active) throw Error('Writer inactive'); return operation(); });
        this.tail = next.catch(error => this.fail(error)); return next;
    }
    async send(action, data) {
        const result = await this.request({ action, owner_token: this.envelope.owner_token,
            attempt_id: this.envelope.attempt_id, revision: this.envelope.revision, ...(data ? { data } : {}) });
        if (!result.success) throw Error(result.reason || 'Storage failed');
        this.envelope = { ...this.envelope, ...result }; return result;
    }
    checkpoint() {
        if (this.exiting) return Promise.reject(Error('Writer closing'));
        return this.queue(() => this.send('save', this.surface.snapshot()));
    }
    exit() {
        if (this.exiting) return this.exiting;
        clearInterval(this.heartbeat);
        const snapshot = this.surface.suspend();
        this.exiting = this.queue(async () => {
            await this.send('save', snapshot); await this.send('release'); this.active = false; return snapshot;
        });
        return this.exiting;
    }
    abandon() { clearInterval(this.heartbeat); this.active = false; this.surface.suspend(); }
}
function webTransport(endpoint, csrfToken) {
    return async body => {
        const response = await fetch(endpoint, { method: 'POST', credentials: 'same-origin', cache: 'no-store',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken }, body: JSON.stringify(body) });
        if (!response.ok) throw Error(`Storage HTTP ${response.status}`);
        return response.json();
    };
}
module.exports = { LeasedSession, webTransport };
