import test from 'node:test';
import assert from 'node:assert/strict';
import { BreakLockTerminal } from '../terminal/adapter.js';
import { BreakLockSession } from './session.js';
const lease = { success: true, data: {}, revision: 0, attempt_id: 'test', owner_token: 'test' };

test('failed checkpoint stops input and countdown scheduling', async () => {
    const surface = new BreakLockTerminal();
    const session = new BreakLockSession(surface, async input => input.action === 'acquire' ? lease : { success: false, reason: 'conflict' });
    await session.acquire(); surface.handleKey('3'); surface.handleKey('enter');
    await assert.rejects(session.checkpoint(), /conflict/);
    const frozen = surface.snapshot(); surface.handleKey('1');
    assert.deepEqual(surface.snapshot(), frozen); assert.equal(surface.scheduler.interval, null);
    assert.equal(session.active, false);
});
test('unresponsive storage fails closed within request timeout', async () => {
    const surface = new BreakLockTerminal();
    const session = new BreakLockSession(surface, input => input.action === 'acquire' ? lease : new Promise(() => {}), { requestTimeoutMs: 15 });
    await session.acquire(); surface.handleKey('enter');
    await assert.rejects(session.checkpoint(), /timeout/); assert.equal(surface.suspended, true);
});
test('exit freezes before pending I/O, serializes final save and release, and is idempotent', async () => {
    const surface = new BreakLockTerminal(), actions = []; let finishSave;
    const session = new BreakLockSession(surface, async input => {
        actions.push(input.action);
        if (input.action === 'save' && actions.filter(a => a === 'save').length === 1) await new Promise(r => { finishSave = r; });
        return { ...lease, revision: input.action === 'save' ? input.revision + 1 : input.revision ?? 0 };
    });
    await session.acquire(); surface.handleKey('enter'); surface.handleKey('1');
    const checkpoint = session.checkpoint(); await new Promise(r => setImmediate(r));
    surface.handleKey('2'); const exit = session.exit(); assert.equal(session.exit(), exit);
    surface.handleKey('3'); finishSave(); await checkpoint;
    assert.deepEqual((await exit).draft, [0,1]); assert.deepEqual(actions, ['acquire','save','save','release']);
    assert.equal(session.active, false);
});
test('invalid saved snapshot is not overwritten and lease is released', async () => {
    const actions = [], surface = new BreakLockTerminal();
    const session = new BreakLockSession(surface, async input => { actions.push(input.action); return { ...lease, data: { invalid: true } }; });
    await assert.rejects(session.acquire(), /Invalid saved state/);
    assert.deepEqual(actions, ['acquire','release']); assert.equal(surface.suspended, true);
});
