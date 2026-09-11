import test from 'node:test';
import assert from 'node:assert/strict';
import { LeasedClient, canWrite } from '../dist/persistence/client.js';
import { DokuelSession as Session } from '../dist/session.js';
const puzzle='.34678912672195348198342567859761423426853791713924856961537284287419635345286179';
function harness(data=Session.fromPuzzle(puzzle,'easy').snapshot()) {
 let revision=0,fail=false;const calls=[];
 const request=async body=>{calls.push(body);if(fail)return {success:false,reason:'conflict'};if(body.action==='save'){assert.equal(body.revision,revision);data=body.data;revision++;}return {success:true,data,revision,owner_token:'owner',attempt_id:'attempt'};};
 return {lease:new LeasedClient(request,()=>{},100000),calls,fail:()=>{fail=true;}};
}
test('acquire restores exact snapshot with canonical completion and notes',async()=>{const h=harness();await h.lease.acquire();assert(canWrite(h.lease.session));h.lease.mutate(s=>{s.selectCell(0,0);s.toggleNotes();s.enterDigit(5);});const s=await h.lease.checkpoint();assert.deepEqual(Session.restore(s).snapshot(),s);await h.lease.release();});
test('concurrent checkpoints serialize revisions',async()=>{const h=harness();await h.lease.acquire();await Promise.all([h.lease.checkpoint(),h.lease.checkpoint(),h.lease.checkpoint()]);assert.equal(h.lease.envelope.revision,3);await h.lease.release();});
test('release freezes writers immediately and saves before releasing once',async()=>{const h=harness();await h.lease.acquire();const a=h.lease.release(),b=h.lease.release();assert.equal(a,b);assert(!canWrite(h.lease.session));assert.throws(()=>h.lease.mutate(s=>s.resume()));await a;assert.deepEqual(h.calls.map(c=>c.action),['acquire','save','release']);});
test('storage failure freezes input and retains local review snapshot',async()=>{const h=harness();await h.lease.acquire();const before=h.lease.session.snapshot();h.fail();await assert.rejects(h.lease.checkpoint(),/conflict/);assert(!h.lease.writable);assert(!canWrite(h.lease.session));assert.deepEqual(h.lease.session.snapshot(),before);});
test('incompatible schema/revision rejected and acquired lease released',async()=>{for(const bad of [{schema:2},{revision:'future'}]){const h=harness({...Session.fromPuzzle(puzzle,'easy').snapshot(),...bad});await assert.rejects(h.lease.acquire());assert.equal(h.calls.at(-1).action,'release');}});
test('new puzzle replacement remains the owned session',async()=>{const h=harness();await h.lease.acquire();const daily=Session.startDaily('2026-09-11');h.lease.replace(daily);assert.deepEqual(await h.lease.release(),daily.snapshot());assert(!canWrite(daily));});
test('checkpoint and release preserve unpaused elapsed without wall-clock adjustment',async()=>{const h=harness();await h.lease.acquire();h.lease.session.advanceTime(1234);const s=await h.lease.release();assert.equal(s.elapsedMs,1234);assert(!s.paused);assert.deepEqual(Session.restore(s).snapshot(),s);});
