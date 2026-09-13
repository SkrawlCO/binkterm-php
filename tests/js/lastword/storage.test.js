/**
 * Plain Node assertions for js/lastword/storage.js (M1A foundation).
 *
 * Uses a mock fetch (no network, no live server) to prove the client now
 * targets the REAL WebDoor storage contract:
 *
 *   GET/PUT/DELETE /api/webdoor/storage/{slot}?game_id=hangman
 *
 * instead of the obsolete /api/webdoor/save, /load, /delete endpoints that
 * the current js/webdoor.js calls and that 404. A server-side proof of the
 * same contract (against real WebDoorController code) lives in
 * tests/Unit/LastWordStorageContractTest.php.
 *
 * Run: node tests/js/lastword/storage.test.js
 */
'use strict';

const assert = require('assert');
const { createLastWordStorage } = require('../../../public_html/webdoors/hangman/js/lastword/storage.js');

let passed = 0;
async function check(name, fn) {
    await fn();
    passed++;
    console.log('  ok - ' + name);
}

function jsonResponse(status, body) {
    return {
        ok: status >= 200 && status < 300,
        status: status,
        json: () => Promise.resolve(body)
    };
}

async function main() {
    console.log('storage.test.js');

    await check('saveSession PUTs to the real storage endpoint with game_id and slot', async () => {
        const calls = [];
        const mockFetch = (url, opts) => {
            calls.push({ url, opts });
            return Promise.resolve(jsonResponse(200, { success: true, slot: 0, saved_at: 'now' }));
        };
        const storage = createLastWordStorage(mockFetch);
        const session = { stateVersion: 1, round: 1, cumulativeScore: 0 };

        const result = await storage.saveSession(session);

        assert.strictEqual(calls.length, 1);
        assert.strictEqual(calls[0].url, '/api/webdoor/storage/0?game_id=hangman');
        assert.strictEqual(calls[0].opts.method, 'PUT');
        const body = JSON.parse(calls[0].opts.body);
        assert.deepStrictEqual(body.data, session);
        assert.strictEqual(body.metadata.kind, 'lastword-session');
        assert.strictEqual(result.success, true);

        // Must never call the obsolete endpoint.
        assert.ok(!calls[0].url.includes('/api/webdoor/save'));
    });

    await check('loadSession GETs the real storage endpoint and unwraps `.data`', async () => {
        const savedSession = { stateVersion: 1, round: 2, cumulativeScore: 700 };
        const calls = [];
        const mockFetch = (url, opts) => {
            calls.push({ url, opts });
            return Promise.resolve(jsonResponse(200, { slot: 0, data: savedSession, metadata: {}, saved_at: 'now' }));
        };
        const storage = createLastWordStorage(mockFetch);

        const loaded = await storage.loadSession();

        assert.strictEqual(calls[0].url, '/api/webdoor/storage/0?game_id=hangman');
        assert.strictEqual(calls[0].opts.method, 'GET');
        assert.deepStrictEqual(loaded, savedSession);
    });

    await check('loadSession returns null (not a throw) on a 404 — no save yet', async () => {
        const mockFetch = () => Promise.resolve(jsonResponse(404, {}));
        const storage = createLastWordStorage(mockFetch);

        const loaded = await storage.loadSession();

        assert.strictEqual(loaded, null);
    });

    await check('deleteSession DELETEs the real storage endpoint', async () => {
        const calls = [];
        const mockFetch = (url, opts) => {
            calls.push({ url, opts });
            return Promise.resolve(jsonResponse(200, { success: true }));
        };
        const storage = createLastWordStorage(mockFetch);

        const result = await storage.deleteSession();

        assert.strictEqual(calls[0].url, '/api/webdoor/storage/0?game_id=hangman');
        assert.strictEqual(calls[0].opts.method, 'DELETE');
        assert.strictEqual(result.success, true);
    });

    await check('save -> load round-trips a representative session without structural loss', async () => {
        let stored = null;
        const mockFetch = (url, opts) => {
            if (opts && opts.method === 'PUT') {
                stored = JSON.parse(opts.body).data;
                return Promise.resolve(jsonResponse(200, { success: true }));
            }
            if (!opts || opts.method === 'GET') {
                return stored === null
                    ? Promise.resolve(jsonResponse(404, {}))
                    : Promise.resolve(jsonResponse(200, { slot: 0, data: stored }));
            }
            throw new Error('unexpected method');
        };
        const storage = createLastWordStorage(mockFetch);

        const session = {
            stateVersion: 1,
            round: 3,
            cumulativeScore: 2100,
            categoriesUsed: ['Movies & TV', 'Music'],
            rounds: [{ round: 1, outcome: 'solved' }],
            currentRound: { round: 3, puzzleId: 'games-0001', consonantsGuessed: ['T', 'H', 'L'] },
            finalState: null,
            startedAt: 1710000000000,
            finished: null
        };

        await storage.saveSession(session);
        const loaded = await storage.loadSession();

        assert.deepStrictEqual(loaded, session);
    });

    await check('save failure surfaces as a rejected promise rather than a silent swallow', async () => {
        const mockFetch = () => Promise.resolve(jsonResponse(500, {}));
        const storage = createLastWordStorage(mockFetch);

        await assert.rejects(() => storage.saveSession({}), /HTTP 500/);
    });

    console.log(`storage.test.js: ${passed} passed`);
}

main().catch((err) => {
    console.error(err);
    process.exit(1);
});
