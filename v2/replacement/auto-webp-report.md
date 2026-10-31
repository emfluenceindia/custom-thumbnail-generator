# Report: "Create WebP automatically for new uploads and regenerated thumbnails" (31 Oct 2026)

## What was asked
Option 2 of three: an admin setting (not forced, not absent) that creates WebP copies automatically for images added by any means and for regenerated thumbnails. It sits on the Settings page, stands out, and explains the caveats clearly.

## What it does
- **Setting:** `ctgen_webp_auto_convert` (registered WordPress setting, group `ctgen_webp_auto`, saved through `options.php`, sanitized to 1/0). **Off by default.**
- **Trigger:** WordPress saving an image's metadata (`added_post_meta` / `updated_post_meta` for `_wp_attachment_metadata`). That happens after sizes are created for every route: Media Library, post editor, featured image, ACF, REST, WP-CLI. It also happens on regeneration (CTG, `wp media regenerate`, other tools) and image-editor edits.
  - JPEG/PNG only.
  - One background event per image, 10 s later (`ctgen_webp_auto_convert`, arg = attachment ID). WP-Cron refuses duplicates while one is pending, even an overdue one.
- **Background run:**
  - Converts the main file, every size (core + CTG) and the original of scaled uploads, through the existing engine (`convert_part`).
  - A WebP older than its JPEG/PNG is re-created; a current one is kept.
  - Does nothing if the setting was turned off in the meantime, or if the image was deleted.
  - Fires `ctgen_webp_files_changed` as before, so serving data is rebuilt and "Serve WebP for all images" picks new images up.
- **Settings page card:** first item under the page header.
  - Green accent and "On" badge when on; amber and "Off" when off.
  - Switch + "Save setting".
  - "What it covers" and "What it does not do" sections.
  - Six caveats: disk space, server load, background timing (WP-Cron), regenerating all thumbnails, other WebP tools, turning it off later.
  - A red warning when `DISABLE_WP_CRON` is set.
  - A "N images are waiting to be converted" line while conversions are pending.
- **Attachment Summary hint:** while "Serve WebP for all images" is on and automatic WebP is off, a notice explains that new uploads won't be served and links to the setting (`#ctgen-webp-auto`). The script shows or hides it after the header switch runs.

## Files
- New (shipped):
  - `webp/class-ctgen-webp-auto-convert.php`
  - `assets/css/ctgen-switch.css` (switch styles moved here from the summary CSS, now shared by both pages)
- Changed (backups `*.bak11`):
  - `includes/init.php`: require, accessor `ctgen_webp_auto_convert()`, `register()`, enqueue `ctgen-switch-css`
  - `templates/ctgen-form-html.php`: `settings_errors( 'general' )` + the card
  - `includes/class-ctgen-attachment-summary.php`: `render_auto_hint()`, stylesheet dependency
  - `assets/js/ctgen-attachment-summary.js` + rebuilt `.min.js`: hint show/hide
  - `assets/css/ctgen-attachment-summary.css`: switch rules removed, hint rule
  - `assets/css/ctgen-webp-convert.css`: card styles
- Tests (not shipped):
  - new `test-class-ctgen-webp-auto-convert.php` (15 tests)
  - new `class-ctgen-test-webp-auto-convert.php` (test double: simulated DISABLE_WP_CRON)
  - `test-ctgen-webp-wiring.php` (+1)
  - `bootstrap.php`

## TDD log
| Step | Result |
|---|---|
| Red | 12 errors + 1 failure (class missing), 183 others passing |
| Green | `OK (197 tests, 646 assertions)` |
| Mutations caught (then restored) | schedule while off; never refresh outdated copies; always re-create (the test was first tightened to compare modification time, since re-converting gives identical bytes); run while off; GIF/WebP scheduled; card always checked; no cron warning |
| Mutation survived, code removed | the plugin's own "already scheduled" check: WP 7.1 core already refuses an identical event while one is pending, including overdue ones. The test for that case (`test_an_overdue_conversion_is_not_queued_twice`) stays as a guard |

## End-to-end on the real site (cleaned up afterwards)
1. Setting on, `wp media import` of a 1600×1000 JPEG → one event scheduled.
2. After the job ran: 7 WebP files (main + 1024, 1536, 768, 300, 150 and the CTG 250×250 size).
3. `wp media regenerate` → event scheduled → the regenerated 250×250's WebP re-created (newer mtime). The main file's WebP, still current, was left untouched.
4. A stray event for an image deleted before it ran later executed without effect.
5. Cleanup: attachment deleted (its WebP copies with it), option deleted, scratch files removed.
6. Verified after cleanup:
   - uploads md5 map identical (256 files)
   - serving rows unchanged
   - 21 cron events (as before)
   - 0 pending auto events

## Checks
- PHPUnit `OK (197 tests, 646 assertions)`. The only printed notices are the existing converter test's deliberate `empty.jpg`.
- PHPCS 0/0 for the plugin and for the tests.
- Plugin Check identical to the baseline.
- `build-js --check` exit 0.
- Real Settings page rendered as admin: card before the existing controls, form + nonce present, setting registered, no PHP messages.

## Observed during this work (not caused by the tests)
Between 11:54 and 12:02 UTC the web server log shows Attachment Summary AJAX requests from your browser. Afterwards:
- only #69 serves (new meta row);
- #414 no longer serves;
- `ctgen_webp_serve_all` = 0.

This matches browser testing of the header switch. Test fixtures always sit above real IDs, so it was left as it is.

## Not verified / known limits
- Saving the setting through the browser form (options.php redirect, "Settings saved." notice) was not run, because there is no logged-in browser session here. Please try it.
- Timing depends on WP-Cron (site traffic, or a server cron when DISABLE_WP_CRON is set). This is explained in the card.
- Uninstall must also delete `ctgen_webp_auto_convert` and pending `ctgen_webp_auto_convert` events (added to P1).
