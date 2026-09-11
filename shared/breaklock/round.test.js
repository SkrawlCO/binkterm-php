import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import Pattern from './upstream/pattern.js';
import { BreakLockRound, UPSTREAM_REVISION } from './round.js';

const secret = [0, 1, 2, 5];
const wrong = [0, 1, 4, 2];
function fixed(mode = 'practice', dotLength = 4) {
    const original = Math.random;
    const draws = [0, 1, 2, 5, 4, 3];
    let i = 0;
    Math.random = () => (draws[i++ % draws.length] + 0.1) / 9;
    try { return new BreakLockRound({ mode, dotLength }); }
    finally { Math.random = original; }
}
function guess(round, positions = wrong) {
    round.clearDraft();
    let result;
    for (const p of positions) result = round.select(p);
    return result.attempt;
}
const state = round => round.snapshot();

test('vendored Pattern bytes match the pinned canonical source', () => {
    const bytes = readFileSync(new URL('./upstream/pattern.js', import.meta.url));
    assert.equal(createHash('sha256').update(bytes).digest('hex'), 'f1c4411b4a7ae3e15eb55d5310cde0821d464166e35483eb62768a4cdc26097b');
});
test('Practice wrong guess increments attempts/counter and canonical feedback', () => {
    const r = fixed();
    assert.deepEqual(guess(r).feedback, [2, 1, 1]);
    assert.equal(r.attempts, 1);
    assert.equal(state(r).counterValue, 1);
    assert.equal(state(r).ended, 'active');
});
test('Practice winning guess leaves displayed counter unchanged', () => {
    const r = fixed(); guess(r); guess(r, secret);
    assert.equal(r.attempts, 2);
    assert.equal(state(r).counterValue, 1);
    assert.deepEqual(state(r).summary, { visible: true, success: true, attemptCount: 2 });
});
test('Challenge wrong guesses decrement only the displayed allowance', () => {
    const r = fixed('challenge'); guess(r); guess(r);
    assert.equal(state(r).counterValue, 8);
    assert.equal(r.attempts, 2);
    assert.equal(state(r).ended, 'active');
});
test('Challenge tenth failure opens loss summary', () => {
    const r = fixed('challenge'); for (let i = 0; i < 10; i++) guess(r);
    assert.equal(state(r).counterValue, 0);
    assert.equal(state(r).ended, 'lost');
    assert.deepEqual(state(r).summary, { visible: true, success: false, attemptCount: 10 });
});
test('Challenge correct tenth wins with counter one', () => {
    const r = fixed('challenge'); for (let i = 0; i < 9; i++) guess(r);
    assert.equal(guess(r, secret).matched, true);
    assert.equal(state(r).counterValue, 1);
    assert.equal(state(r).ended, 'won');
});
test('Countdown starts at sixty; one callback is one tick', () => {
    const r = fixed('countdown');
    assert.deepEqual(state(r).timer, { remainingTicks: 60, running: true, nextTickDelayMs: 1000 });
    r.tick(); assert.equal(state(r).timer.remainingTicks, 59);
    guess(r); assert.equal(state(r).counterValue, null);
    assert.equal(state(r).timer.remainingTicks, 59);
});
test('Countdown timeout clamps, stops and records failure', () => {
    const r = fixed('countdown'); for (let i = 0; i < 60; i++) r.tick();
    assert.deepEqual(state(r).timer, { remainingTicks: 0, running: false, nextTickDelayMs: null });
    assert.equal(state(r).ended, 'lost');
    const before = state(r); r.tick(); assert.deepEqual(state(r), before);
});
test('winning guess before final tick stops Countdown at one', () => {
    const r = fixed('countdown'); for (let i = 0; i < 59; i++) r.tick();
    guess(r, secret); r.tick();
    assert.equal(state(r).timer.remainingTicks, 1);
    assert.equal(state(r).timer.running, false);
    assert.equal(state(r).ended, 'won');
});
test('timeout before winning guess keeps loss and original summary count', () => {
    const r = fixed('countdown'); for (let i = 0; i < 60; i++) r.tick();
    assert.equal(guess(r, secret).matched, true);
    assert.equal(state(r).ended, 'lost');
    assert.equal(state(r).counterValue, 1); // Canonical null++ behavior.
    assert.equal(state(r).summary.attemptCount, 0);
});
test('loss reveal is history presentation, not another attempt', () => {
    const r = fixed('challenge'); for (let i = 0; i < 10; i++) guess(r);
    assert.equal(r.reveal().revealed, true);
    assert.equal(r.attempts, 10);
    assert.equal(state(r).history.length, 11);
    assert.deepEqual(r.history().at(-1), { type: 'reveal', sequence: secret, feedback: [4, 0, 0] });
    assert.equal(state(r).counterValue, 10);
    assert.equal(state(r).summary.visible, false);
});
test('win reveal adds nothing and switches counter to attempts', () => {
    const r = fixed('countdown'); guess(r, secret);
    assert.equal(r.reveal().revealed, false);
    assert.equal(state(r).history.length, 1);
    assert.equal(state(r).counterValue, 1);
    assert.equal(state(r).statusDisplay, 'counter');
    assert.equal(state(r).summary.visible, false);
});
test('continued guesses after result update history/counter without new summary', () => {
    const r = fixed(); guess(r, secret); r.reveal(); guess(r); guess(r, secret);
    assert.equal(r.attempts, 3);
    assert.equal(state(r).counterValue, 3);
    assert.equal(state(r).ended, 'won');
    assert.equal(state(r).summary.visible, false);
    assert.equal(state(r).summary.attemptCount, 1);
});
test('new game resets round and toggles summary, retains pending reset callback', () => {
    const r = fixed('challenge'); guess(r, secret); r.newGame();
    assert.equal(r.attempts, 0);
    assert.equal(state(r).counterValue, 10);
    assert.equal(state(r).ended, 'active');
    assert.deepEqual(state(r).draft, []);
    assert.equal(state(r).summary.visible, false);
    assert.equal(state(r).pendingGuessResetDelayMs, 1000);
    assert.equal(r.select(0).blocked, 'pending-reset');
    r.clearDraft(); assert.deepEqual(r.select(0).added, [0]);
});
test('Home does not stop timer; old Countdown can time out new Practice', () => {
    const r = fixed('countdown'); r.tick();
    assert.deepEqual(r.home(), { type: 'home' }); r.start('practice', 4);
    for (let i = 0; i < 59; i++) r.tick();
    assert.equal(state(r).mode, 'practice');
    assert.equal(state(r).ended, 'lost');
});
test('Countdown restart retains live scheduling phase and hidden counter', () => {
    const r = BreakLockRound.restore(fixed('countdown').snapshot({ nextTickDelayMs: 125 }));
    r.start('practice', 4); guess(r); r.start('countdown', 4);
    assert.equal(state(r).timer.remainingTicks, 60);
    assert.equal(state(r).timer.nextTickDelayMs, 125);
    assert.equal(state(r).counterValue, 1);
});
test('snapshot/restore parity across partial draft, timeout, reveal and continued guesses', () => {
    const a = fixed('countdown'); a.select(0); a.select(1); a.tick();
    const b = BreakLockRound.restore(JSON.parse(JSON.stringify(a.snapshot())));
    for (const action of [r => r.select(4), r => r.select(2), r => { for (let i = 0; i < 59; i++) r.tick(); }, r => r.reveal(), r => guess(r, secret)]) {
        assert.deepEqual(action(a), action(b)); assert.deepEqual(state(a), state(b));
    }
    assert.equal(state(b).ended, 'lost');
    assert.equal(b.attempts, 2);
});
test('restore never draws a new secret or catches up absent callbacks', () => {
    const snapshot = fixed('countdown').snapshot({ nextTickDelayMs: 13 });
    const previous = Math.random;
    Math.random = () => { throw new Error('Unexpected RNG'); };
    try { assert.deepEqual(BreakLockRound.restore(snapshot).snapshot(), snapshot); }
    finally { Math.random = previous; }
});
test('snapshot is detached, including nested history and secret', () => {
    const r = fixed(); guess(r); const s = state(r);
    s.secret[0] = 8; s.history[0].sequence[0] = 8; s.timer.running = true;
    assert.deepEqual(state(r).secret, secret);
    assert.deepEqual(state(r).history[0].sequence, wrong);
    const restored = BreakLockRound.restore(state(r)); const copy = state(r);
    assert.deepEqual(state(restored), copy);
});
test('canonical midpoint insertion and automatic submission', () => {
    const r = fixed(); r.select(0);
    assert.deepEqual(r.select(2).added, [1, 2]);
    assert.equal(r.attempts, 0);
    const result = r.select(8); // Canonical midpoint 5 fills last slot; 8 omitted.
    assert.deepEqual(result.added, [5]);
    assert.equal(result.attempt.matched, true);
    assert.deepEqual(state(r).draft, secret);
});
test('duplicate rejection is delegated to canonical Pattern.addDot', () => {
    const r = fixed(); r.select(0); const original = Pattern.prototype.addDot; let called = 0;
    Pattern.prototype.addDot = function (p) { called++; return original.call(this, p); };
    try { assert.deepEqual(r.select(0).added, []); assert.equal(called, 1); }
    finally { Pattern.prototype.addDot = original; }
    assert.deepEqual(state(r).draft, [0]);
});
test('full rejection remains canonical; submitted draft is not submitted twice', () => {
    const p = new Pattern(4); secret.forEach(n => p.addDot(n));
    const before = [...p.suite]; assert.deepEqual(p.addDot(8), []); assert.deepEqual(p.suite, before);
    const r = fixed(); guess(r, secret); assert.equal(r.select(8).blocked, 'pending-reset');
    assert.equal(r.attempts, 1);
});
test('gesture end clears partial draft, pending reset preserves submitted display', () => {
    const r = fixed(); r.select(0); r.endGesture(); assert.deepEqual(state(r).draft, []);
    guess(r); r.endGesture(); assert.deepEqual(state(r).draft, wrong);
    const restored = BreakLockRound.restore(r.snapshot({ pendingGuessResetDelayMs: 42 }));
    assert.equal(state(restored).pendingGuessResetDelayMs, 42);
    restored.clearDraft(); assert.deepEqual(state(restored).draft, []);
});
test('all difficulty lengths instantiate canonical Pattern and complete correctly', () => {
    for (const length of [4, 5, 6]) {
        const r = fixed('practice', length); const answer = state(r).secret;
        assert.equal(answer.length, length);
        assert.deepEqual(guess(r, answer).feedback, [length, 0, 0]);
    }
});
test('restore rejects incompatible revision, noncanonical sequences and invalid timers', () => {
    const s = state(fixed());
    for (const mutate of [x => x.upstreamRevision = 'other', x => x.secret = [0, 2, 5, 8], x => x.draft = [0, 0], x => x.timer.running = true, x => x.history = [{ type: 'guess', sequence: [0] }]]) {
        const copy = structuredClone(s); mutate(copy); assert.throws(() => BreakLockRound.restore(copy), TypeError);
    }
    assert.equal(s.upstreamRevision, UPSTREAM_REVISION);
});

test('new round draws a new secret through canonical fillRandomly', () => {
    const r = fixed(); const original = Pattern.prototype.fillRandomly; let calls = 0;
    Pattern.prototype.fillRandomly = function () { calls++; return original.call(this); };
    try { r.newGame(); assert.equal(calls, 1); }
    finally { Pattern.prototype.fillRandomly = original; }
});
test('leftover Countdown can overwrite a later Practice win at zero', () => {
    const r = fixed('countdown'); r.start('practice', 4); guess(r, state(r).secret);
    assert.equal(state(r).ended, 'won');
    assert.equal(state(r).timer.running, true);
    for (let i = 0; i < 60; i++) r.tick();
    assert.equal(state(r).ended, 'lost');
    assert.equal(state(r).summary.success, false);
});
test('all modes retain round and next-input parity after restoration', () => {
    for (const mode of ['practice', 'challenge', 'countdown']) {
        const a = fixed(mode); guess(a); const b = BreakLockRound.restore(state(a));
        assert.deepEqual(guess(a, secret), guess(b, secret));
        assert.deepEqual(state(a), state(b));
        assert.deepEqual(a.reveal(), b.reveal());
        assert.deepEqual(guess(a), guess(b));
        assert.deepEqual(state(a), state(b));
    }
});
