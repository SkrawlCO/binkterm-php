import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {createHash} from 'node:crypto';
const root=new URL('../',import.meta.url);
test('every vendored canonical module and MIT license matches pinned SHA-256',async()=>{
    const p=JSON.parse(await readFile(new URL('provenance.json',root),'utf8'));
    assert.equal(p.revision,'6aad7ccaccb7d354fb7ed88472e48ba2b2cb2f8e');assert.equal(p.license,'MIT');
    for(const f of p.files){const bytes=await readFile(new URL(f.localPath,root));assert.equal(createHash('sha256').update(bytes).digest('hex'),f.sha256,f.upstreamPath);}
    assert.match(await readFile(new URL('upstream/LICENSE',root),'utf8'),/Copyright \(c\) 2026 Adrien Brault/);
});
