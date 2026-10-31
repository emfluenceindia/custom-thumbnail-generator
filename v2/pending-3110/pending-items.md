# Pending items: "Serve WebP" feature (status on 31 Oct 2026, after automatic WebP)

Plugin: Custom Thumbnail Generator. Feature: show visitors WebP copies instead of JPEG/PNG.
Source plan: `v2/replacement/replacement-skill.md` (milestones R1–R4, §10).
Reports: `v2/replacement/r1-report.md`, `r2-report.md`, `toggle-report.md`, `bulk-serve-report.md`, `auto-webp-report.md`.

## 1. Where things stand

| Part | Status | Notes |
|---|---|---|
| R1: manifest + URL mapper | Done | Tested, PHPCS clean |
| R2: front-end replacement, health check, lifecycle | Done | Tested |
| Per-image "Serve WebP" switch (start of R3) | Done | Confirmed working in the browser |
| "Serve WebP" column + switch for all images (R3) | Done, **awaiting your browser test** | See section 3 |
| Automatic WebP for new uploads and regenerated thumbnails | Done, **awaiting your browser test** | 197 tests / 646 assertions pass; tested end to end with a real import and regeneration; see section 3a |
| Rest of R3 | **Pending** | Section 5 |
| R4: verification | **Pending** | Section 6 |

**Is the plugin safe to use today?** Yes, with the limits listed below.
- Serving is off for every image until an admin turns it on, one image at a time or for all images with the header switch.
- Automatic WebP is off until an admin turns it on in Settings.
- While an image is serving, every size without a valid, smaller WebP keeps its original URL.
- A WebP removed outside the plugin is noticed by the daily health check, or straight away when the image's files change.

## 2. Summary of pending items and their impact

Severity: **High** = can mislead users or leave data behind on a live site. **Medium** = a real gap in function or workflow. **Low** = convenience or housekeeping.

| # | Item | Type | Severity | Impact if left undone |
|---|---|---|---|---|
| B1 | Browser test of the "Serve WebP" column | Manual test | **High** | The new switches' JavaScript has no automated tests. Your browser activity at 11:54–12:02 suggests you started; please confirm the result |
| B2 | Browser test of the automatic WebP setting | Manual test | Medium | Saving through the Settings form and the card's look are not yet confirmed in a browser |
| D1 | WebP served without a Content-Type | Decision | Medium | Decides how R4 behaves on servers like this one |
| D2 | Fix MIME type on this dev server | Decision | Low | Dev only. Browsers show the images anyway |
| ~~D3~~ | ~~"Serve WebP for all ready images"~~ | Decision | Settled | Built as the header switch (section 3) |
| M1 | Block editor load/save check | Manual test | Medium | Not yet proven that saving a post never stores `.webp` URLs. More pressing now that many images can be switched on at once |
| P1 | Uninstall cleanup | R3 build | **High** | Deleting the plugin leaves serving data, the "serve all" and automatic WebP settings, and any pending conversions behind |
| P2 | readme is out of date | R3 build | **High** | The readme says the plugin never changes the displayed image, and doesn't mention the switches or automatic WebP |
| P3 | Bulk actions for *selected* rows | R3 build | Low (was Medium) | Optional now: the header switch covers the whole library, and single images have their own switch |
| P4 | "Regenerate this size" | R3 build | Medium | A broken or missing WebP size stays on the original until "Regenerate WebP" or a full re-conversion |
| P5 | Remaining R3 tests (40, 62, 63, 65; 43–46 only if P3 is built) | R3 build | Medium | P1, P2 and P4 would ship untested |
| P6 | R4: "Serving on N of M places" + reachability check | R4 build | Medium | No way to see where originals still appear, and a blocked WebP URL isn't caught |
| H1 | `.bak` files and no git | Housekeeping | Low | Clutter only. They never ship |
| H2 | Coverage can't be measured | Environment | Low | No xdebug/pcov in the container |
| H3 | Only tested on PHP 7.4 | Environment | Low | The plugin claims PHP 7.0+ |

## 3. Just built: the "Serve WebP" column

### What it does

- **New last column, "Serve WebP",** on **Custom Thumbnail Generator > Attachment Summary**.
- **Header switch on:**
  - It asks for confirmation first.
  - It then serves WebP for every JPEG/PNG image that has a valid WebP smaller than the original, working in batches of 20 with progress shown above the table.
  - The "larger" rule applies size by size: a size whose WebP is bigger keeps its original.
  - An image with no smaller, valid WebP at any size stays on the original.
  - Images that are already WebP, GIFs and other types are never included.
  - The setting is remembered (option `ctgen_webp_serve_all`). While it is on, images converted later are served as WebP automatically.
- **Header switch off:** asks for confirmation, then serves the originals for every image, including ones switched on one at a time.
- **Per-image switch:** moved from "WebP status" into the new column. It still works on its own, as an exception to the header switch.
- **"Regenerate WebP"** also refreshes the image's switch, so a greyed-out switch becomes usable as soon as a WebP exists.
- **New filter:** `ctgen_webp_serve_all_batch` (batch size, default 20).

### Usage

1. Open **Attachment Summary**.
2. Click the switch in the **Serve WebP** column header and confirm.
3. Keep the page open until the message says "Done".
4. Purge any page cache or CDN.
5. To make exceptions, use the switch in an image's row.

### Known limits

- On phone-width screens WordPress hides the column headers, so the header switch isn't visible. Each row's switch still is.
- If the page is closed mid-run, some images aren't switched yet. Switching again finishes the job.
- If an image is switched off by hand while "serve all" is on, regenerating its WebP turns it back on, because it counts as a new WebP copy.

### B1: browser test (owed by you)

1. Turn the header switch on, confirm, and wait for "Done". Check the row switches and the summary message.
2. View a page that uses one of the images and confirm the image URL ends in `.webp`.
3. Switch one row off and confirm that page shows the original again.
4. Turn the header switch off and confirm every row switch is off and pages show the originals.
5. Click **Regenerate WebP** on an image whose switch is greyed out and confirm the switch becomes usable.

- **Impact if it fails:** the server side is tested, so a failure would be in the page script or styling. It would be fixed before anything else.
- **Rollback:** every edited file has a `.bak10` copy of the version you approved earlier.

## 3a. Just built: automatic WebP for new images

### What it does
- **Setting:** "Create WebP automatically for new uploads and regenerated thumbnails". It is the first card on **Custom Thumbnail Generator > Settings**, with a green accent when on and amber when off. **Off by default.**
- **When on:** every new JPEG/PNG gets WebP copies of its main file and every size (WordPress + CTG), shortly after it is added. Every route counts: Media Library, post editor, featured image, ACF, REST, WP-CLI.
- **Regenerated thumbnails** get fresh WebP copies. Copies that are still current are left alone.
- **Runs in the background** (WP-Cron), so uploads are not slowed down.
- **The card explains** the covered routes, what it doesn't do, and six caveats. It warns when WP-Cron is turned off and shows how many images are waiting.
- **With "Serve WebP for all images"** on, new images are served as WebP as soon as their copies exist. The Attachment Summary page shows a hint linking to the setting when serve-all is on but automatic WebP is off.

### B2: browser test (owed by you)
1. Open **Settings**, read the card, turn the switch on and click **Save setting**. Expect "Settings saved." and a green card with an "On" badge.
2. Upload a JPEG in the Media Library, wait a minute and browse any page (this lets WP-Cron run).
3. On **Attachment Summary**, check that the new image shows WebP copies for every size.
4. Turn the setting off and save. Expect the amber card with an "Off" badge.

## 4. Decisions still needed

### D1: servers that send WebP without a Content-Type

- **What:** this Lando server returns `.webp` files with no `Content-Type` header, because `/etc/mime.types` has no entry for `image/webp`. Browsers still display the images. Some CDNs and strict proxies may not.
- **Where it matters:** R4's reachability check (P6).
- **Options:**
  - **(a) Recommended:** warn the admin, show the one-line server fix, and keep serving.
  - **(b)** Treat the image as unreachable and stop serving it until the server is fixed.
- **Impact:** (a) keeps WebP working on most hosts, with a visible warning. (b) is safer on unusual set-ups, but would switch serving off on this dev server and on any host with the same gap. With the header switch, (b) could switch off many images at once.

### D2: fix this dev server

- **What:** add `AddType image/webp .webp` to the site's `.htaccess`, outside the `# BEGIN WordPress` block.
- **Impact:** a site change, not plugin code, and it doesn't ship. It makes the dev server behave like a correctly configured host, so R4 can be tested properly.

### P3: build bulk actions for selected rows?

- **New question:** now that the header switch exists, bulk actions for selected rows are optional.
- **Recommendation:** skip it unless you want to switch a hand-picked group in one go.

## 5. Pending R3 work, step by step (recommended order)

### Step 1 (P1): uninstall cleanup. Do this first.

- **Today:** `uninstall.php` removes only the usage-scan data (`_ctgen_usage`, `_ctgen_usage_count`, the per-page user setting), and only on the current site.
- **Missing:**
  - post meta `_ctgen_webp_serve` (one row per serving image)
  - option `ctgen_webp_serve_all` (**new**, the header switch's setting)
  - option `ctgen_webp_auto_convert` (**new**, automatic WebP) and any pending `ctgen_webp_auto_convert` events
  - option `ctgen_webp_health_cursor`
  - any leftover `ctgen_webp_health_check` cron event
  - every site of a multisite network, not just the current one
- **Impact if not done:**
  - Deleting the plugin leaves rows behind. They're harmless on their own, but WordPress.org reviewers expect a clean uninstall.
  - If the plugin is installed again, images start serving WebP with no admin action. With "serve all" left on, newly converted images would also start serving straight away.
- **Kept on purpose:** WebP files, image files and custom sizes. This matches the current documented behaviour.
- **Usage:** none. It runs automatically when an admin clicks **Delete** on the Plugins screen.
- **Tests:** 62, 63. They must snapshot and restore real site data, because the suite runs on the real database.

### Step 2 (P2): readme and FAQ

- **Today:** `readme.txt` line 44 says *"The plugin creates the WebP files but does not change which image WordPress displays."* That has been false since R2.
- **To add:**
  - How to turn serving on: the "Serve WebP" column, the switch for all images, and per-image exceptions
  - That images converted later are served automatically while the switch for all images is on
  - The automatic WebP setting: what it covers, that it is off by default, and its caveats (disk space, server load, WP-Cron timing)
  - That sizes whose WebP is larger keep the original, and that WebP/GIF images aren't affected
  - Browser support (all current browsers; WebP-only, no `<picture>` fallback)
  - Caches and CDNs: purge after switching. The `ctgen_webp_serving_changed` action exists for cache plugins
  - Hooks:
    - `ctgen_webp_should_replace`
    - `ctgen_webp_serving_changed`
    - `ctgen_webp_files_changed`
    - `ctgen_webp_health_check_batch`
    - `ctgen_webp_health_check_interval`
    - `ctgen_webp_serve_all_batch` (**new**)
    - cron event `ctgen_webp_auto_convert` (**new**, for site owners checking their cron queue)
  - The remaining 404 risk: a WebP deleted by hand is served until the next health check or file change. The instant fix is to switch the image off and on again
  - What uninstall removes and what it keeps
- **Impact if not done:** users and reviewers get wrong information, and nobody knows to purge their caches.
- **Usage:** documentation only.

### Step 3 (P4): "Regenerate this size"

- **What:** a button beside each skipped size, shown when the reason is `missing`, `invalid` or `dimension_mismatch`. It re-converts that one size (the conversion engine's `force`), then rebuilds the image's serving data.
- **Not shown for:**
  - `larger_than_source`: re-converting gives the same result
  - `not_owned`: the plugin must never overwrite another item's file
  - `source_missing`: there is nothing to convert from
- **Impact if not done:** a size that went missing or broken keeps showing the JPEG/PNG. Nothing breaks. "Regenerate WebP" for the whole image also fixes it, but redoes every size.
- **Usage:**
  1. In the image's **Serve WebP** cell, expand the list of sizes that show the original.
  2. Click **Regenerate** beside the size.
- **Tests:** 40, 65.

### Step 4 (P3, optional): bulk actions for selected rows

- **What:** "Serve WebP" / "Stop serving WebP" in the table's Bulk actions menu, for the ticked rows only, in batches, with the same rules as the switches.
- **Impact if not done:** small. Use the header switch for everything, and row switches for exceptions.
- **Usage:**
  1. Tick the images.
  2. Choose the action from Bulk actions.
  3. Click **Apply**.
- **Tests:** 43–46.

### Step 5: quality gates and report

Run all of these, then write `v2/replacement/r3-report.md`. After that, test it in the browser.
- full PHPUnit suite
- PHPCS (0 errors / 0 warnings)
- Plugin Check (no new issues against the baseline)
- `node v2/tools/build-js.js --check`
- uploads md5 map unchanged (proves no image file was touched)

## 6. R4: verification (after R3)

### Step 6 (P6a): "Serving WebP on N of M places"

- **What:** uses the existing usage scanner to look at each place an image appears and report whether visitors actually get WebP there. Where they don't, it gives the reason, for example:
  - a URL hard-coded by a page builder
  - a link to the media file
  - CSS backgrounds
  - a cached page
- **Impact if not done:** admins see "Serving WebP" but can't tell which pages still show originals.
- **Usage:** read the new line in the **Serve WebP** cell, and expand it for the list of places.

### Step 7 (P6b): reachability check

- **What:** asks the server for each served WebP URL (`HEAD`, retrying with `GET` on a 405/501 reply). Applies D1's rule to the Content-Type. Marks unreachable sizes so they fall back to the original.
- **Impact if not done:** if a server rule, security plugin or CDN blocks `.webp`, visitors get broken images while the admin sees "Serving". This is the main remaining 404 risk. The header switch makes it wider, because one click can switch on the whole library.
- **Usage:** automatic when serving is switched on (per image or for all images) and during the health check. The result appears in the Serve WebP cell.

## 7. Manual test still owed (M1)

The tests checked the stored content directly. The editor round trip needs a logged-in browser, and creating login credentials for automation was not allowed.

1. Switch serving on for an image used in a page.
2. Open that page in the block editor, change nothing, and click **Update**.
3. In the database (or **Code editor** view), confirm the content still has the `.jpg`/`.png` URL, not `.webp`.

- **Expected:** no `.webp` in the stored content. The plugin only rewrites output for visitors.
- **Impact if it fails:** turning serving off would no longer restore the originals in that post, which matters more now that "off for all images" is one click. That would be a High-severity bug, fixed before continuing.

## 8. Housekeeping

- **H1, `.bak` files:**
  - 81 backup files in the plugin folder (including `v2/`) and 8 in the test folder.
  - This includes the `.bak10` set (the version before the bulk switch) and the `.bak11` set (the version before automatic WebP).
  - `.distignore` excludes them from releases, so they never ship. Without git they are the only way to roll back.
  - Options: keep them, archive them to a `.tar.gz` and then delete, or put the plugin under git (best).
  - Deleting them can't be undone, so this waits for your choice.
- **H2, coverage:** no xdebug or pcov in the container, so line coverage is reported as BLOCKED. Every behaviour has a named test, and mutation checks were run instead (7 for the bulk switch and 8 for automatic WebP; the one that survived exposed redundant code, which was removed).
- **H3, PHP versions:** tests run on PHP 7.4.33. The code avoids syntax newer than 7.0, but it hasn't been run on 7.0–7.3 or on PHP 8.x. Worth a run on PHP 8.2+ before release.

## 9. Recommended order at a glance

1. B1 and B2: test the "Serve WebP" column and the automatic WebP setting in the browser.
2. M1: the 2-minute editor check.
3. Answer D1, D2 and the P3 question (a simple "go with the recommendations" is enough).
4. Step 1: uninstall cleanup (including `ctgen_webp_serve_all`).
5. Step 2: readme and FAQ.
6. Step 3: "Regenerate this size".
7. Step 4: bulk actions for selected rows, only if you want them.
8. Step 5: quality gates, report, browser test.
9. Steps 6–7: R4 verification.
10. Before release: PHP 8.x run, decide on the `.bak` files / git.
