import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { createHash } from 'node:crypto';
const root = new URL('../', import.meta.url);
const read = p => fs.readFileSync(new URL(p, root));
const hash = p => createHash('sha256').update(read(p)).digest('hex');

test('pinned presentation, adaptations, shared forwarders and frozen tooling match provenance', () => {
    const provenance = JSON.parse(read('provenance.json'));
    assert.equal(provenance.revision, '6aad7ccaccb7d354fb7ed88472e48ba2b2cb2f8e');
    assert.equal(provenance.license, 'MIT');
    assert.match(read(provenance.licensePath).toString(), /Copyright \(c\) 2026 Adrien Brault/);
    for (const file of provenance.files) {
        assert.equal(hash(file.localPath), file.localSha256, file.localPath);
        if (file.status === 'unchanged') assert.equal(file.localSha256, file.upstreamSha256);
        else assert.equal(file.status, 'adapted');
    }
    for (const file of provenance.sharedForwarders) {
        assert.equal(hash(file.localPath), file.sha256);
        assert.match(read(file.localPath).toString(), /^export \* from "\.\.\/\.\.\/\.\.\/upstream\/src\/lib\//);
        assert.ok(read(file.sharedPath).length);
    }
    assert.equal(hash('bun.lock'), provenance.toolchain.localLockSha256);
    const lock = JSON.parse(read('bun.lock'));
    const pkg = JSON.parse(read('package.json'));
    for (const [name, version] of Object.entries({ ...pkg.dependencies, ...pkg.devDependencies })) {
        assert.equal(lock.packages[name][0], `${name}@${version}`);
    }
});
