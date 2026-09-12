# BBS Directory — IBBS (Telnet BBS Guide) Sync

Operational reference for the automated BBS Directory import from the
official Telnet BBS Guide list.

## Official source

- Download page: https://www.telnetbbsguide.com/lists/download-list/
- Canonical machine-readable file inside the archive: `bbslist.csv`
- Published monthly; filename pattern `ibbsMMYY.zip`

## Importer

`scripts/ibbs_directory_sync.php`, backed by `src/Directory/IbbsImportService.php`.

- **Default mode is dry-run.** Pass `--apply` to actually write.
- **Default acquisition downloads the current official archive** via
  `IbbsImportService::downloadCurrentArchive()` (HTTPS, discovers the
  monthly link off the download page, validates ZIP signature/size before
  anything touches the directory).
- **Manual/recovery mode:** `--zip=/path/to/IBBSxxxx.ZIP` uses a local
  archive instead of downloading.
- On any acquisition/parse/low-row-count failure, the directory is left
  completely untouched and the script exits non-zero.

## Monthly automation (cron)

Wired into the existing `docker/entrypoint.sh` cron-generation convention
(same pattern as `ENABLE_RSS_POSTER`/`ENABLE_ECHOMAIL_ROBOTS`):

- `ENABLE_IBBS_DIRECTORY_SYNC` — default **false**
- `IBBS_DIRECTORY_SYNC_SCHEDULE` — default `0 6 1 * *` (monthly, 1st, 06:00)

Bare-metal equivalent lines are in `cron.example`.

## Geo backfill (separate job)

Coordinates are not supplied by IBBS; they're filled in separately by the
pre-existing `scripts/geocode_bbs_directory.php` (wraps
`BbsDirectory::backfillMissingCoordinates()`), which is bounded/batchable
and already respects the Nominatim geocoder's 1 request/sec throttle.

- `ENABLE_BBS_DIRECTORY_GEOCODING` — default **false**
- `BBS_DIRECTORY_GEOCODING_SCHEDULE` — default `0 */6 * * *`
- `BBS_DIRECTORY_GEOCODING_BATCH_SIZE` — default `150`

Not to be confused with `BBS_DIRECTORY_GEOCODING_ENABLED`, which is the
geocoder *provider* kill switch (unrelated to scheduling).

## Reconciliation semantics

- New upstream board → `INSERT`, `source='ibbs'`.
- Existing matched board (endpoint first, name fallback) → `UPDATE`
  source-owned fields only.
- `is_local=TRUE` / manual rows → **never** overwritten, never marked
  missing, untouched by IBBS absence logic.
- Board present in a prior edition but absent from a new validated one →
  `missing_since` is set; the row is **never hard-deleted**.
- Board reappears in a later edition → `missing_since` is cleared and the
  row is updated normally (reactivation), reusing the same record.
- Two differently-named boards sharing one endpoint → preserved as
  distinct records (`DISTINCT_SHARED_ENDPOINT`).
- Endpoint collisions with a matching normalized location *and* a strongly
  similar normalized name → classified `LIKELY_ALIAS`, held for admin
  review, **never** silently merged or inserted.

## First edition (September 2026)

- Edition: `ibbs0926`
- Source rows: 1,047 → valid: 1,047
- Imported: 1,042 (incl. 14 distinct-shared-endpoint boards)
- Held for review (likely alias, not inserted): 4 rows (2 pairs — e.g.
  `BBS Retrocampus` / `Retrocampus BBS`, `S.W.A.T.S. BBS` / `SWATS BBS`)
- Protected local row: 1 (L33TEST)
- Resulting directory: 1,043 rows

## Map

Leaflet + `Leaflet.markercluster`, OSM tiles, Nominatim-derived coordinates.
**Human visual acceptance: PASS.** The real basemap blocker was the
production Apache CSP (`img-src 'self' data:'`) blocking the OSM tile
hosts, proven via real Firefox console/DOM evidence; fixed by extending
`img-src` to allow `https://{a,b,c}.tile.openstreetmap.org` explicitly at
the host Apache vhost (not in this repo). Earlier CSS-brightness and
hidden-tab-sizing hypotheses were investigated but were **not** the actual
cause (the hidden-tab `invalidateSize()` fix was kept as an independently
correct Leaflet/Bootstrap-tabs practice; the CSS brightness change was
reverted to its original value).

## Known follow-up (non-blocking)

`geocode_cache` stores both successful and `null` (no-result) geocode
results with no TTL. A transient Nominatim outage could therefore leave a
location's cache entry permanently `null` until manually cleared. Not
addressed in this slice — parked as future hardening.
