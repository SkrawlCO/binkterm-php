# Wordwright local Web adapter

Canonical React presentation over `../session.ts`. No WebDoor manifest, caller
routes, database, leases or PP wiring. Legacy Wordle is untouched.

## Run

Node >=20.19, npm >=10. From this directory:

    npm ci --ignore-scripts
    npm run build
    npm run preview -- --port 43191 --strictPort

Preview binds only 127.0.0.1. No production service restart is needed.
React 19.2.8, Vite 8.1.5 and TypeScript 6.0.3 match the pinned upstream versions;
Tailwind/PostCSS follow upstream's build path. Exact dependencies are locked.
Build output and node_modules are ignored.

## State ownership and local export

`src/bridge.ts` owns one shared WordwrightSession and publishes detached snapshots
through React useSyncExternalStore. GameProvider translates UI actions into shared
methods. StatsProvider reads the same snapshot and delegates resets to the shared
session. Neither provider keeps a parallel reducer, stats fold or repository.
Canonical selectors/helpers in presentation resolve to the existing shared
upstream files; there is no second engine copy.

The canonical board, keyboard, physical-key hook, reveal timeline, result panel,
stats/history modal, responsive styles, focus management/live announcements,
colourblind and reduced-motion settings are retained. Settings are memory-only
presentation state. All lengths are loaded by the shared session before mounting;
a startup failure shows a reload message rather than a second game state machine.

This reference surface deliberately has no automatic reload persistence. For
local review use the browser console:

    const saved = JSON.stringify(window.wordwrightReview.snapshot());
    await window.wordwrightReview.restore(JSON.parse(saved));

Copy the exported JSON out before a reload, then restore it after loading. The
review API is local tooling, not an authenticated production API. It returns
canonical game/meta/stats including the secret; future host packaging must decide
its review exposure. There is no arbitrary action dispatch or rule bypass.

    const handoff = window.wordwrightReview.prepareHandoff();

Ordinary restore retains shared/upstream revealing-to-playing behavior.
prepareHandoff dispatches canonical REVEAL_COMPLETE and snapshots the result and
stats together. Restoring publishes one coherent snapshot; action calls are
blocked while asynchronous restore is pending. A future host must disable input
visibly during its acquire/restore flow and own atomic persistence.

## Storage and cache containment

No localStorage/sessionStorage game authority, no browser repositories, no
service worker or PWA registration. Settings do not write browser storage either.
The canonical footer and crash recovery copy were adjusted to reflect this local
memory-only lifecycle; the crash screen does not clear unrelated browser data.
Upstream storage modules are only transitive source imports for pure helpers;
repository factories are not invoked. A build does not expose this directory to
L33TEST callers.

## Provenance

Upstream: https://github.com/Libin-Samkutty/wordwright
Pin: 1dcf92d0c6d9e873a3d885920c21ae3ff81f0a33
MIT declaration and missing standalone LICENSE are documented in the parent.
`provenance.json` lists original Git-file SHA-256 hashes, local hashes and whether
files were reused, adapted or omitted. Canonical components/hooks/styles are
unchanged except ErrorBoundary's storage recovery; providers, App, main and
clock/Result facades are adapted. Storage notices are omitted because there is no
browser storage. New bridge/build/test code has no claim of upstream authorship.
No game engine or shared session source was changed in this slice.

## Checks

From parent, `npm test` runs the 37 shared tests. Build the Web adapter, start the
loopback preview, then run `node browser.test.mjs`. The browser check uses the
already-installed Playwright and Chromium at this workstation; override
PLAYWRIGHT_MODULE, CHROME_BIN and WORDWRIGHT_URL on another machine. It imports the
parent's generated shared module to create a deterministic APPLE fixture through
canonical create/answer selection, then imports that snapshot into the Web surface.
All play uses physical/on-screen keyboard and canonical React controls. No engine
rules are reproduced in the browser test. Screenshots/evidence go to /tmp.

Optional caller-scoped review: window.wordwrightReview.connect(endpoint, csrfToken) uses the leased client. Connected mode replaces memory-only authority, displays checkpoint/release controls, and disables arbitrary snapshot imports. See ../persistence/README.md; no final route or manifest is added.
