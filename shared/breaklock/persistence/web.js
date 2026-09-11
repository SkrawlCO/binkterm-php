import { BreakLockSession, webTransport } from './session.js';

/** Explicit opt-in from a trusted host. No route, caller ID, or navigation destination. */
export async function connectWeb(surface, { endpoint, csrfToken, onExit = () => {}, onError = () => {} }) {
    const report = error => {
        let notice = document.getElementById('breaklock-storage-error');
        if (!notice) {
            notice = document.createElement('div'); notice.id = 'breaklock-storage-error';
            notice.setAttribute('role', 'alert');
            notice.style.cssText = 'position:fixed;top:0;left:0;right:0;z-index:10000;background:#222;color:white;padding:12px';
            document.body.appendChild(notice);
        }
        notice.textContent = 'Progress storage stopped. Reload to reacquire your last checkpoint. ' + error.message;
        onError(error);
    };
    const session = new BreakLockSession(surface, webTransport(endpoint, csrfToken), { onError: report });
    try { await session.acquire(); } catch (error) { report(error); throw error; }
    const exit = async () => { const state = await session.exit(); onExit(); return state; };
    // Hosts can bind their own Save & Return button to exit; no host navigation is inferred.
    return { checkpoint: () => session.checkpoint(), exit, session };
}
