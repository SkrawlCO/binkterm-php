import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { chromium } from '../web/node_modules/@playwright/test/index.mjs';
import { helperTransport } from './node-transport.mjs';
import { connectTerminal } from './terminal.mjs';
import { DokuelSession as Session } from '../dist/session.js';
const {callers:[a]}=JSON.parse(await readFile('/tmp/dokuel-persistence/callers.json'));
const helper=helperTransport('docker',['exec','-i','-e',`DOOR_USER_NUMBER=${a.id}`,'binkterm-app','php','/var/www/html/shared/dokuel/persistence/helper.php']);
const browser=await chromium.launch({args:['--no-sandbox']});let terminal;
try {
 terminal=await connectTerminal(helper.request);
 const solved='534678912672195348198342567859761423426853791713924856961537284287419635345286179';
 terminal.adapter.restore(Session.fromPuzzle('..'+solved.slice(2),'easy').snapshot());terminal.lease.replace(terminal.adapter.session);
 terminal.adapter.key('1');terminal.adapter.key('h');const hint=terminal.adapter.session.view().state.activeHint;assert.equal(hint.technique,'mistake');
 const saved=await terminal.lease.release();terminal=null;
 const context=await browser.newContext({viewport:{width:375,height:667},isMobile:true,hasTouch:true});
 await context.addCookies([{name:'binktermphp_session',value:a.session,url:'http://127.0.0.1:43203',httpOnly:true}]);
 const p=await context.newPage();await p.goto('http://127.0.0.1:43203');await p.evaluate(csrf=>window.dokuelReview.connect('/state',csrf),a.csrf);
 const restored=await p.evaluate(()=>window.dokuelReview.snapshot());assert.deepEqual(restored,saved);assert.deepEqual(Session.restore(restored).view().state.activeHint,hint);console.log('PASS Mistake hint exact terminal -> PostgreSQL -> Web restoration');
 for(const width of [375,320]){await p.setViewportSize({width,height:667});await p.waitForTimeout(350);assert(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));await p.screenshot({path:`/tmp/dokuel-persistence/persistent-mobile-${width}.png`});}
 console.log('PASS connected 375px and 320px controls and hint layout without overflow');
 const released=p.waitForResponse(r=>r.url().endsWith('/state') && r.request().postDataJSON()?.action==='release');
 await p.getByRole('button',{name:'Save & Return',exact:true}).tap();await (await released).finished();await p.waitForFunction(()=>window.dokuelReview.storageStatus().active===false);assert(!await p.evaluate(()=>window.dokuelReview.storageStatus().error));console.log('PASS explicit touch Save & Return saves and releases');
} finally {terminal?.lease.abandon();helper.close();await browser.close();}
