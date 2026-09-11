# Ordinary Puzzles local Web reference

This local React surface consumes `../session.cjs`. It is not a WebDoor and has no host routes, authentication, storage, leases, or service worker.

## Build and review

From `shared/ordinary-puzzles`:

```
npm ci --ignore-scripts
npm ci --ignore-scripts --prefix web
ALLOW_FONT_FALLBACK=1 npm run build --prefix web
```

Serve `web/dist` on loopback only for local review. Generated output and dependencies are ignored. Build executes the existing shared TypeScript build first. No upstream game code changes.

Browser checks use an already installed Playwright and Chrome (no downloads):

```
PLAYWRIGHT_MODULE=/path/to/playwright CHROME_PATH=/path/to/chrome node web/browser.test.cjs
```

The test starts and closes its own ephemeral loopback server and browser. Screenshots go to `/tmp/ordinary-web-desktop.png` and `/tmp/ordinary-web-mobile.png`.

## Source respect and scope

`upstream/Tile.tsx` is byte-for-byte pinned upstream presentation. `upstream.json` records paths, revision, and SHA-256. MIT notice is `../LICENSE.upstream`, also copied into builds. The renderer preserves upstream symbols, connected borders, completed/in-progress fills, highlights, and cell typography.

`compat.jsx` replaces React Native primitives with CSS/React DOM solely for this renderer. Its observer is identity because neutral snapshots trigger parent React renders. Its light palette follows `src/op-design/colors.ts`. Board sizing/grid layout and pointer routing are adapted from `Board.tsx` and the canonical pointer methods. Tile orientation/completion are read from shared state, never calculated here. The local selection controls browse all four tiers/1,200 stable IDs; the full upstream menu/navigation and mobile haptics are not imported. Completion remains visibly on screen instead of animating into another app screen.

`app.jsx` calls shared load/begin/leave/enter/end/exit, state, export and restore. Tap is the same canonical begin/end path. Coordinates and pointer capture are presentation concerns. Hover decisions use canonical interaction state. Restored active drags retain canonical state and resume on the next pointer press; cancel/outside release uses shared exit. No board internals are mutated and no line, collision, reset, or completion rules are duplicated.

Snapshot tools are explicit JSON export/import only, without automatic persistence. `window.ordinaryPuzzles` is a local reference/test API exposing the same shared operations. Loading/restarting deliberately creates a fresh session board. Host packaging and access policy belong to later slices.

## Open font

Build requires `ALLOW_FONT_FALLBACK=1`. Only the pinned repository's `assets/fonts/Inter-SemiBold.otf` is copied, under its actual Inter name, with SIL OFL-1.1 license. This selects the same bundled fallback input as upstream `prepare-fonts.mjs` without executing its private-asset probing/copy branch or creating private-font-named aliases. No private font is obtained, inspected, copied or required. React/React DOM are pinned to upstream-compatible 19.2.3; esbuild 0.24.2 is an isolated bundler, not a dev server. No service worker registration exists.
