// Run only with isolated caller credentials created by the documented review setup.
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {createRequire} from 'node:module';
import {execFileSync} from 'node:child_process';
import {helperTransport} from './node-transport.mjs';
import {connectTerminal} from './terminal.mjs';
import {loadDictionary} from '../dist/upstream/src/dictionary/loadDictionary.js';
import {scoreGame} from '../dist/upstream/src/engine/score.js';
const require=createRequire(import.meta.url);
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'/root/.npm/_npx/e41f203b7505f1fb/node_modules/playwright');
const {callers}=JSON.parse(await readFile(process.env.WORDWRIGHT_PROOF_CALLERS||'/tmp/wordwright-persistence/callers.json'));
const [a,b]=callers;const url=process.env.WORDWRIGHT_PROOF_URL||'http://127.0.0.1:43193';
const helpers=[];let checks=0;const pass=m=>{checks++;console.log('PASS '+m);};
const transport=caller=>{const h=helperTransport('docker',['exec','-i','-e',`DOOR_USER_NUMBER=${caller.id}`,'binkterm-app','php','/var/www/html/shared/wordwright/persistence/helper.php']);helpers.push(h);return h;};
const browser=await chromium.launch({executablePath:process.env.CHROME_BIN||'/root/.cache/puppeteer/chrome/linux-148.0.7778.97/chrome-linux64/chrome',args:['--no-sandbox']});
const contexts=[];let terminal;
try {
 const context=await browser.newContext({viewport:{width:1100,height:850}});contexts.push(context);
 await context.addCookies([{name:'binktermphp_session',value:a.session,url,httpOnly:true}]);
 const page=await context.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.goto(url);await page.getByRole('grid',{name:'Guess board'}).waitFor();
 const connect=()=>page.evaluate(csrf=>window.wordwrightReview.connect('/state',csrf),a.csrf);
 const snap=()=>page.evaluate(()=>window.wordwrightReview.snapshot());
 const release=()=>page.evaluate(()=>window.wordwrightReview.release());
 const checkpoint=()=>page.evaluate(()=>window.wordwrightReview.checkpoint());
 await connect();pass('authenticated Web acquire');
 assert.equal((await fetch(url+'/state',{method:'POST',headers:{'Content-Type':'application/json'},body:'{"action":"acquire"}'})).status,401);pass('unauthenticated rejected');
 assert.equal((await page.evaluate(()=>fetch('/state',{method:'POST',headers:{'Content-Type':'application/json'},body:'{"action":"acquire"}'}).then(r=>r.status))),403);pass('missing CSRF rejected');
 assert.equal(await page.evaluate(csrf=>fetch('/state',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({action:'acquire',user_id:999})}).then(r=>r.status),a.csrf),400);pass('body caller override rejected');
 // Deterministic play: read the actual canonical selected answer, then type it.
 let s=await snap();await page.keyboard.type(s.game.answer);await page.keyboard.press('Enter');
 assert.equal((await snap()).game.status,'revealing');
 const completed=await release();assert.equal(completed.game.status,'won');assert.equal(completed.stats.overall.gamesPlayed,1);pass('mid-reveal atomic release completes canonical result');
 const h=transport(a);terminal=await connectTerminal(h.request);assert.deepEqual(terminal.adapter.snapshot(),completed);pass('completed Web -> PostgreSQL -> terminal exact stats/meta/game');
 const r=completed.stats.recentGames[0];assert.equal(r.score,scoreGame(r));assert.equal(completed.stats.overall.currentStreak,1);assert.equal(completed.stats.overall.bestStreak,1);assert.equal(completed.stats.overall.distribution[0],1);pass('canonical score/time/streak/distribution');
 await terminal.lease.release();h.close();terminal=null;
 await connect();assert.deepEqual(await snap(),completed);pass('restored completion deduplication');
 await page.getByRole('button',{name:'Play again',exact:true}).click();
 s=await snap();const dictionary=await loadDictionary(5);const wrong=dictionary.answers.find(w=>w!==s.game.answer);
 await page.keyboard.type(wrong);await page.keyboard.press('Enter');await page.waitForFunction(()=>window.wordwrightReview.snapshot().game.status==='playing');
 await page.keyboard.type(s.game.answer.slice(0,2));
 const early=await checkpoint();assert.equal(early.game.currentInput,s.game.answer.slice(0,2));assert.equal(early.game.guesses.length,1);pass('atomic checkpoint with partial draft and meaningful stats');
 const sent=await release();const h2=transport(a);terminal=await connectTerminal(h2.request);
 assert.deepEqual(terminal.adapter.snapshot(),sent);pass('Web -> database -> terminal partial state exact parity');
 for(const ch of s.game.answer.slice(2))terminal.adapter.handle(ch,{name:ch.toLowerCase()});
 terminal.adapter.handle('\r',{name:'return'});
 const won=await terminal.lease.checkpoint();assert.equal(won.stats.overall.gamesPlayed,2);assert.equal(won.stats.overall.gamesWon,2);assert.equal(won.stats.recentGames.length,2);assert.equal(won.stats.overall.currentStreak,2);assert.equal(won.stats.overall.distribution[1],1);
 await terminal.lease.release();terminal=null;h2.close();await connect();assert.deepEqual(await snap(),won);pass('terminal -> database -> Web completion and exact statistics');
 await release();await connect();assert.deepEqual((await snap()).stats,won.stats);pass('opposite-surface repeated restore does not duplicate stats');
 await page.getByRole('radio',{name:'6 letters'}).click();const switched=await release();assert.equal(switched.selectedLength,6);assert.notEqual(switched.game.id,won.game.id);assert.deepEqual(switched.stats,won.stats);pass('length switch discards game while retaining stats');
 const h3=transport(a);terminal=await connectTerminal(h3.request);assert.deepEqual(terminal.adapter.snapshot(),switched);pass('selected length and separate recent-answer buckets restore');
 // Repeated new-game commands consume canonical picker and restored metadata.
 const previous=terminal.adapter.snapshot().meta.recentAnswers[6];
 terminal.adapter.handle('',{name:'escape'});terminal.adapter.handle('N');assert(!previous.includes(terminal.adapter.snapshot().game.answer));
 for(const length of [4,5,6]){
  terminal.adapter.handle('',{name:'escape'});terminal.adapter.handle('L');terminal.adapter.handle(String(length));
  for(let i=0;i<53;i++){terminal.adapter.handle('',{name:'escape'});terminal.adapter.handle('N');}
 }
 const longer=await terminal.lease.release();terminal=null;h3.close();
 for(const length of [4,5,6])assert.equal(longer.meta.recentAnswers[length].length,50);pass('canonical repeat suppression, 50 entries separately per length');
 await connect();assert.deepEqual(await snap(),longer);await release();pass('longer metadata/state exact restore');
 const ha=transport(a),hb=transport(b);const owned=await ha.request({action:'acquire'});const other=await hb.request({action:'acquire'});
 assert.equal(Object.keys(other.data).length,0);
 assert.equal((await hb.request({action:'save',owner_token:owned.owner_token,attempt_id:other.attempt_id,revision:other.revision,data:longer})).success,false);
 assert.equal((await ha.request({action:'save',owner_token:other.owner_token,attempt_id:owned.attempt_id,revision:owned.revision,data:longer})).success,false);
 await hb.request({action:'release',owner_token:other.owner_token});
 const callerB=await connectTerminal(hb.request);callerB.adapter.handle('',{name:'escape'});callerB.adapter.handle('L');callerB.adapter.handle('4');
 const bState=await callerB.lease.release();assert.equal(bState.selectedLength,4);assert.equal(bState.stats.overall.gamesPlayed,0);
 assert.equal(bState.meta.recentAnswers[6].length,0);hb.close();pass('two callers cannot read or write each other state');
 const old={...owned};execFileSync('docker',['exec','-e','WORDWRIGHT_REVIEW=1','binkterm-app','php','/tmp/ww-control.php','expire']);
 const successor=transport(a);const fresh=await successor.request({action:'acquire'});assert.deepEqual(fresh.data,longer);pass('abrupt-close expiry recovers last checkpoint');
 const advanced=await successor.request({action:'save',owner_token:fresh.owner_token,attempt_id:fresh.attempt_id,revision:fresh.revision,data:longer});assert.equal(advanced.revision,fresh.revision+1);
 assert.equal((await ha.request({action:'save',owner_token:old.owner_token,attempt_id:old.attempt_id,revision:old.revision,data:completed})).success,false);pass('real stale writer rejected after successor revision advance');
 await successor.request({action:'release',owner_token:fresh.owner_token});successor.close();ha.close();
 await connect();assert.deepEqual(await snap(),longer);await release();pass('stale write leaves successor state authoritative');
 // Exercise stale-owner failure visibly on the actual Web surface too.
 await connect();const beforeStale=await snap();execFileSync('docker',['exec','-e','WORDWRIGHT_REVIEW=1','binkterm-app','php','/tmp/ww-control.php','expire']);
 const last=transport(a);const lastLease=await last.request({action:'acquire'});
 await last.request({action:'save',owner_token:lastLease.owner_token,attempt_id:lastLease.attempt_id,revision:lastLease.revision,data:beforeStale});
 await assert.rejects(checkpoint);assert.equal(await page.evaluate(()=>window.wordwrightReview.storageStatus().active),false);
 await page.keyboard.type('A');assert.deepEqual(await snap(),beforeStale);
 assert((await page.getByRole('status').allTextContents()).some(t=>t.includes('conflict')));pass('Web stale-writer failure visible and input suspended');
 await last.request({action:'release',owner_token:lastLease.owner_token});last.close();
 assert.deepEqual(errors,[]);pass('no browser errors');
 console.log('Snapshot bytes',JSON.stringify({early:Buffer.byteLength(JSON.stringify(early)),completed:Buffer.byteLength(JSON.stringify(completed)),longer:Buffer.byteLength(JSON.stringify(longer))}));
 console.log(`${checks}/${checks} real-storage handoff checks PASS`);
} finally {terminal?.lease.abandon();for(const h of helpers)h.close();await browser.close();}
