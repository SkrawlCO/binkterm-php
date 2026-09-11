// Real authenticated Web -> PostgreSQL -> terminal adapter proof. Isolated callers only.
import assert from 'node:assert/strict';
import { readFile, writeFile } from 'node:fs/promises';
import { execFileSync } from 'node:child_process';
import { chromium } from '../web/node_modules/@playwright/test/index.mjs';
import { helperTransport } from './node-transport.mjs';
import { connectTerminal } from './terminal.mjs';
import { DokuelSession as Session } from '../dist/session.js';
const { callers: [a,b] } = JSON.parse(await readFile('/tmp/dokuel-persistence/callers.json'));
const url='http://127.0.0.1:43203', helpers=[], results=[];
const pass=name=>{results.push(name);console.log('PASS '+name);};
const transport=c=>{const h=helperTransport('docker',['exec','-i','-e',`DOOR_USER_NUMBER=${c.id}`,'binkterm-app','php','/var/www/html/shared/dokuel/persistence/helper.php']);helpers.push(h);return h;};
const expire=()=>execFileSync('docker',['exec','-e','DOKUEL_REVIEW=1','binkterm-app','php','/var/www/html/shared/dokuel/persistence/review/control.php','expire']);
const browser=await chromium.launch({args:['--no-sandbox']});let terminal;
try {
 const context=await browser.newContext({viewport:{width:1280,height:800}});
 await context.addCookies([{name:'binktermphp_session',value:a.session,url,httpOnly:true}]);
 const p=await context.newPage(), errors=[];p.on('pageerror',e=>errors.push(e.message));p.on('dialog',d=>d.accept());await p.goto(url);
 const connect=()=>p.evaluate(csrf=>window.dokuelReview.connect('/state',csrf),a.csrf);
 const snap=()=>p.evaluate(()=>window.dokuelReview.snapshot());
 const checkpoint=()=>p.evaluate(()=>window.dokuelReview.checkpoint());
 const release=()=>p.evaluate(()=>window.dokuelReview.release());
 const cell=i=>p.locator(`button[data-row="${Math.floor(i/9)}"][data-col="${i%9}"]`);
 const digit=n=>p.locator(`[data-numpad-digit="${n}"]`);
 const terminalOpen=async()=>{terminal=await connectTerminal(transport(a).request,{intervalMs:100000});return terminal;};
 const terminalRelease=async()=>{const s=await terminal.lease.release();terminal=null;return s;};
 await connect();pass('authenticated Web acquire with generated Easy puzzle');
 assert.equal((await fetch(url+'/state',{method:'POST',body:'{"action":"acquire"}'})).status,401);
 assert.equal(await p.evaluate(()=>fetch('/state',{method:'POST',body:'{"action":"acquire"}'}).then(r=>r.status)),403);
 assert.equal(await p.evaluate(csrf=>fetch('/state',{method:'POST',headers:{'X-CSRF-Token':csrf},body:'{"action":"acquire","caller_id":99}'}).then(r=>r.status),a.csrf),400);pass('authentication, CSRF and caller-override rejection');
 let s=await snap();const early=JSON.stringify(s).length;const v=Session.restore(s).view();const empty=[...s.puzzle].flatMap((c,i)=>c==='.'?[i]:[]);const [i,j]=empty;
 await cell(i).click();await digit(Number(v.state.solution[i])).click();
 await cell(j).click();const n=digit(3);await n.hover();await p.mouse.down();await p.waitForTimeout(310);await p.mouse.up();
 await cell(j).click();await p.waitForTimeout(1100);
 await p.getByRole('button',{name:'Hint',exact:true}).click();
 s=await checkpoint();assert(s.elapsedMs>0);assert(s.actions.length>3);const webState=Session.restore(s).view().state;assert(webState.activeHint);assert(!['mistake','reveal'].includes(webState.activeHint.technique));assert(webState.history.length>0);assert(webState.board.flat().some(c=>c.notes.size>0));pass('real Web entry, notes, selection, undo history, logical hint and elapsed checkpoint');
 // Pause through canonical Web control before exact transfer.
 await p.getByRole('button',{name:'Pause',exact:true}).click();
 const sent=await release();assert(sent.paused);await p.waitForTimeout(1100);
 await terminalOpen();assert.deepEqual(terminal.lease.session.snapshot(),sent);assert.deepEqual(terminal.lease.session.view(),Session.restore(sent).view());pass('Web -> PostgreSQL -> terminal exact paused state and canonical hint parity');
 terminal.adapter.key('p');terminal.adapter.advanceTime(1250);
 const moveTo=index=>{let pos=terminal.adapter.session.view().state.selectedCell;if(!pos){terminal.adapter.key('right');pos=terminal.adapter.session.view().state.selectedCell;}while(pos.row!==Math.floor(index/9)){terminal.adapter.key(pos.row<Math.floor(index/9)?'down':'up');pos=terminal.adapter.session.view().state.selectedCell;}while(pos.col!==index%9){terminal.adapter.key(pos.col<index%9?'right':'left');pos=terminal.adapter.session.view().state.selectedCell;}};
 moveTo(i);terminal.adapter.key('0');terminal.adapter.key(String(v.state.solution[i]));
 moveTo(j);terminal.adapter.key('n');terminal.adapter.key(String(v.state.solution[j]));terminal.adapter.key('n');
 assert(terminal.adapter.session.view().state.history.length>webState.history.length);
 const back=await terminalRelease();assert.equal(back.elapsedMs,sent.elapsedMs+1250);assert(!back.paused);
 await connect();let returned=await snap();assert.equal(returned.elapsedMs,back.elapsedMs);assert.deepEqual(returned,back);pass('terminal -> PostgreSQL -> Web values/notes/selection/history and active timing parity');
 await p.waitForTimeout(600);assert((await snap()).elapsedMs>back.elapsedMs);await cell(j).click();await digit(7).click();await release();pass('Web continues timing and play after active handoff');
 await connect();await p.getByRole('button',{name:'Back',exact:true}).click();await p.getByRole('button',{name:'Daily Challenge',exact:true}).click();
 s=await snap();const di=s.puzzle.indexOf('.'), dj=s.puzzle.indexOf('.',di+1);await cell(di).click();await digit(2).hover();await p.mouse.down();await p.waitForTimeout(310);await p.mouse.up();await cell(dj).click();await digit(1).click();
 const daily=await release();assert.equal(daily.identity.kind,'daily');assert(Session.restore(daily).view().state.board.flat().some(c=>c.notes.size>0));await terminalOpen();assert.deepEqual(terminal.lease.session.snapshot(),daily);assert.deepEqual(terminal.lease.session.view(),Session.restore(daily).view());await terminalRelease();pass('Daily original givens, identity and progress exact across surfaces without regeneration');
 // Deterministic completion through canonical terminal actions, then opposite-surface restore.
 await terminalOpen();const solved='534678912672195348198342567859761423426853791713924856961537284287419635345286179';
 terminal.adapter.restore(Session.fromPuzzle('.'+solved.slice(1),'easy').snapshot());terminal.lease.replace(terminal.adapter.session);
 terminal.adapter.key('5');terminal.adapter.advanceTime(800);assert.equal(terminal.adapter.session.view().state.status,'completed');const completed=await terminalRelease();
 await connect();assert.deepEqual(await snap(),completed);assert.equal(Session.restore(await snap()).view().state.status,'completed');await release();await connect();assert.deepEqual(await snap(),completed);await release();pass('completed terminal -> Web exact grid, frozen time and repeat restore without side effects');
 // Heavy partial note/history snapshot is informational, not journal compaction.
 await terminalOpen();terminal.adapter.start('medium','dokuel-persistence-heavy');terminal.lease.replace(terminal.adapter.session);
 const hi=terminal.adapter.session.snapshot().puzzle.indexOf('.');terminal.adapter.session.selectCell(Math.floor(hi/9),hi%9);terminal.adapter.key('n');
 for(let k=0;k<400;k++)terminal.adapter.key(String(k%9+1));
 const heavy=await terminalRelease();assert.deepEqual(Session.restore(heavy).snapshot(),heavy);pass('note/history-heavy journal restores exactly');
 const ha=transport(a),hb=transport(b),owned=await ha.request({action:'acquire'}),other=await hb.request({action:'acquire'});assert.equal(Object.keys(other.data).length,0);
 assert.equal((await hb.request({action:'save',owner_token:owned.owner_token,attempt_id:other.attempt_id,revision:other.revision,data:heavy})).success,false);
 assert.equal((await ha.request({action:'save',owner_token:other.owner_token,attempt_id:owned.attempt_id,revision:owned.revision,data:heavy})).success,false);
 await hb.request({action:'release',owner_token:other.owner_token});pass('two real callers isolated and foreign owner tokens rejected');
 expire();const hs=transport(a),successor=await hs.request({action:'acquire'});assert.deepEqual(successor.data,heavy);pass('abrupt-close lease expiry recovers last checkpoint');
 const advanced=await hs.request({action:'save',owner_token:successor.owner_token,attempt_id:successor.attempt_id,revision:successor.revision,data:daily});assert.equal(advanced.revision,successor.revision+1);
 assert.equal((await ha.request({action:'save',owner_token:owned.owner_token,attempt_id:owned.attempt_id,revision:owned.revision,data:heavy})).success,false);await hs.request({action:'release',owner_token:successor.owner_token});
 await connect();assert.deepEqual(await snap(),daily);pass('stale writer rejected; successor snapshot remains authoritative');
 expire();const ht=transport(a),last=await ht.request({action:'acquire'});await assert.rejects(checkpoint);assert.equal(await p.evaluate(()=>window.dokuelReview.storageStatus().active),false);const frozen=await snap();await p.keyboard.press('3');await p.waitForTimeout(500);assert.deepEqual(await snap(),frozen);assert.match(await p.getByRole('status').innerText(),/Save stopped: conflict/);await ht.request({action:'release',owner_token:last.owner_token});pass('visible Web stale-writer failure freezes input and timer');
 // Store an incompatible snapshot only for isolated caller B; acquire must reject and release.
 const bLease=await hb.request({action:'acquire'});await hb.request({action:'save',owner_token:bLease.owner_token,attempt_id:bLease.attempt_id,revision:bLease.revision,data:{...daily,revision:'future'}});await hb.request({action:'release',owner_token:bLease.owner_token});await assert.rejects(connectTerminal(hb.request));const unlocked=await hb.request({action:'acquire'});assert(unlocked.success);await hb.request({action:'release',owner_token:unlocked.owner_token});pass('incompatible engine revision rejected without a stranded lease');
 assert.deepEqual(errors,[]);pass('zero browser errors');
 const sizes={early,heavy:JSON.stringify(heavy).length,completed:JSON.stringify(completed).length};console.log('Snapshot bytes',sizes);
 await writeFile('/tmp/dokuel-persistence/results.json',JSON.stringify({results,sizes},null,2));console.log(`${results.length} persistence checks PASS`);
} finally {terminal?.lease.abandon();for(const h of helpers)h.close();await browser.close();}
