import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { helperTransport } from './node-transport.js';
import { BreakLockSession } from './session.js';
import { BreakLockTerminal } from '../terminal/adapter.js';
const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT_MODULE);
const root = process.cwd(), site = '/tmp/breaklock-persistence-site';
const helpers = [], sessions = [], checks = [], errors = [];
const check = name => { checks.push(name); console.log('PASS ' + name); };
function helper(caller = 1) {
    const h = helperTransport('docker', ['run', '--rm', '-i', '--network', 'container:breaklock-persistence-proof',
        '--mount', `type=bind,src=${root},dst=/work,readonly`, '-w', '/work',
        '-e', 'TATHAM_TEST_DSN=pgsql:host=127.0.0.1;dbname=tatham_slice1', '--entrypoint', 'php',
        'binktermphp-binkterm-app:latest', 'shared/breaklock/persistence/fixture.php', String(caller)]);
    helpers.push(h); return h;
}
execFileSync('docker', ['exec', 'breaklock-persistence-proof', 'psql', '-U', 'postgres', '-d', 'tatham_slice1', '-c', "DELETE FROM webdoor_storage WHERE game_id='breaklock'"]);
const web = helper();
const server = http.createServer(async (req, res) => {
    if (req.url === '/state') {
        // Disposable authenticated review host: one fixed caller, no caller ID in requests.
        if (req.headers.cookie !== 'review=caller-one' || req.headers['x-csrf-token'] !== 'review-csrf') return res.writeHead(403).end();
        let body = ''; for await (const chunk of req) body += chunk;
        res.setHeader('Content-Type', 'application/json'); res.setHeader('Cache-Control', 'no-store');
        try { res.end(JSON.stringify(await web.request(JSON.parse(body)))); } catch { res.writeHead(500).end(); }
        return;
    }
    const file = path.resolve(site, '.' + (req.url === '/' ? '/index.html' : req.url));
    if (!file.startsWith(site + '/') || !fs.existsSync(file)) return res.writeHead(404).end();
    res.setHeader('Content-Type', ({ '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml' })[path.extname(file)] || 'text/plain');
    res.end(fs.readFileSync(file));
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const url = `http://127.0.0.1:${server.address().port}`;
let browser;
try {
    browser = await chromium.launch({ executablePath: process.env.CHROME_BIN, headless: true, args: ['--no-sandbox'] });
    const context = await browser.newContext({ viewport: { width: 960, height: 800 } });
    await context.addCookies([{ name: 'review', value: 'caller-one', url }]);
    await context.addInitScript(() => { window.breaklockStorage = { endpoint: '/state', csrfToken: 'review-csrf' }; });
    async function pageOpen() {
        const p = await context.newPage(); p.on('pageerror', e => errors.push(e.message));
        await p.goto(url); await p.evaluate(() => window.breaklock.ready); return p;
    }
    let page = await pageOpen();
    const state = () => page.evaluate(() => window.breaklock.snapshot());
    async function drag(seq, release = true) {
        const box = await page.locator('svg.lock').boundingBox();
        const point = n => [box.x + box.width * (15 + n % 3 * 35) / 100, box.y + box.height * (15 + Math.floor(n / 3) * 35) / 100];
        await page.mouse.move(...point(seq[0])); await page.mouse.down();
        for (const n of seq.slice(1)) await page.mouse.move(...point(n));
        if (release) await page.mouse.up();
    }
    await page.locator('.action-btn').click();
    const wrong = JSON.stringify((await state()).secret) === '[0,1,4,2]' ? [0,1,2,5] : [0,1,4,2];
    await drag(wrong); await drag([0,1], false);
    const saved = await page.evaluate(() => window.breaklock.exit());
    assert.equal(saved.history.length, 1); assert.deepEqual(saved.draft, [0,1]); await page.close();
    const terminal = new BreakLockTerminal(); const th = helper();
    let session = new BreakLockSession(terminal, th.request); sessions.push(session);
    assert.deepEqual(await session.acquire(), saved);
    assert.deepEqual(terminal.round.snapshot(), saved); check('Web -> terminal exact persisted snapshot, completed guess + partial draft');
    terminal.handleKey('c'); for (const n of wrong) terminal.handleKey(String(n+1));
    terminal.handleKey('c'); terminal.handleKey('9');
    const back = await session.exit(); assert.equal(back.history.length, 2); assert.deepEqual(back.draft, [8]);
    page = await pageOpen(); assert.deepEqual(await state(), back);
    await drag(wrong); assert.equal((await state()).history.length, 3); check('terminal -> Web exact restore and continued Web play');
    await page.evaluate(() => window.breaklock.checkpoint()); check('explicit checkpoint');
    await page.locator('.status-bar-cancel').click();
    await page.locator('.selector-right').click(); await page.locator('.selector-right').click();
    await page.locator('.action-btn').click(); await page.waitForTimeout(2250);
    const clockSave = await page.evaluate(() => window.breaklock.exit());
    assert.equal(clockSave.mode, 'countdown'); assert(clockSave.timer.remainingTicks <= 58);
    assert(clockSave.timer.nextTickDelayMs > 0 && clockSave.timer.nextTickDelayMs < 1000);
    await page.close(); await new Promise(resolve => setTimeout(resolve, 1300));
    const next = new BreakLockTerminal(); session = new BreakLockSession(next, helper().request); sessions.push(session);
    assert.deepEqual(await session.acquire(), clockSave);
    const ticks = next.round.snapshot().timer.remainingTicks;
    const started = performance.now();
    while (next.round.snapshot().timer.remainingTicks === ticks) await new Promise(resolve => setTimeout(resolve, 5));
    const elapsed = performance.now() - started;
    assert.equal(next.round.snapshot().timer.remainingTicks, ticks - 1);
    assert(Math.abs(elapsed - clockSave.timer.nextTickDelayMs) < 100, `${elapsed} versus ${clockSave.timer.nextTickDelayMs}`);
    for (const n of next.round.snapshot().secret) next.handleKey(String(n+1));
    assert.equal(next.round.snapshot().ended, 'won'); assert.equal(next.round.snapshot().timer.running, false);
    await session.exit(); check(`Countdown: disconnected 1300ms, no catch-up, residual ${clockSave.timer.nextTickDelayMs}ms resumed in ${Math.round(elapsed)}ms, win`);
    const a = helper(), b = helper(); const leaseA = await a.request({ action: 'acquire' });
    assert(leaseA.success); assert.equal((await b.request({ action: 'acquire' })).success, false);
    await a.request({ action: 'release', owner_token: leaseA.owner_token });
    const leaseB = await b.request({ action: 'acquire' });
    const write = { action: 'save', owner_token: leaseB.owner_token, attempt_id: leaseB.attempt_id, revision: leaseB.revision, data: leaseB.data };
    assert((await b.request(write)).success);
    assert.equal((await b.request(write)).success, false); check('same-owner stale revision rejected');
    assert.equal((await a.request({ ...write, owner_token: leaseA.owner_token })).success, false);
    const release = await b.request({ action: 'release', owner_token: leaseB.owner_token });
    assert.equal(release.revision, leaseB.revision + 1); assert.deepEqual(release.data, leaseB.data); check('former writer rejected; successor data preserved');
    const other = await helper(2).request({ action: 'acquire' }); assert(other.success); assert.deepEqual(other.data, []); check('caller isolation');
    assert.equal((await web.request({ action: 'acquire', user_id: 2 })).success, false); check('request caller spoof rejected');
    const unauth = await fetch(url + '/state', { method: 'POST', body: '{}' }); assert.equal(unauth.status, 403); check('review host rejects unauthenticated request');
    assert.deepEqual(errors, []); check('browser runtime has no errors');
    fs.writeFileSync('/tmp/breaklock-persistence-proof.json', JSON.stringify({ checks, saved, back, clockSave }, null, 2));
} finally {
    for (const s of sessions) { clearInterval(s.heartbeat); s.surface.suspend(); }
    await browser?.close(); for (const h of helpers) h.close(); await new Promise(resolve => server.close(resolve));
}
