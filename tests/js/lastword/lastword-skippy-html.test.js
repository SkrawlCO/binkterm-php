/**
 * Last Word — SLICE 3B ("CALLER READINESS") static markup checks.
 *
 * Plain string assertions against the real lastword-skippy.html, matching
 * the project convention for verifying static template content (see
 * CuratedPlaceWebTest.php's approach for curated_place.twig) rather than
 * routing this through the heavier synthetic DOM harness in
 * app-final-skippy-dom.test.js, which never sees static HTML that isn't
 * referenced by an element id.
 */
'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');

const HTML_PATH = path.join(__dirname, '..', '..', '..', 'public_html', 'webdoors', 'hangman', 'lastword-skippy.html');
const html = fs.readFileSync(HTML_PATH, 'utf8');

let passed = 0;
function check(name, fn) {
    fn();
    passed++;
    console.log('  ok - ' + name);
}

function stripHtmlComments(s) {
    return s.replace(/<!--[\s\S]*?-->/g, '');
}

/**
 * The #category-select section only — the one screen shown before Round 1,
 * never again — with HTML comments stripped so scans below check real
 * markup, not this file's own explanatory doc comments.
 */
function categorySelectSection() {
    const start = html.indexOf('<section id="category-select"');
    assert.ok(start !== -1, 'precondition: #category-select section must exist');
    const end = html.indexOf('</section>', start);
    return stripHtmlComments(html.slice(start, end));
}

/** Every other panel/section in the file, concatenated — the active-round HUD and beyond. */
function everythingElse() {
    const cs = categorySelectSection();
    return stripHtmlComments(html).split(cs).join('');
}

check('v0.1 / EARLY PLAYTEST identity is present on the category-select (start) screen', () => {
    const section = categorySelectSection();
    assert.ok(section.indexOf('lw-version') !== -1, 'expected a .lw-version element inside #category-select');
    assert.ok(section.indexOf('v0.1') !== -1, 'expected the literal v0.1 label');
    assert.ok(/early playtest/i.test(section), 'expected the literal "Early Playtest" label');
});

check('the first-moment orientation line is present on the category-select (start) screen', () => {
    const section = categorySelectSection();
    assert.ok(section.indexOf('lw-orientation') !== -1, 'expected a .lw-orientation element inside #category-select');
    // Core loop per the accepted brief: objective + 6 strikes + earn/spend economy + four rounds to Final.
    assert.ok(/6 strikes/i.test(section), 'orientation should mention 6 strikes');
    assert.ok(/final/i.test(section), 'orientation should mention Final');
});

check('no modal/tutorial/dismiss control was added alongside the orientation or version copy', () => {
    const section = categorySelectSection();
    assert.strictEqual(section.indexOf('<dialog'), -1, 'no <dialog> element');
    assert.strictEqual(section.indexOf('modal'), -1, 'no modal-named class/id');
    assert.strictEqual(section.indexOf('dismiss'), -1, 'no dismiss control');
    assert.strictEqual(section.indexOf('tutorial'), -1, 'no tutorial-named element');
    // Neither block carries an id — confirms they are plain static copy, not
    // JS-driven elements that could be hidden/shown/persisted per caller.
    assert.strictEqual(/id="[^"]*version[^"]*"/i.test(section), false);
    assert.strictEqual(/id="[^"]*orientation[^"]*"/i.test(section), false);
});

check('the version/orientation copy appears ONLY on the category-select screen — never in the active-round HUD or any later panel', () => {
    const rest = everythingElse();
    assert.strictEqual(rest.indexOf('lw-version'), -1, 'lw-version must not appear outside #category-select');
    assert.strictEqual(rest.indexOf('lw-orientation'), -1, 'lw-orientation must not appear outside #category-select');
    assert.strictEqual(rest.indexOf('EARLY PLAYTEST'), -1, 'the human-facing label must not repeat elsewhere');
});

check('the active-round HUD (#game) and Final (#final-play) panels are untouched by this slice', () => {
    const gameStart = html.indexOf('<main id="game"');
    const gameEnd = html.indexOf('</main>', gameStart);
    const gameSection = stripHtmlComments(html.slice(gameStart, gameEnd));
    assert.strictEqual(gameSection.indexOf('lw-version'), -1);
    assert.strictEqual(gameSection.indexOf('lw-orientation'), -1);

    const finalStart = html.indexOf('<main id="final-play"');
    const finalEnd = html.indexOf('</main>', finalStart);
    const finalSection = stripHtmlComments(html.slice(finalStart, finalEnd));
    assert.strictEqual(finalSection.indexOf('lw-version'), -1);
    assert.strictEqual(finalSection.indexOf('lw-orientation'), -1);
});

console.log('lastword-skippy-html.test.js: ' + passed + ' passed');
