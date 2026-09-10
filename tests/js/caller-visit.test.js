'use strict';
const {test} = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync(__dirname + '/../../public_html/js/caller-visit.js', 'utf8');
function harness(storage = new Map()) {
    const handlers = {};
    const calls = [];
    let now = 100000000;
    const document = {hidden:false, hasFocus:()=>true, querySelector:()=>({content:'csrf'}), addEventListener:(type, fn)=>{ handlers[type] = fn; }};
    const window = {currentUserId:1, UserStorage:{getItem:k=>storage.get(k), setItem:(k,v)=>storage.set(k,v)}, fetch:async (...args)=>{calls.push(args); return {ok:true, json:async()=>({success:true})};}};
    vm.runInNewContext(source, {window, document, Date:{now:()=>now}, Number, JSON});
    return {handlers, calls, document, window, advance:ms=>{now+=ms;}, fire:(props={})=>handlers.pointerdown({isTrusted:true,...props})};
}
const flush = () => new Promise(resolve=>setImmediate(resolve));
test('only trusted foreground interaction signals; no timer, visibility or page-load listeners', async()=>{
    const h=harness();
    assert.deepEqual(Object.keys(h.handlers), ['pointerdown','keydown','touchstart']);
    assert.equal(h.calls.length,0);
    h.fire({isTrusted:false});
    h.document.hidden=true; h.fire();
    h.document.hidden=false; h.document.hasFocus=()=>false; h.fire();
    assert.equal(h.calls.length,0);
    h.document.hasFocus=()=>true; h.fire(); await flush();
    assert.deepEqual(JSON.parse(JSON.stringify(h.calls[0])), ['/api/caller-visit', {method:'POST',keepalive:true}]);
});
test('one signal per episode across navigation, then another only after trusted idle return', async()=>{
    const storage=new Map(); const h=harness(storage);
    h.handlers.keydown({isTrusted:true}); await flush();
    h.fire(); await flush(); assert.equal(h.calls.length,1);
    const next=harness(storage); next.fire(); await flush(); assert.equal(next.calls.length,0);
    for(let i=0;i<4;i++){next.advance(20*60000);next.fire();await flush();}
    assert.equal(next.calls.length,0);
    next.advance(31*60000); assert.equal(next.calls.length,0);
    next.handlers.touchstart({isTrusted:true}); await flush(); assert.equal(next.calls.length,1);
});
test('anonymous and missing-CSRF pages cannot send; failed request retries only with interaction',async()=>{
    const h=harness(); h.window.currentUserId=null; h.fire(); assert.equal(h.calls.length,0);
    h.window.currentUserId=1; h.document.querySelector=()=>null; h.fire(); assert.equal(h.calls.length,0);
    h.document.querySelector=()=>({content:'csrf'});
    h.window.fetch=async()=>{h.calls.push('failed');throw Error('offline');};
    h.fire();await flush(); h.fire();assert.equal(h.calls.length,1);
    h.advance(61000);assert.equal(h.calls.length,1);h.fire();await flush();assert.equal(h.calls.length,2);
});
