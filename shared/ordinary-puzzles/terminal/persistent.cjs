#!/usr/bin/env node
// Local trusted launcher only. Identity comes from inherited DOOR_USER_NUMBER.
const { run } = require('./cli.cjs');
const { connectTerminal } = require('../persistence/terminal.cjs');
(async () => {
    if (process.argv.length !== 2) throw Error('No caller or snapshot arguments accepted');
    const { helperTransport } = await import('../persistence/node-transport.mjs');
    const helper = helperTransport();
    let cleanup;
    try {
        const { adapter, session } = await connectTerminal(helper.request, {
            onError: error => { cleanup?.(); process.stderr.write(`Progress storage stopped: ${error.message}\n`); },
        });
        cleanup = run({ adapter, onQuit: () => {
            session.exit().catch(error => { process.stderr.write(`Save failed: ${error.message}\n`); process.exitCode = 1; })
                .finally(() => helper.close());
        } });
    } catch (error) { helper.close(); throw error; }
})().catch(error => { process.stderr.write(error.message + '\n'); process.exitCode = 1; });
