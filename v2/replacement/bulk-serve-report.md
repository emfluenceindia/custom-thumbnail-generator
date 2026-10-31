# Report: "Serve WebP" column with a switch for all images (31 Oct 2026)

## What was asked
1. A new **Serve WebP** column whose header holds an on/off switch. On: every image is served as WebP, except where the WebP is larger than the original and images that are already WebP.
2. Switch off: serve the original files.
3. Move the per-image switch from "WebP status" into the new column. Keep the per-image feature.

## What it does
- **Header switch on:** after a confirmation, the site remembers "serve all" (option `ctgen_webp_serve_all`), then switches every JPEG/PNG image on, in batches of 20 (filter `ctgen_webp_serve_all_batch`), with progress text above the table.
  - The rules are the per-image switch's own (`set_serve`): a size whose WebP is larger keeps its original, size by size; an image with no smaller, valid WebP stays off and is counted as "keeps the original".
  - WebP, GIF and other images are never candidates.
- **While it is on:** an image whose WebP copies are created later (any conversion, via `ctgen_webp_files_changed`) starts serving by itself.
- **Header switch off:** after a confirmation, every serving image (including ones switched on one by one) is switched off, in batches, so visitors get the originals.
- **Per-image switch:** unchanged behaviour, now in the Serve WebP column. It works as an exception: you can still switch single images off (or on) afterwards; the header switch stays where it is.
- During a run, all switches are locked, leaving the page warns, visible rows refresh as their batch completes, and a failing request is retried (3 failures in a row stop the run with a message; switching again finishes the job).
- "Regenerate WebP" now also refreshes the image's Serve WebP cell, so a switch that was disabled becomes usable straight away.

## Files
- New (shipped): `webp/class-ctgen-webp-serve-all.php` (`CTGEN_Webp_Serve_All`: `start`, `is_on`, `count`, `run_batch`, `on_files_changed`, `ajax_serve_all` with steps `start` / `batch`).
- Changed (backups `*.bak10` = the approved toggle state):
  - `includes/init.php`: require + `ctgen_webp_serve_all()` accessor + `register()`.
  - `includes/class-ctgen-attachment-summary-table.php`: `serve` column (header = switch), `column_serve()`.
  - `includes/class-ctgen-attachment-summary.php`: `render_serve_all_header()`, `render_serve_cell()`; switch removed from `render_webp_cell()`; per-image label now screen-reader text naming the image; `serve_html` in both regenerate responses; status line above the table; 8 new strings.
  - `webp/class-ctgen-webp-serve-ajax.php`: response `html` is now the Serve WebP cell.
  - `assets/js/ctgen-attachment-summary.js` (+ rebuilt `.min.js`): `serveAll()`, `replaceServeCell()`, lock/unlock, regenerate refreshes the serve cell.
  - `assets/css/ctgen-attachment-summary.css`: switch styles moved to a shared `.ctgen-switch` class; column widths (16/8/20/15/27/14); header layout.
- Tests (dev tooling, not shipped): new `test-class-ctgen-webp-serve-all.php` (11 tests); `test-class-ctgen-webp-serve-ajax.php` (cell tests point at the new cell, +3 tests); `test-ctgen-webp-wiring.php` (+2); `bootstrap.php` (require). Backups `*.bak10`.

## TDD log
| Step | Result |
|---|---|
| Red | 20 errors + 2 failures (new class missing / switch still in the WebP status cell), 160 others passing |
| Green | `OK (182 tests, 593 assertions)` |
| Mutations, each caught then restored | ignore switch state for new copies; always "done"; WebP originals as candidates (twice, before and after the query rewrite); cells for non-visible rows; no capability check; `start` ignores the state |

Test safety: batches in tests always start just below the first fixture's ID, so only fixtures are switched; the real option is saved and restored. Verified afterwards: your serving rows (#69, #414) byte-identical, option not left behind, 0 fixtures.

## Checks
- PHPUnit `OK (182 tests, 593 assertions)`.
- PHPCS 0/0 (plugin and tests).
- Plugin Check identical to the baseline. The first version, which built the queries from a shared clause, was flagged, so the queries were rewritten.
- `build-js --check` exit 0.
- Uploads md5 map identical (256 files).
- Real Attachment Summary rendered as admin: header switch in thead and tfoot, per-image switch in the new column, none left in WebP status, no PHP messages.

## Known limits
- On phone-width screens WordPress hides non-primary column headers, so the header switch is not visible there (each row's switch still is, labelled "Serve WebP").
- If a run is interrupted (page closed), the remembered state is already the new one but some images were not switched yet; switching again completes it.
- Regenerating WebP for an image you switched off by hand while "serve all" is on switches it back on (it counts as new WebP copies).
- Not done yet: uninstall must also delete `ctgen_webp_serve_all` (added to the pending list, P1). JS is not unit-tested (no JS test setup); please test in the browser.
