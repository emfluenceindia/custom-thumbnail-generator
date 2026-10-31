## R2 report

Date: 2026-10-30. Milestone R2: `CTGEN_Webp_Replacer` (front-end gate + filters), lifecycle rebuilds, `CTGEN_Webp_Health_Check`, wiring. Also step 1 of the agreed plan: the 7 outdated tests.

### Step 1: the 7 outdated tests (before R2)
Updated to the current, intended design (backups: `test-class-ctgen-webp-ajax-handlers.php.bak`, `test-class-ctgen-webp-converter.php.bak`). No plugin code changed.
- Tests 1–4: counts include `full` (2→3, 1→2, 1→2, 3→4) **and** each now asserts `full` converted (and `count( sizes ) === total`), so the extra count is a checked behaviour.
- Test 5 rewritten: `test_full_size_file_is_processed_as_full_not_as_a_thumbnail` (`full` under its own key, missing thumbnail still skipped).
- Test 6 → `test_static_gif_is_excluded` (`ctgen_webp_gif_excluded`, no output file). Test 7 keeps its intent (no flattened file), expects `ctgen_webp_gif_excluded`.
- Result: `OK (111 tests, 283 assertions)` — first fully green suite.

### Re-verified facts
- WordPress 7.1.4 installed; filter signatures checked in core: `wp_get_attachment_image_src` (media.php:1119, 4 args), `wp_calculate_image_srcset` (1632, 5), `wp_content_img_tag` (2072, 3), `render_block` (class-wp-block.php:717), `wp_update_attachment_metadata` (post.php:7181, a filter that runs *before* saving — so lifecycle uses `added/updated_post_meta` instead).
- `WP_HTML_Tag_Processor` available (WP ≥ 6.2; plugin requires 6.5).
- Loader pattern: `ctgen_webp_handlers()` per-request accessor (init.php) — copied for the three new accessors.
- The Attachment Summary's "Regenerate WebP" uses `convert_part(…, true)` / `convert_attachment(…, true)` — the WebP-overwriting paths.
- Featured-image covers go through `wp_get_attachment_image_src` (blocks/cover.php:152–157), so only cover's *saved* fixed-background markup needs `render_block`.
- Not multisite here (`is_multisite()` false).

### TDD log
| # | Test | Red (failure seen) | Green | Full suite after |
|---|---|---|---|---|
| 0 | (step 1) 7 outdated tests updated to current design; (refactor) fixture helpers moved to trait CTGEN_Webp_Serve_Fixtures | n/a | OK (111 tests, 283 assertions) | OK (111 tests) |
| 1 | Replacer: image_src WebP when serving, original when not (req 22) | Error: Class 'CTGEN_Webp_Replacer' not found | OK | OK (113 tests) |
| 2 | Replacer: srcset replaces only manifest sizes, rest byte-identical (req 23) | Error: undefined method filter_srcset() | OK | OK (114 tests) |
| 3 | Replacer: content <img> changes only src+srcset, every other attribute byte-identical (req 24) | Error: undefined method filter_content_img_tag() | OK (WP_HTML_Tag_Processor; attribute only rewritten when a URL changed) | OK (115 tests) |
| 4 | Replacer: wp_get_attachment_image() end to end, webp only for manifest sizes (req 25) | Error: undefined method register(); then REAL BUG: srcset missing entirely - core's wp_calculate_image_srcset() drops srcset when src (already WebP) matches no metadata file | OK: register()/unregister(); filter_srcset_meta() gives the srcset calculation an in-memory metadata copy with served sizes renamed to their WebP, only when src is a served WebP | OK (116 tests) |
| 5 | Replacer gate: admin screen gets original (req 26) | webp returned | OK: should_replace() + servable_manifest() used by all callbacks | OK (117 tests) |
| 6 | Replacer gate: cron gets original (req 26) | webp returned | OK: wp_doing_cron() | see after |
| 7 | Replacer gate: any REST request gets original (req 26) | webp returned (override of a not-yet-existing method had no effect) | OK: protected is_rest_request() -> wp_is_serving_rest_request() | OK (119 tests) |
| 8 | Replacer gate: JSON request (Accept: application/json) gets original (req 26) | webp returned | OK: wp_is_json_request() | OK (120 tests) |
| 9 | Replacer gate: WP-CLI gets original (req 26) | webp returned | OK: protected is_cli() | OK (121 tests) |
| 10 | Replacer gate: 'ctgen_webp_should_replace' false -> original; receives attachment ID (req 26) | webp returned | OK: documented filter, default true | OK (122 tests) |
| 11 | Replacer gate: feed gets original (req 27, decision) | webp returned | OK: is_feed() (only once a main query exists) | OK (123 tests) |
| 12 | Replacer gate: oEmbed output gets original (req 26, decision) | webp returned | OK: is_embed() | OK (124 tests) |
| 13 | Replacer: serving off restores original in same request (req 28) | Passed on first run (no cache exists). Seen red by mutation K (static per-request manifest cache). Restored. | OK | OK (125 tests) |
| 14 | Replacer: wp_get_attachment_url stays original while serving (req 58, G2) | Passed on first run (never filtered). Seen red by mutation L (closure filtering wp_get_attachment_url). Restored. | OK | OK (126 tests) |
| 15 | Replacer: rendering a post serves WebP in src + core srcset, post_content unchanged (req 29) | First run failed on MY fixture assumption (wp_insert_post stores '/>' as ' />'); test corrected to compare stored-before vs stored-after. Then passed. Seen red by mutation M (srcset metadata renamed for every src -> content srcset lost). Restored. | OK | OK (127 tests) |
| 16 | Replacer: rendering makes zero filesystem-seam calls (req 31) | Passed on first run (render only reads meta). Seen red by mutation N (build() on every render). Restored. | OK | OK (128 tests) |
| 17 | Replacer: partial manifest (no full WebP) -> original src, WebP medium in srcset (req 32) | Passed on first run. Seen red by mutation O (all-or-nothing on 'full'). Restored. | OK | OK (129 tests) |
| 18 | Replacer: core/cover fixed-background style url() served as WebP, rest of block unchanged (§3.3 render_block) | Error: undefined method filter_render_block() | OK: tag processor, only .wp-block-cover__image-background style, only this attachment's manifest URLs | OK (130 tests) |
| 19 | Replacer: render_block leaves non-cover blocks untouched (§3.3) | Passed on first run. Seen red by mutation P (block name not checked). Restored. | OK | OK (131 tests) |
| 20 | Replacer robustness: false / non-array / empty values pass through, no notice | ErrorException: Trying to access array offset on value of type bool | OK: guards in filter_image_src()/filter_srcset() | OK (132 tests) |
| 21 | Health: metadata update rebuilds; stale size -> dimension_mismatch (req 30, thumbnails regenerated) | Error: Class 'CTGEN_Webp_Health_Check' not found | OK: added/updated_post_meta on _wp_attachment_metadata -> rebuild() | OK (134 tests) |
| 22 | Health: image edit (new -e file names) rebuilds; no old entry survives (req 30) | Passed on first run. Seen red by mutation Q (rebuild merges previous sizes). Restored. | OK | OK (135 tests) |
| 23 | Health: regenerating one part / all parts (force) rebuilds in same request (req 33, 30 'WebP regenerated'), 2 tests | both: medium still dimension_mismatch after regeneration (written together: one mechanism) | OK: engine fires ctgen_webp_files_changed when it wrote a WebP; health check rebuilds on it. Backup class-ctgen-webp-ajax-handlers.php.bak9 (pre-R2) | OK (137 tests) |
| 24 | Health: run() notices a WebP deleted outside the plugin -> missing, front end original (req 34) | Error: undefined method run() | OK: run() rebuilds every serving attachment | OK (138 tests) |
| 25 | Health: batches of ctgen_webp_health_check_batch, cursor resume, wrap to 0 (req 35) | all 3 rebuilt in one run (no batching) | OK: keyset query on postmeta (post_id > cursor, LIMIT batch), cursor option autoload off | OK (139 tests) |
| - | FOUND: orphan _ctgen_webp_serve row for post 999999999, leaked by R1 mutation G (enable() on a missing ID during the mutation run); and cursor option leaked by test 34. Deleted both (meta_id 9281; option). Health tests now save/restore the cursor; new self-check test_no_orphan_serve_meta_left_behind. | n/a | OK | OK (140 tests) |
| 26 | Health: run changes no file, does not rewrite an unchanged manifest (req 36) | updated_post_meta fired for _ctgen_webp_serve | OK: rebuild() skips the write when only 'built' would change | OK (141 tests) |
| 27 | Health: activation schedules daily event (hooked to run()), deactivation unschedules (req 38) | Error: undefined method activate() | OK | OK (144 tests) |
| 28 | Health: admin_init schedules a missing event once (req 59, G3) | Error: undefined method maybe_schedule() | OK | OK (145 tests) |
| 29 | Health: interval filter used; unknown recurrence falls back to daily (§3.7) | ['daily','daily'] (filter ignored) | OK | OK (146 tests) |
| 30 | Health: network deactivation unschedules on every site; single-site only current (req 60, G3) | visited [] (no network loop) | OK: is_network()/site_ids()/on_site() seams (switch_to_blog + restore in finally) | OK (147 tests) |

### Real bugs found and fixed during R2
1. **srcset lost on `wp_get_attachment_image()`** (found by test 25): core only builds a srcset when `src` is one of the attachment's metadata files; once `src` is WebP, core dropped srcset entirely. Fixed with `filter_srcset_meta()` (`wp_calculate_image_srcset_meta`): only when `src` is one of this attachment's served WebPs, the srcset calculation sees an in-memory metadata copy with served sizes renamed to their WebP files. Mutation M proves the "only when" condition matters (without it, post-content srcsets disappear).
2. **Orphan DB row from R1** (found by the batch test): R1's mutation G had stored `_ctgen_webp_serve` for non-existent post 999999999. Deleted (meta_id 9281). R1's "no fixtures left behind" claim was therefore incomplete for that one row. New self-check `test_no_orphan_serve_meta_left_behind` now guards this.
3. **Cursor option leak**: the health-check tests now save/restore `ctgen_webp_health_cursor` and any real scheduled event.

### Test run
```
PHPUnit 9.6.37 by Sebastian Bergmann and contributors.
OK (150 tests, 433 assertions)
```
Printed notices during the run: only `getimagesize(): Read error!` / `exif_imagetype(): Read error!` from core, triggered on purpose by the existing converter tests' `empty.jpg`.

### Coverage
**BLOCKED** — no xdebug/pcov in the container. Traceability:

| Requirement | Test(s) |
|---|---|
| 22 src on/off | `test_image_src_is_webp_when_serving_and_original_when_not` |
| 23 srcset only manifest sizes | `test_srcset_replaces_only_manifest_sizes` |
| 24 content img: only src/srcset | `test_content_img_tag_changes_only_src_and_srcset` (full tag string) |
| 25 end to end | `test_attachment_image_html_uses_webp_for_manifest_sizes_only` |
| 26 gate | `test_gate_admin_screen_gets_original`, `…_cron_…`, `…_rest_request_…`, `…_json_request_…`, `…_wp_cli_…`, `…_should_replace_filter_false_…`, `…_embed_…` |
| 27 feeds (decision: originals) | `test_gate_feed_gets_original` |
| 28 off restores same request | `test_turning_serving_off_restores_original_in_the_same_request` |
| 29 post_content unchanged | `test_rendering_a_post_serves_webp_and_leaves_post_content_unchanged` |
| 30 lifecycle | `test_metadata_update_rebuilds_and_stale_size_drops_out`, `test_image_edit_rebuilds_and_no_old_entry_survives`, `test_regenerating_one_part_rebuilds_the_manifest` |
| 31 no FS during render | `test_rendering_makes_no_filesystem_call` |
| 32 partial manifest | `test_partial_manifest_original_src_with_webp_in_srcset` |
| 33 plugin write paths rebuild | `test_regenerating_one_part_…`, `test_regenerating_all_parts_…` |
| 34 external deletion healed | `test_run_notices_a_webp_deleted_outside_the_plugin` |
| 35 batches + cursor | `test_run_works_in_batches_and_resumes_from_the_cursor` |
| 36 no file change / no rewrite | `test_run_changes_no_file_and_does_not_rewrite_an_unchanged_manifest` |
| 37 empty sizes keeps meta | `test_rebuild_with_no_servable_size_keeps_serving_on_with_originals` |
| 38 activation / deactivation | `test_activation_schedules_and_deactivation_unschedules` |
| 58 (G2) attachment URL original | `test_attachment_url_stays_original_while_serving` |
| 59 (G3) admin_init schedules once | `test_admin_init_schedules_a_missing_event_once` |
| 60 (G3) network deactivation | `test_network_deactivation_unschedules_on_every_site` |
| 61 (A2) unreachable not revived | `test_run_does_not_revive_an_unreachable_size` |
| §3.3 render_block core/cover | `test_cover_block_fixed_background_is_served_as_webp`, `test_render_block_leaves_other_blocks_untouched` |
| §3.7 interval filter | `test_interval_filter_is_used_and_unknown_names_fall_back_to_daily` |
| §3.2 wiring | `Test_CTGEN_Webp_Wiring` (3 tests) |
| Robustness | `test_callbacks_pass_through_empty_values_without_notice` |

### §10 amendments applied in R2
- A2 — applied (health check honours marks), proven by tests 61 (+ R1's 53–55).
- G2 — applied, proven by test 58.
- G3 — scheduling + deactivation applied, proven by tests 59, 60. (Uninstall part is R3.)
- G5 — activation/deactivation hooks in the main file (`.bak8` first), proven by `test_activation_and_deactivation_hooks_are_registered`. They call instance methods through `ctgen_webp_health_check()` rather than static methods (same effect, matches the loader's accessor style).

### PHPCS / Plugin Check / JS drift
- PHPCS: plugin `17 / 17` files, 0 errors / 0 warnings; all 9 test files 0 / 0.
- Plugin Check: identical to the pre-R1 baseline (`diff` empty).
- JS drift: not applicable (R3).
- Uploads: md5 map of all 256 real files identical to the pre-R1 snapshot; no fixture folders left; DB: 0 `_ctgen_webp_serve` rows, 0 `ctgen_webp_*` options, 0 fixture posts.
- PHP 7.0 syntax: grep for 7.1+ constructs in all runtime files: none; every shipped PHP file passes `php -l` (PHP 7.4).

### Files
- Created (shipped): `webp/class-ctgen-webp-replacer.php`, `webp/class-ctgen-webp-health-check.php`.
- Changed (backups): `includes/init.php` (`.bak8`), `custom-thumbnail-generator.php` (`.bak8`), `webp/class-ctgen-webp-ajax-handlers.php` (`.bak9` = pre-R2; `.bak8` = pre-R1), `webp/class-ctgen-webp-manifest.php` (created in R1, no backup needed).
- Dev tooling: new `tests/trait-ctgen-webp-serve-fixtures.php`, `tests/test-class-ctgen-webp-replacer.php`, `tests/test-class-ctgen-webp-health-check.php`, `tests/test-ctgen-webp-wiring.php`; `bootstrap.php` (`.bak` from R1) requires the new classes and trait.

### Decisions made
- **REST: every REST/JSON request gets originals**, not only `context=edit` — the block editor also reads media in view context. Headless front ends therefore don't get WebP (safe choice, rule 4).
- **Feeds and oEmbed output get originals.**
- **Lifecycle hook**: `added_post_meta`/`updated_post_meta` for `_wp_attachment_metadata` (fires after the save), not the `wp_update_attachment_metadata` filter (fires before). Covers core regeneration, the media editor (crop/rotate/restore) and this plugin's regeneration.
- **Plugin write paths** announce `do_action( 'ctgen_webp_files_changed', $id )` (new, documented) after actually writing a WebP; the health check rebuilds on it. `delete_webp_copies()` needs nothing (it runs when the attachment and its meta are deleted).
- `rebuild()` writes only when the manifest changed (ignoring `built`).
- Lifecycle rebuilds live in `CTGEN_Webp_Health_Check` (its job: "keep manifests current, on change and on schedule"), keeping the replacer purely about output.
- Health check query: keyset on `wp_postmeta` (`post_id > cursor … LIMIT batch`), cursor option autoload off; unknown interval names fall back to `daily`.

### Manual check (§8) — on the real site
The active theme ("project-x") renders posts and the homepage with static templates (`index.php`, `front-page.php`) that never call `the_content()`, so no existing URL shows content images. A temporary published page (`page.php`, which does) was created with JPEG #417, PNG #414 and PNG #281, and deleted afterwards. Serving was turned on for those three only and turned off again at the end.
1. Serving on for one JPEG (#417) and one PNG (#414), plus #281 (full + thumbnail WebPs larger than source) — observed: manifests `417/414: all 6 parts served`, `281: ctgen_250x250 served; full, thumbnail larger_than_source`.
2. Logged-out page source — observed: #417 and #414 use `.webp` in `src` and all 4 `srcset` entries; #281 keeps `.png` for full and 150x150, `.webp` only for 250x250; `class`, `sizes`, `fetchpriority` unchanged.
3. Editor — **partly done**. The REST API (what the editor reads) returned 0 WebP URLs, 18 originals with serving on; stored `post_content` md5 unchanged across page view + REST read; no `.webp` in stored content. Logged-in editor load/save was **not** done: reproducing it needed a temporary application password, which the permission system blocked. Steps for you: log in, open any page using a served image in the block editor, check the image URLs in the code editor are `.jpg/.png`, save, and confirm with `lando wp post get <ID> --field=post_content | grep -c '\.webp'` → `0`.
4. Serving off — observed: next load has 0 `.webp`; the img tags are byte-identical to the "before" page.
5. Network — observed: all 9 served WebP URLs `HTTP/2 200`, **but no `Content-Type` header at all**. Cause: this Lando container's Apache 2.4.54 (Debian) `/etc/mime.types` has no `image/webp` (`.jpg` correctly gets `image/jpeg`). Browsers still display same-origin WebP (they sniff the RIFF/WEBP signature), but see "Decision needed".

### Blocked / not done
- Coverage: BLOCKED (no xdebug/pcov).
- PHP 7.0 runtime: BLOCKED (only PHP 7.4; no PHPCompatibility). Mitigated by grep + `php -l`.
- §8 step 3 logged-in editor save: not done (see above).
- Admin screens (Attachment Summary, regeneration) were not clicked through: no browser login here. Checked instead: all shipped PHP files lint clean; `/`, `/feed/`, `/wp-json/`, media REST, `wp-login.php` return 200 with 0 PHP messages in the responses (WP_DEBUG on).

### Decision needed (before R4)
**Servers that send WebP without `Content-Type: image/webp`.** This Lando server does. R4's reachability check (§3.7) as written would mark every size `unreachable` on such a server and silently stop serving WebP site-wide. Options:
- (a) Recommended: R4 treats "200 but no/incorrect Content-Type" as a **server warning**, not `unreachable`: keep serving, show the admin a notice with the fix (`AddType image/webp .webp` for Apache; `image/webp webp;` in nginx `mime.types`). Only a non-200 marks `unreachable`.
- (b) Keep §3.7 strict (no serving until the server is fixed).
- Independently: fix this dev server (add `AddType image/webp .webp` to the site's `.htaccess` or the Lando Apache config) — a site/server change, not plugin code; not done without your OK.
