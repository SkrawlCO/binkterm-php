# public_html/img — static image assets

| File | What it is |
|---|---|
| `logo.svg`, `logo-square.svg` | BinktermPHP's own mascot logo (Kludge the corvid). Upstream project artwork. |
| `l33test-mark.png` | **L33TEST brand mark** — the cyan geometric L33TEST glyph, 512×512, RGBA, transparent. Used by the Crossroads masthead (`templates/webdoors.twig`, `templates/crossroads.twig`) and, on this deployment, by the navbar brand via `data/appearance.json` → `branding.logo_url`. |

## `l33test-mark.png` provenance

**User-supplied L33TEST branding asset; not upstream BinkTerm artwork.**

Supplied by the operator for this local project and authorised for use as the
canonical L33TEST production mark (Curated Catalog v1.0, Slice 3).

Derived deterministically from the supplied master
(`L33Test Logo icon(PNG).png`, 2250×2250 RGBA, sha256
`825e4c69c9f6b60523e14c51ce479bf244472f5328e6a61b25e90408ea690bf7`): the
opaque glyph was cropped to its alpha bounding box and centred in a 512×512
transparent canvas with 8% symmetric padding. No colour, geometry, or
transparency was altered; the supplied master is retained unmodified outside
the repo. Regeneration script: `/root/openglad-assay/s3/make_mark.cjs`.

The mark's own cyan is approximately `#45f3ff`; the site accent it sits beside
(`data/appearance.json` `branding.accent_color`, `#00D9E8`) is a deliberately
close member of the same family. The masthead CSS reuses that site accent and
never introduces a third cyan.
