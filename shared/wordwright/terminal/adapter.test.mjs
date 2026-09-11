import test from 'node:test';
import assert from 'node:assert/strict';
import { EventEmitter } from 'node:events';
import { PassThrough } from 'node:stream';
import { TerminalAdapter, plain } from './adapter.mjs';
import { runTerminal } from './cli.mjs';
import { WordwrightSession } from '../dist/session.js';
import { loadDictionary } from '../dist/upstream/src/dictionary/loadDictionary.js';
let id=0;
async function fixture(length=5,answer){const d=await loadDictionary(length);return new TerminalAdapter(await WordwrightSession.create(length,{id:()=>`terminal-${++id}`,random:{next:()=>answer?(d.answers.indexOf(answer)+.1)/d.answers.length:0}}),{color:false});}
const letters=(a,text)=>{for(const c of text)a.handle(c,{name:c.toLowerCase()});};
const enter=a=>a.handle('\r',{name:'return'});
const esc=a=>a.handle('\x1b',{name:'escape'});
const command=(a,c)=>{esc(a);a.handle(c);};
const win=a=>{letters(a,a.snapshot().game.answer);enter(a);};
function fits(a){const lines=plain(a.render()).split('\r\n');assert(lines.length<=24);assert(Math.max(...lines.map(l=>l.length))<=80);}

test('all A-Z including N/L/S/Q translate directly without commands',()=>{
 const seen=[];const a=new TerminalAdapter({inputLetter:l=>seen.push(l)});
 letters(a,'ABCDEFGHIJKLMNOPQRSTUVWXYZ');assert.equal(seen.join(''),'ABCDEFGHIJKLMNOPQRSTUVWXYZ');assert.equal(a.screen,'game');
});
test('backspace and submit/reveal delegated in order',()=>{
 const seen=[];const a=new TerminalAdapter({backspace:()=>seen.push('backspace'),submit:()=>{seen.push('submit');return {ok:true};},completeReveal:()=>seen.push('reveal')});
 a.handle('',{name:'backspace'});enter(a);assert.deepEqual(seen,['backspace','submit','reveal']);
});
test('rejected shared submission does not complete reveal',()=>{
 const a=new TerminalAdapter({submit:()=>({ok:false,error:'not-a-word'}),completeReveal:()=>assert.fail()});enter(a);assert.match(a.message,/Not in word/);
});
test('Esc command mode leaves partial guess untouched',async()=>{
 const a=await fixture(6);letters(a,'NLSQ');esc(a);a.handle('S');assert.equal(a.screen,'stats');esc(a);assert.equal(a.snapshot().game.currentInput,'NLSQ');
});
for(const length of [4,5,6])test(`${length} letter ANSI and plain layouts fit 80x24`,async()=>{const a=await fixture(length);fits(a);a.color=true;fits(a);assert.match(plain(a.render()),new RegExp(`${length} letters`));});
test('valid/invalid guesses display shared results',async()=>{const a=await fixture();letters(a,'ZZZZZ');enter(a);assert.equal(a.snapshot().game.guesses.length,0);assert.match(a.render(),/Not in word list/);for(let i=0;i<5;i++)a.handle('',{name:'backspace'});win(a);assert.match(a.render(),/SOLVED/);});
test('duplicate letters have readable canonical symbols',async()=>{const a=await fixture(5,'APPLE');letters(a,'ALLEY');enter(a);assert.match(a.render(),/A=  L\+  L-  E\+  Y-/);});
test('six wrong attempts display loss; finished alphabet ignored canonically',async()=>{const a=await fixture(5,'APPLE');for(let i=0;i<6;i++){letters(a,'ALLEY');enter(a);}assert.match(a.render(),/Answer: APPLE/);const before=a.snapshot();letters(a,'NLSQ');enter(a);assert.deepEqual(a.snapshot(),before);});
test('several successive games select answers through shared newGame',async()=>{const a=await fixture();const seen=new Set();for(let i=0;i<4;i++){assert(!seen.has(a.snapshot().game.answer));seen.add(a.snapshot().game.answer);win(a);command(a,'N');}assert.equal(a.snapshot().stats.overall.gamesPlayed,4);});
test('length selection clears old draft through shared setLength',async()=>{const a=await fixture();letters(a,'AB');command(a,'L');a.handle('6');assert.equal(a.snapshot().selectedLength,6);assert.equal(a.snapshot().game.currentInput,'');});
test('stats history pages and all screens fit',async()=>{const a=await fixture();for(let i=0;i<7;i++){win(a);command(a,'N');}command(a,'S');fits(a);assert.match(a.render(),/Played 7/);a.handle(']');assert.match(a.render(),/Page 2\/2/);a.handle('[');assert.match(a.render(),/Page 1\/2/);for(const screen of ['game','menu','help','length']){a.screen=screen;fits(a);}});
test('partial local restore and continue',async()=>{const a=await fixture(6);letters(a,'NLSQ');const saved=JSON.parse(JSON.stringify(a.snapshot()));const b=new TerminalAdapter(await WordwrightSession.restore(saved));assert.deepEqual(b.snapshot(),saved);b.handle('A');assert.equal(b.snapshot().game.currentInput,'NLSQA');});
test('completed session, stats, length restore parity',async()=>{const a=await fixture(4);win(a);const saved=a.prepareHandoff();const b=new TerminalAdapter(await WordwrightSession.restore(saved));assert.deepEqual(b.snapshot(),saved);assert.match(b.render(),/SOLVED/);});
test('Ctrl-C and menu Q request exit, ordinary Q is a letter',async()=>{const a=await fixture();a.handle('Q');assert.equal(a.snapshot().game.currentInput,'Q');assert.equal(a.handle('',{ctrl:true,name:'c'}),'quit');esc(a);assert.equal(a.handle('Q'),'quit');});
test('lifecycle restores raw mode/cursor/screen and removes handlers on quit',async()=>{
 const input=new PassThrough();input.isTTY=true;input.isRaw=false;input.setRawMode=v=>{input.isRaw=v;};
 const output=new EventEmitter();output.isTTY=true;output.columns=80;output.rows=24;let text='';output.write=s=>{text+=s;};
 const signals=new EventEmitter();let exits=0;const close=runTerminal(await fixture(),{input,output,signals,onExit:()=>exits++});
 assert.equal(input.isRaw,true);output.emit('resize');signals.emit('SIGTERM');close();
 assert.equal(exits,1);assert.equal(input.isRaw,false);assert.equal(signals.listenerCount('SIGTERM'),0);
 assert.match(text,/\x1b\[\?25h\x1b\[\?1049l$/);input.destroy();
});
test('unsupported terminal size displays resize guidance',async()=>{
 const input=new PassThrough();input.isTTY=true;input.setRawMode=()=>{};const output=new EventEmitter();Object.assign(output,{isTTY:true,columns:60,rows:20});let text='';output.write=s=>text+=s;
 const close=runTerminal(await fixture(),{input,output,signals:new EventEmitter()});assert.match(text,/needs 80 columns x 24/);close();input.destroy();
});
