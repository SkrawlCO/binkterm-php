import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { WordwrightSession } from '../dist/session.js';
import { loadDictionary } from '../dist/upstream/src/dictionary/loadDictionary.js';
const require=createRequire(import.meta.url);
const {chromium}=require(process.env.PLAYWRIGHT_MODULE || '/root/.npm/_npx/e41f203b7505f1fb/node_modules/playwright');
const browser=await chromium.launch({executablePath:process.env.CHROME_BIN || '/root/.cache/puppeteer/chrome/linux-148.0.7778.97/chrome-linux64/chrome',args:['--no-sandbox']});
let count=0; const pass=name=>{count++; console.log(`PASS ${count}: ${name}`);};
try {
 const context=await browser.newContext({viewport:{width:1100,height:850}});
 const page=await context.newPage(); const errors=[]; page.on('response',r=>{if(r.status()>=400)errors.push(`${r.status()} ${r.url()}`);}); page.on('pageerror',e=>errors.push(e.message)); page.on('console',m=>{if(m.type()==='error')errors.push(m.text());});
 await page.goto(process.env.WORDWRIGHT_URL || 'http://127.0.0.1:43191/');
 await page.getByRole('grid',{name:'Guess board'}).waitFor(); pass('canonical Web surface loads');
 const snapshot=()=>page.evaluate(()=>window.wordwrightReview.snapshot());
 const restore=async value=>{await page.evaluate(v=>window.wordwrightReview.restore(v),value);await page.waitForTimeout(60);};
 const type=async word=>{await page.keyboard.type(word);};
 const submit=async()=>{await page.keyboard.press('Enter');await page.waitForFunction(()=>window.wordwrightReview.snapshot().game.status!=='revealing');};
 for(const length of [4,5,6]) {
  await page.getByRole('radio',{name:`${length} letters`}).click();
  assert.equal((await snapshot()).game.wordLength,length); pass(`${length}-letter selection`);
 }
 assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));pass('desktop no horizontal overflow'); await page.screenshot({path:'/tmp/wordwright-web-desktop.png'});
 await page.getByRole('radio',{name:'5 letters'}).click();pass('length switch delegates to shared session');
 const dict=await loadDictionary(5);
 const fixture=await WordwrightSession.create(5,{random:{next:()=> (dict.answers.indexOf('APPLE')+.1)/dict.answers.length},id:()=> 'web-fixture'});
 await restore(fixture.snapshot());
 await type('ZZZZZ'); await page.keyboard.press('Enter');
 assert.equal((await snapshot()).game.guesses.length,0); await page.getByText('Not in word list',{exact:true}).waitFor();pass('invalid dictionary rejection and toast');
 for(let i=0;i<5;i++)await page.keyboard.press('Backspace');
 await type('ALLEY'); await page.keyboard.press('Enter');
 assert.equal((await snapshot()).game.status,'revealing');pass('canonical reveal phase and animation');
 await page.waitForFunction(()=>window.wordwrightReview.snapshot().game.status==='playing');
 assert.deepEqual((await snapshot()).game.guesses[0].evaluation,['correct','present','absent','present','absent']);pass('duplicate-letter feedback');
 assert.equal(await page.getByRole('gridcell').count(),30);pass('valid guess renders canonical board');
 await type('AP'); const partial=await snapshot();pass('active snapshot with partial typed input');
 await restore(partial);assert.deepEqual(await snapshot(),partial);pass('partial input and active restore parity');
 // Demonstrate a true page reload followed by explicit local export/import; no browser persistence.
 await page.reload();await page.getByRole('grid',{name:'Guess board'}).waitFor();await restore(partial);
 assert.deepEqual(await snapshot(),partial);pass('reload plus explicit restore');
 await type('PLE'); await submit();assert.equal((await snapshot()).game.status,'won');pass('physical keyboard and win');
 const won=await snapshot(); await type('Z');assert.deepEqual(await snapshot(),won);pass('post-result input rejected');
 assert.equal(won.stats.overall.gamesWon,1);assert.equal(won.stats.overall.currentStreak,1);
 assert.deepEqual(won.stats.overall.distribution,[0,1,0,0,0,0]);pass('stats, streak, distribution update');
 assert.equal(won.stats.recentGames.length,1);assert(won.stats.recentGames[0].solveMs>0);pass('recent history and solve timing');
 await page.getByRole('button',{name:'Statistics',exact:true}).click();
 await page.getByRole('dialog').waitFor();assert((await page.getByRole('dialog').innerText()).includes('APPLE'));pass('canonical stats/history UI');
 await page.keyboard.press('Escape');
 await restore(won);assert.deepEqual(await snapshot(),won);pass('completed game, stats and meta restore');
 await page.getByRole('button',{name:'Play again',exact:true}).click();const next=await snapshot();
 assert.notEqual(next.game.id,won.game.id);assert.notEqual(next.game.answer,won.game.answer);pass('unlimited new game and recent answer suppression');
 const wrong=dict.answers.find(w=>w!==next.game.answer);
 for(let i=0;i<6;i++){await type(wrong);await submit();}
 assert.equal((await snapshot()).game.status,'lost');assert.equal((await snapshot()).stats.overall.currentStreak,0);pass('six-attempt loss and streak reset');
 await page.getByRole('button',{name:'Play again',exact:true}).click();
 const answer=(await snapshot()).game.answer;await type(answer);await page.keyboard.press('Enter');
 const handoff=await page.evaluate(()=>window.wordwrightReview.prepareHandoff());
 assert.equal(handoff.game.status,'won');await restore(handoff);assert.deepEqual(await snapshot(),handoff);pass('prepareHandoff canonically finishes reveal and records stats');
 await page.getByRole('button',{name:'Play again',exact:true}).click();
 await page.setViewportSize({width:375,height:812});
 assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));pass('375px mobile layout');
 await page.getByRole('button',{name:'A',exact:true}).click();assert.equal((await snapshot()).game.currentInput,'A');pass('on-screen keyboard');
 await page.screenshot({path:'/tmp/wordwright-web-mobile.png'});
 await page.getByRole('button',{name:'Settings',exact:true}).click();await page.getByRole('dialog').waitFor();pass('canonical settings dialog');
 await page.getByRole('switch',{name:'Colourblind mode'}).click(); assert.equal(await page.evaluate(()=>document.documentElement.dataset.palette),'cb');pass('colourblind palette');
 await page.getByRole('switch',{name:'Reduce motion'}).click();assert.equal(await page.evaluate(()=>document.documentElement.dataset.motion),'reduced');pass('reduced-motion setting');
 await page.keyboard.press('Escape');
 assert.equal(await page.evaluate(()=>localStorage.length),0);assert.equal(await page.evaluate(()=>navigator.serviceWorker.getRegistrations().then(r=>r.length)),0);pass('no localStorage authority or service worker');
 assert.deepEqual(errors,[]);pass('no browser errors');
 // Real touch input in mobile context.
 const touch=await browser.newContext({viewport:{width:375,height:812},isMobile:true,hasTouch:true,reducedMotion:'reduce'});
 const mobile=await touch.newPage();mobile.on('pageerror',e=>errors.push(e.message));await mobile.goto(process.env.WORDWRIGHT_URL || 'http://127.0.0.1:43191/');
 await mobile.getByRole('button',{name:'A',exact:true}).tap();
 assert.equal(await mobile.evaluate(()=>window.wordwrightReview.snapshot().game.currentInput),'A');pass('mobile touch event input');
 await mobile.getByRole('radio',{name:'6 letters'}).tap();assert(await mobile.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));pass('six-letter mobile board fits');
 await mobile.screenshot({path:'/tmp/wordwright-web-mobile-six.png'});assert.deepEqual(errors,[]);
 console.log(`${count}/${count} browser checks PASS`);
} finally { await browser.close(); }
