import { WordwrightSession } from '../session.js';
/** Single serialized lease owner. Game/meta/stats are one opaque atomic value. */
export class LeasedClient {
    request;
    changed;
    intervalMs;
    session;
    envelope;
    active = false;
    error = '';
    tail = Promise.resolve();
    heartbeat;
    closing;
    constructor(request, changed = () => { }, intervalMs = 10000) {
        this.request = request;
        this.changed = changed;
        this.intervalMs = intervalMs;
    }
    async call(body) {
        let timer;
        try {
            const result = await Promise.race([this.request(body), new Promise((_, reject) => { timer = setTimeout(() => reject(Error('Storage timeout')), 8000); })]);
            if (!result.success)
                throw Error(result.reason || 'Writer conflict');
            return result;
        }
        finally {
            clearTimeout(timer);
        }
    }
    async acquire() {
        if (this.envelope)
            throw Error('Already acquired');
        try {
            this.envelope = await this.call({ action: 'acquire' });
            this.session = Object.keys(this.envelope.data || {}).length ? await WordwrightSession.restore(this.envelope.data) : await WordwrightSession.create();
            this.active = true;
            this.changed();
            this.heartbeat = setInterval(() => { this.checkpoint().catch(() => { }); }, this.intervalMs);
            this.heartbeat.unref?.();
            return this.session.snapshot();
        }
        catch (e) {
            if (this.envelope)
                await this.call({ action: 'release', owner_token: this.envelope.owner_token }).catch(() => { });
            this.fail(e);
            throw e;
        }
    }
    mutate(fn) {
        if (!this.active || this.closing)
            throw Error('Writer inactive');
        const value = fn(this.session);
        this.changed();
        return value;
    }
    fail(e) { clearInterval(this.heartbeat); this.active = false; this.error = e instanceof Error ? e.message : 'Storage failed'; this.changed(); }
    enqueue(fn) {
        const next = this.tail.then(() => { if (!this.active)
            throw Error('Writer inactive'); return fn(); });
        this.tail = next.catch(e => this.fail(e));
        return next;
    }
    async send(action, data) {
        const result = await this.call({ action, owner_token: this.envelope.owner_token, attempt_id: this.envelope.attempt_id, revision: this.envelope.revision, ...(data ? { data } : {}) });
        this.envelope = { ...this.envelope, ...result };
        return result;
    }
    checkpoint() {
        if (this.closing)
            return Promise.reject(Error('Writer closing'));
        return this.enqueue(async () => { const snapshot = this.session.prepareHandoff(); this.changed(); await this.send('save', snapshot); this.changed(); return snapshot; });
    }
    release() {
        if (this.closing)
            return this.closing;
        clearInterval(this.heartbeat);
        // Suspend input immediately; queue save after any in-flight checkpoint.
        this.closing = this.enqueue(async () => {
            const snapshot = this.session.prepareHandoff();
            this.changed();
            await this.send('save', snapshot);
            await this.send('release');
            this.active = false;
            this.changed();
            return snapshot;
        });
        this.changed();
        return this.closing;
    }
    get writable() { return this.active && !this.closing; }
    abandon() { clearInterval(this.heartbeat); this.active = false; this.changed(); }
}
export function webTransport(endpoint, csrf) {
    const url = new URL(endpoint, location.href);
    if (url.origin !== location.origin)
        throw Error('Storage must be same origin');
    return async (body) => {
        const response = await fetch(url, { method: 'POST', credentials: 'same-origin', cache: 'no-store', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify(body) });
        if (!response.ok)
            throw Error(`Storage HTTP ${response.status}`);
        return response.json();
    };
}
