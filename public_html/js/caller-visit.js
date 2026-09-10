/* One caller-return signal per foreground interaction episode; no activity payload. */
(function () {
    'use strict';
    const key = 'callerVisitEpisode';
    const idleMs = 30 * 60 * 1000;
    let memory = null;
    let owner = null;
    let inFlight = false;
    let attemptedAt = 0;

    function read() {
        try { return JSON.parse(window.UserStorage.getItem(key)) || memory; }
        catch (_) { return memory; }
    }
    function save(state) {
        memory = state;
        try { window.UserStorage.setItem(key, JSON.stringify(state)); }
        catch (_) { /* Storage restrictions must not prevent the current page's signal. */ }
    }
    function interact(event) {
        if (!event.isTrusted || document.hidden || !document.hasFocus() || !window.currentUserId) return;
        if (owner !== window.currentUserId) {
            owner = window.currentUserId;
            memory = null;
            attemptedAt = 0;
        }
        const csrf = document.querySelector('meta[name="csrf-token"]');
        if (!csrf || !csrf.content) return;
        const now = Date.now();
        let state = read();
        if (!state || !Number.isFinite(state.lastInteraction) || state.lastInteraction > now
            || now - state.lastInteraction >= idleMs) {
            state = { startedAt: now, lastInteraction: now, reported: false };
        }
        state.lastInteraction = now;
        save(state);
        if (state.reported || inFlight || (attemptedAt && now - attemptedAt < 60000)) return;
        inFlight = true;
        attemptedAt = now;
        const episode = state.startedAt;
        const requestUser = window.currentUserId;
        // app.js supplies the standard CSRF header and token-resynchronization retry.
        window.fetch('/api/caller-visit', { method: 'POST', keepalive: true })
            .then(response => response.ok ? response.json() : null)
            .then(data => {
                const current = read();
                if (data && data.success && window.currentUserId === requestUser
                    && current && current.startedAt === episode) {
                    current.reported = true;
                    save(current);
                }
            })
            .catch(() => { /* Retry only on a later trusted interaction, never a timer. */ })
            .finally(() => { inFlight = false; });
    }
    ['pointerdown', 'keydown', 'touchstart'].forEach(type => {
        document.addEventListener(type, interact, { capture: true, passive: true });
    });
}());
