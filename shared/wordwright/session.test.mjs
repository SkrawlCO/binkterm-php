import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { createHash } from 'node:crypto';
import { WordwrightSession as Session, UPSTREAM_REVISION } from './dist/session.js';
import { loadDictionary } from './dist/upstream/src/dictionary/loadDictionary.js';
import { boardRows, remainingGuesses } from './dist/upstream/src/engine/selectors.js';
import { deriveKeyStates } from './dist/upstream/src/engine/keyboardState.js';
import { scoreGame } from './dist/upstream/src/engine/score.js';
import { evaluateGuess } from './dist/upstream/src/engine/evaluate.js';

let sequence = 0;
async function fixture(length = 5, answer) {
    let time = 1000;
    const dictionary = await loadDictionary(length);
    if (answer) assert.ok(dictionary.answers.includes(answer));
    const deps = { now: () => time, id: () => `game-${++sequence}`,
        random: { next: () => answer ? (dictionary.answers.indexOf(answer) + 0.1) / dictionary.answers.length : 0 } };
    return { session: await Session.create(length, deps), dictionary, deps, time: value => { time = value; } };
}
function type(session, word) { for (const letter of word) session.inputLetter(letter); }
function guess(session, word) { type(session, word); assert.equal(session.submit().ok, true); }
function win(session) { guess(session, session.getState().answer); session.completeReveal(); }
const wire = value => JSON.parse(JSON.stringify(value));

test('pinned source hashes and revision', async () => {
    const provenance = JSON.parse(await readFile('upstream.json'));
    assert.equal(provenance.revision, UPSTREAM_REVISION);
    for (const [file, expected] of Object.entries(provenance.sha256))
        assert.equal(createHash('sha256').update(await readFile('upstream/' + file)).digest('hex'), expected, file);
});
for (const length of [4, 5, 6]) test(`${length}-letter canonical game and distinct answer/guess lists`, async () => {
    const { session, dictionary } = await fixture(length);
    assert.equal(session.getState().answer.length, length);
    assert.equal(session.getState().wordLength, length);
    assert.equal(dictionary.answers.length, 700);
    assert.ok(dictionary.guesses.size > dictionary.answers.length);
    win(session); assert.equal(session.getState().status, 'won');
});
test('valid guess enters revealing without prematurely recording stats', async () => {
    const { session, dictionary } = await fixture();
    guess(session, dictionary.answers[1]);
    assert.equal(session.getState().status, 'revealing');
    assert.equal(session.getState().guesses.length, 1);
    assert.equal(session.getStats().overall.gamesPlayed, 0);
});
test('invalid dictionary guess leaves game unchanged', async () => {
    const { session } = await fixture(); type(session, 'ZZZZZ'); const before = session.snapshot();
    assert.equal(session.submit().error, 'not-a-word'); assert.deepEqual(session.snapshot(), before);
});
test('wrong-length guess leaves game unchanged', async () => {
    const { session } = await fixture(); type(session, 'AB'); const before = session.snapshot();
    assert.equal(session.submit().error, 'too-short'); assert.deepEqual(session.snapshot(), before);
});
test('duplicate-letter evaluation comes from canonical engine', async () => {
    const { session } = await fixture(5, 'APPLE'); guess(session, 'ALLEY');
    assert.deepEqual(session.getState().guesses[0].evaluation, ['correct','present','absent','present','absent']);
    assert.deepEqual(session.getState().guesses[0].evaluation, evaluateGuess('ALLEY', 'APPLE'));
});
test('win is decided on reveal completion', async () => {
    const { session } = await fixture(); guess(session, session.getState().answer);
    assert.equal(session.getState().status, 'revealing'); session.completeReveal(); assert.equal(session.getState().status, 'won');
});
test('six attempts lose; repeated guesses remain allowed', async () => {
    const { session, dictionary } = await fixture();
    for (let i = 0; i < 6; i++) { guess(session, dictionary.answers[1]); session.completeReveal(); }
    assert.equal(session.getState().status, 'lost'); assert.equal(session.getRemainingAttempts(), 0);
    assert.equal(session.getStats().overall.gamesPlayed, 1); assert.equal(session.getStats().overall.gamesWon, 0);
});
test('post-result input and submit rejected', async () => {
    const { session } = await fixture(); win(session); const before = session.snapshot();
    assert.equal(session.inputLetter('A'), false); assert.equal(session.backspace(), false);
    assert.equal(session.submit().error, 'not-playing'); assert.deepEqual(session.snapshot(), before);
});
test('partial input, uppercase, backspace, character/length restrictions are canonical', async () => {
    const { session } = await fixture(); type(session, 'a1bcdef'); assert.equal(session.getState().currentInput, 'ABCDE');
    session.backspace(); assert.equal(session.getState().currentInput, 'ABCD');
});
test('new game resets session while retaining metadata/stats', async () => {
    const { session } = await fixture(); win(session); const before = session.snapshot(); session.newGame();
    assert.notEqual(session.getState().id, before.game.id); assert.equal(session.getState().status, 'playing');
    assert.equal(session.getState().startedAt, null); assert.deepEqual(session.getState().guesses, []);
    assert.deepEqual(session.getStats(), before.stats);
});
test('switching length discards prior session; same-length selection does not', async () => {
    const { session } = await fixture(); type(session, 'AB'); session.setLength(4);
    assert.equal(session.snapshot().selectedLength, 4); assert.equal(session.getState().currentInput, '');
    const before = session.snapshot(); session.setLength(4); assert.deepEqual(session.snapshot(), before);
});
test('recent-answer suppression across successive new games', async () => {
    const { session } = await fixture(); const seen = new Set();
    for (let i = 0; i < 51; i++) { assert.ok(!seen.has(session.getState().answer)); seen.add(session.getState().answer); session.newGame(); }
});
test('recent answers capped at 50 independently per length', async () => {
    const { session } = await fixture();
    for (const length of [4,5,6]) { session.setLength(length); for (let i=0;i<55;i++) session.newGame(); }
    for (const length of [4,5,6]) assert.equal(session.snapshot().meta.recentAnswers[length].length, 50);
});
test('completion updates overall/per-length stats and canonical score', async () => {
    const { session, time } = await fixture(); guess(session, session.getState().answer); time(6000); session.completeReveal();
    const stats = session.getStats(); assert.equal(stats.overall.gamesPlayed, 1); assert.equal(stats.perLength[5].gamesWon, 1);
    assert.equal(stats.perLength[4].gamesPlayed, 0);
    assert.equal(stats.overall.totalScore, scoreGame({won:true,guessesUsed:1,wordLength:5,solveMs:5000}));
});
test('completion deduplicated by game ID across callbacks and restore', async () => {
    const { session, deps } = await fixture(); win(session); const before = session.snapshot();
    session.completeReveal(); const restored = await Session.restore(wire(before), deps); restored.completeReveal();
    assert.deepEqual(restored.snapshot(), before); assert.deepEqual(session.snapshot(), before);
});
test('winning streak increases and loss resets current but not best', async () => {
    const { session, dictionary } = await fixture(); win(session); session.newGame(); win(session);
    assert.equal(session.getStats().overall.currentStreak, 2); session.newGame();
    const wrong = dictionary.answers.find(w => w !== session.getState().answer);
    for (let i=0;i<6;i++) { guess(session, wrong); session.completeReveal(); }
    assert.equal(session.getStats().overall.currentStreak, 0); assert.equal(session.getStats().overall.bestStreak, 2);
});
test('distribution and cumulative winning guesses', async () => {
    const { session, dictionary } = await fixture(); guess(session, dictionary.answers[1]); session.completeReveal(); win(session);
    assert.deepEqual(session.getStats().overall.distribution, [0,1,0,0,0,0]); assert.equal(session.getStats().overall.totalGuessesInWins, 2);
});
test('solve time starts on first input and ends at reveal callback', async () => {
    const { session, time } = await fixture(); time(5000); guess(session, session.getState().answer); time(12000); session.completeReveal();
    assert.equal(session.getStats().overall.totalSolveMs, 7000); assert.equal(session.getStats().overall.fastestSolveMs, 7000);
});
test('canonical duration clamps negative and excessive time', async () => {
    for (const [end, expected] of [[0,0],[200000000,86400000]]) {
        const { session, time } = await fixture(); guess(session, session.getState().answer); time(end); session.completeReveal();
        assert.equal(session.getStats().recentGames[0].solveMs, expected);
    }
});
test('active game snapshot/restore preserves submitted rows', async () => {
    const { session, dictionary, deps } = await fixture(); guess(session, dictionary.answers[1]); session.completeReveal();
    const snapshot = wire(session.snapshot()); assert.deepEqual((await Session.restore(snapshot, deps)).snapshot(), snapshot);
});
test('partial input and selected length restore exactly', async () => {
    const { session, deps } = await fixture(6); type(session, 'ABC'); const snapshot = wire(session.snapshot());
    const restored = await Session.restore(snapshot, deps); assert.deepEqual(restored.snapshot(), snapshot);
    restored.inputLetter('D'); assert.equal(restored.getState().currentInput, 'ABCD');
});
test('completed session and stats restore exactly', async () => {
    const { session, deps } = await fixture(); win(session); const snapshot = wire(session.snapshot());
    assert.deepEqual((await Session.restore(snapshot, deps)).snapshot(), snapshot);
});
test('recent suppression persists across restore', async () => {
    const { session, deps } = await fixture(); session.newGame(); const snapshot = wire(session.snapshot());
    const restored = await Session.restore(snapshot, deps); session.newGame(); restored.newGame();
    assert.equal(session.getState().answer, restored.getState().answer); assert.deepEqual(session.snapshot().meta, restored.snapshot().meta);
});
test('ordinary reload preserves upstream revealing-to-playing quirk even for win', async () => {
    const { session, deps } = await fixture(); guess(session, session.getState().answer);
    const restored = await Session.restore(wire(session.snapshot()), deps);
    assert.equal(restored.getState().status, 'playing'); assert.equal(restored.getState().guesses.length, 1);
    assert.equal(restored.getStats().overall.gamesPlayed, 0); assert.equal(restored.completeReveal(), false);
});
test('explicit handoff canonically completes win and exports atomic stats', async () => {
    const { session, deps } = await fixture(); guess(session, session.getState().answer);
    const snapshot = wire(session.prepareHandoff()); assert.equal(snapshot.game.status, 'won'); assert.equal(snapshot.stats.overall.gamesPlayed, 1);
    assert.deepEqual((await Session.restore(snapshot, deps)).snapshot(), snapshot);
});
test('explicit nonfinal reveal handoff returns to playing', async () => {
    const { session, dictionary } = await fixture(); guess(session, dictionary.answers[1]); const snapshot = session.prepareHandoff();
    assert.equal(snapshot.game.status, 'playing'); assert.equal(snapshot.stats.overall.gamesPlayed, 0);
});
test('derived board, keyboard and attempts match canonical selectors', async () => {
    const { session, dictionary } = await fixture(); guess(session, dictionary.answers[1]); const game = session.getState();
    assert.deepEqual(session.getBoardProjection(), boardRows(game));
    assert.deepEqual(session.getKeyboardState(), deriveKeyStates(game.guesses)); assert.equal(session.getRemainingAttempts(), remainingGuesses(game));
});
test('history capped at 50 and deduplication IDs at 100', async () => {
    const { session } = await fixture();
    for (let i=0;i<105;i++) { win(session); session.newGame(); }
    assert.equal(session.getStats().recentGames.length, 50); assert.equal(session.getStats().recordedGameIds.length, 100);
    assert.equal(session.getStats().overall.gamesPlayed, 105);
});
test('snapshot/state/stats cannot externally mutate authoritative state', async () => {
    const { session } = await fixture(); const before = session.snapshot();
    session.getState().answer = 'BAD'; session.getStats().overall.gamesPlayed = 999; session.snapshot().meta.recentAnswers[5].push('BAD');
    assert.deepEqual(session.snapshot(), before);
});
test('incompatible and malformed snapshots rejected', async () => {
    const { session } = await fixture();
    for (const snapshot of [null, {...session.snapshot(),schemaVersion:2}, {...session.snapshot(),upstreamRevision:'bad'},
        {...session.snapshot(),selectedLength:9}, {...session.snapshot(),stats:{}}, {...session.snapshot(),game:{...session.getState(),answer:'ZZZZZ'}}])
        await assert.rejects(() => Session.restore(snapshot));
});
test('wall-clock disconnected time contributes on resumed completion', async () => {
    const { session, deps, time } = await fixture(); type(session, session.getState().answer.slice(0,1));
    const snapshot = wire(session.snapshot()); time(61000); const restored = await Session.restore(snapshot, deps);
    type(restored, restored.getState().answer.slice(1)); restored.submit(); restored.completeReveal();
    assert.equal(restored.getStats().overall.totalSolveMs, 60000);
});
test('loss on final pending reveal settles before handoff', async () => {
    const { session, dictionary, deps } = await fixture();
    for(let i=0;i<5;i++) { guess(session,dictionary.answers[1]); session.completeReveal(); }
    guess(session,dictionary.answers[1]);
    const reload = await Session.restore(wire(session.snapshot()), deps);
    assert.equal(reload.getState().status,'playing'); assert.equal(reload.getRemainingAttempts(),0);
    const handoff = session.prepareHandoff(); assert.equal(handoff.game.status,'lost');
    assert.equal(handoff.stats.overall.gamesPlayed,1);
    assert.deepEqual((await Session.restore(wire(handoff),deps)).snapshot(),handoff);
});
test('canonical statistics/history resets remain distinct', async () => {
    const { session } = await fixture(); win(session); const before=session.getStats();
    session.resetStatistics(); assert.equal(session.getStats().overall.gamesPlayed,0);
    assert.deepEqual(session.getStats().recentGames,before.recentGames);
    assert.deepEqual(session.getStats().recordedGameIds,before.recordedGameIds);
    session.newGame(); win(session); session.resetHistory();
    assert.equal(session.getStats().overall.gamesPlayed,1); assert.deepEqual(session.getStats().recentGames,[]);
    assert.deepEqual(session.getStats().recordedGameIds,[]);
});
test('construction and actions never access browser storage', async () => {
    let accessed = false;
    Object.defineProperty(globalThis,'localStorage',{configurable:true,get(){accessed=true; throw new Error('Unexpected browser storage');}});
    try { const {session,deps}=await fixture(); win(session); await Session.restore(wire(session.prepareHandoff()),deps); assert.equal(accessed,false); }
    finally { delete globalThis.localStorage; }
});
