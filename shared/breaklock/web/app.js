import { connectWeb } from '../persistence/web.js';
import { BreakLockWeb } from './adapter.js';
document.getElementById('app-intro')?.remove();
const surface = new BreakLockWeb();
// A local review host may inject endpoint + CSRF context. Default build remains standalone.
let persistence;
const ready = window.breaklockStorage ? connectWeb(surface, window.breaklockStorage).then(value => {
    persistence = value;
    const exit = document.createElement('button');
    exit.textContent = 'Save & Return';
    exit.style.cssText = 'position:fixed;bottom:8px;right:8px;z-index:9999';
    exit.onclick = () => { exit.disabled = true; value.exit().then(() => exit.remove()).catch(error => {
        exit.textContent = 'Save failed: ' + error.message;
    }); };
    document.body.appendChild(exit);
}) : Promise.resolve();
ready.catch(() => {}); // connectWeb presents acquisition failures; hosts can still await ready.
// Snapshot returns detached data; checkpoint/exit are explicit persistence operations.
window.breaklock = Object.freeze({ snapshot: () => surface.snapshot(), ready, checkpoint: () => persistence?.checkpoint(), exit: () => persistence?.exit() });
window.addEventListener('pagehide', event => {
    if (persistence) { persistence.session.fail(new Error('Page closed; resume last checkpoint')); }
    if (!event.persisted) surface.dispose();
});
