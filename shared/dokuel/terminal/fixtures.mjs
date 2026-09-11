// Canonical sessions for terminal transport/UI proof, not a second Sudoku implementation.
import { mkdirSync, writeFileSync } from 'node:fs';
import { DokuelSession as Session } from '../dist/session.js';
const directory = process.argv[2];
if (!directory) throw new Error('Fixture output directory required');
mkdirSync(directory, { recursive: true });
const solution = '534678912672195348198342567859761423426853791713924856961537284287419635345286179';
const save = (name, session) => writeFileSync(`${directory}/${name}.json`, session.export());
save('partial', Session.fromPuzzle('..' + solution.slice(2), 'easy'));
save('last', Session.fromPuzzle('.' + solution.slice(1), 'easy'));
const reveal = Session.fromPuzzle('1....7.9..3..2...8..96..5....53..9...1..8...26....4...3......1..4......7..7...3..', 'expert');
for (let i = 0; i < 81; i++) {
    reveal.requestHint(); const hint = reveal.view().state.activeHint;
    if (hint.technique === 'reveal') break;
    reveal.enterDigit(hint.value);
}
if (reveal.view().state.activeHint.technique !== 'reveal') throw new Error('Expected canonical Reveal fixture');
save('reveal', reveal);
