## R1 report

Date: 2026-10-30. Milestone R1: `CTGEN_Webp_Manifest` + `CTGEN_Webp_Url_Mapper`, no hooks, no UI. Skill: `replacement-skill.md` (incl. §10 amendments A2, A3 for R1).

### Re-verified facts
- PHP baseline 7.0 / WP 6.5 — runtime code uses no 7.1+ syntax (grep for nullable/void/typed props/const visibility/`fn`/`?->`: none in new code); `php -l` clean on the container's PHP 7.4.33. A PHP 7.0 interpreter and PHPCompatibility are not available (see Blocked).
- Converter `convert( $source, $destination )` returns array|WP_Error — confirmed, used by the tests to make real WebPs.
- GIF excluded (`ctgen_webp_gif_excluded`) — confirmed in `validate_image_attachment()`; mapped to `ctgen_webp_unsupported_source` by the manifest.
- WebP naming `basename.webp` beside the source; `full` and `original_image` included — confirmed in `full_size_entries()` / `process_file()`.
- Name clashes / Media Library `.webp` ownership — confirmed in `has_name_conflict()` / `attachment_id_for_file()`; both now reused by the manifest.
- `verify_existing_webp()` defines "valid and current" — confirmed; its pure part extracted (see Decisions) so there is still exactly one rule.
- `list_parts()` is public and lists `full`, `original_image`, sizes — confirmed; the manifest is built from it.
- Test bootstrap loads `/app/wp-load.php`, `PHPUnit\Framework\TestCase`, `require_once`s the converter only — confirmed; manifest + mapper added.
- Fixture pattern (fixed title, tracked IDs, dedicated uploads folder, `wp_delete_attachment( $id, true )`, final "no fixtures left behind") — confirmed and copied.
- No coverage driver (`php -m`: no xdebug/pcov) — confirmed.
- NEW (not in §1): PHPUnit's `convertNoticesToExceptions` / `convertWarningsToExceptions` are NOT effective under this WP bootstrap — warnings are printed but tests pass. Both new test classes install their own throwing error handler in `setUp()`.
- NEW (not in §1): the plugin has no Xdebug-free way to detect PHP 7.0 syntax; and `.distignore` excludes only `*.bak` and `*.bak[0-9]*` (a name like `.bak-r1` would ship).

### Baseline before R1 (not caused by R1)
`Tests: 31, Assertions: 98, Failures: 7.` — all 7 are pre-existing, out-of-date tests (test files dated 2026-09-29, code changed 2026-10-22/23):
1. `Test_CTGEN_Webp_Ajax_Handlers::test_all_sizes_converts_jpeg_and_png_sizes_independently` (expects 2 converted; `full` is now converted too → 3)
2. `…::test_missing_thumbnail_file_is_skipped_not_failed_and_others_still_complete` (same +1)
3. `…::test_corrupt_thumbnail_is_failed_and_others_still_complete` (same +1)
4. `…::test_run_level_counts_match_per_item_outcomes` (same +1)
5. `…::test_full_size_original_is_never_processed_as_a_thumbnail` (asserts no `full` key; `full` is now processed by design)
6. `Test_CTGEN_Webp_Converter::test_valid_static_gif_converts_successfully` (GIF excluded since 2026-10-22)
7. `Test_CTGEN_Webp_Converter::test_animated_gif_is_rejected_not_flattened` (now `ctgen_webp_gif_excluded`)

These were NOT changed: updating them changes existing assertions and is outside R1. **Decision needed from the user.** "No regression" for R1 = exactly these 7 failures, no others (verified by name after every green step).

### TDD log
Every behaviour has a red step. Where a required test was already satisfied by earlier code, it was seen failing through a deliberate mutation of the production code (then restored, `diff` empty) — listed as such.

| # | Test | Red (failure seen) | Green | Full suite after |
|---|---|---|---|---|
| 1 | Url_Mapper: manifest size URL maps to WebP (req 15) | Error: Class 'CTGEN_Webp_Url_Mapper' not found | OK (1 test) | 32 tests, 7 failures (baseline 7) |
| 2 | Url_Mapper: query string / fragment preserved (req 16) | 3 data sets failed: `.jpg?ver=2&w=1` returned unmapped | OK (4 tests) | 35 tests, 7 failures (baseline) |
| 3 | Url_Mapper: http / protocol-relative / host case map (req 17) | 3 data sets returned unmapped | OK (7 tests) | 38 tests, 7 failures (baseline) |
| 4 | Url_Mapper: other host / folder / file / size not in manifest unchanged (req 17-19), 8 rows | Passed on first run (guards already met by #1-#3). Seen red by mutation: A (folder check off) failed 4 rows, B (substring name match) failed 3 rows, C (name searched in whole URL) failed "same name in query only". Code restored, diff empty. | OK (8 tests) | 46 tests, 7 failures (baseline) |
| 5 | Url_Mapper: uppercase .JPG / .jpeg / .png / -scaled (req 20) | Passed on first run (WebP name is read from the manifest). Seen red by mutation D (WebP name derived by replacing `.jpg`): 3 rows failed; the `-scaled` row is a naming guard only. Restored. | OK (4 tests) | 50 tests, 7 failures (baseline) |
| 6 | Url_Mapper: bad input unchanged, no notice (req 21), 17 rows | First run: warnings PRINTED but tests passed -> discovered PHPUnit's warning conversion is not active under this WP bootstrap; added an explicit throwing error handler to the test class. Then red: 6 ErrorExceptions (strcspn on array, offset on null, undefined index sizes/webp, foreach on string, illegal offset) + 1 failure ("webp not a basename" mapped to ../evil.webp) | OK (36 tests) | 67 tests, 7 failures (baseline) |
| 7 | Manifest: JPEG, valid WebP for every size, all listed (req 1) | Error: Class 'CTGEN_Webp_Manifest' not found | OK | 69 tests, 7 failures (baseline) |
| 8 | Manifest: size without WebP -> skipped 'missing' (req 4) | ErrorException: Trying to access array offset on value of type bool | OK | 70 tests, 7 failures (baseline) |
| 9 | Manifest: WebP larger than / equal to source -> larger_than_source (req 3), 2 tests | both: medium/full listed in sizes (768 B source vs 6030 B WebP; 6028 = 6028) | OK | 72 tests, 7 failures (baseline) |
| 10 | Manifest: renamed JPEG as .webp -> invalid (req 5) | full listed in sizes (6659 B source, 768 B "webp") | OK; extracted shared rule CTGEN_Webp_Ajax_Handlers::webp_problem() (verify_existing_webp delegates; existing handler tests unchanged) | 73 tests, same 7 baseline names |
| 11 | Manifest: stale WebP with other dimensions -> dimension_mismatch (req 6) | Passed on first run (shared rule wired in #10). Seen red by mutation E (manifest skips only invalid): full listed in sizes. Restored. | OK | 74 tests, 7 failures (baseline) |
| 12 | Manifest: .webp that is its own Media Library item -> not_owned, file untouched (req 7) | full listed in sizes | OK; made CTGEN_Webp_Ajax_Handlers::attachment_id_for_file() public (unchanged logic) | 75 tests, 7 failures (baseline) |
| 13 | Manifest (extra, §1 name clash): photo.jpg + photo.png in one folder -> every part not_owned | full listed in sizes | OK; made has_name_conflict() public (unchanged logic) | 76 tests, 7 failures (baseline) |
| 14 | Manifest: PNG with alpha listed when WebP valid + smaller (req 2) | Passed on first run (no format-specific code). Seen red by mutation F (PNG sources rejected). Restored. | OK | 77 tests, 7 failures (baseline) |
| 15 | Manifest: enable() stores version-1 manifest, basenames only (req 11) | Error: Call to undefined method CTGEN_Webp_Manifest::enable() | OK; build() now returns full shape (version, sizes, skipped, unreachable, built) | 78 tests, 7 failures (baseline) |
| 16 | Manifest: GIF attachment refused with ctgen_webp_unsupported_source, nothing stored (req 8) | ErrorException: Undefined index: file (build iterated over the WP_Error from list_parts) | OK | 79 tests, 7 failures (baseline) |
| 17 | Manifest: ID 0 / non-existent / non-image -> specific WP_Error codes, nothing stored (req 9) | Passed on first run (errors passed through since #16). Seen red by mutation G (errors swallowed as empty manifest): all 3 returned "not an error". Restored. | OK | 80 tests, 7 failures (baseline) |
| 18 | Manifest: scaled upload, full and original_image judged independently (req 10), 2 tests | both: original_image dropped (its metadata dimensions are 0 -> dimension_mismatch) | OK: source dimensions read from file when metadata has none (as process_file does) | 82 tests, 7 failures (baseline) |
| 19 | Manifest (extra): source file gone -> source_missing | ErrorException: Trying to access array offset on value of type bool | OK | 83 tests, 7 failures (baseline) |
| 20 | Manifest: get() returns stored manifest, null when off (§3.1) | Error: Call to undefined method CTGEN_Webp_Manifest::get() | OK | 84 tests, 7 failures (baseline) |
| 21 | Manifest: malformed / unknown-version meta reads as off, no notice (req 12), 13 rows | 12 rows read as valid (the plain string already returned null) | OK: strict shape check in get() | 97 tests, 7 failures (baseline) |
| 22 | Manifest: enable() changes no file (md5+mtime) and no other meta (req 13) | Passed on first run (class only reads). Seen red by mutation H (read_file touches files) and mutation I (enable also writes _ctgen_usage_count). Restored. | OK | 98 tests, 7 failures (baseline) |
| 23 | Manifest: delete() removes only the manifest (req 14) | Error: Call to undefined method CTGEN_Webp_Manifest::delete() | OK | 99 tests, 7 failures (baseline) |
| 24 | Manifest: rebuild() when not serving writes nothing, returns 'not_serving' (req 56, A3) | Error: Call to undefined method rebuild() (NOTE: written together with #25 in one red step - deviation from "one behaviour per red"; made green one at a time to keep each cycle honest) | OK | - |
| 25 | Manifest: rebuild() when serving stores current state (§3.4) | first: undefined method (shared with #24); after #24 green: "Failed asserting that null is identical to Array" | OK | 101 tests, 7 failures (baseline) |
| 26 | Manifest: serving turned off mid-rebuild stays off (req 57, A3) | rebuild wrote the manifest back (meta present after) | OK: second is_serving_now() check just before writing | 102 tests, 7 failures (baseline) |
| 27 | Manifest: rebuild keeps 'unreachable' mark while WebP unchanged (req 53, A2) | rebuild put 'medium' back into sizes (the flip-flop) | OK: build() takes previous marks, keeps one only when basename+bytes+mtime match; read_file() also returns mtime | 103 tests, 7 failures (baseline) |
| 28 | Manifest: mark dropped when WebP mtime / bytes change (req 54, A2), 2 tests | Passed on first run (comparison from #27). Seen red by mutation J1 (mtime ignored -> mtime test fails) and J2 (bytes ignored -> bytes test fails). Restored. | OK | 105 tests, 7 failures (baseline) |
| 29 | Manifest: malformed 'unreachable' reads as empty, no notice (req 55, A2), 5 rows | ErrorException: Undefined index: unreachable; 4 failures (raw malformed value returned / mark kept) | OK: get() keeps only well-formed marks | 110 tests, 7 failures (baseline) |
| 30 | Manifest (extra): metadata size entry without 'file' -> source_missing, no notice | ErrorException: Undefined index: file | OK. Refactor (suite green): rebuild() uses get()'s normalised marks; removed §-references to unshipped v2 docs from runtime comments | 111 tests, 7 failures (baseline) |
| - | Cleanup: metadata-without-file test repairs metadata in finally{} so core's wp_delete_attachment() in tearDown raises no notice | (notice was printed from wp-includes/post.php:6953 during tearDown) | OK | 111 tests, 7 failures (baseline); only remaining printed notices are the pre-existing converter tests' empty.jpg getimagesize/exif_imagetype |

### Test run
Final full suite (`vendor/bin/phpunit`, inside Lando):
```
PHPUnit 9.6.37 by Sebastian Bergmann and contributors.
Tests: 111, Assertions: 265, Failures: 7.
```
The 7 failures are exactly the 7 baseline names above. New tests: 80 (URL mapper 36 incl. data sets, manifest 44 incl. data sets), all passing. `--filter Test_CTGEN_Webp_Url_Mapper` → `OK (36 tests)`; `--filter Test_CTGEN_Webp_Manifest` → `OK (44 tests)`.

Printed notices during the run: only `getimagesize(): Read error!` / `exif_imagetype(): Read error!`, from the pre-existing converter tests' `empty.jpg` fixture. The new tests print none (and would fail on any, via their error handler).

### Coverage
**BLOCKED** — `php -m` in the Lando container lists neither xdebug nor pcov. Traceability instead:

| Requirement | Test(s) |
|---|---|
| §0 r2 / §10 A1: serving code never writes files | `test_enable_changes_no_file_and_no_other_meta` (md5+mtime of every file); `test_webp_owned_by_another_attachment_is_skipped_and_untouched` |
| §0 r3: no other stored content changed | `test_enable_changes_no_file_and_no_other_meta`, `test_delete_removes_only_the_manifest` |
| §0 r5: exists | `test_size_without_webp_is_skipped_as_missing` |
| §0 r5: valid image/webp | `test_webp_that_is_really_a_jpeg_is_skipped_as_invalid` |
| §0 r5: same dimensions | `test_stale_webp_with_other_dimensions_is_skipped`, `test_scaled_upload_*` (original_image dims read from file) |
| §0 r5: belongs to this attachment | `test_webp_owned_by_another_attachment_*`, `test_name_clash_with_another_image_*` |
| §0 r5: smaller | `test_size_whose_webp_is_larger_is_skipped`, `test_size_whose_webp_is_the_same_size_is_skipped` |
| §3.1 shape, basenames only | `test_enable_stores_a_version_1_manifest_with_basenames_only` |
| §3.4 malformed / unknown version = off, no notice | `test_malformed_or_unknown_version_manifest_reads_as_off` (13 rows) |
| §3.4 toggle off | `test_delete_removes_only_the_manifest` |
| §3.4 rebuild reflects files now | `test_rebuild_when_serving_stores_the_current_state` |
| §5.3 #1–14 | #1 jpeg_with_valid…, #2 png_with_alpha…, #3 larger/same_size, #4 without_webp, #5 really_a_jpeg, #6 stale_webp, #7 owned_by_another, #8 gif_attachment, #9 invalid_ids, #10 scaled_upload_* (2), #11 enable_stores…, #12 malformed_or_unknown…, #13 enable_changes_no_file…, #14 delete_removes_only… |
| §5.3 #15–21 | mapper: maps_to_its_webp_url (#15–17 rows), url_outside_the_manifest (#17–19 rows), source_extension_or_case (#20), bad_input (#21, 17 rows) |
| §10 #53 | `test_rebuild_keeps_unreachable_mark_while_file_is_unchanged` |
| §10 #54 | `test_rebuild_drops_unreachable_mark_when_mtime_changes`, `…_when_bytes_change` |
| §10 #55 | `test_malformed_unreachable_reads_as_empty` (5 rows) |
| §10 #56 | `test_rebuild_when_not_serving_writes_nothing` |
| §10 #57 | `test_serving_turned_off_during_rebuild_stays_off` |
| Extras | name clash → not_owned; source file gone → source_missing; metadata size without 'file' → source_missing |
| Fixture hygiene | `test_no_fixtures_left_behind` (manifest class) |

### PHPCS / Plugin Check / JS drift
- PHPCS (`--standard=.phpcs.xml.dist`, whole plugin): `15 / 15 (100%)`, 0 errors, 0 warnings (13 files before + 2 new). New test files + bootstrap, same standard: 0/0 (the ruleset doesn't include tests; checked to match the existing tests, which are clean). New `phpcs:ignore` only in test files, each with `-- reason`, for test-only constructs (throwing error handler, seeded `mt_rand` for byte-identical fixtures, `rename`/`touch` on fixtures).
- Plugin Check: output identical to the pre-R1 baseline (`diff` empty). Baseline findings, all in non-shipped files: `v2/deploy/svn-release.sh`, `v2/tests/m4-run-all.sh` (application_detected), `.distignore`, `.gitignore` (hidden_files).
- JS drift: not applicable (R3 onward).
- Uploads: md5 map of all 256 files under `wp-content/uploads` (excluding the fixture folders) identical before/after; both fixture folders removed.

### Files
- Created (shipped): `webp/class-ctgen-webp-manifest.php`, `webp/class-ctgen-webp-url-mapper.php`. Not wired into `includes/init.php` yet (R2 wires everything, per §3.2/§4).
- Created (dev tooling, not shipped): `ctgen-webp-dev-tooling/tests/test-class-ctgen-webp-manifest.php`, `…/test-class-ctgen-webp-url-mapper.php`.
- Changed (with backup):
  - `webp/class-ctgen-webp-ajax-handlers.php` → backup `class-ctgen-webp-ajax-handlers.php.bak8` (a `.bak` from 2026-10-06 already existed and was not overwritten, per rule 6; it is too old to be a safe rollback, so a numbered backup matching `.distignore`'s `*.bak[0-9]*` was added). Changes: extracted `webp_problem()` (public) from `verify_existing_webp()`, which now delegates; `attachment_id_for_file()` and `has_name_conflict()` made public. Logic unchanged; existing handler tests unchanged in result.
  - `ctgen-webp-dev-tooling/tests/bootstrap.php` → `bootstrap.php.bak`. Added two `require_once` lines.
- Skill: `replacement-skill.md` — 3 small wording fixes approved before R1 (health check compares whole manifest; pointers to §10 A1; no existing nonce test pattern).

### Decisions made
- Mapper API: `map( $url, $manifest, $folder_url )`, pure. The attachment-ID convenience lookup belongs to R2 (needs WP functions).
- Mapper keeps the input's own scheme/host form in the output; folder comparison ignores scheme (`http`/`https`/`//`) and host letter case; relative and malformed URLs never map.
- Manifest reuses the handlers' rules (one rule, not two) via constructor injection; `webp_problem()` returns `''|'invalid'|'dimension_mismatch'` so the manifest can give the precise reason.
- New skip reason `source_missing` (source file gone, or metadata size without a file): rule 5 needs the source to prove anything. Add it to the UI reason texts in R3.
- Name clash (photo.jpg + photo.png in one folder) → every part `not_owned`.
- WebP equal in size to the source → `larger_than_source` (saves nothing).
- Reason precedence: source_missing → missing → not_owned → invalid / dimension_mismatch → larger_than_source → unreachable.
- `rebuild()` returns the stored manifest, `'not_serving'`, or `WP_Error`; on a stored-but-malformed manifest it rebuilds a valid one (self-heal), since the meta exists.
- `read_file()` is the filesystem seam (`clearstatcache` per path, so a file changed earlier in the same request is seen). `has_name_conflict()` / `attachment_id_for_file()` do their own checks outside the seam; test 31 (R2, zero filesystem calls during render) is unaffected because rendering never builds.
- `imagedestroy()` not used in test fixtures (deprecated in newer PHP, unnecessary for tiny images).

### Deviations
- #24/#25: two behaviours were written in one red step; made green one at a time and logged honestly.
- 9 required tests passed on their first run because earlier green code already satisfied them; each was seen red through a production-code mutation (A–J, listed in the TDD log) and the code restored.

### Blocked / not done
- Coverage: BLOCKED — no xdebug/pcov in the container (`lando ssh -c "php -m"`).
- PHP 7.0 runtime verification: BLOCKED — the container runs PHP 7.4.33 and PHPCompatibility is not installed in `ctgen-webp-dev-tooling/vendor`. Mitigation: manual grep for 7.1+ syntax (none) + `php -l`.
- 7 pre-existing failing tests: left as they are, awaiting the user's decision (see Baseline).
- Manual check (§8): not applicable to R1 (no hooks, no front-end output yet). First applicable in R2.

### Manual check (§8)
Not applicable to R1 — nothing renders yet.

### Correction (added 2026-10-30, during R2)
R1's mutation G (errors swallowed as an empty manifest) made `enable( 999999999 )` store a `_ctgen_webp_serve` row for that non-existent post during the mutation run. The "no fixtures left behind" test only checks tagged posts, so it didn't catch it. Found by R2's batch test, deleted (meta_id 9281), and guarded from now on by `test_no_orphan_serve_meta_left_behind`. The 7 outdated tests listed above were updated in R2's step 1 (see r2-report.md).
