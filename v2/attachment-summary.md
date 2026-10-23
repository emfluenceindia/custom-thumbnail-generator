---
name: attachment-summary
description: Plan and rules for the Custom Thumbnail Generator "Attachment Summary" page - an image inventory showing where each image is used, measured WebP savings, a reversible replace-with-WebP action that never breaks the site, and (later) consent-based deletion of the old JPG/PNG files. Use when working on the ctgen-attachment-summary page or any feature that changes attachments or their files.
---

# Attachment Summary: Image Inventory, WebP Replacement and Clean-up

You are Claude acting as a senior WordPress plugin engineer working in
`wp-content/plugins/custom-thumbnail-generator`. This file is the high-level plan
for the **Attachment Summary** page. It defines the goal, the non-negotiable safety
rules, the milestones and their gates. Each milestone gets its own detailed prompt
(like `v2/bulk-convert/m1.md` to `m4.md`) before work starts; do not build from this
file alone.

## 1. Goal

Give administrators one page that answers, for every image in the media library:

1. **Where is it used?** Its parent post ("Uploaded to") and every post, page or
   other place that actually references it.
2. **How much would WebP save?** Current size versus the real size of a WebP version.
3. **Can I switch it to WebP safely?** A per-image (and later bulk) action that
   converts the image and all its sizes to WebP and switches the site over to them,
   **without breaking anything**, and that can be undone.
4. **Can I reclaim the disk space?** Later, and only with explicit consent: delete the
   old JPG/PNG files once nothing depends on them.

## 2. The prime directive: nothing may break

"Nothing breaks" is not a hope; it is the design. These rules apply to every milestone
and override any convenience:

1. **Old URLs keep working until the user deletes the old files.** Replacing an image
   never removes or renames the original JPG/PNG or its sizes. Anything the scanner
   misses (hard-coded theme URLs, external sites, emails, social shares, CDN and
   page caches) keeps working because the old files are still there.
2. **Every change is reversible until deletion.** Before changing an attachment, record
   everything needed to restore it: original `_wp_attached_file`, full
   `_wp_attachment_metadata`, MIME type, and every database value rewritten (table,
   row, column/meta key, old value, new value). "Revert" restores all of it exactly.
3. **Verify, then switch.** Generate and verify every WebP file (decodable, correct
   MIME and dimensions) before touching the database. If any required file fails, change
   nothing and report why.
4. **Never write a reference you can't parse.** URL rewriting must handle plain HTML,
   block markup and attributes, JSON, and PHP-serialized data (a plain string replace
   corrupts serialized lengths). Data the code cannot parse safely is reported as
   "found but not rewritten", never edited blindly.
5. **Never claim more than was measured.** Sizes and savings shown in the UI come from
   real files, not estimates. "No usage found" means "not found by the scan", never
   "unused".
6. **Deletion is a separate, explicit, per-run consent**, preceded by a fresh scan and a
   clear warning that external links cannot be detected (see Milestone A5).
7. **Real user media is never used for destructive tests.** Tests use fixtures; back up
   the database and `wp-content/uploads` before any end-to-end run, and prove both are
   restored afterwards (same approach as the M1-M3 harnesses).

## 3. Key facts and corrections to keep in mind

- **"Attached" is not "used".** WordPress records only one parent post per attachment
  (`post_parent`, shown as "Uploaded to"). An image can be used in many posts and be
  "attached" to none, or attached to a post that no longer uses it. The page must show
  both, clearly labelled.
- **Usage detection can never be complete.** Detectable: post content (`<img>`, `srcset`,
  links, block attributes such as `"id":123`), featured images (`_thumbnail_id`),
  galleries, custom fields, options and widgets, theme mods, and common page-builder data.
  Not detectable: URLs hard-coded in theme/plugin files or CSS, other websites, emails,
  RSS readers, search engines, social networks. Rule 2.1 is what keeps those working.
- **Performance comes from serving WebP, not from replacing.** Pages get faster when
  browsers receive WebP. The existing feature already creates WebP copies of thumbnails
  (`photo-300x200.webp`). Replacing the attachment additionally makes WordPress output
  WebP URLs everywhere it generates markup, and enables deleting the originals later
  (disk space). Present replacement honestly as that, not as the only way to gain speed.
- **"100% quality" WebP is usually not smaller.** Re-encoding an already-compressed JPEG
  at the maximum lossy quality often produces a *larger* file, and it is still lossy (it
  does not preserve the original exactly). WordPress's own default WebP quality is 86
  (`WP_Image_Editor::get_default_quality()`, filterable via `wp_editor_set_quality`).
  The size columns must show the size at the quality that will actually be used, and
  replacement must be refused or warned about when the WebP is not smaller. PNG with
  transparency must keep its alpha channel. Lossless WebP for PNG must be verified in
  this environment before it is offered, not assumed.
- **WebP is not supported everywhere images travel.** All current browsers support it,
  but some email clients and older services do not. If images are used in newsletters
  or feeds, keeping the originals (rule 2.1) matters.
- **Name collisions are real.** `photo.jpg` and `photo.png` can both exist and would both
  become `photo.webp`; an uploaded `photo.webp` attachment may already exist. A
  deterministic, collision-free naming rule is required, and an existing file is never
  overwritten unless it is verified to be this attachment's own WebP copy.

## 4. Verified starting state (re-verify before relying on it)

- Menu: top-level **Custom Thumbnail Generator** with submenus **Settings**
  (`admin.php?page=ctgen-admin-settings`) and **Attachment Summary**
  (`admin.php?page=ctgen-attachment-summary`), registered in `includes/settings-page.php`.
  The summary page is a placeholder rendered by `ctgen_render_attachment_summary_page()`.
  Page hook suffixes are read with `ctgen_admin_page_hook( 'settings' | 'attachment_summary' )`
  (never hard-code them; they depend on the translated menu title).
- Converter: `CTGEN_Webp_Converter::convert( $source_path, $destination_path )` in
  `webp/class-ctgen-webp-converter.php`. JPEG and PNG only (`ALLOWED_SOURCE_MIME_TYPES`);
  GIF is rejected with `ctgen_webp_gif_excluded` (user decision). Writes via a verified temp
  file and atomic rename; never modifies the source; returns path/file/width/height/mime or
  a `WP_Error` with a specific code. It does not set quality explicitly (editor default).
- Existing WebP copies: `<file name>.webp` next to each file, created by the bulk/per-size
  conversion (`webp/class-ctgen-webp-ajax-handlers.php`). The bulk run ("all_sizes") covers
  every thumbnail, the full-size file (`full`, the "-scaled" copy for large uploads) and the
  untouched original upload (`original_image`); per-size buttons cover one thumbnail size.
  An attachment whose base name is shared with another image file in the same folder
  (e.g. photo.jpg + photo.png) is refused (`ctgen_webp_name_conflict`), and a WebP name that
  is already a Media Library file is never overwritten (`ctgen_webp_destination_in_use`).
- AJAX security pattern: `check_ajax_referer( 'ctgen_media_actions', 'security' )` then
  `current_user_can( 'manage_options' )`; inputs are attachment IDs and validated slugs,
  never paths; responses never contain server paths.
- Front end: readable JS source + minified build via `v2/tools/build-js.js`
  (`--check` detects drift); assets are loaded only on their own admin page.
- Tooling: Lando (`lando wp ...`), PHPCS ruleset `.phpcs.xml.dist` at the project root
  (tools in the container's `/tmp/ctgen-tools`, lost on rebuild), Plugin Check run against
  the release build made by `v2/deploy/svn-release.sh`, Playwright browser tests in
  `v2/tests/` using `v2/tools`.
- House rules: keep a `.bak` copy next to any existing file before its first edit;
  everything development-only lives in `v2/` (never shipped); `WP_DEBUG` is on.
- The bulk-conversion Milestone 4 (hardening, including a per-request timeout in the
  conversion JS so a hung request cannot stall a run) is still open. Finish it first, or
  make the same timeout fix part of A1.

## 5. The page

A list table that looks and behaves like the Media Library list view (`WP_List_Table`:
pagination, per-page screen option, sorting, search, filters, bulk-action dropdown).

| Column | Content |
|---|---|
| Image | Thumbnail preview, title, file name, links to edit / view |
| Type | JPEG / PNG / GIF / WebP / other, dimensions |
| Uploaded to | `post_parent` title with link, or "Unattached" |
| Used in | Detected references, each with title, link and source (content, featured image, gallery, custom field, option/widget, builder). Count + expandable list. "None found" wording per rule 2.5 |
| Current size | Original file + all generated sizes, total and per-file on expand |
| WebP size | Measured size of the WebP versions (same files), at the quality that will be used; "Measure" if not yet measured |
| Saving | Bytes and percentage saved; flagged when WebP is larger |
| Status | Original / WebP copies exist / Replaced (revertible) / Originals deleted |
| Actions | Measure, Replace with WebP, Revert, (later) Delete originals |

Filters: type, attached/unattached, used/no usage found, replaced/not replaced,
"WebP would be larger". Rows update in place after every action (no full reload);
the summary counts at the top of the page update with them.

## 6. Milestones

Each milestone ends with a strict completion gate like M1-M3: every checklist item
reported pass/fail with evidence, `php -l`, PHPCS and Plugin Check clean, JS checks
clean, browser items exercised on the real admin page, and the database and uploads
proven restored after tests.

### A1 - Read-only inventory and usage scan
- List table with the columns above except WebP size / Saving / write actions.
- Usage scanner: batched, resumable (AJAX batches like the conversion feature, never one
  unbounded request), results cached per attachment with a timestamp and a "Rescan"
  action. Covers every source listed in section 3 that can be parsed safely; reports
  which sources were scanned.
- No file or database writes except the scanner's own cache.
- Gate highlights: fixture posts reference fixture images in each supported way
  (content `<img>`, srcset, block attribute, featured image, gallery, custom field,
  serialized option); every reference is found and labelled; an attached-but-unused and a
  used-but-unattached image are shown correctly; pagination/sorting/search work on a
  library larger than one page; performance measured on a large fixture set.

### A2 - Measured WebP size and savings
- "Measure" per row and in batches: generate the WebP versions of the original and every
  size into a private temporary location (or reuse verified existing copies), record exact
  byte sizes, then discard temporaries. Cache results; invalidate when the source file
  changes (size + mtime or hash).
- Show size, bytes saved, percentage, and flag "WebP larger". Decide and document the
  quality setting (section 3) and whether PNG uses lossless WebP, after verifying what
  the active editor actually does.
- Still no change to attachments or references.
- Gate highlights: numbers equal the bytes of real generated files; JPEG, PNG with
  alpha, already-small images (WebP larger case), missing size files; nothing left in
  uploads after measuring.

### A3 - Replace with WebP (reversible)
Per image, in this order, stopping with no changes on any failure:
1. Preconditions: JPEG/PNG, original and size files readable, measured saving acceptable
   (or user confirmed), collision-free target names, enough disk space.
2. Generate and verify WebP files for the original (including `-scaled` / `original_image`
   handling) and every size.
3. Fresh usage scan; build the rewrite plan (every old URL, including each size URL and
   srcset entries, mapped to its WebP URL). Show it if the user asks.
4. Write the undo record (rule 2.2).
5. Switch the attachment: `_wp_attached_file`, `_wp_attachment_metadata` (file and every
   size's file, mime-type, filesize), `post_mime_type`, GUID left untouched.
6. Apply the rewrite plan with serialization-safe updates; report anything found but not
   rewritable.
7. Purge caches the plugin can reach (object cache entries for changed posts); tell the user
   to clear page/CDN caches.
8. Old files stay on disk (rule 2.1).
- "Revert" restores the undo record exactly and leaves the WebP files in place (or removes
  only the ones this run created).
- Gate highlights: after replacing, every fixture page renders the WebP URLs and still
  returns HTTP 200 for every image request, old URLs still return 200, the editor still
  opens the posts without block-validation errors, page-builder fixture data is intact,
  serialized options still unserialize; Revert returns the database (row-by-row) and
  attachment to the exact previous state; a forced failure at each step leaves no partial
  change.

### A4 - Bulk replace and summary refresh
- Bulk action on selected rows or the whole filtered list, processed one image per request
  with live progress, the same safety rules per image, and a final report (replaced,
  skipped with reason, failed with reason, all revertible).
- Gate highlights: the same checks as A3 at scale, plus interruption in the middle (closed
  tab, network loss) leaves every image either fully replaced or untouched.

### A5 - Delete old JPG/PNG files (later, consent only)
- Available only for replaced images. Requires an explicit confirmation listing the files
  and the space to be freed, with a clear statement that external links and hard-coded
  URLs cannot be detected.
- Before deleting: fresh scan; refuse if any reference to the old files is still found;
  refuse if another attachment shares the file.
- Prefer a quarantine first (move to a protected folder for a retention period with
  one-click restore) over immediate deletion; permanent deletion is a second, separate
  step.
- Offer redirects from old URLs to the WebP files (documented server rule, or a WordPress
  fallback for requests that reach PHP) so external links keep working.
- After deletion, Revert is no longer possible for that image; the UI says so.

## 7. Open decisions (decide before the milestone that needs them)

| Decision | Needed by |
|---|---|
| Which usage sources to support (which page builders, custom-field plugins) | A1 |
| Scan cache storage (post meta vs. custom table) and when it expires | A1 |
| WebP quality for replacement; lossless for PNG or not | A2 |
| Minimum saving required to allow Replace (e.g. refuse when larger) | A3 |
| Naming rule for collisions (`photo.jpg` + `photo.png`, existing `photo.webp`) | A3 |
| Whether to also offer a no-replace "serve WebP" mode (filters such as `wp_content_img_tag`) as the default, safest option | A3 |
| Undo log storage and retention | A3 |
| Quarantine location, retention period and redirect method | A5 |
| Multisite behaviour; sites whose media is offloaded to S3/CDN (files not local) | A1 |

## 8. Out of scope until explicitly requested

- Converting GIF (excluded by decision).
- Changing images on other sites of a multisite network.
- Editing theme or plugin files to fix hard-coded URLs.
- Any automatic deletion, scheduled deletion, or deletion without the consent flow in A5.
