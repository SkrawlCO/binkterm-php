# BreakLock local Web surface

This slice is deliberately staged outside the served document root. It has no
WebDoor/Experience manifest, host launch route, authentication, persistence or PP
membership. Build output is restricted to an explicit scratch path under /tmp.
Do not copy it into a served directory without a separate authorized host slice.

## Presentation and source ownership

`upstream-files.json` lists every vendored file and its SHA-256 at canonical
revision `a06fb28a3fa6072a089ca664c66a7bf08c0a3e99`. These files are unchanged.
They share the original MIT notice in `../LICENSE.upstream`; the build includes
that notice in its output. Vendored paths include:

- `src/controllers/{menu,option,selector,extender,langselector,summary,history,lock,statusBar,countdown}/`
- `src/controllers/game/game.scss`, `src/style.scss`, `src/_variables.scss`
- `src/utils/*.js`, `src/config.js`, `src/l10n/en.yml`
- `index.html`, `assets/intro.svg`

`adapter.js` reuses the upstream menu controls, SVG drawing helpers, lock gesture
geometry, history container, status/summary templates, quotes and responsive SCSS.
`SharedLock` overrides state-mutating lock methods. Mouse/touch coordinates are
converted using client coordinates, and the upstream mouseleave listener is
removed on release. These are input plumbing changes, not Pattern rules.

The original GameCtrl is not included. StatusBar/Countdown objects provide DOM
only: their counter/timer mutation methods are never called. Summary content is
rendered from shared result state, and only shared state determines visibility.
No separate secret, draft Pattern, attempt counter or result lives in the adapter.
`../round.js` remains the only orchestration owner; its unchanged Pattern is
resolved directly by the bundle. There is no second Pattern copy.

The adapter calls `start`, `newGame`, `select`, `clearDraft`, `reveal`, `home`,
`tick`, `history` and `snapshot`. Rendering membership/highlights, feedback-circle
colors and the countdown bar are presentation only. Completed guesses are
submitted by the shared layer, not by the Web adapter.

## Clock and lifecycle

One native 1000ms interval delivers one shared `tick()` per callback. It is not
replaced when another round starts while the timer is still running. Shared
state stops it on win/timeout; Home deliberately retains it, including the
upstream cross-mode timeout quirk. There is no absolute deadline or catch-up.
A separate one-shot callback delivers the shared pending draft reset; starting a
new gesture cancels it. Summary overlays, continuation, counter quirks and
recorded results come from the shared module.

`window.breaklock.snapshot()` is a read-only export seam returning detached JSON,
including pending callback residual delays. It returns null on the initial menu.
There is no import, server call, lease, storage or deterministic-secret production
hook. Full-page departure disposes callbacks; a browser back/forward-cache
suspension preserves the surface for browser resumption.

## Service worker and bounded presentation choices

The build omits upstream service-worker registration and does not ship its worker.
It also removes automatic locale redirects, PWA install links and the localStorage
OLED easter egg from the generated HTML. Future caller API/state responses cannot
be captured by an upstream cache-first worker from this surface.

This slice builds English only; its language switcher is hidden. Existing upstream
English strings remain in their upstream localization catalog. No BBS translations
or caller-facing routes are added. The build uses upstream's system monospace
fallback stack and omits the font-face block; it does not redistribute font files.
No new icons are introduced. The existing SVG symbol definitions and instruction
illustration remain recognizable. Full PWA installation is not enabled here.

## Build

Small pinned build tools: esbuild 0.24.2, Sass 1.83.4, YAML 2.7.0. No upstream
Webpack/Babel modernization or platform dependency change is needed. For an
isolated tool installation:

```sh
npm install --prefix /tmp/breaklock-build-tools --ignore-scripts --no-audit --no-fund esbuild@0.24.2 sass@1.83.4 yaml@2.7.0
BREAKLOCK_BUILD_DEPS=/tmp/breaklock-build-tools node shared/breaklock/web/build.mjs /tmp/breaklock-web-output
```

Alternatively install this directory's development dependencies locally and run
`node build.mjs /tmp/breaklock-web-output`. The build compiles unchanged SCSS and
extensionless upstream imports, then performs upstream's localization-token
replacement. HTML acquisition files are left unchanged; containment transforms
apply only to generated scratch output. `build-inputs.json` records bundle inputs.
The existing served application and its service-worker cache are unaffected.

## Optional browser proof

`smoke.cjs` uses an existing Playwright installation and Chromium executable:

```sh
PLAYWRIGHT_MODULE=/path/to/playwright CHROME_BIN=/path/to/chrome node shared/breaklock/web/smoke.cjs /tmp/breaklock-web-output /tmp/breaklock-web-evidence
```

It binds an ephemeral loopback port and closes the browser/server in cleanup.
Desktop pointer input exercises mode selection, midpoint insertion, Practice win,
Challenge exhaustion, feedback/history, both reveals and post-result continuation.
Countdown uses a real timed callback, then test-only delivery of its registered
callback to reach timeout quickly. No game state is injected. A 375x812 mobile
context wins via Chromium touch events. Screenshots, a snapshot and JSON results
are written outside the repository. No browser tooling is installed by this test.
