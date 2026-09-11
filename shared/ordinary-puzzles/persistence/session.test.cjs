const { test } = require('node:test');
const assert = require('node:assert/strict');
const { LeasedSession } = require('./session.cjs');
const { OrdinaryPuzzlesSession } = require('../session.cjs');
function surface() {
    let core = new OrdinaryPuzzlesSession('e9c2882a25e2');
    return { frozen: false, snapshot: () => core.export(),
        suspend() { this.frozen = true; return core.export(); },
        restore(s) { if(s) core = OrdinaryPuzzlesSession.restore(s); this.frozen = false; } };
}
const acquired = { success:true, data:[], owner_token:'owner', attempt_id:'attempt', revision:0 };
test('serialized checkpoints advance revision; exit freezes, saves and releases once', async () => {
    const calls = [], s = surface();
    const lease = new LeasedSession(s, async body => {
        calls.push(body); return body.action === 'acquire' ? acquired : { success:true, revision:body.revision + (body.action==='save'?1:0) };
    });
    await lease.acquire(); assert.equal(s.frozen,false);
    await Promise.all([lease.checkpoint(),lease.checkpoint()]);
    assert.deepEqual(calls.slice(1).map(x=>x.revision),[0,1]);
    const exiting = lease.exit(); assert(s.frozen); assert.equal(lease.exit(),exiting); await exiting;
    assert.deepEqual(calls.map(x=>x.action),['acquire','save','save','save','release']);
    assert.equal(lease.active,false);
});
test('corrupt replay releases acquired lease without overwriting saved data', async () => {
    const s=surface(), calls=[];
    const lease=new LeasedSession(s,async body=>{calls.push(body.action);return body.action==='acquire'?{...acquired,data:{bad:true}}:{success:true};});
    await assert.rejects(lease.acquire()); assert(s.frozen);assert.deepEqual(calls,['acquire','release']);
});
test('stale result freezes input, stops heartbeat and prevents later writes', async () => {
    let reported=false; const s=surface();
    const lease=new LeasedSession(s,async body=>body.action==='acquire'?acquired:{success:false,reason:'conflict'},{onError:()=>{reported=true;}});
    await lease.acquire();await assert.rejects(lease.checkpoint(),/conflict/);assert(s.frozen);assert(reported);assert(!lease.active);
    await assert.rejects(lease.checkpoint(),/inactive/);
});
test('request timeout freezes writer without claiming save success', async () => {
    const s=surface();const lease=new LeasedSession(s,()=>new Promise(()=>{}),{timeoutMs:5});
    await assert.rejects(lease.acquire(),/timeout/);assert(s.frozen);assert(!lease.active);
});
test('unfinished canonical snapshot is replayed on acquire',async()=>{
    const core=new OrdinaryPuzzlesSession('e9c2882a25e2');core.begin(0,1);core.enter(0,3);
    const snapshot=core.export(),s=surface();
    const lease=new LeasedSession(s,async()=>({...acquired,data:snapshot}));
    await lease.acquire();assert.deepEqual(s.snapshot(),snapshot);lease.abandon();assert(s.frozen);
});
