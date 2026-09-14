/**
 * Plain Node assertions for js/lastword/rivalry.js (Fork #7, "Picking
 * Sides"). Pure/DOM-free module: no fetch, no storage, no BinkTermPHP
 * knowledge — persistence is exercised separately, at the controller level,
 * in tests/js/lastword/app-final-skippy-dom.test.js.
 *
 * Run: node tests/js/lastword/rivalry.test.js
 */
'use strict';

const assert = require('assert');
const LastWordRivalry = require('../../../public_html/webdoors/hangman/js/lastword/rivalry.js');

let passed = 0;
function check(name, fn) {
    fn();
    passed++;
    console.log('  ok - ' + name);
}

console.log('rivalry.test.js');

check('createRivalryRecord returns a fresh 0/0 record at the current schema version', () => {
    assert.deepStrictEqual(LastWordRivalry.createRivalryRecord(), { version: 1, skippy: 0, bob: 0 });
});

check('sanitizeRivalryRecord(null/undefined) — "no save yet" — sanitizes to a fresh 0/0 record', () => {
    assert.deepStrictEqual(LastWordRivalry.sanitizeRivalryRecord(null), { version: 1, skippy: 0, bob: 0 });
    assert.deepStrictEqual(LastWordRivalry.sanitizeRivalryRecord(undefined), { version: 1, skippy: 0, bob: 0 });
});

check('sanitizeRivalryRecord passes through a valid record unchanged', () => {
    assert.deepStrictEqual(
        LastWordRivalry.sanitizeRivalryRecord({ version: 1, skippy: 12, bob: 4 }),
        { version: 1, skippy: 12, bob: 4 }
    );
});

check('sanitizeRivalryRecord coerces non-numeric/NaN counts to 0', () => {
    assert.deepStrictEqual(
        LastWordRivalry.sanitizeRivalryRecord({ skippy: 'oops', bob: undefined }),
        { version: 1, skippy: 0, bob: 0 }
    );
    assert.deepStrictEqual(
        LastWordRivalry.sanitizeRivalryRecord({ skippy: NaN, bob: {} }),
        { version: 1, skippy: 0, bob: 0 }
    );
});

check('sanitizeRivalryRecord floors a fractional count and clamps a negative one to 0', () => {
    assert.deepStrictEqual(
        LastWordRivalry.sanitizeRivalryRecord({ skippy: 3.9, bob: -5 }),
        { version: 1, skippy: 3, bob: 0 }
    );
});

check('sanitizeRivalryRecord tolerates a completely malformed non-object input', () => {
    assert.deepStrictEqual(LastWordRivalry.sanitizeRivalryRecord('garbage'), { version: 1, skippy: 0, bob: 0 });
    assert.deepStrictEqual(LastWordRivalry.sanitizeRivalryRecord(42), { version: 1, skippy: 0, bob: 0 });
});

check('applyOutcome("solved") increments Skippy only, and does not mutate the record passed in', () => {
    const before = { version: 1, skippy: 2, bob: 5 };
    const after = LastWordRivalry.applyOutcome(before, 'solved');
    assert.deepStrictEqual(after, { version: 1, skippy: 3, bob: 5 });
    assert.deepStrictEqual(before, { version: 1, skippy: 2, bob: 5 }, 'input record must be unchanged');
});

check('applyOutcome("struck-out") increments Bob only, and does not mutate the record passed in', () => {
    const before = { version: 1, skippy: 2, bob: 5 };
    const after = LastWordRivalry.applyOutcome(before, 'struck-out');
    assert.deepStrictEqual(after, { version: 1, skippy: 2, bob: 6 });
    assert.deepStrictEqual(before, { version: 1, skippy: 2, bob: 5 });
});

check('applyOutcome with any other value (including the in-progress null outcome) is a harmless same-reference no-op', () => {
    const record = { version: 1, skippy: 2, bob: 5 };
    assert.strictEqual(LastWordRivalry.applyOutcome(record, null), record);
    assert.strictEqual(LastWordRivalry.applyOutcome(record, undefined), record);
    assert.strictEqual(LastWordRivalry.applyOutcome(record, 'not-a-real-outcome'), record);
});

check('applyOutcome sanitizes a malformed record before incrementing it, rather than propagating garbage', () => {
    const after = LastWordRivalry.applyOutcome({ skippy: 'oops', bob: -3 }, 'solved');
    assert.deepStrictEqual(after, { version: 1, skippy: 1, bob: 0 });
});

check('one solved + one struck-out compose correctly across two independent applyOutcome calls (one point per completed session)', () => {
    let record = LastWordRivalry.createRivalryRecord();
    record = LastWordRivalry.applyOutcome(record, 'solved');
    record = LastWordRivalry.applyOutcome(record, 'struck-out');
    record = LastWordRivalry.applyOutcome(record, 'solved');
    assert.deepStrictEqual(record, { version: 1, skippy: 2, bob: 1 });
});

console.log(`rivalry.test.js: ${passed} passed`);
