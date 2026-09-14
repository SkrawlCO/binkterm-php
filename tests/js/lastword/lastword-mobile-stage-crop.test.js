/**
 * Last Word — "KEEP THE CHARACTERS IN THE GAME" mobile sticky/crop proof
 * (Slice 3B follow-up).
 *
 * Plain string/structural checks against the real CSS/HTML, matching the
 * project convention already used for other static-markup checks (see
 * lastword-skippy-html.test.js). This proves the STRUCTURE of the fix
 * (mobile-only, correct selector/breakpoint, shared by Final, no keypad/
 * tap-target changes) — it cannot and does not prove visual quality; that
 * is the real-phone human gate this proof is waiting on.
 */
'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');

const CSS_PATH = path.join(__dirname, '..', '..', '..', 'public_html', 'webdoors', 'hangman', 'css', 'lastword-skippy.css');
const HTML_PATH = path.join(__dirname, '..', '..', '..', 'public_html', 'webdoors', 'hangman', 'lastword-skippy.html');
const css = fs.readFileSync(CSS_PATH, 'utf8');
const html = fs.readFileSync(HTML_PATH, 'utf8');

let passed = 0;
function check(name, fn) {
    fn();
    passed++;
    console.log('  ok - ' + name);
}

function stripCssComments(s) {
    return s.replace(/\/\*[\s\S]*?\*\//g, '');
}
const cssNoComments = stripCssComments(css);

/**
 * The @media (max-width: 780px) block containing the sticky/crop rule,
 * isolated for scoped assertions. Located via `position: sticky` — the one
 * marker unique to this new rule — rather than the `.lw-skippy-portrait`
 * selector, which also has an earlier, unrelated base (non-media) rule.
 */
function stickyCropBlock() {
    // Anchored on "max-height:" (with colon, no space before it) — unlike
    // "position: sticky", this file's own explanatory comment text never
    // happens to quote that exact substring, so this reliably lands on the
    // real declaration rather than the comment's prose describing it.
    const ruleStart = css.indexOf('max-height:');
    assert.ok(ruleStart !== -1, 'precondition: the sticky/crop rule must exist');
    const lastCommentEnd = css.lastIndexOf('*/', ruleStart);
    const searchFrom = lastCommentEnd === -1 ? 0 : lastCommentEnd;
    const mediaStart = css.indexOf('@media (max-width: 780px)', searchFrom);
    assert.ok(mediaStart !== -1 && mediaStart <= ruleStart,
        'precondition: the rule must sit inside an @media (max-width: 780px) block, found after the preceding comment');
    const blockEnd = css.indexOf('\n}\n', ruleStart) + 3;
    const block = css.slice(mediaStart, blockEnd);
    assert.ok(block.indexOf('position: sticky;') !== -1, 'sanity: the isolated block must contain the real sticky declaration');
    return block;
}

check('the sticky/crop rule targets .lw-skippy-portrait inside @media (max-width: 780px) only', () => {
    const block = stickyCropBlock();
    assert.ok(block.indexOf('position: sticky') !== -1);
    assert.ok(block.indexOf('overflow: hidden') !== -1);
    assert.ok(block.indexOf('max-height') !== -1);
});

check('no unguarded (desktop-applying) .lw-skippy-portrait rule sets position: sticky', () => {
    // Every REAL declaration (trailing ";", not prose mentioning it in a
    // comment) of "position: sticky" in the whole stylesheet must be the
    // one inside the 780px media block found above — i.e. sticky never
    // applies outside a mobile media query.
    const stickyDeclarations = (cssNoComments.match(/position:\s*sticky;/g) || []).length;
    assert.strictEqual(stickyDeclarations, 1, 'sticky positioning must be declared exactly once, and only inside the mobile media block');
});

check('the crop is a CSS-only correction — the rule\'s actual declarations need no scroll-event language, just sticky/overflow', () => {
    const block = stripCssComments(stickyCropBlock());
    assert.strictEqual(block.toLowerCase().indexOf('scroll'), -1, 'a pure sticky/overflow crop needs no scroll-event CSS declarations');
});

check('no new JS file was added for the crop — only the existing lastword-skippy.css/.html carry it', () => {
    const jsDir = path.join(__dirname, '..', '..', '..', 'public_html', 'webdoors', 'hangman', 'js', 'lastword');
    const jsFiles = fs.readdirSync(jsDir).filter((f) => f.endsWith('.js'));
    // Same known js/lastword file set this whole slice sequence has used —
    // no new controller/renderer file introduced for a CSS-only crop.
    assert.ok(jsFiles.indexOf('app-final-skippy.js') !== -1);
    assert.ok(jsFiles.every((f) => !/crop|sticky|mobile-stage/i.test(f)),
        'no dedicated new JS file for this proof — it is CSS-only, as required');
});

check('Final shares the identical .lw-skippy-portrait wrapper — the crop applies to Final for free, with no separate rule needed', () => {
    const gallowsPortraitIdx = html.indexOf('<div class="lw-skippy-portrait">');
    const finalPortraitIdx = html.indexOf('<div class="lw-skippy-portrait">', gallowsPortraitIdx + 1);
    assert.ok(gallowsPortraitIdx !== -1 && finalPortraitIdx !== -1, 'both the round and Final stage wrappers must exist');
    // Only two portrait wrappers exist in the whole page (round + Final) —
    // proves there is no separate/duplicated wrapper class for Final that
    // could drift from the round-HUD treatment.
    const allOccurrences = (html.match(/class="lw-skippy-portrait"/g) || []).length;
    assert.strictEqual(allOccurrences, 2, 'exactly one portrait wrapper for #gallows and one for #finalGallows, sharing one class');
});

check('the Gallows developer override is unaffected — the crop rule targets the wrapper, never the apparatus/predicament selection', () => {
    const block = stickyCropBlock();
    assert.strictEqual(block.indexOf('apparatus'), -1);
    assert.strictEqual(block.indexOf('predicament'), -1);
    assert.strictEqual(block.indexOf('SAFE_FILL'), -1);
});

check('no keypad/letter-grid/tap-target CSS was touched by this change', () => {
    const block = stickyCropBlock();
    assert.strictEqual(block.indexOf('.letters'), -1, 'the sticky/crop rule must not touch letter-grid selectors');
    assert.strictEqual(block.indexOf('solveButton'), -1);
    assert.strictEqual(block.indexOf('hintButton'), -1);
});

check('reduced-motion is irrelevant to this change — no animation/transition was introduced', () => {
    const block = stickyCropBlock();
    assert.strictEqual(block.indexOf('animation'), -1);
    assert.strictEqual(block.indexOf('transition'), -1);
});

check('cache-bust token was bumped at least to the accepted Slice 3B mobile-crop version', () => {
    // Not pinned to an exact later token -- subsequent slices legitimately
    // bump this further (see Slice 5's caller-path corrections); this only
    // proves it never regressed back below the mobile-crop proof's own bump.
    const match = html.match(/\?v=skippy(\d+)/);
    assert.ok(match, 'expected a ?v=skippyNN cache-bust token');
    assert.ok(Number(match[1]) >= 25, 'expected the token to be at or past skippy25 (the mobile-crop proof)');
});

console.log('lastword-mobile-stage-crop.test.js: ' + passed + ' passed');
