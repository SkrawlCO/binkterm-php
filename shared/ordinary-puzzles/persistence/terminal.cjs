const { TerminalAdapter } = require('../terminal/adapter.cjs');
const { LeasedSession } = require('./session.cjs');
/** Trusted launcher supplies transport/identity. No caller argument or gameplay filtering. */
async function connectTerminal(request, options = {}) {
    const adapter = new TerminalAdapter();
    let suspended = true;
    const key = adapter.key.bind(adapter);
    adapter.key = value => { if (!suspended || value === 'q' || value === 'ctrl-c') key(value); };
    const surface = {
        snapshot: () => adapter.snapshot(),
        suspend: () => { suspended = true; return adapter.snapshot(); },
        restore: snapshot => {
            if (snapshot) Object.assign(adapter, new TerminalAdapter({ snapshot }));
            suspended = false;
        },
    };
    const session = new LeasedSession(surface, request, options);
    await session.acquire();
    return { adapter, session };
}
module.exports = { connectTerminal };
