import { chromium, expect } from '@playwright/test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { DokuelSession as Session } from '../../dist/session.js';
import { getDailyPuzzle } from '../../dist/upstream/src/lib/daily.js';

const base = process.env.DOKUEL_URL ?? 'http://127.0.0.1:43198';
const output = new URL('../test-results/', import.meta.url);
fs.mkdirSync(output, { recursive: true });
const solved = '534678912672195348198342567859761423426853791713924856961537284287419635345286179';
const fresh = () => Session.fromPuzzle('..' + solved.slice(2), 'easy');
const errors = [], external = [], sockets = [], results = [];
const browser = await chromium.launch({ headless: true, args: ['--no-sandbox'] });
async function check(name, fn) {
    if (process.env.DOKUEL_CHECK && !name.includes(process.env.DOKUEL_CHECK)) return;
    await fn(); results.push(name); console.log(`ok ${results.length} - ${name}`);
}
async function context(mobile = false, width = 375) {
    const c = await browser.newContext({ viewport: mobile ? { width, height: 667 } : { width: 1280, height: 800 }, isMobile: mobile, hasTouch: mobile, reducedMotion: 'reduce' });
    const p = await c.newPage();
    p.on('pageerror', e => errors.push(e.message));
    p.on('console', m => { if (m.type() === 'error') errors.push(m.text()); });
    p.on('websocket', s => sockets.push(s.url()));
    await p.route(u => u.origin !== new URL(base).origin, r => { external.push(r.request().url()); return r.abort(); });
    await p.goto(base);
    return p;
}
const cell = (p, r, c) => p.locator(`button[data-row="${r}"][data-col="${c}"]`);
const digit = (p, n) => p.locator(`[data-numpad-digit="${n}"]`);
async function review(p) {
    await p.getByRole('button', { name: 'Review snapshot', exact: true }).first().click();
    await expect(p.locator('#snapshot')).toBeVisible();
}
async function restore(p, s) {
    if (!await p.locator('#snapshot').count()) await review(p);
    await p.locator('#snapshot').fill(typeof s === 'string' ? s : s.export());
    await p.getByRole('button', { name: 'Restore snapshot', exact: true }).click();
    await expect(p.getByRole('region', { name: 'Sudoku board' })).toBeVisible();
}
async function exported(p) {
    await review(p);
    await p.getByRole('button', { name: 'Export snapshot', exact: true }).click();
    return JSON.parse(await p.locator('#snapshot').inputValue());
}
async function hold(p, locator, touch = false) {
    await locator.scrollIntoViewIfNeeded();
    const b = await locator.boundingBox(), x = b.x + b.width / 2, y = b.y + b.height / 2;
    if (touch) {
        const cdp = await p.context().newCDPSession(p);
        await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x, y }] });
        await p.waitForTimeout(300);
        await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
        await cdp.detach();
    } else {
        await p.mouse.move(x, y); await p.mouse.down(); await p.waitForTimeout(300); await p.mouse.up();
    }
}
async function noOverflow(p) {
    const widths = await p.evaluate(() => ({ viewport: innerWidth, content: document.documentElement.scrollWidth }));
    assert.ok(widths.content <= widths.viewport, JSON.stringify(widths));
}
try {
    const p = await context();
    await check('surface loads with canonical difficulty picker', async () => {
        await expect(p.getByRole('heading', { name: 'Dokuel', exact: true })).toBeVisible();
        await p.getByRole('button', { name: 'Start Solo', exact: true }).click();
        for (const d of ['Easy', 'Medium', 'Hard', 'Expert']) await expect(p.getByRole('button', { name: new RegExp('^' + d) })).toBeVisible();
    });
    for (const d of ['easy', 'medium', 'hard', 'expert']) await check(`${d} UI start uses shared canonical seed and metadata`, async () => {
        await p.goto(base); await p.getByRole('button', { name: 'Start Solo' }).click();
        await p.getByRole('button', { name: new RegExp('^' + d, 'i') }).click();
        await expect(p.locator('button[data-row]')).toHaveCount(81);
        const s = await exported(p);
        assert.equal(s.difficulty, d); assert.equal(s.identity.kind, 'solo');
        assert.equal(s.puzzle, Session.startSolo(d, s.identity.key).snapshot().puzzle);
    });
    await check('Daily UI start has canonical date, puzzle and exact restore', async () => {
        await p.goto(base); await p.getByRole('button', { name: 'Daily Challenge' }).click();
        const s = await exported(p);
        assert.equal(s.identity.kind, 'daily'); assert.equal(s.difficulty, 'medium');
        assert.equal(s.puzzle, getDailyPuzzle(s.identity.date, 'medium').puzzle);
        await restore(p, JSON.stringify(s)); assert.deepEqual(await exported(p), s);
    });
    await check('digit entry, erase and working undo through shared actions', async () => {
        await restore(p, fresh()); await cell(p, 0, 0).click(); await digit(p, 5).click();
        await expect(cell(p, 0, 0)).toHaveAttribute('aria-label', /value 5/);
        await p.getByRole('button', { name: 'Erase', exact: true }).click();
        await expect(cell(p, 0, 0)).toHaveAttribute('aria-label', /empty/);
        await p.getByRole('button', { name: 'Undo', exact: true }).click();
        await expect(cell(p, 0, 0)).toHaveAttribute('aria-label', /value 5/);
        const s = Session.restore(await exported(p));
        assert.equal(s.view().grid.values[0], '5'); assert.equal(s.view().state.history.length, 1);
    });
    await check('hold notes toggle/remove and canonical peer-note elimination undo', async () => {
        await restore(p, fresh()); await cell(p, 0, 1).click(); await hold(p, digit(p, 5));
        await expect(cell(p, 0, 1)).toHaveAttribute('aria-label', /notes 5/);
        await hold(p, digit(p, 5)); await expect(cell(p, 0, 1)).not.toHaveAttribute('aria-label', /notes/);
        await hold(p, digit(p, 5)); await cell(p, 0, 0).click(); await digit(p, 5).click();
        await expect(cell(p, 0, 1)).not.toHaveAttribute('aria-label', /notes/);
        await p.getByRole('button', { name: 'Undo', exact: true }).click();
        await expect(cell(p, 0, 1)).toHaveAttribute('aria-label', /notes 5/);
    });
    await check('keyboard note mode, cursor selection and erase', async () => {
        await restore(p, fresh()); await cell(p, 0, 0).click(); await p.keyboard.press('n'); await p.keyboard.press('5');
        await expect(cell(p, 0, 0)).toHaveAttribute('aria-label', /notes 5/);
        const s = Session.restore(await exported(p)); assert.equal(s.view().state.notesMode, true);
        assert.equal(s.view().state.selectedCell, null); // canonical note gesture releases selection
    });
    await check('drag multi-selection uses shared batch notes', async () => {
        await restore(p, fresh());
        await cell(p, 0, 0).scrollIntoViewIfNeeded();
        const a = await cell(p, 0, 0).boundingBox(), b = await cell(p, 0, 1).boundingBox();
        await p.mouse.move(a.x+a.width/2,a.y+a.height/2); await p.mouse.down();
        await p.mouse.move(b.x+b.width/2,b.y+b.height/2,{steps:8}); await p.mouse.up();
        await digit(p,5).click();
        await expect(cell(p,0,0)).toHaveAttribute('aria-label',/notes 5/);
        await expect(cell(p,0,1)).toHaveAttribute('aria-label',/notes 5/);
        const s=Session.restore(await exported(p)); assert.deepEqual(s.view().grid.notes.slice(0,2),[[5],[5]]);
    });
    await check('wrong-entry conflicts and canonical Mistake explanation', async () => {
        await restore(p, fresh()); await cell(p,0,0).click(); await p.keyboard.press('3');
        await expect(cell(p,0,0)).toHaveAttribute('aria-label', /conflict/);
        await p.getByRole('button',{name:'Hint',exact:true}).click(); await expect(p.getByText('Mistake',{exact:true})).toBeVisible();
        const s=Session.restore(await exported(p)); assert.equal(s.view().state.activeHint.technique,'mistake');
        assert.ok(s.view().projection.errors.has(0)); assert.ok(s.view().projection.conflicts.size > 0);
    });
    await check('logical hint text, highlights, usage and exact active-hint restore', async () => {
        await restore(p, fresh()); await cell(p,0,0).click(); await p.getByRole('button',{name:'Hint',exact:true}).click();
        await expect(p.getByText('Naked Single',{exact:true})).toBeVisible();
        const s=await exported(p), state=Session.restore(s).view().state;
        assert.equal(state.hintsUsed,1); assert.ok(state.activeHint.relatedCells.length);
        await restore(p,JSON.stringify(s)); await expect(p.getByText(state.activeHint.explanation,{exact:true})).toBeVisible();
        assert.deepEqual(await exported(p),s);
    });
    await check('explicit canonical Reveal fallback is presented', async () => {
        const s=Session.fromPuzzle('1....7.9..3..2...8..96..5....53..9...1..8...26....4...3......1..4......7..7...3..','expert');
        for(let i=0;i<81;i++){s.requestHint();const h=s.view().state.activeHint;if(h.technique==='reveal')break;s.enterDigit(h.value);}
        assert.equal(s.view().state.activeHint.technique,'reveal'); s.dismissHint();
        await restore(p,s);await p.getByRole('button',{name:'Hint',exact:true}).click();await expect(p.getByText('Reveal',{exact:true})).toBeVisible();
    });
    await check('pause/resume freezes shared timing and input', async () => {
        const s=fresh();s.advanceTime(65000);await restore(p,s);
        await p.getByRole('button',{name:'Pause',exact:true}).click();
        await expect(p.getByRole('button',{name:'Resume game',exact:true})).toBeVisible();
        await p.keyboard.press('5');const frozen=await exported(p);await p.waitForTimeout(1100);
        await p.getByRole('button',{name:'Export snapshot',exact:true}).click();assert.deepEqual(JSON.parse(await p.locator('#snapshot').inputValue()),frozen);
        await restore(p,JSON.stringify(frozen));await p.getByRole('button',{name:'Resume game',exact:true}).click();await p.waitForTimeout(1100);
        const next=await exported(p);assert.ok(next.elapsedMs>=frozen.elapsedMs+900);assert.ok(next.elapsedMs>=65000);
    });
    await check('snapshot preserves values notes selection history and derived state', async () => {
        const s=Session.startSolo('easy','web-restore');const v=s.view();const cells=[];
        for(let r=0;r<9;r++)for(let c=0;c<9;c++)if(!v.state.board[r][c].isGiven)cells.push([r,c]);
        const [a,b]=cells;s.selectCell(...a);s.enterDigit(Number(v.state.solution[a[0]*9+a[1]]));s.toggleNoteAt(...b,2);s.selectCell(...b);s.pause();s.advanceTime(1000);
        await restore(p,s);assert.deepEqual(await exported(p),s.snapshot());
        await restore(p,s);await p.getByRole('button',{name:'Resume game',exact:true}).click();await p.getByRole('button',{name:'Undo',exact:true}).click();
        s.resume();s.undo();s.pause();const got=Session.restore(await exported(p)).view();const want=s.view();got.elapsedMs=want.elapsedMs;assert.deepEqual(got,want);
    });
    await check('completion uses canonical state and completed snapshot restores exactly', async () => {
        const s=Session.fromPuzzle('.'+solved.slice(1),'easy');s.advanceTime(9000);await restore(p,s);
        await cell(p,0,0).click();await digit(p,5).click();await expect(p.getByRole('dialog').getByText('You Won!')).toBeVisible();
        const saved=await exported(p);assert.equal(Session.restore(saved).view().state.status,'completed');
        await restore(p,JSON.stringify(saved));await expect(p.getByRole('dialog').getByText('You Won!')).toBeVisible();assert.deepEqual(await exported(p),saved);
    });
    await check('invalid restore reports error and retains existing session', async () => {
        await p.locator('#snapshot').fill('{broken');await p.getByRole('button',{name:'Restore snapshot',exact:true}).click();await expect(p.getByRole('alert')).toBeVisible();
        await p.getByRole('button',{name:'Export snapshot',exact:true}).click();assert.equal(Session.restore(await p.locator('#snapshot').inputValue()).view().state.status,'completed');
    });
    await check('1280x800 desktop layout and settings keyboard accessibility', async () => {
        await restore(p,Session.startSolo('easy','desktop-layout'));await p.waitForTimeout(700);await noOverflow(p);
        await p.getByRole('button',{name:'Settings',exact:true}).click();await expect(p.getByRole('button',{name:'Close settings'})).toBeVisible();
        await p.keyboard.press('Escape');await expect(p.getByRole('button',{name:'Settings',exact:true})).toBeFocused();
        await p.screenshot({path:new URL('desktop.png',output).pathname,fullPage:true});
    });
    for(const width of [375,320]) await check(`${width}x667 touch entry notes hints settings and completion layout`,async()=>{
        const m=await context(true,width);await restore(m,fresh());
        await cell(m,0,1).tap();await hold(m,digit(m,5),true);await expect(cell(m,0,1)).toHaveAttribute('aria-label',/notes 5/);
        await cell(m,0,0).tap();await digit(m,5).tap();await expect(cell(m,0,0)).toHaveAttribute('aria-label',/value 5/);
        await expect(cell(m,0,1)).not.toHaveAttribute('aria-label',/notes/);
        await m.getByRole('button',{name:'Undo',exact:true}).tap();await expect(cell(m,0,1)).toHaveAttribute('aria-label',/notes 5/);
        await m.getByRole('button',{name:'Hint',exact:true}).tap();await expect(m.getByRole('button',{name:'Dismiss hint'})).toBeVisible();await noOverflow(m);
        await m.screenshot({path:new URL(`mobile-${width}-hint.png`,output).pathname,fullPage:true});
        const board=await m.getByRole('region',{name:'Sudoku board'}).boundingBox();assert.ok(board.width>=width-35);
        const key=await digit(m,5).boundingBox();assert.ok(key.height>=44);assert.ok(key.width>=28);
        await m.getByRole('button',{name:'Settings',exact:true}).tap();await noOverflow(m);await expect(m.getByRole('button',{name:'Close settings'})).toBeVisible();await m.getByRole('button',{name:'Close settings'}).tap();
        await restore(m,Session.fromPuzzle('.'+solved.slice(1),'easy'));await cell(m,0,0).tap();await digit(m,5).tap();
        await expect(m.getByRole('dialog').getByText('You Won!')).toBeVisible();await noOverflow(m);
        await m.getByRole('dialog').getByRole('button',{name:'Review snapshot'}).tap();await expect(m.locator('#snapshot')).toBeVisible();
        await restore(m,Session.startSolo('easy','mobile-layout'));
        await m.waitForTimeout(700); await noOverflow(m);
        await expect(m.locator('[data-numpad-digit]:visible')).toHaveCount(9);
        await m.screenshot({path:new URL(`mobile-${width}-game.png`,output).pathname,fullPage:true});
        await m.getByRole('button',{name:'Hint',exact:true}).tap();
        await m.waitForTimeout(700);
        const hintedBoard=await m.getByRole('region',{name:'Sudoku board'}).boundingBox();
        assert.ok(hintedBoard.width>=width-35,JSON.stringify(hintedBoard));
        await noOverflow(m);
        // Full-page capture changes Chromium's viewport while ResizeObserver
        // is snapping canonical cells. Capture the real phone viewport instead.
        await m.evaluate(() => window.scrollTo(0, 0));
        await m.waitForTimeout(100);
        await m.screenshot({path:new URL(`mobile-${width}-hint-viewport.png`,output).pathname});
        await digit(m,5).scrollIntoViewIfNeeded();
        await m.screenshot({path:new URL(`mobile-${width}-hint-numpad.png`,output).pathname});
        await m.context().close();
    });
    await check('no browser errors, external services, browser persistence or service workers',async()=>{
        assert.deepEqual(errors,[]);assert.deepEqual(external,[]);assert.deepEqual(sockets,[]);
        assert.deepEqual(await p.evaluate(async()=>({local:localStorage.length,session:sessionStorage.length,workers:(await navigator.serviceWorker.getRegistrations()).length,databases:(await indexedDB.databases()).length})),{local:0,session:0,workers:0,databases:0});
    });
    fs.writeFileSync(new URL(process.env.DOKUEL_CHECK ? 'results-filtered.json' : 'results.json',output),JSON.stringify({passed:results.length,results,errors,external,sockets},null,2));
    console.log(`${results.length} Web checks PASS`);
} finally { await browser.close(); }
