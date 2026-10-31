---
name: ctgen-webp-replacement
description: Build the "Serve WebP instead of JPEG/PNG" feature in Custom Thumbnail Generator. Covers render-time URL replacement, a per-attachment toggle, usage-aware verification, and strict TDD with PHPUnit. Use for any work on serving, toggling, verifying or reverting WebP replacement. Not for the conversion engine itself, which already exists.
---

# Skill: WebP Replacement (serve WebP in place of JPEG/PNG)

You are a senior WordPress plugin engineer working in `wp-content/plugins/custom-thumbnail-generator/`. Your job is to make the front end of the site **serve the WebP copies the plugin already generates** in place of the original JPEG/PNG, per attachment, under the administrator's control, **without deleting, moving or rewriting any file or any stored post content**.

Read the code before you trust this document. Every "fact" below was true on 2026-10-29. Re-check each one you rely on and say in your report which ones you re-checked.

Background and product reasoning: [webp-replacement.md](webp-replacement.md) (same folder).

---

## 0. Rules that override everything else

1. **Strict TDD. No exceptions.** Production code is written only to make a failing test pass that you have already seen fail. See §5. If you find yourself writing production code first, stop, delete it, and write the test.
2. **No file is ever deleted, moved, renamed or overwritten by this feature.** Originals, thumbnails and WebP copies stay on disk exactly as they are. (Deleting originals, "2a", is a separate future feature and is out of scope.) The one narrow exception, "Regenerate this size" through the existing engine, is defined in §10 A1.
3. **No stored content is ever rewritten.** Never change `post_content`, `_wp_attachment_metadata`, `_wp_attached_file`, builder meta or options to point at `.webp`. Replacement happens only in rendered output. Turning the feature off must restore the original output instantly.
4. **Never rewrite in an editing context.** If a WebP URL reaches the block editor, Classic editor, media modal or a REST `context=edit` response, it can be saved into `post_content` and the "no stored content is rewritten" rule is broken by the user's next save. Admin, editor and edit-context output must always show the original URLs.
5. **Serve WebP only when it is proven safe** for that exact file: the WebP exists, is a valid `image/webp`, has the same dimensions as its source, belongs to this attachment, and is smaller than the source. If any check fails for a size, that size keeps its original URL. Never guess.
6. **`.bak` before editing any pre-existing file.** Before your first edit to a file that already existed, copy it to `<file>.bak` next to it, unless a `.bak` already exists, in which case do not overwrite it. There is no git in this project; `.bak` files are the only rollback.
7. **`v2/` is never shipped.** Plans, reports, harnesses and this skill live in `v2/`. Runtime code must never `require` anything from `v2/` or from `ctgen-webp-dev-tooling/`.
8. **Report honestly.** Show test output exactly as printed. A check you could not run is reported as **BLOCKED** with the exact command and error, never as passed.

---

## 1. Verified codebase facts (re-verify before relying on them)

| Fact | Where |
|---|---|
| Classes are global, prefixed `CTGEN_`; functions prefixed `ctgen_`; text domain `custom-thumbnail-generator`. | whole plugin |
| `includes/init.php` is the single loader; new classes are `require_once`d and instantiated there, in the same style. | `includes/init.php` |
| Baseline: PHP 7.0 (`Requires PHP: 7.0`), WordPress 6.5 (`Requires at least: 6.5`), tested up to 7.1. Do not use PHP 7.1+ syntax in runtime code (no nullable types, no `void` return types, no class-constant visibility). Tests may use PHPUnit 9 syntax. | plugin header, `readme.txt` |
| Converter: `CTGEN_Webp_Converter::convert( $source, $destination )` returns an array or `WP_Error` with `ctgen_webp_*` codes. | `webp/class-ctgen-webp-converter.php` |
| Allowed sources are `image/jpeg` and `image/png` only. **GIF is excluded** (`ctgen_webp_gif_excluded`, user decision 2026-10-22). Replacement therefore applies to JPEG and PNG only. | converter `ALLOWED_SOURCE_MIME_TYPES` |
| WebP naming: the copy sits beside its source, same basename, `.webp` extension: `photo-300x200.jpg` becomes `photo-300x200.webp`. The full size and, for scaled uploads, `original_image` also get copies. | `CTGEN_Webp_Ajax_Handlers::process_file()` |
| Name clashes (`photo.jpg` and `photo.png` in one folder) are refused with `ctgen_webp_name_conflict`. An existing Media Library `.webp` with the target name is never overwritten (`ctgen_webp_destination_in_use`). So **a `.webp` next to a source file is not necessarily ours.** Ownership must be checked. | `has_name_conflict()`, `attachment_id_for_file()` |
| `verify_existing_webp()` already defines "valid and current": real `image/webp` by `wp_getimagesize()` and dimensions equal to the source size's metadata. Reuse this rule; don't invent a second one. | AJAX handlers |
| Every AJAX handler: `check_ajax_referer( 'ctgen_media_actions', 'security' )` then `current_user_can( 'manage_options' )`, input via `sanitize_text_field( wp_unslash( ... ) )` / `absint()`, and client-facing errors through a safe-message map that never echoes a path. | `webp/class-ctgen-webp-ajax-handlers.php` |
| Usage scanner: `CTGEN_Usage_Scanner`, cached in post meta `_ctgen_usage` (refs) and `_ctgen_usage_count`. `get_cached( $id )` reads it. Ref sources include `featured_image`, `product_gallery`, `builder`, `custom_field`, post content, options, settings. | `includes/class-ctgen-usage-scanner.php` |
| Attachment Summary UI: per-row table with a "WebP status" column and "Regenerate WebP" button. | `includes/class-ctgen-attachment-summary-table.php`, `assets/js/ctgen-attachment-summary.js` |
| JS: edit the readable source, then rebuild the `.min.js` with `node v2/tools/build-js.js`; `node v2/tools/build-js.js --check` must exit 0 (no source/min drift). | `v2/tools/build-js.js` |
| Dev tooling (PHPUnit 9.6, PHPCS + WPCS 3) lives **outside** the plugin in `wp-content/plugins/ctgen-webp-dev-tooling/` so Plugin Check never sees it. Ruleset: `ctgen-webp-dev-tooling/.phpcs.xml.dist`. | dev-tooling |
| Test bootstrap loads the real site via `/app/wp-load.php` (Lando container path) and uses `PHPUnit\Framework\TestCase`, not `WP_UnitTestCase` (no wp-tests-lib available). It currently `require_once`s only the converter, so every new class under test must be added there. | `ctgen-webp-dev-tooling/tests/bootstrap.php` |
| Tests write to the real database. Fixtures are tagged with a fixed post title, tracked, stored in a dedicated uploads subfolder and deleted in `tearDown()`. A final test asserts nothing tagged remains. Copy this pattern exactly. | `tests/test-class-ctgen-webp-ajax-handlers.php` |
| Site runs under Lando (`.lando.yml`, recipe `wordpress`, webroot `.`). No git repository. | site root |
| `uninstall.php` exists. Any new option or meta key must be removed there. | plugin root |

---

## 2. Feature scope

### In scope

- A per-attachment **"Serve WebP"** on/off state, off by default.
- A **manifest** per attachment listing exactly which sizes are safe to replace (see §3.1), built when the toggle is turned on and rebuilt when the attachment's files change.
- **Render-time URL replacement** on the front end for every size listed in the manifest.
- An admin **per-row toggle** and **bulk action** on the Attachment Summary screen, using the existing AJAX/nonce pattern.
- A **usage-aware verification** step that reports "Serving WebP on N of M places", using the usage scanner's refs.
- Clean **lifecycle handling**: regenerate, edit, restore, delete, uninstall.
- **Self-healing**: a scheduled health check that rebuilds manifests in small batches, so a WebP removed outside the plugin (FTP, another optimiser, a backup restore with originals only) stops being served without anyone acting. See §3.7.
- A per-size **"Regenerate this size"** action for sizes skipped because their WebP is missing or broken.

### Out of scope (do not build, even partially)

- Deleting or replacing original files ("2a", storage saving).
- `.htaccess` / Nginx rewrite rules or `Accept`-header content negotiation.
- Rewriting stored content in the database.
- GIF, AVIF, or any source other than JPEG/PNG.
- Upload-time automatic conversion or automatic enabling.
- Third-party cache purge integrations, unless a milestone below explicitly includes it.

---

## 3. Design

Keep the design small. Each class has one job and is testable on its own.

### 3.1 Data model

| Key | Type | Meaning |
|---|---|---|
| `_ctgen_webp_serve` (post meta) | array | Present only when serving is on. Shape below. Absent means "off". |

```php
array(
	'version' => 1,                 // bump when the shape changes; unknown version = treat as off
	'sizes'   => array(
		// key: size slug, or 'full', or 'original_image'
		'medium' => array(
			'source' => 'photo-300x200.jpg',   // basename only, never an absolute path
			'webp'   => 'photo-300x200.webp',  // basename only
			'source_bytes' => 18234,
			'webp_bytes'   => 6120,
		),
	),
	'skipped' => array(
		// key: size => reason code, for the UI ("larger_than_source", "missing", "invalid", "not_owned", "dimension_mismatch", "unsupported_source", "unreachable")
	),
	'built'   => 1730192400,        // time() when built, for display only; never used in logic or tests
)
```

Why a manifest: the front-end replacer must **never touch the filesystem** per request. All `file_exists` / `wp_getimagesize` / `filesize` work happens once, when the manifest is built in admin. The replacer only reads one meta value (which WordPress already caches with the post meta).

The cost of this choice is that the manifest can go stale if a WebP disappears after it was built. That is handled by rebuilding, never by checking the disk during a render: see §3.4 and §3.7.

**Partial manifests are normal and valid.** Conversion succeeds or fails per size (`convert_attachment()` returns success even when single sizes fail), and the full size is the most likely to fail (largest file, memory/time limits) or be skipped (`larger_than_source`). A result like "WebP thumbnails in `srcset`, original JPEG in `src`" is correct HTML: the browser picks one candidate and decodes whatever format it gets. Don't add an "all sizes or nothing" rule, and don't require `full` to be in `sizes` before serving can be enabled. Show the partial state clearly instead (§3.5).

`unreachable` is set only by the verification step (R4) and means the WebP exists on disk but the site's public URL for it did not return `200` with `Content-Type: image/webp` (typical cause: media offloaded to S3/CDN without the `.webp`).

<!-- DISABLED (2026-10-29): ignore this instruction; it is kept for reference only.
Leave room for the future states from the discussion (`original` → `webp-served` → `webp-only`). Do not build `webp-only`, but don't design the key so it can't be added.
-->

### 3.2 Classes

| Class | File | Responsibility | Touches WP hooks? |
|---|---|---|---|
| `CTGEN_Webp_Manifest` | `webp/class-ctgen-webp-manifest.php` | Builds, validates, reads and deletes the manifest for one attachment. Applies all of §0 rule 5. Pure decisions in small methods; filesystem access goes through one method so tests can use real fixture files. | No |
| `CTGEN_Webp_Url_Mapper` | `webp/class-ctgen-webp-url-mapper.php` | Given an attachment ID (or a URL plus manifest), returns the WebP URL for a source URL, or the original URL unchanged. Pure string logic. Handles query strings, fragments, protocol-relative and scheme variants, and `-scaled` names. Never builds a URL for a size not in the manifest. | No |
| `CTGEN_Webp_Replacer` | `webp/class-ctgen-webp-replacer.php` | Registers the front-end filters and delegates every decision to the mapper. Owns the "is this a front-end render?" gate (§3.3). | Yes |
| `CTGEN_Webp_Serve_Ajax` | `webp/class-ctgen-webp-serve-ajax.php` | `ctgen_webp_set_serve` (single and bulk), `ctgen_webp_regenerate_size` and `ctgen_webp_verify_serving`. Same nonce, capability and sanitising pattern as existing handlers. | Yes |
| `CTGEN_Webp_Health_Check` | `webp/class-ctgen-webp-health-check.php` | Scheduled self-healing (§3.7). Schedules/unschedules its cron event, walks attachments with `_ctgen_webp_serve` in batches and rebuilds each manifest through `CTGEN_Webp_Manifest`. Has no validation rules of its own. | Yes |

Wire all five in `includes/init.php` in the existing style. Add each to the test bootstrap.

### 3.3 Where replacement happens

Filters (verify each signature against the installed WordPress core before using it):

- `wp_get_attachment_image_src`: single `src`.
- `wp_calculate_image_srcset`: each `$sources[ $w ]['url']`.
- `wp_content_img_tag`: `<img>` in post content (provides the attachment ID). Replace `src` and `srcset` only; leave all other attributes byte-identical.
- `render_block`: only for blocks that print image URLs outside `<img>` (e.g. `core/cover` background `url(...)`). Add one block at a time, each with its own tests. Don't do a blind regex over all block HTML.

The **front-end gate** returns false (no replacement) when any of these is true:

- `is_admin()` (this includes admin-ajax; the summary table must show originals).
- `wp_is_json_request()` or `REST_REQUEST` with `context=edit`.
- The request is a feed, embed preview inside the editor, `wp_doing_cron()`, or WP-CLI. Decide feeds explicitly, write a test for the decision, and record it in the report.
- The filter `ctgen_webp_should_replace` (new, documented, default true) returns false. This is the escape hatch for site owners.

### 3.4 Lifecycle

| Event | Required behaviour |
|---|---|
| Thumbnails regenerated (this plugin or core `wp_update_attachment_metadata`) | Rebuild the manifest if serving is on. A size whose WebP is now stale (dimension mismatch) drops out of `sizes` into `skipped`. |
| WebP regenerated ("Regenerate WebP") | Rebuild the manifest if serving is on. |
| Image edited (crop/rotate/restore in the media editor) | Rebuild. New filenames mean old manifest entries must not survive. |
| Attachment deleted | Meta goes with the post. The existing `delete_webp_copies()` path is unchanged. |
| Any code path in this plugin deletes or overwrites a WebP file of an attachment that is serving | Rebuild that attachment's manifest in the same request, after the file operation finishes. Find every such path (grep for `unlink`, `wp_delete_file`, `delete_webp_copies`, converter calls with `$force`) and list them in the report. |
| A WebP removed or changed outside the plugin (FTP, another plugin, a restore) | Picked up by the next health check run (§3.7). Until then that URL may return 404; this delay is documented in the readme, along with "turn serving off" as the instant fix. |
| A rebuild leaves `sizes` empty | Keep the meta (serving stays "on", so it recovers when WebP is regenerated). The replacer serves originals for everything, and the UI shows a warning instead of "Serving WebP". |
| Toggle off | `delete_post_meta`. Nothing else changes. Output is instantly original. |
| Plugin deactivated | Unschedule the health check event. Leave the meta (reactivating restores serving). |
| Plugin uninstalled | `uninstall.php` deletes `_ctgen_webp_serve` for all posts, deletes the health check cursor option and unschedules its event. |
| Manifest has an unknown `version` or a malformed shape | Treat as off. Never fatal, never notice. |

### 3.5 Admin UI

- Per-row control in the WebP status column: a "Serve WebP" toggle with clear state ("Serving WebP: 5 of 6 sizes", with a hover/expand list of skipped sizes and their reasons as readable text).
- In that skipped list, sizes with reason `missing`, `invalid` or `dimension_mismatch` get a **"Regenerate this size"** button. It converts that one part with the existing single-part conversion (`convert_attachment()` / the per-part method in `CTGEN_Webp_Ajax_Handlers`; reuse it, don't duplicate it), then rebuilds the manifest and refreshes the row. No button for `larger_than_source` (keeping the original is the right result), `not_owned` (the file belongs to someone else) or `unsupported_source`. For `unreachable` show the reason only ("WebP exists but its URL doesn't load; check media offload/CDN"). The exact limits on what this button may write are in §10 A1.
- Bulk action: "Serve WebP" / "Stop serving WebP" for selected rows. Process in batches over AJAX like the existing bulk convert. Never one huge request.
- The toggle is disabled with an explanation when the attachment has no valid WebP copies yet ("Generate WebP first").
- "Could save X" changes to "Saving X per load" once serving is on.
- Verification (milestone R4) shows "Serving WebP on N of M places", listing places that still show the original and why when it can tell (e.g. hardcoded URL in builder meta).
- All strings translatable, escaped on output (`esc_html__`, `esc_attr`, `wp_kses` with `CTGEN_ALLOWED_TAGS` where that is the existing pattern). Accessible: real `<button>`/`<input type="checkbox">`, labels, `aria-live` for status updates, keyboard operable.

### 3.6 Security

- Every AJAX endpoint: nonce check, then `manage_options`, then `absint` every ID, then confirm each ID is an image attachment. Bulk requests cap the number of IDs per call.
- Never return absolute filesystem paths to the browser. Errors go through a safe-message map.
- The verification step only fetches URLs of this site (`home_url()` host), with `wp_safe_remote_get` / `wp_safe_remote_head`, a short timeout and a cap on the number of requests per call. No external hosts, ever.
- Output escaping in the replacer: the mapper returns URLs; anything injected into HTML is escaped with `esc_url` / `esc_attr`.
- The health check runs only from cron, takes no input, and makes no HTTP requests.

### 3.7 Self-healing and 404 prevention

The replacer never checks the disk, so a manifest is the only thing between a missing WebP and a 404. Three layers keep it accurate:

1. **Immediate:** every place in this plugin that removes or overwrites a WebP rebuilds the manifest in the same request (§3.4).
2. **Scheduled health check** (`CTGEN_Webp_Health_Check`):
   - Cron hook `ctgen_webp_health_check`, scheduled on activation (and on load if missing), recurrence `daily`, filterable via `ctgen_webp_health_check_interval`.
   - Each run processes at most `ctgen_webp_health_check_batch` attachments (filter, default 50) that have `_ctgen_webp_serve`, ordered by ID, resuming from the option `ctgen_webp_health_cursor` (autoload off). When it reaches the end it resets the cursor to 0.
   - For each attachment it calls the same manifest rebuild used everywhere else. It never adds its own rules, never deletes or writes a file, and never turns serving off.
   - It writes the meta only if the rebuilt manifest differs from the stored one in any key except `built` (`sizes`, `skipped`, `unreachable`); this avoids cache churn and needless `updated_post_meta` hooks.
3. **Reachability check** (R4 verification): for each manifest size it sends a same-host `wp_safe_remote_head` to the WebP URL. Anything other than `200` with `Content-Type: image/webp` moves that size to `skipped` as `unreachable`. This catches what a disk check can't: offloaded media, CDN rules, server MIME misconfiguration. It runs only when an admin triggers verification, never from cron and never during a front-end request.

Not done, on purpose:

- **Render-time `file_exists`**: breaks the zero-filesystem render rule (test 31) and costs I/O on every page view.
- **Client-side `onerror` fallback**: changes `<img>` attributes (breaks test 24), conflicts with strict CSP, and does not work reliably with `srcset`.
- **`<picture>` as a 404 fallback**: a WebP-capable browser that picks the `<source>` does not fall back to `<img>` when it 404s, so it does not solve this problem.
- **Server rewrite fallback** (serve `.jpg` when `.webp` is missing): out of scope (§2).

Residual risk, stated in the readme: between an external deletion and the next health check run, and in page caches holding old HTML, a WebP URL may 404. Turning serving off for the image (or the `ctgen_webp_should_replace` filter site-wide) restores originals immediately, because originals are never touched.

---

## 4. Milestones

Deliver one milestone at a time. Finish its tests, PHPCS, Plugin Check and report before starting the next.

| ID | Delivers | Depends on |
|---|---|---|
| **R1** | `CTGEN_Webp_Manifest` + `CTGEN_Webp_Url_Mapper`, fully unit tested. No hooks, no UI. | none |
| **R2** | `CTGEN_Webp_Replacer` with the front-end gate and the four filters, lifecycle rebuilds (§3.4), and `CTGEN_Webp_Health_Check` (§3.7 layers 1–2). Enabled only via a test or `wp eval`. | R1 |
| **R3** | AJAX endpoints, per-row toggle, bulk action, "Regenerate this size", uninstall cleanup (meta, cursor option, cron event), JS source + rebuilt `.min.js`. | R2 |
| **R4** | Usage-aware verification ("N of M places") using `CTGEN_Usage_Scanner` refs and same-site fetches, plus the WebP reachability check (§3.7 layer 3). | R3 |

---

## 5. Strict TDD workflow (mandatory)

### 5.1 The cycle, for every behaviour

1. **Red.** Write one test for one behaviour. Run it. It must fail **for the expected reason** (assertion failure or "class/method not found" on first use). Copy the failing output into your working notes. A test that passes before you write the code is wrong or redundant. Fix it or delete it.
2. **Green.** Write the **smallest** production change that makes that test pass. No extra branches, no "while I'm here", no speculative parameters.
3. **Run the whole suite.** Every existing test (converter, AJAX handlers, everything new) must still pass. A regression is fixed before any new test is written.
4. **Refactor.** Clean up production and test code with the suite green. Run the full suite again after refactoring.
5. Repeat.

You may not:

- Write production code for behaviour that has no failing test.
- Change a test's assertion to match buggy output. If the test was wrong, say so in the report and explain why.
- Mark a test skipped, incomplete or risky to get a green run. `markTestSkipped` is allowed only for a genuine environment limit (e.g. Imagick missing), with the reason in the message, and it is listed in the report.
- Write a test that only runs code without asserting its result.
- Batch several behaviours into one red step.

### 5.2 Unit testing standards

- **Location:** `wp-content/plugins/ctgen-webp-dev-tooling/tests/`, one file per class: `test-class-ctgen-webp-manifest.php`, `test-class-ctgen-webp-url-mapper.php`, `test-class-ctgen-webp-replacer.php`, `test-class-ctgen-webp-serve-ajax.php`. The suite picks up `test-*.php` automatically (`phpunit.xml.dist`).
- **Base class:** `PHPUnit\Framework\TestCase`, matching existing tests. Add each new class to `tests/bootstrap.php` with `require_once`, using `CTGEN_PLUGIN_UNDER_TEST_DIR`. Back up `bootstrap.php` to `bootstrap.php.bak` first (if no `.bak` exists).
- **Names say the behaviour:** `test_size_larger_than_source_is_skipped_with_reason`, not `test_manifest_2`.
- **One behaviour per test**, Arrange / Act / Assert, with a docblock giving the requirement it proves.
- **Use data providers** for the mapper's URL variants (query strings, fragments, `http`/`https`/protocol-relative, `-scaled`, uppercase `.JPG`, `.jpeg`, `.png`, non-matching URLs, URLs from another attachment, external hosts).
- **Real files, real WordPress.** Use the existing fixture images (`tests/fixtures/`) copied into a per-test scratch or uploads subfolder. Create attachments with the existing tagged-fixture pattern (fixed title, tracked IDs, dedicated uploads subfolder, `wp_delete_attachment( $id, true )` in `tearDown()`, and a final "no fixtures left behind" test).
- **Isolation:** every test leaves no meta, option, filter, global or file behind. Filters added in a test are removed in `tearDown()`. Restore `$_POST`, `$_REQUEST`, `$GLOBALS['wp_query']` and the current user if a test changes them.
- **Determinism:** no network, no `sleep`, no dependence on `time()`, test order or existing site content. The `built` timestamp is never asserted beyond "is int".
- **Testing the gate:** simulate contexts by setting the minimal globals/constants the code reads. For constants that cannot be undefined inside a process (e.g. `REST_REQUEST`), put the check behind a small overridable method or the `ctgen_webp_should_replace` filter, and test it through that seam. Don't run separate processes to work around a design that could be testable.
- **AJAX tests:** call the handler's underlying method directly (like the existing `convert_attachment()` tests), and test the nonce/capability gate separately by setting the current user and `$_REQUEST['security']` and catching `WPDieException`-style termination via `wp_die_ajax_handler` / `wp_doing_ajax` filters. Read the existing AJAX tests first: as of 2026-10-30 they test only the underlying methods and contain **no** nonce/capability tests, so there is no pattern to copy for the gate. Create one (R3), keep it small and reusable across the new AJAX tests, and describe it in the R3 report.
- **Assert exact output** for filters: the full returned array or the full `<img>` tag string, so you catch unintended attribute changes.

### 5.3 Required test cases (minimum; add more as needed)

**R1: Manifest**

1. JPEG attachment with valid WebP for every size: all sizes in `sizes`, `skipped` empty.
2. PNG with alpha: included when WebP is valid and smaller.
3. A size whose WebP is larger than or equal to the source: in `skipped` with `larger_than_source`.
4. A size with no WebP file: `missing`.
5. A `.webp` that is actually a JPEG renamed: `invalid`.
6. A WebP with wrong dimensions (stale): `dimension_mismatch`.
7. A `.webp` that is its own Media Library attachment: `not_owned`, and that file is untouched.
8. GIF attachment: manifest refused with `unsupported_source`; nothing stored.
9. Non-image attachment / non-existent ID: `WP_Error` with a specific code; nothing stored.
10. Scaled upload: `full` (the `-scaled` file) and `original_image` handled independently.
11. Stored manifest contains basenames only (assert no `/` in any filename value).
12. Malformed or unknown-`version` meta reads as "off" with no PHP notice (assert via an error handler that fails the test on any notice/warning).
13. Building the manifest changes no file on disk (md5 + mtime before/after for every file in the folder) and no other post meta (snapshot all meta before/after).
14. Deleting the manifest leaves every other meta key unchanged.

**R1: URL mapper** (data provider)

15. Source URL of a manifest size maps to its WebP URL.
16. Query string and fragment are preserved.
17. `http`, `https` and protocol-relative variants of the uploads URL all map; a different host never maps.
18. A size not in the manifest returns the input unchanged (identical string).
19. A URL of another attachment with a similar name (`photo-1.jpg` vs `photo.jpg`) never maps.
20. Uppercase extension and `.jpeg` map correctly.
21. Empty, non-string and malformed input return unchanged with no notice.

**R2: Replacer**

22. `wp_get_attachment_image_src` returns the WebP URL on the front end when serving is on, and the original when it is off.
23. `wp_calculate_image_srcset` replaces only the manifest sizes; other entries are byte-identical.
24. `wp_content_img_tag`: `src` and `srcset` replaced; every other attribute (`class`, `alt`, `width`, `height`, `loading`, `decoding`, `fetchpriority`, `style`, `data-*`) byte-identical.
25. `wp_get_attachment_image()` output end to end contains no original URL for manifest sizes and keeps the original for skipped sizes.
26. Gate: no replacement when `is_admin()`, in REST edit context, during cron, in WP-CLI, or when `ctgen_webp_should_replace` returns false. One test each.
27. Feeds: the chosen behaviour, asserted.
28. Turning serving off restores original output in the same request (no stale in-memory cache).
29. `post_content` in the database is unchanged after rendering a post that contains the image (read raw from `$wpdb` before and after).
30. Lifecycle: regenerating thumbnails, regenerating WebP and editing the image each rebuild the manifest; a stale size drops to `skipped`.
31. Performance guard: rendering an image with serving on calls no filesystem function. Assert by counting calls through the manifest's filesystem seam, which must be zero during render.
32. Partial manifest: `full` missing, other sizes valid. `wp_get_attachment_image()` output has the original URL in `src` and WebP URLs for the valid sizes in `srcset`, exactly as asserted.
33. Each plugin code path that deletes or overwrites a WebP (as listed in the report) rebuilds the manifest in the same request: the removed size is in `skipped` right after the call.
34. Health check: a WebP deleted directly from disk after the manifest was built moves to `skipped` (`missing`) after one run; the front end then serves the original for that size.
35. Health check: honours the batch size and resumes from the cursor across runs; wraps to 0 at the end.
36. Health check: changes no file on disk (md5 + mtime snapshot) and does not write the meta when nothing changed (assert no `updated_post_meta` action fired).
37. Health check: an attachment whose rebuild leaves `sizes` empty keeps its meta, and the front end serves originals.
38. Health check event is scheduled on activation and unscheduled on deactivation.

**R3: AJAX + UI**

39. Missing/invalid nonce: rejected. Non-admin: rejected. Each asserted separately.
40. Bulk with more than the per-call cap: rejected or truncated as documented.
41. Enabling an attachment with no valid WebP: specific error, nothing stored.
42. Response never contains an absolute filesystem path (assert `ABSPATH` and the uploads `basedir` don't appear in the JSON).
43. "Regenerate this size" for a `missing` size: the WebP is created, that size moves from `skipped` to `sizes`, other sizes are untouched (files and manifest entries).
44. "Regenerate this size" is refused for `larger_than_source`, `not_owned` and `unsupported_source`, with a specific error and nothing changed.
45. `uninstall.php` removes `_ctgen_webp_serve` from all posts, the `ctgen_webp_health_cursor` option and the cron event, and nothing else (snapshot of other meta and options before/after).
46. `node v2/tools/build-js.js --check` exits 0.

**R4: Verification**

47. Counts N of M from the usage scanner's refs for a fixture post that contains the image.
48. Only same-host URLs are fetched; an option ref pointing at an external host is reported as "not checkable" and never fetched (assert via the `pre_http_request` filter, which also keeps tests offline).
49. A hardcoded original URL in builder meta is reported with its source (`builder`) and reason.
50. Fetch cap and timeout respected (assert the args passed to `pre_http_request`).
51. Reachability: a WebP URL answered with `404` (via `pre_http_request`) moves to `skipped` as `unreachable`; one answered `200` with `Content-Type: image/jpeg` does too; `200` with `image/webp` stays in `sizes`. One test each.
52. Reachability uses HEAD requests, same host only, and never runs during a front-end render or from cron.

### 5.4 Coverage

Check whether a coverage driver exists (`php -m | grep -Ei 'xdebug|pcov'` inside Lando). If it does, run with `--coverage-text` and report line coverage for every new class. The target is 100% of lines in `Manifest` and `Url_Mapper` and at least 90% in `Replacer`, `Serve_Ajax` and `Health_Check`, with every uncovered line explained. If no driver exists, report coverage as **BLOCKED** and instead include a **requirement → test** traceability table covering every row of §0, §3.3, §3.4, §3.7 and §5.3.

---

## 6. Commands

Run from the WordPress root unless noted. Confirm each command works before relying on it; if the Lando form fails, try the plain form and report which one worked.

```bash
# Full PHPUnit suite (inside the Lando container; /app is the webroot there)
lando ssh -c "cd /app/wp-content/plugins/ctgen-webp-dev-tooling && vendor/bin/phpunit"

# One file / one test while doing red-green
lando ssh -c "cd /app/wp-content/plugins/ctgen-webp-dev-tooling && vendor/bin/phpunit tests/test-class-ctgen-webp-url-mapper.php"
lando ssh -c "cd /app/wp-content/plugins/ctgen-webp-dev-tooling && vendor/bin/phpunit --filter test_query_string_is_preserved"

# Coding standards (ruleset lives in the dev-tooling folder)
lando ssh -c "cd /app/wp-content/plugins/ctgen-webp-dev-tooling && vendor/bin/phpcs --standard=.phpcs.xml.dist"

# Plugin Check (the plugin as shipped)
lando wp plugin check custom-thumbnail-generator

# JS build and drift check
node wp-content/plugins/custom-thumbnail-generator/v2/tools/build-js.js
node wp-content/plugins/custom-thumbnail-generator/v2/tools/build-js.js --check
```

---

## 7. Definition of done (per milestone)

All of these, with evidence pasted into the report:

- [ ] Every behaviour in the milestone has a test that was seen failing first (red output recorded) and passes now.
- [ ] Full PHPUnit suite green, existing tests included. Exact final summary line pasted (`OK (N tests, M assertions)`).
- [ ] No PHP notice, warning or deprecation during the run with `WP_DEBUG` on.
- [ ] PHPCS: zero errors and zero warnings on new and changed files, with no new `phpcs:ignore` unless justified inline (the existing style: `-- reason`).
- [ ] Plugin Check: no new errors or warnings compared to before the milestone.
- [ ] `build-js.js --check` exits 0 (R3 onward).
- [ ] No original, thumbnail or WebP file changed (md5 map of `wp-content/uploads` before/after the full test run is identical, apart from test fixture folders that the run creates and removes).
- [ ] No test fixture left in the database (the "no fixtures left behind" test passes).
- [ ] Every pre-existing file you edited has a `.bak`.
- [ ] PHP 7.0-compatible runtime code (no 7.1+ syntax in plugin files).
- [ ] The manual front-end check from §8 was done.

---

## 8. Manual check (after automated tests pass)

1. Turn serving on for one JPEG and one PNG used on a real page.
2. View the page source logged out: manifest sizes use `.webp` in `src` and `srcset`; skipped sizes use originals.
3. Open the same post in the block editor: only original URLs appear. Save it; confirm `post_content` still has original URLs.
4. Turn serving off: the page source shows originals again on the next load.
5. Browser dev tools Network tab: WebP responses are `200` with `Content-Type: image/webp`.
6. Turn serving back on. Delete one size's `.webp` directly on disk (a fixture image, not real content), run `lando wp cron event run ctgen_webp_health_check`, reload the page logged out: that size now uses the original URL and the Network tab shows no 404. Regenerate the size from the admin and confirm it is served as WebP again.

Record what you saw for each step.

---

## 9. Report format

```
## R<n> report

### Re-verified facts
- <fact from §1> — confirmed by <file:line / command>

### TDD log
| # | Test | Red (failure reason seen) | Green | Notes |

### Test run
<exact final PHPUnit output>

### Coverage
<table, or BLOCKED + traceability table>

### PHPCS / Plugin Check / JS drift
<exact output>

### Files
- Created: ...
- Changed (with .bak): ...

### Decisions made
- <decision> — because <reason>

### Blocked / not done
- <item> — <exact command and error>

### Manual check (§8)
1. ... observed: ...
```

Keep the report factual. Don't call anything "flawless", "fully tested" or "production-ready". The evidence above is what demonstrates quality.

---

## 10. Planning to make over not-ready and Gaps

Added 2026-10-30. Reasoning: [webp-replacement.md](webp-replacement.md), section of the same name.

**This section amends §0–§8. Where it conflicts with an earlier section, this section wins.** Apply each amendment in the milestone given in §10.4, under the same strict TDD rules (§5): every behaviour below gets its red test first. New required tests continue the §5.3 numbering from 53.

### 10.1 Amendments that fix "not ready"

**A1. Scope of the "no file is overwritten" rule (amends §0 rule 2).**

- The serving code (`CTGEN_Webp_Manifest`, `CTGEN_Webp_Url_Mapper`, `CTGEN_Webp_Replacer`, `CTGEN_Webp_Health_Check` and the verification code) never creates, writes, moves, renames or deletes any file. Its only filesystem access is reading, through the manifest's filesystem seam.
- The one exception in the whole feature is "Regenerate this size" (§3.5). It may create or overwrite **only that size's `.webp`**, and **only** by calling the existing `CTGEN_Webp_Ajax_Handlers::convert_part( $attachment_id, $part, true )`. Never call the converter directly, never write a file yourself, never add a new write path.
- The engine's own guards (`ctgen_webp_destination_in_use`, `ctgen_webp_name_conflict`) are what protect files that aren't ours. Rely on them; don't bypass or reimplement them. Map their error codes through the safe-message map.
- Originals and thumbnails are never written by anything in this feature, with no exception.

**A2. `unreachable` survives rebuilds (amends §3.1, §3.7).**

- Manifest shape (still `version` 1, since nothing has been built yet) gains a top-level key:
  ```php
  'unreachable' => array(
  	// size => what the file looked like when its URL failed
  	'medium' => array( 'webp' => 'photo-300x200.webp', 'webp_bytes' => 6120, 'webp_mtime' => 1730192400 ),
  ),
  ```
  A size in `unreachable` is never in `sizes`, and `skipped[ size ]` is `'unreachable'`.
- Every rebuild (toggle, lifecycle, health check, regenerate) keeps an `unreachable` entry when the file on disk still has the same basename, size in bytes and mtime. If any of the three differs, or the file is gone, drop the entry and evaluate the size normally.
- Only the verification step (R4) adds entries, and it removes an entry when that URL returns `200` with `Content-Type: image/webp`.
- Turning serving off deletes the meta and with it the marks, so turning it on again starts fresh.
- Malformed `unreachable` data is treated as empty, with no notice (extends test 12).

**A3. Only "turn on" creates the manifest meta (amends §3.4, §3.7).**

- `CTGEN_Webp_Manifest` exposes two distinct operations: `enable( $id )`, the only code path allowed to create `_ctgen_webp_serve`, and `rebuild( $id )`, used by everything else.
- `rebuild()` computes the new manifest, then re-reads the meta straight from the database: delete the attachment's `post_meta` object-cache entry, then `get_post_meta()`. If the meta is absent, it writes nothing and returns a specific result (e.g. `'not_serving'`).
- The re-read happens through an overridable method so a test can simulate "turned off while rebuilding".
- In the report, record that a sub-millisecond window remains between that re-read and the write, and why it's acceptable: the next toggle or health check corrects it, and no file is ever at risk.

### 10.2 Amendments that close the Gaps

**G1. Browsers without WebP: recorded decision (amends §2, §3.5).**

- Serve the WebP URL to every browser. Do not add `<picture>`, user-agent sniffing, `Accept`-header negotiation or client-side fallbacks. If a later task asks for a fallback, it's a new feature with its own skill.
- Add a readme FAQ ("What about browsers that don't support WebP?") explaining the ~97% support, the trade-off, and that turning serving off restores originals immediately.
- Add one line of help text by the per-row toggle saying the same, translatable and escaped.

**G2. `wp_get_attachment_url` stays unfiltered (amends §3.3, R4).**

- Do not filter `wp_get_attachment_url`, `wp_get_original_image_url`, or `attachment_link`. Reason: they feed admin screens, the editor, REST, exports and file operations in other plugins; filtering them can put WebP URLs into stored content.
- In verification (R4), an original URL found only inside `<a href="…">` or `<meta property="og:image" …>` / `<meta name="twitter:image" …>` counts as **"original by design"**: listed, but not counted as a failure in "N of M". Any original URL in `<img src>`, `srcset` or inline `background-image` for a manifest size is a failure as before.

**G3. Multisite (amends §3.4, §3.7, R3 uninstall).** `readme.txt` promises network-wide activation, so:

- Health check scheduling: on `admin_init`, if `wp_next_scheduled( 'ctgen_webp_health_check' )` is false, schedule it. This covers subsites under network activation, where the activation hook runs once only. The activation hook also schedules it for the current site.
- Deactivation hook: when `$network_wide` is true and `is_multisite()`, loop over `get_sites( array( 'fields' => 'ids', 'number' => 0 ) )` with `switch_to_blog()` / `restore_current_blog()` and unschedule on each; otherwise unschedule on the current site. Put the site-ID list behind an overridable method so it can be tested on a single-site install.
- `uninstall.php`: on multisite, run the cleanup for the new keys (`_ctgen_webp_serve`, `ctgen_webp_health_cursor`, the cron event) on every site using the same loop. Do **not** change the existing cleanup lines for `_ctgen_usage`, `_ctgen_usage_count` and the user meta; list their single-site gap in the report under "Blocked / not done" as a follow-up for the user to decide.

**G4. Page caches and CDNs (amends §3.5; purge integrations stay out of scope).**

- After every successful change to serving state (toggle on/off, bulk, "Regenerate this size", a rebuild that changed `sizes`, a verification that changed `unreachable`), fire `do_action( 'ctgen_webp_serving_changed', $attachment_id, $state )`, where `$state` is `'on'` or `'off'`. Document it in the readme as the hook for purging caches.
- Every success message in the admin UI for toggle, bulk and regenerate includes: "If you use a page cache or CDN, purge it to see the change." (translatable).
- Readme FAQ: "I turned on WebP but still see JPG/PNG" pointing to cache purging and the verification step.

**G5. Accuracy fixes.**

- Add to §1: the plugin has **no** `register_activation_hook` / `register_deactivation_hook` today. Register both in `custom-thumbnail-generator.php` (a pre-existing file: `.bak` first, rule 6), delegating to static methods on `CTGEN_Webp_Health_Check`.
- Reachability check (§3.7 layer 3): if `wp_safe_remote_head` returns `405` or `501`, retry **once** with `wp_safe_remote_get`, with the same timeout and same host restriction, and count it against the same request cap.
- The `DISABLED` comment block in §3.1 has no effect. Don't build anything from it.

### 10.3 Additional required tests

**R1**

53. A2: a rebuild keeps an `unreachable` entry whose basename, bytes and mtime match the file on disk; the size stays out of `sizes`.
54. A2: a rebuild drops an `unreachable` entry when the file's bytes or mtime differ, and the size is evaluated normally.
55. A2: malformed `unreachable` data reads as empty with no notice.
56. A3: `rebuild()` on an attachment without `_ctgen_webp_serve` writes nothing and returns `'not_serving'`.
57. A3: meta deleted through the re-read seam during `rebuild()` stays deleted afterwards.

**R2**

58. G2: `wp_get_attachment_url()` returns the original URL while serving is on (front-end context).
59. G3: on `admin_init`, a missing health check event is scheduled; an existing one is not duplicated.
60. G3: deactivation with `$network_wide = true` unschedules on every site ID from the seam; with `false`, only on the current site. If the test site isn't multisite, test through the seam; `markTestSkipped` is allowed only for the part that needs a real `switch_to_blog()`, with the reason.
61. A2 + §3.7: a health check run does not move an `unreachable` size back into `sizes`.

**R3**

62. A1: "Regenerate this size" for `invalid` overwrites only that `.webp`; the md5 of every other file in the folder (originals, thumbnails, other WebP copies) is unchanged.
63. A1: "Regenerate this size" where the `.webp` is its own Media Library attachment is refused with the engine's error mapped to a safe message, and the file is unchanged.
64. G4: `ctgen_webp_serving_changed` fires once with the right ID and state for toggle on, toggle off and each item of a bulk action; it doesn't fire when nothing changed.
65. G3: `uninstall.php` on multisite removes the new keys and events on every site from the seam and leaves the existing cleanup lines unchanged (snapshot).

**R4**

66. A2: a verification that gets `200` + `image/webp` for an `unreachable` size moves it back to `sizes` and clears the entry.
67. G2: an original URL found only in `<a href>` or `og:image` is reported "original by design" and does not reduce N.
68. G5: `HEAD` answered `405` triggers exactly one `GET` with the same timeout; a second failure marks `unreachable`; total requests stay within the cap.

### 10.4 Where each amendment is applied

| Amendment | Milestone |
|---|---|
| A2 (shape, rebuild rule), A3 | R1 |
| A2 (health check honours it), G2 (no filter + test), G3 (scheduling, deactivation), G5 (hooks, `.bak`) | R2 |
| A1, G1 (help text, readme), G3 (uninstall), G4 | R3 |
| A2 (set/clear by verification), G2 (verification wording), G5 (HEAD → GET) | R4 |

The R*n* report (§9) gains one line per amendment applied in that milestone: `- A<n>/G<n> — applied, proven by tests <numbers>`.
