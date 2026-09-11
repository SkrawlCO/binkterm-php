import assert from 'node:assert/strict';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { helperTransport } from './node-transport.mjs';
const require = createRequire(import.meta.url);
const { connectTerminal } = require('./terminal.cjs');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE);
const root = process.cwd(), base = 'shared/ordinary-puzzles/persistence/';
const docker = ['run','--rm','-i','--network','container:ordinary-persistence-proof','--mount',`type=bind,src=${root},dst=/work,readonly`,'-w','/work','-e','TATHAM_TEST_DSN=pgsql:host=127.0.0.1;dbname=tatham_slice1'];
const auth = JSON.parse(execFileSync('docker',[...docker,'--entrypoint','php','binktermphp-binkterm-app:latest',base+'test-seed.php']));
const helpers=[], sessions=[], checks=[], sizes={};
const ok=s=>{checks.push(s);console.log('PASS '+s);};
function helper(caller=1){const h=helperTransport('docker',[...docker,'-e',`DOOR_USER_NUMBER=${caller}`,'--entrypoint','php','binktermphp-binkterm-app:latest','-d',`auto_prepend_file=/work/${base}test-bootstrap.php`,base+'helper.php']);helpers.push(h);return h;}
const sql=s=>execFileSync('docker',['exec','ordinary-persistence-proof','psql','-U','postgres','-d','tatham_slice1','-c',s]);
const browser=await chromium.launch({executablePath:process.env.CHROME_PATH,headless:true,args:['--no-sandbox']});
try {
 const context=await browser.newContext({viewport:{width:1100,height:900}}), url='http://127.0.0.1:43189';
 await context.addCookies([{name:'binktermphp_session',value:auth.session,url}]);
 await context.addInitScript(options=>{window.ordinaryPuzzlesStorage=options;},{endpoint:'/state',csrfToken:auth.csrf});
 const errors=[];
 async function open(){const p=await context.newPage();p.on('pageerror',e=>errors.push(e.message));await p.goto(url);await p.evaluate(()=>ordinaryPuzzles.ready);return p;}
 let page=await open();ok('project Auth session + CSRF acquire through PHP endpoint');
 const snap=()=>page.evaluate(()=>ordinaryPuzzles.snapshot());
 async function drag(a,b,release=true){const center=async c=>{const r=await page.locator(`[data-cell="${c.join(':')}"]`).boundingBox();return[r.x+r.width/2,r.y+r.height/2];};await page.mouse.move(...await center(a));await page.mouse.down();await page.mouse.move(...await center(b));if(release)await page.mouse.up();}
 await drag([0,1],[0,3]);await drag([0,4],[0,5],false);
 const early=await snap();sizes.early=Buffer.byteLength(JSON.stringify(early));assert(early.verification.interaction.dragging);
 await page.evaluate(()=>ordinaryPuzzles.checkpoint());await page.evaluate(()=>ordinaryPuzzles.exit());await page.close();
 const h=helper();let t=await connectTerminal(h.request);sessions.push(t.session);
 assert.deepEqual(t.adapter.snapshot(),early);ok('Web -> PostgreSQL -> terminal exact journal, lines, geometry and unfinished drag');
 t.adapter.key('space');t.adapter.key('a');t.adapter.key('r');t.adapter.key('space');t.adapter.key('d');
 const back=await t.session.exit();await h.close();
 page=await open();assert.deepEqual(await snap(),back);ok('terminal -> PostgreSQL -> Web exact replay after reset and new unfinished drag');
 await drag([0,5],[0,4]);assert.equal((await snap()).verification.interaction.dragging,false);ok('restored Web unfinished interaction continues with real pointer input');
 for(let i=0;i<12;i++){await drag([0,1],[0,3]);await drag([0,1],[0,1]);}
 sizes.longer=Buffer.byteLength(JSON.stringify(await snap()));
 await page.evaluate(()=>ordinaryPuzzles.load('e9c2882a25e2'));
 for(const line of require('../test/quire-solution.json').solution) for(const target of [line.coords[0],line.coords.at(-1)])if(target.join()!==line.origin.join())await drag(line.origin,target);
 const solved=await snap();assert(solved.verification.cleared);sizes.solved=Buffer.byteLength(JSON.stringify(solved));await page.evaluate(()=>ordinaryPuzzles.exit());await page.close();
 t=await connectTerminal(helper().request);sessions.push(t.session);assert.deepEqual(t.adapter.snapshot(),solved);assert(t.adapter.state().lines.every(l=>l.completed));assert(t.adapter.render().join('\n').includes('PUZZLE COMPLETE!'));await t.session.exit();ok('canonical solved Web -> database -> terminal state and completion rendering');
 const a=helper(),b=helper(),leaseA=await a.request({action:'acquire'});assert(leaseA.success);assert.equal((await b.request({action:'acquire'})).success,false);ok('active writer excludes second acquire');
 const write=(lease,data=solved)=>({action:'save',owner_token:lease.owner_token,attempt_id:lease.attempt_id,revision:lease.revision,data});
 const savedA=await a.request(write(leaseA));assert(savedA.success);
 // Test-only expiry, no long lease wait or clock changes.
 sql("UPDATE webdoor_storage SET metadata=jsonb_set(metadata,'{lease_expires_at}','0') WHERE user_id=1 AND game_id='ordinary-puzzles'");
 const leaseB=await b.request({action:'acquire'});assert(leaseB.success);assert.deepEqual(leaseB.data,solved);ok('abrupt-close expiry restores last acknowledged checkpoint');
 const successor=await b.request(write(leaseB,early));assert(successor.success);
 assert.equal((await a.request(write({...leaseA,revision:savedA.revision}))).success,false);
 assert.equal((await b.request(write(leaseB))).success,false);
 const released=await b.request({action:'release',owner_token:leaseB.owner_token});assert.deepEqual(released.data,early);assert.equal(released.revision,successor.revision);ok('former owner and stale revision rejected, successor preserved');
 const other=helper(2),lease2=await other.request({action:'acquire'});assert(lease2.success);assert.deepEqual(lease2.data,[]);await other.request({action:'release',owner_token:lease2.owner_token});ok('active database callers isolated');
 assert.equal((await a.request({action:'acquire',caller_id:2})).success,false);assert.equal((await a.request({action:'acquire',game_id:'tatham'})).success,false);ok('caller and namespace injection rejected');
 const missing=await fetch(url+'/state',{method:'POST',headers:{'Content-Type':'application/json'},body:'{}'});assert.equal(missing.status,401);
 const bad=await context.request.post(url+'/state',{data:{action:'acquire'}});assert.equal(bad.status(),403);ok('real Auth rejects absent session and missing CSRF');
 // Prove visible conflict handling freezes the actual Web surface.
 page=await open();const before=await snap();sql("UPDATE webdoor_storage SET metadata=jsonb_set(metadata,'{lease_expires_at}','0') WHERE user_id=1 AND game_id='ordinary-puzzles'");
 const takeover=await b.request({action:'acquire'});assert(takeover.success);
 await page.getByText('Checkpoint',{exact:true}).click();await page.getByRole('alert').filter({hasText:'Progress storage stopped'}).waitFor();
 await drag([0,1],[0,3]);assert.deepEqual(await snap(),before);await b.request({action:'release',owner_token:takeover.owner_token});ok('Web stale writer visibly fails and freezes input');
 assert.deepEqual(errors,[]);ok('no browser runtime errors');
 console.log(JSON.stringify({checks:checks.length,snapshotBytes:sizes}));fs.writeFileSync('/tmp/ordinary-persistence-proof.json',JSON.stringify({checks,snapshotBytes:sizes},null,2));
} finally {for(const s of sessions)s.abandon();await browser.close();for(const h of helpers)h.close();}
