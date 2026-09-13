/**
 * Plain Node assertions for js/lastword/idle-chatter.js — Skippy's
 * restrained idle-chatter dialogue pools + setTimeout-based timing
 * controller. Isolated from the DOM; the timer controller is driven here
 * via an injected fake setTimeout/clearTimeout so no real delays occur.
 * Run: node tests/js/lastword/idle-chatter.test.js
 */
'use strict';

const assert = require('assert');
const IC = require('../../../public_html/webdoors/hangman/js/lastword/idle-chatter.js');

let passed = 0;
function check(name, fn) {
    fn();
    passed++;
    console.log('  ok - ' + name);
}

console.log('idle-chatter.test.js');

const ACTIVE_STATES = ['CONFIDENT', 'CONFUSED', 'CONCERNED', 'NERVOUS', 'PLEADING', 'TERRIFIED', 'SAVED'];

// ---- pure dialogue pool / pickChatterLine ------------------------------

check('every active gallows-character.js strike state (0-6) plus SAVED has a chatter pool', () => {
    ACTIVE_STATES.forEach((name) => {
        const pool = IC.IDLE_CHATTER_LINES[name];
        assert.ok(Array.isArray(pool) && pool.length >= 2, name + ' should have a real pool');
    });
});

check('COMEDIC_DEFEAT deliberately has no chatter pool (he is gone)', () => {
    assert.ok(!IC.IDLE_CHATTER_LINES.COMEDIC_DEFEAT);
});

check('pickChatterLine returns null for a state with no pool', () => {
    assert.strictEqual(IC.pickChatterLine('COMEDIC_DEFEAT', null, () => 0.1), null);
    assert.strictEqual(IC.pickChatterLine('NOT_A_STATE', null, () => 0.1), null);
});

check('pickChatterLine always returns a line from that state\'s own pool', () => {
    ACTIVE_STATES.forEach((name) => {
        const pool = IC.IDLE_CHATTER_LINES[name];
        for (let i = 0; i < pool.length * 3; i++) {
            const line = IC.pickChatterLine(name, null, () => (i % pool.length) / pool.length);
            assert.ok(pool.includes(line), name + ' -> ' + line);
        }
    });
});

check('pickChatterLine avoids repeating the immediately-previous line', () => {
    ACTIVE_STATES.forEach((name) => {
        const pool = IC.IDLE_CHATTER_LINES[name];
        pool.forEach((previous) => {
            for (let i = 0; i < pool.length; i++) {
                const next = IC.pickChatterLine(name, previous, () => i / pool.length);
                assert.notStrictEqual(next, previous, name + ': should not immediately repeat "' + previous + '"');
            }
        });
    });
});

check('dialogue lines contain no scoring/gameplay-mechanic leakage keywords', () => {
    // Flavor only — never a hint, never a number, never an instruction that
    // could be mistaken for real gameplay guidance.
    const bannedPattern = /\b(hint|answer is|the word is|\d+ points?)\b/i;
    Object.keys(IC.IDLE_CHATTER_LINES).forEach((state) => {
        IC.IDLE_CHATTER_LINES[state].forEach((line) => {
            assert.ok(!bannedPattern.test(line), state + ' line looks like it leaks gameplay info: ' + line);
        });
    });
});

// ---- timer controller (fake timers — no real delays) --------------------

function makeFakeClock() {
    let nextId = 1;
    const timers = new Map(); // id -> { fn, delay }
    return {
        setTimeout: (fn, delay) => {
            const id = nextId++;
            timers.set(id, { fn, delay });
            return id;
        },
        clearTimeout: (id) => { timers.delete(id); },
        pendingCount: () => timers.size,
        pendingDelays: () => Array.from(timers.values()).map((t) => t.delay),
        // Fire the single pending timer (asserts exactly one is pending) and
        // run its callback.
        fireOne: function () {
            assert.strictEqual(timers.size, 1, 'expected exactly one pending timer, found ' + timers.size);
            const [id, t] = Array.from(timers.entries())[0];
            timers.delete(id);
            t.fn();
        }
    };
}

check('resetIdle schedules exactly one first-remark timer in the 12-20s window', () => {
    const clock = makeFakeClock();
    const ctrl = IC.createIdleChatterController({
        getStateName: () => 'CONFIDENT',
        onChatter: () => {},
        rng: () => 0.5,
        setTimeout: clock.setTimeout,
        clearTimeout: clock.clearTimeout
    });
    ctrl.resetIdle();
    assert.strictEqual(clock.pendingCount(), 1);
    const delay = clock.pendingDelays()[0];
    assert.ok(delay >= IC.FIRST_DELAY_MIN_S * 1000 && delay <= IC.FIRST_DELAY_MAX_S * 1000, 'delay=' + delay);
});

check('after firing once, the next scheduled delay falls in the 20-35s later window', () => {
    const clock = makeFakeClock();
    const shown = [];
    const ctrl = IC.createIdleChatterController({
        getStateName: () => 'CONFIDENT',
        onChatter: (line) => shown.push(line),
        rng: () => 0.3,
        setTimeout: clock.setTimeout,
        clearTimeout: clock.clearTimeout
    });
    ctrl.resetIdle();
    clock.fireOne();
    assert.strictEqual(shown.length, 1);
    assert.strictEqual(clock.pendingCount(), 1);
    const delay = clock.pendingDelays()[0];
    assert.ok(delay >= IC.LATER_DELAY_MIN_S * 1000 && delay <= IC.LATER_DELAY_MAX_S * 1000, 'delay=' + delay);
});

check('a resetIdle stretch shows at most MAX_REMARKS_PER_STRETCH remarks, then goes quiet', () => {
    const clock = makeFakeClock();
    const shown = [];
    const ctrl = IC.createIdleChatterController({
        getStateName: () => 'NERVOUS',
        onChatter: (line) => shown.push(line),
        rng: () => 0.9, // biases maxRemarksThisStretch toward the top of [2,3]
        setTimeout: clock.setTimeout,
        clearTimeout: clock.clearTimeout
    });
    ctrl.resetIdle();
    // Fire far more times than the max possible remarks per stretch could
    // ever allow; the controller must stop scheduling once its budget is
    // spent rather than chattering forever.
    for (let i = 0; i < 10 && clock.pendingCount() > 0; i++) {
        clock.fireOne();
    }
    assert.ok(shown.length >= IC.MIN_REMARKS_PER_STRETCH && shown.length <= IC.MAX_REMARKS_PER_STRETCH,
        'shown.length=' + shown.length);
    assert.strictEqual(clock.pendingCount(), 0, 'controller must stop scheduling once its per-stretch budget is spent');
});

check('resetIdle cancels a pending timer and starts a fresh stretch (a meaningful action interrupts idle)', () => {
    const clock = makeFakeClock();
    const shown = [];
    const ctrl = IC.createIdleChatterController({
        getStateName: () => 'CONCERNED',
        onChatter: (line) => shown.push(line),
        rng: () => 0.5,
        setTimeout: clock.setTimeout,
        clearTimeout: clock.clearTimeout
    });
    ctrl.resetIdle();
    assert.strictEqual(clock.pendingCount(), 1);
    ctrl.resetIdle(); // simulates a player action before the first remark ever fired
    assert.strictEqual(clock.pendingCount(), 1, 'should still have exactly one (fresh) pending timer, not a leaked extra');
    assert.strictEqual(shown.length, 0, 'no chatter should have fired yet');
});

check('stop() cancels the pending timer and fire() no-ops if it somehow still runs', () => {
    const clock = makeFakeClock();
    const shown = [];
    const ctrl = IC.createIdleChatterController({
        getStateName: () => 'CONFIDENT',
        onChatter: (line) => shown.push(line),
        rng: () => 0.5,
        setTimeout: clock.setTimeout,
        clearTimeout: clock.clearTimeout
    });
    ctrl.resetIdle();
    const debug = ctrl._debugState();
    assert.strictEqual(debug.timerPending, true);
    ctrl.stop();
    assert.strictEqual(clock.pendingCount(), 0, 'stop() must clear the pending timer (real cleanup on round/state transitions)');
    assert.strictEqual(ctrl._debugState().active, false);
});

check('a fired remark never repeats the state\'s previous remark within the same controller', () => {
    const clock = makeFakeClock();
    const shown = [];
    let i = 0;
    const ctrl = IC.createIdleChatterController({
        getStateName: () => 'PLEADING',
        onChatter: (line) => shown.push(line),
        rng: () => { i++; return (i % 7) / 7; }, // varied but deterministic
        setTimeout: clock.setTimeout,
        clearTimeout: clock.clearTimeout
    });
    ctrl.resetIdle();
    for (let n = 0; n < IC.MAX_REMARKS_PER_STRETCH && clock.pendingCount() > 0; n++) {
        clock.fireOne();
    }
    for (let n = 1; n < shown.length; n++) {
        assert.notStrictEqual(shown[n], shown[n - 1], 'remark #' + n + ' repeated the previous remark');
    }
});

check('getStateName returning falsy (gameplay already ended) skips a firing without crashing or spending budget', () => {
    const clock = makeFakeClock();
    const shown = [];
    let state = null;
    const ctrl = IC.createIdleChatterController({
        getStateName: () => state,
        onChatter: (line) => shown.push(line),
        rng: () => 0.5,
        setTimeout: clock.setTimeout,
        clearTimeout: clock.clearTimeout
    });
    ctrl.resetIdle();
    clock.fireOne(); // no state yet -> should skip silently and reschedule
    assert.strictEqual(shown.length, 0);
    assert.strictEqual(clock.pendingCount(), 1, 'should still reschedule rather than stopping outright');
    state = 'CONFIDENT';
    clock.fireOne();
    assert.strictEqual(shown.length, 1);
});

check('chatter callbacks receive only a dialogue string and state name — no mutable game object is ever exposed', () => {
    const clock = makeFakeClock();
    const receivedArgTypes = [];
    const ctrl = IC.createIdleChatterController({
        getStateName: () => 'CONFIDENT',
        onChatter: (line, stateName) => {
            receivedArgTypes.push([typeof line, typeof stateName]);
        },
        rng: () => 0.5,
        setTimeout: clock.setTimeout,
        clearTimeout: clock.clearTimeout
    });
    ctrl.resetIdle();
    clock.fireOne();
    assert.deepStrictEqual(receivedArgTypes, [['string', 'string']]);
});

console.log(passed + ' passed');
