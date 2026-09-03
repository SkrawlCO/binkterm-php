# Chessmata WebDoor (`chessmata-web`) — Crossroads Experience #4, graphical Web surface

Slice 4. An authenticated BinkTerm **Web** caller launches the **official upstream
Chessmata SPA** (3D graphical client), embedded same-origin in the Crossroads
WebDoor iframe, already signed in as their own Chessmata account — the *same*
account `ChessmataIdentity` maps their BinkTerm identity to for the Telnet
surface. No Chessmata login / registration / email / password / API-key prompt.

The graphical board, menus and gameplay are 100% the pinned upstream Chessmata
SPA served by the sibling `chessmata` container at `/chessmata/`. Nothing here is
a BinkTerm chess UI.

## Why the id is `chessmata-web` (not `chessmata`)

`chessmata` is already a **NativeDoor** (Slice 3, Telnet). The generic
`/games/{id}` route checks native doors before web doors and 404s a
`hide_from_web` native door before ever reaching the web-door branch, so the two
surfaces need distinct door-ids for now. Unifying them under one catalog card
(one Experience, "play in browser" / "play in terminal") is the later
catalog-composition slice — deliberately **not** done here.

## Files (all tracked)

| File | Role |
|---|---|
| `webdoor.json` | manifest — `entry_point: index.php`, `requirements.admin_only: true` |
| `index.php` | fail-closed bootstrap: `requireAuth()` + game-enabled + `admin_only` gate, then serves a **token-free** loading page |
| `bootstrap.js` | client: clears any stale token, same-origin `POST web-credential.php`, writes the JWT into the SPA's own `localStorage['chessmata_auth_token']`, joins the WebDoor session lifecycle, `location.replace('/chessmata/')` |
| `web-credential.php` | same-origin **POST only** endpoint — `requireAuth()` + same-origin (`Sec-Fetch-Site`/`Origin`) + `admin_only`; returns `ChessmataWebSession::issue()` (a short-lived **JWT**, `Cache-Control: no-store`, never logged, never the durable `cmk_` key) |

Broker + credential logic: `src/Crossroads/ChessmataWebSession.php` →
`src/Crossroads/ChessmataIdentity.php` (Slice 2). `webCredential()` (JWT), not
`terminalCredential()` (`cmk_`), is used for the browser.

## The auth hand-off (no upstream change)

The upstream SPA (`src/hooks/useAuth.ts`) reads
`localStorage['chessmata_auth_token']` on mount and calls `GET /api/auth/me`. The
WebDoor bootstrap page is the **same origin** as `/chessmata/`, so seeding that
key from the bootstrap is exactly the mechanism the client already uses — the SPA
comes up authenticated with no code change. The token is delivered only in a
same-origin POST response body (no-store), never in a URL / query / Referer /
log / persistent BinkTerm storage.

## Embedding / headers

Chessmata's Go backend hard-codes `X-Frame-Options: DENY` (no config knob). For
same-origin embedding:

* **binkterm-app Caddy** (`docker/Caddyfile`) strips the `DENY` on `/chessmata/*`
  and adds `Content-Security-Policy: frame-ancestors 'self'`.
* **host Apache** scopes its stricter site-wide CSP *out* of `/chessmata/*` (a
  `<LocationMatch>` `Header always unset Content-Security-Policy`) so the SPA runs
  under its own purpose-built CSP; the global `X-Frame-Options: SAMEORIGIN` still
  applies. Every other route is unchanged.

## Enablement (deploy config, not committed)

`config/webdoors.json` (git-ignored, admin-managed): `"chessmata-web": { "enabled": true }`.
`requirements.admin_only: true` in the manifest keeps it admin-only until rollout.
