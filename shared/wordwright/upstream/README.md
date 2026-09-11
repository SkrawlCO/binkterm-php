# Wordwright

An offline, production-quality Wordle experience for **4-, 5-, and 6-letter** words. No backend, no accounts, no network calls — everything runs in the browser and persists to LocalStorage.

> **Status:** V1 complete — all 48 tasks done. 415 unit/integration tests and 98 browser tests pass; Lighthouse scores 100 for Performance, Accessibility and Best Practices; every WCAG AA contrast pair is verified in CI. See [`docs/TASKS.md`](./docs/TASKS.md) and [`docs/ACCEPTANCE.md`](./docs/ACCEPTANCE.md).

---

## Table of Contents

- [Overview](#overview)
- [Features](#features)
- [Tech Stack](#tech-stack)
- [Getting Started](#getting-started)
- [Development Workflow](#development-workflow)
- [Project Structure](#project-structure)
- [Documentation Map](#documentation-map)
- [Roadmap](#roadmap)
- [License](#license)

---

## Overview

Wordwright is a single-page web app that recreates the Wordle game loop with three word lengths, unlimited replays, and a full statistics suite. It is deliberately **local-first**: the dictionaries ship with the bundle, game state and stats live in LocalStorage, and the app is fully playable with the network disabled.

**Design principles**

1. **KISS over abstraction.** Build the simplest thing that satisfies the spec. Add indirection only where V2 demands it.
2. **Framework-independent engine.** All game rules live in pure TypeScript with zero React imports, so they are trivially testable and reusable.
3. **Accessibility is a requirement, not a polish item.** Screen reader parity, keyboard-only play, colorblind-safe palette, reduced-motion support.
4. **Offline by construction.** No `fetch`, no runtime CDN, no telemetry.

---

## Screenshots

| Light                                                     | Dark                                                    |
| --------------------------------------------------------- | ------------------------------------------------------- |
| ![Wordwright in light mode](./docs/screenshots/light.png) | ![Wordwright in dark mode](./docs/screenshots/dark.png) |

| Colourblind palette                                                                                                    | Statistics                                                                                                  |
| ---------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------- |
| ![Colourblind mode using blue and orange tiles with check and half-circle markers](./docs/screenshots/colourblind.png) | ![Statistics panel showing streaks, averages and the guess distribution](./docs/screenshots/statistics.png) |

Colourblind mode swaps green/yellow for blue/orange **and** adds distinct tile markers, so colour is never the only signal. Screenshots are generated from the production build by `npm run screenshots`.

## Features

### Gameplay

- 4, 5, and 6 letter modes — 6 guesses in every mode
- Unlimited games with random answer selection (no repeats until the pool cycles)
- Exact Wordle evaluation semantics, including correct duplicate-letter handling
- Guess validation against a large allowed-guess dictionary, separate from the curated answer list
- Win/loss detection, in-progress game resumption after refresh

### Experience

- Responsive layout from 320 px phones to widescreen desktops
- Physical keyboard **and** on-screen keyboard, kept in sync
- Tile flip reveal, key press/state animations, shake on invalid input, win bounce
- Loading states for dictionary chunks, non-blocking error toasts
- Restart at any time; switch word length at any time

### Statistics (LocalStorage)

Games played · games won · win % · current streak · best streak · average guesses · guess distribution · total score · average solve time · fastest solve · recent games log

### Settings

Light / Dark / System theme · colorblind-friendly palette · reduced motion override · reset statistics · reset history

### Accessibility

Full keyboard navigation · ARIA grid semantics and live announcements · visible focus rings · WCAG AA contrast · `prefers-reduced-motion` and `prefers-color-scheme` honoured

---

## Tech Stack

| Concern       | Choice                                           | Pinned     |
| ------------- | ------------------------------------------------ | ---------- |
| UI            | React (function components + hooks)              | 19.2       |
| Language      | TypeScript (strict)                              | 6.0        |
| Build         | Vite                                             | 8.1        |
| Styling       | Tailwind CSS (CSS-first `@theme` tokens)         | 4.3        |
| Tests         | Vitest + React Testing Library + jsdom           | 4.1        |
| Lint / Format | ESLint (flat config) + Prettier                  | 9.39 / 3.9 |
| State         | React Context + `useReducer` (no external store) | —          |
| Persistence   | LocalStorage behind a versioned repository layer | —          |

TypeScript is held at 6.x and ESLint at 9.x so that type-aware linting and `eslint-plugin-jsx-a11y` both keep working; the reasoning and the upgrade trigger are recorded in [ADR-015](./docs/DECISIONS.md#adr-015--toolchain-version-pins-typescript-6-eslint-9).

No backend. No authentication. No APIs. No database.

---

## Getting Started

### Prerequisites

- Node.js **>= 20.19** (Vite 7 requirement)
- npm **>= 10**

### Install & run

```bash
git clone <repo-url> wordwright
cd wordwright
npm install
npm run dev        # http://localhost:5173
```

### Build & preview

```bash
npm run build      # type-check + production bundle into dist/
npm run preview    # serve dist/ locally
```

To verify the offline promise: run `npm run preview`, load the page, then disable networking in DevTools and keep playing.

---

## Development Workflow

| Command                 | Purpose                                    |
| ----------------------- | ------------------------------------------ |
| `npm run dev`           | Vite dev server with HMR                   |
| `npm run build`         | `tsc -b` then `vite build`                 |
| `npm run preview`       | Serve the production build                 |
| `npm run test`          | Vitest in watch mode                       |
| `npm run test:run`      | Single CI-style test pass                  |
| `npm run test:coverage` | Coverage report (thresholds enforced)      |
| `npm run lint`          | ESLint over `src/`                         |
| `npm run lint:fix`      | ESLint with `--fix`                        |
| `npm run format`        | Prettier write                             |
| `npm run format:check`  | Prettier check (CI)                        |
| `npm run typecheck`     | `tsc --noEmit`                             |
| `npm run verify`        | typecheck + lint + format:check + test:run |

**Loop:** write a failing test → implement → `npm run verify` → commit with a [Conventional Commit](./docs/CONTRIBUTING.md#commit-conventions) message.

The architecture is also enforced by ESLint, so a violation fails the build rather than waiting for review:

| Rule                                                            | Enforces                                   |
| --------------------------------------------------------------- | ------------------------------------------ |
| No `react` / DOM / storage imports in `src/engine/**`           | ADR-003 — framework-independent engine     |
| No `Math.random()` / `Date.now()` in `src/engine/**`            | ADR-009 — injected randomness and clock    |
| No raw colour utilities (`bg-green-500`) in `src/components/**` | ADR-008 — semantic design tokens           |
| No `localStorage` outside `src/storage/**`                      | NFR-11 — persistence only via repositories |
| 100% branch coverage for `src/engine/**`                        | NFR-8                                      |

Engine work is test-first. UI work is spec-first. See [`docs/TASKS.md`](./docs/TASKS.md) for the ordered backlog.

---

## Project Structure

```
wordwright/
├── docs/                  # Specification set (source of truth)
├── prompts/               # Prompts for AI coding assistants
├── public/                # Static assets served as-is
├── src/
│   ├── engine/            # Pure game rules — no React, no DOM
│   ├── dictionary/        # Word lists + lazy loading registry
│   ├── storage/           # LocalStorage repositories + migrations
│   ├── state/             # React providers wrapping the engine
│   ├── components/        # Presentational + container components
│   ├── hooks/             # Reusable React hooks
│   ├── lib/               # Small shared utilities
│   ├── styles/            # Tailwind entry + design tokens
│   └── test/              # Test setup and shared fixtures
└── <tooling configs>
```

Full breakdown in [`docs/ARCHITECTURE.md`](./docs/ARCHITECTURE.md).

---

## Documentation Map

| Document                                                   | What it answers                                                                                                         |
| ---------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------- |
| [`docs/SPEC.md`](./docs/SPEC.md)                           | **What** we are building — PRD, user stories, functional & non-functional requirements, edge cases, acceptance criteria |
| [`docs/ARCHITECTURE.md`](./docs/ARCHITECTURE.md)           | **How** it is built — folders, components, state, data flow, storage, engine design, extension points                   |
| [`docs/TASKS.md`](./docs/TASKS.md)                         | **In what order** — phased, independently completable tasks                                                             |
| [`docs/CONTRIBUTING.md`](./docs/CONTRIBUTING.md)           | **Working agreements** — coding standards, git & commit conventions, PR checklist                                       |
| [`docs/ACCEPTANCE.md`](./docs/ACCEPTANCE.md)               | **Definition of Done** — the final validation checklist                                                                 |
| [`docs/DECISIONS.md`](./docs/DECISIONS.md)                 | **Why** — ADR-style records for every significant choice                                                                |
| [`prompts/implementation.md`](./prompts/implementation.md) | The build prompt for a coding assistant                                                                                 |
| [`prompts/review.md`](./prompts/review.md)                 | The review prompt for a coding assistant                                                                                |

Read `SPEC.md` first. When code and docs disagree, the docs win — or the docs get updated in the same PR.

---

## Roadmap

**V1 — MVP (in scope now)**
Core 4/5/6-letter gameplay, dictionaries, full UX, statistics, settings, accessibility, tests, tooling.

**V2 — Gameplay expansion (not implemented; architecture must not block it)**
Daily challenge · hard mode · practice mode · timed mode · survival mode · custom & seeded games · shareable links · calendar heatmap · long-term trends · per-length analytics · win history charts · sound effects · richer animations · themes · confetti · achievements · installable PWA with offline caching.

**V3 — Community & advanced (explicitly out of scope)**
Accounts · cloud sync · multiplayer · leaderboards · friend challenges · multiple languages · community dictionaries · daily events · seasonal themes · tournament / endless / co-op / race modes.

The V2 and V3 lists exist to steer architecture — see the _Extension Points_ section of [`docs/ARCHITECTURE.md`](./docs/ARCHITECTURE.md). **Do not implement them in V1.**

---

## License

MIT. Word lists are derived from public-domain and permissively licensed sources; see `src/dictionary/data/README.md` for provenance.
