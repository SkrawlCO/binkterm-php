# Crossroads Experience icons — staging

Source-of-truth / staging area for original Crossroads Experience icon artwork
that is **not yet wired into an Experience**.

Once an Experience is ready to use its icon, the production copy is placed in
that Experience's own directory (for a native door:
`native-doors/doors/{id}/icon.png`, referenced by `game.icon` in
`nativedoor.json`). Files here are not served by the web server and are not
referenced by any manifest.

See `docs/NativeDoors.md` → **Experience icon** for the canonical asset spec.

## Contents

| File | Experience | Status |
|------|------------|--------|
| `elsewhere.png` | Elsewhere | **Staged, not wired.** Elsewhere / Tangaria integration is paused; the icon is prepared (original 512×512 PNG) but deliberately not referenced by `native-doors/doors/elsewhere/nativedoor.json`. |

## Provenance

All artwork here is original work created for this project. It contains no
third-party logos, cover art, game assets, sprites, ANSI art, or trademarked
material.
