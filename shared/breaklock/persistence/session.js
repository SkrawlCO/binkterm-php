import { BreakLockRound } from '../round.js';

/** One serial request stream per writer. Identity is supplied by the trusted transport. */
export class BreakLockSession {
    constructor(surface, request, { onError = () => {}, intervalMs = 10000, requestTimeoutMs = 8000 } = {}) {
        this.surface = surface;
        this.request = body => new Promise((resolve, reject) => {
            const timeout = setTimeout(() => reject(new Error('Storage timeout')), requestTimeoutMs);
            Promise.resolve().then(() => request(body)).then(resolve, reject).finally(() => clearTimeout(timeout));
        });
        this.onError = onError;
        this.intervalMs = intervalMs; this.tail = Promise.resolve(); this.active = false;
        this.heartbeat = null; this.exiting = null;
        surface.suspend();
    }
    async acquire() {
        const result = await this.request({ action: 'acquire' });
        if (!result.success) throw new Error(result.reason || 'acquire');
        this.envelope = result; this.active = true;
        try {
            if (this.exiting) throw new Error('Exit requested during acquisition');
            const state = result.data?.schemaVersion ? BreakLockRound.restore(result.data).snapshot() : null;
            if (state === null && Object.keys(result.data || {}).length) throw new Error('Invalid saved state');
            this.surface.restore(state);
            this.heartbeat = setInterval(() => this.checkpoint().catch(() => {}), this.intervalMs);
            this.heartbeat.unref?.();
            return state;
        } catch (error) {
            await this.request({ action: 'release', owner_token: result.owner_token }).catch(() => {});
            this.fail(error); throw error;
        }
    }
    enqueue(operation) {
        const next = this.tail.then(async () => {
            if (!this.active) throw new Error('Writer inactive');
            return operation();
        });
        this.tail = next.catch(error => { this.fail(error); });
        return next;
    }
    async send(action, extra = {}) {
        const result = await this.request({ action, owner_token: this.envelope.owner_token,
            attempt_id: this.envelope.attempt_id, revision: this.envelope.revision, ...extra });
        if (!result.success) throw new Error(result.reason || 'storage');
        this.envelope = { ...this.envelope, ...result };
        return result;
    }
    checkpoint() {
        return this.enqueue(() => {
            const data = this.surface.snapshot();
            return this.send(data ? 'save' : 'renew', data ? { data } : {});
        });
    }
    exit() {
        if (this.exiting) return this.exiting;
        clearInterval(this.heartbeat);
        const frozen = this.surface.suspend();
        this.exiting = this.enqueue(async () => {
            if (frozen) await this.send('save', { data: frozen });
            await this.send('release'); this.active = false;
            return frozen;
        });
        return this.exiting;
    }
    fail(error) {
        clearInterval(this.heartbeat); this.active = false;
        this.surface.suspend(); this.onError(error);
    }
}

export function webTransport(endpoint, csrfToken) {
    return async body => {
        const response = await fetch(endpoint, { method: 'POST', credentials: 'same-origin', cache: 'no-store',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken }, body: JSON.stringify(body) });
        if (!response.ok) throw new Error(`Storage HTTP ${response.status}`);
        return response.json();
    };
}
