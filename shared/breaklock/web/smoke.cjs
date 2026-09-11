// Optional local browser proof. Uses an existing Playwright/Chromium installation.
const fs = require('node:fs'), path = require('node:path'), http = require('node:http');
const assert = require('node:assert/strict');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = path.resolve(process.argv[2]), output = path.resolve(process.argv[3]);
fs.mkdirSync(output, { recursive: true });
(async () => {
    const server = http.createServer((req, res) => {
        const name = decodeURIComponent(new URL(req.url, 'http://localhost').pathname);
        const file = path.resolve(root, '.' + (name === '/' ? '/index.html' : name));
        if (!file.startsWith(root + '/') || !fs.existsSync(file) || !fs.statSync(file).isFile()) return res.writeHead(404).end();
        res.setHeader('Content-Type', ({ '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml' })[path.extname(file)] || 'application/octet-stream');
        res.setHeader('Cache-Control', 'no-store'); res.end(fs.readFileSync(file));
    });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    let browser;
    const checks = [], errors = [];
    try {
        browser = await chromium.launch({ executablePath: process.env.CHROME_BIN, headless: true, args: ['--no-sandbox'] });
        const page = await browser.newPage({ viewport: { width: 960, height: 800 } });
        page.on('pageerror', e => errors.push(e.message));
        // Test-only delivery of the SAME registered callback; no core mutation API.
        await page.addInitScript(() => {
            const nativeSet = window.setInterval.bind(window), nativeClear = window.clearInterval.bind(window), callbacks = new Map();
            window.setInterval = (fn, ms, ...args) => { const id = nativeSet(fn, ms, ...args); if (ms === 1000) callbacks.set(id, () => fn(...args)); return id; };
            window.clearInterval = id => { callbacks.delete(id); nativeClear(id); };
            window.__deliverTicks = count => { for (let i = 0; i < count; i++) for (const fn of [...callbacks.values()]) fn(); };
        });
        const url = `http://127.0.0.1:${server.address().port}/`;
        await page.goto(url); await page.waitForFunction(() => !!window.breaklock);
        const snapshot = () => page.evaluate(() => window.breaklock.snapshot());
        async function drag(sequence) {
            const box = await page.locator('svg.lock').boundingBox(); assert(box && box.width > 0);
            const point = n => ({ x: box.x + box.width * (15 + n % 3 * 35) / 100, y: box.y + box.height * (15 + Math.floor(n / 3) * 35) / 100 });
            const first = point(sequence[0]); await page.mouse.move(first.x, first.y); await page.mouse.down();
            for (const n of sequence.slice(1)) { const p = point(n); await page.mouse.move(p.x, p.y); }
            await page.mouse.up();
        }
        const wrong = s => JSON.stringify(s.secret) === '[0,1,4,2]' ? [0,1,2,5] : [0,1,4,2];
        await page.locator('.action-btn').click(); assert.equal((await snapshot()).mode, 'practice'); checks.push('menu/start');
        // Partial gesture proving midpoint through the real pointer path.
        let box = await page.locator('svg.lock').boundingBox();
        await page.mouse.move(box.x+box.width*.15, box.y+box.height*.15); await page.mouse.down();
        await page.mouse.move(box.x+box.width*.85, box.y+box.height*.15);
        assert.deepEqual((await snapshot()).draft, [0,1,2]); await page.mouse.up(); checks.push('canonical midpoint');
        let s = await snapshot(); await drag(wrong(s)); s = await snapshot();
        assert.equal(s.counterValue, 1); assert.equal(s.history.length, 1);
        assert.equal(await page.locator('.history-container svg').count(), 1); checks.push('Practice wrong/history');
        await drag(s.secret); s = await snapshot(); assert.equal(s.ended, 'won'); assert.equal(s.counterValue, 1);
        assert.equal(s.summary.visible, true); await page.locator('.summary.active').waitFor(); checks.push('Practice win counter/summary');
        await page.locator('.summary button[rel="1"]').click(); s = await snapshot();
        assert.equal(s.history.length, 2); assert.equal(s.summary.visible, false); await drag(wrong(s));
        assert.equal((await snapshot()).ended, 'won'); checks.push('win reveal/continue');
        await page.locator('.status-bar-cancel').click(); await page.locator('.selector-right').click();
        await page.locator('.action-btn').click(); s = await snapshot(); assert.equal(s.mode, 'challenge');
        await drag(wrong(s)); assert.equal((await snapshot()).counterValue, 9);
        for (let i = 1; i < 10; i++) await drag(wrong(s));
        s = await snapshot(); assert.equal(s.counterValue, 0); assert.equal(s.ended, 'lost'); checks.push('Challenge ten attempts/failure');
        await page.locator('.summary button[rel="1"]').click();
        s = await snapshot(); assert.equal(s.history.length, 11); assert.equal(s.counterValue, 10);
        assert.equal(await page.locator('svg[data-entry="reveal"]').count(), 1);
        await drag(s.secret); assert.equal((await snapshot()).ended, 'lost'); checks.push('loss reveal/continue');
        await page.locator('.status-bar-cancel').click(); await page.locator('.selector-right').click();
        await page.locator('.action-btn').click(); assert.equal((await snapshot()).mode, 'countdown');
        await page.waitForTimeout(1150); s = await snapshot(); assert(s.timer.remainingTicks < 60);
        assert.equal(await page.locator('.countdown-counter').textContent(), String(s.timer.remainingTicks)); checks.push('Countdown visible real callback');
        await page.evaluate(() => window.__deliverTicks(window.breaklock.snapshot().timer.remainingTicks));
        s = await snapshot(); assert.equal(s.ended, 'lost'); assert.equal(s.timer.running, false); checks.push('Countdown timeout summary');
        await page.waitForTimeout(1100);
        await page.screenshot({ path: path.join(output, 'desktop.png') });
        assert.equal(await page.evaluate(async () => (await navigator.serviceWorker.getRegistrations()).length), 0);
        checks.push('no service worker');
        fs.writeFileSync(path.join(output, 'snapshot.json'), JSON.stringify(s, null, 2)); checks.push('snapshot export');
        const mobile = await browser.newPage({ viewport: { width: 375, height: 812 }, isMobile: true, hasTouch: true });
        mobile.on('pageerror', e => errors.push(e.message)); await mobile.goto(url); await mobile.locator('.action-btn').tap();
        const answer = await mobile.evaluate(() => window.breaklock.snapshot().secret);
        const client = await mobile.context().newCDPSession(mobile); box = await mobile.locator('svg.lock').boundingBox();
        for (let i = 0; i < answer.length; i++) { const n = answer[i]; await client.send('Input.dispatchTouchEvent', { type: i ? 'touchMove' : 'touchStart', touchPoints: [{ x: box.x+box.width*(15+n%3*35)/100, y: box.y+box.height*(15+Math.floor(n/3)*35)/100 }] }); }
        await client.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
        assert.equal(await mobile.evaluate(() => window.breaklock.snapshot().ended), 'won');
        assert(await mobile.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
        await mobile.waitForTimeout(1100);
        await mobile.screenshot({ path: path.join(output, 'mobile.png') }); checks.push('375px mobile touch win/layout');
        assert.deepEqual(errors, []);
        fs.writeFileSync(path.join(output, 'result.json'), JSON.stringify({ result: 'PASS', checks, errors }, null, 2));
        console.log(JSON.stringify({ result: 'PASS', checks, errors }, null, 2));
    } finally { if (browser) await browser.close(); await new Promise(resolve => server.close(resolve)); }
})().catch(error => { console.error(error); process.exitCode = 1; });
