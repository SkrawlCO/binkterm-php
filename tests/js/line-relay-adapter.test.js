'use strict';

const assert = require('assert');
const path = require('path');
const {
    LineRelayAdapter,
    NativeAdapter,
    RloginAdapter,
    createEmulatorAdapter
} = require('../../scripts/dosbox-bridge/emulator-adapters');

const basePath = path.resolve(__dirname, '../..');

function captureAdapter() {
    const adapter = new LineRelayAdapter(basePath);
    const output = [];
    const submitted = [];
    adapter.onData(data => output.push(data.toString('utf8')));
    adapter.process = {
        stdin: {
            destroyed: false,
            write: data => submitted.push(data)
        }
    };
    return { adapter, output, submitted };
}

{
    const selected = createEmulatorAdapter(basePath, 'native', 'multizork');
    assert(selected instanceof LineRelayAdapter, 'line manifest must select the generic line adapter');
    assert(createEmulatorAdapter(basePath, 'native', 'pubterm') instanceof NativeAdapter);
    assert(createEmulatorAdapter(basePath, 'rlogin', 'anything') instanceof RloginAdapter);
}

{
    const { adapter, output, submitted } = captureAdapter();
    adapter.write(Buffer.from('looX\x08k\r'));
    assert.strictEqual(output.join(''), 'looX\b \bk\r\n');
    assert.deepStrictEqual(submitted, ['look\n']);
}

{
    const { adapter, output, submitted } = captureAdapter();
    adapter.write(Buffer.from('north\r\n'));
    adapter.write(Buffer.from('\x1b[A'));
    assert.strictEqual(output.join(''), 'north\r\n');
    assert.deepStrictEqual(submitted, ['north\n']);
}

{
    const { adapter, output, submitted } = captureAdapter();
    adapter.write(Buffer.from('x'.repeat(LineRelayAdapter.MAX_LINE_BYTES + 20) + '\n'));
    assert.strictEqual(output.join('').length, LineRelayAdapter.MAX_LINE_BYTES + 2);
    assert.strictEqual(submitted[0].length, LineRelayAdapter.MAX_LINE_BYTES + 1);
}

console.log('line-relay-adapter tests passed');
