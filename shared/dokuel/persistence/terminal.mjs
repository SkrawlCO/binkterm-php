import { LeasedClient } from '../dist/persistence/client.js';
import { TerminalAdapter } from '../terminal/adapter.mjs';
export async function connectTerminal(request, { intervalMs = 10000 } = {}) {
    let adapter;
    const lease = new LeasedClient(request, () => {
        if (adapter && lease.error) adapter.message = `Save stopped: ${lease.error}. Q quits.`;
    }, intervalMs);
    await lease.acquire();
    adapter = new TerminalAdapter(lease.session);
    const key = adapter.key.bind(adapter), advance = adapter.advanceTime.bind(adapter), resize = adapter.resize.bind(adapter);
    adapter.key = name => {
        if (!lease.writable) { if (['q', 'ctrl-c'].includes(name)) adapter.quit = true; return; }
        if (adapter.screen === 'menu' && name === 'e') {
            lease.checkpoint().then(() => { adapter.message = 'Checkpoint saved.'; }).catch(() => {}); return;
        }
        key(name);
        if (adapter.session !== lease.session) lease.replace(adapter.session);
    };
    adapter.advanceTime = ms => { if (lease.writable) advance(ms); };
    adapter.resize = (columns, rows) => { if (lease.writable) resize(columns, rows); };
    return { adapter, lease };
}
