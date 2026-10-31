# WebP Replacement: Feature Discussion

Date: 2026-10-29
Context: Custom Thumbnail Generator, Attachment Summary screen (per-row WebP status column with "Regenerate WebP").

---

## Question

1. The plan was to add a per-row option that lets the user convert PNG/JPG/GIF to WebP. The conversion engine already works.

2. Next I want a feature that lets the user decide whether or not to replace existing JPG/PNG/GIF with the converted WebP file. Why?
   - a. Storage space saving
   - b. Faster loading (performance boost)

3. For now, replacement won't remove any file from the server, so I am only concerned about 2b.

What do you think of feature #2? Can it be useful? Can it attract more users to the plugin? Can it help the plugin stand out?

---

## Answer

### Short answer

Yes, build it. Without it the conversion does nothing for the user. Generating WebP files only gives a speed boost once visitors are actually served those files. Serving them is what turns the feature into a real performance gain.

By itself, though, it won't make the plugin stand out. "Serve WebP instead of JPG/PNG" is a basic feature that many plugins already have: EWWW, ShortPixel, Imagify, Smush, Converter for Media, WebP Express, and the WP Performance team's Modern Image Formats. Users expect it. They won't install a plugin just for it.

What can make it stand out is something the Attachment Summary screen shows that the others don't have: **the plugin knows where each image is used** ("3 places", "Scanned 1 second ago"). Most WebP plugins have one global on/off switch and don't show which pages are affected. This plugin can offer a per-image, usage-aware, checkable version.

### How to implement "replace" (without touching files)

Rewrite URLs when the page is rendered, and don't change the database:

- `wp_get_attachment_image_src`, `wp_calculate_image_srcset`: cover theme and core image output
- `wp_content_img_tag` (WP 6.0+) and `render_block`: cover post content and blocks
- Store a per-attachment meta flag (e.g. `_serve_webp`), so turning it off is instant and nothing is lost

About 97% of browsers support WebP now, so a direct URL swap is acceptable. Offer `<picture>` with a JPG/PNG fallback as an optional "safe mode". Don't make it the default, because it can break CSS that targets `img` directly.

Avoid `.htaccess` rewriting based on the `Accept` header as the main method. It doesn't work on Nginx, and CDNs or page caches often get it wrong without `Vary: Accept`.

### Ideas that could make it stand out

1. **Per-row "Serve WebP" toggle** next to "Regenerate WebP", plus a bulk action. The row UI already exists.
2. **Usage-aware preview:** "Enabling this affects: Home, About, Blog post X." Only this plugin can show this, because it tracks usage.
3. **Skip sizes where WebP is bigger.** Some PNGs, especially small or flat-colour ones, come out larger as WebP. Serve WebP per size only when it's actually smaller, and say so in the UI.
4. **Check that it works.** After enabling, fetch the pages that use the image and confirm they now serve the `.webp` URL. Show a "Serving WebP on 3/3 places" badge. Hardcoded URLs, page builders (Elementor, Divi) and CSS `background-image` are where other plugins silently fail. Showing "2/3 — 1 hardcoded in Elementor" would build a lot of trust.
5. **Show the result as page weight:** "Homepage images: 1.2 MB → 410 KB". People understand that better than compression ratios. Once an image is being served as WebP, "Could save 19.6 KB" can change to "Saving 19.6 KB per load".
6. **Before/after compare slider** for checking quality before enabling. This matters most for PNGs with transparency or text.
7. **Purge caches on toggle:** WP Rocket, LiteSpeed, W3TC and similar. Otherwise users think it's broken.

### Things to watch

- **Animated GIFs:** either produce animated WebP or skip them and show the reason. Don't serve a still frame.
  - *Note added when saving this file:* GIF is already excluded from conversion entirely (decision of 2026-10-22, error code `ctgen_webp_gif_excluded`), so replacement only applies to JPEG and PNG.
- **Plan the data now for 2a (deleting originals):** if each attachment has a clear state (e.g. `original` → `webp-served` → `webp-only`), the storage-saving step later builds on this one instead of needing a rewrite.

**Recommendation:** ship the per-image toggle with URL rewriting first, then add the "check that it works" step. That check is the hardest part for other plugins to copy, because it relies on the usage scanner.

---

## Planning to make over not-ready and Gaps

Date: 2026-10-30
Context: a readiness review of `replacement-skill.md` found 3 design flaws that would stop or mislead the build ("not ready") and 5 open decisions ("Gaps"). This section records how each one is closed and why. The executable version of the same plan is appended to `replacement-skill.md` under the same heading, as binding amendments that take precedence over the earlier sections of the skill.

### Not ready

**1. Rule "no file is ever overwritten" vs "Regenerate this size"**

- *Problem:* regenerating a size skipped as `invalid` or `dimension_mismatch` overwrites that `.webp` through the conversion engine, which the rule forbids. An agent would either refuse to build the button or break the rule.
- *Plan:* narrow the rule to the serving code (manifest, mapper, replacer, health check, verification). These never write, move or delete a file. The only file write the feature can trigger is "Regenerate this size", and only through the existing engine (`CTGEN_Webp_Ajax_Handlers::convert_part()`), which already refuses to overwrite a Media Library `.webp` and refuses name clashes. Originals and thumbnails stay untouchable without exception.
- *Proof:* tests that regeneration rewrites only the one `.webp`, that originals and thumbnails keep the same md5, and that a `.webp` owned by another attachment is refused.

**2. `unreachable` flip-flop**

- *Problem:* verification marks a size `unreachable` (its URL 404s, e.g. offloaded to S3/CDN). The next health check rebuilds the manifest from disk, sees a valid file and puts the size back, so the broken URL is served again.
- *Plan:* store unreachable sizes in their own `unreachable` key in the manifest, with the WebP's basename, size in bytes and modification time at the moment it failed. Any rebuild keeps the mark while those three still match the file on disk. The mark is cleared only when a later verification gets `200` + `image/webp`, when the file changes, or when serving is turned off and on again.
- *Proof:* tests that a health check run doesn't revive an unreachable size, that a changed file clears the mark, and that a successful verification clears it.

**3. Health check can switch serving back on after the admin switched it off**

- *Problem:* a rebuild that started before the admin turned serving off can write the manifest afterwards and switch serving back on.
- *Plan:* only the "turn on" action may create the manifest meta. Every other rebuild (health check, lifecycle hooks, verification, regenerate) reads the meta again from the database, bypassing the object cache, just before writing, and writes nothing if it's gone. A tiny window remains between that re-read and the write; it is documented as acceptable because the next toggle or health check corrects it and no file is ever at risk.
- *Proof:* a test that deletes the meta while a rebuild is in progress (through a seam) and asserts the meta is still absent afterwards; a test that rebuilding an attachment that isn't serving writes nothing.

### Gaps

**4. Browsers without WebP**

- *Decision:* swap the URL directly for everyone (about 97% browser support). No `<picture>`, no user-agent sniffing, no `Accept`-header negotiation, consistent with §2 "out of scope".
- *Plan:* write it into the skill as a recorded decision so no agent invents a fallback. Add a readme FAQ entry and a short help text by the toggle explaining the trade-off, with "turn serving off" as the remedy.

**5. `wp_get_attachment_url` (lightbox "link to media file", `og:image`, many themes)**

- *Decision:* don't filter it. It is also used by admin screens, exports, REST, the editor and many plugins for file operations, so filtering it risks writing WebP URLs into stored content and breaking integrations.
- *Plan:* a regression test asserts it returns the original while serving is on. Verification (R4) reports original URLs found in `<a href>` and `<meta property="og:image">` as "original by design" instead of counting them as failures.

**6. Multisite**

- *Finding:* `readme.txt` already promises "activate network-wide to manage thumbnails across subsites", so multisite has to work.
- *Plan:*
  - Post meta, options and cron events are per site already.
  - Schedule the health check per site on `admin_init` when it's missing. A network activation runs the activation hook only once, so this check is what covers every subsite.
  - Network deactivation unschedules the event on every site.
  - `uninstall.php` loops over all sites for the new keys and the cron event.
  - The existing uninstall cleanup (`_ctgen_usage` etc.) has the same single-site gap. It is reported as a follow-up, not silently changed.

**7. Page caches and CDNs**

- *Plan:* no purge integrations (still out of scope). Instead:
  - Every success message for toggle, bulk and regenerate tells the admin to purge the page cache or CDN if they use one.
  - A new action `ctgen_webp_serving_changed( $attachment_id, $state )` fires after every change, so site owners and cache plugins can purge from their side.
  - A readme FAQ entry covers it.

**8. Small accuracy fixes**

- The plugin has no activation or deactivation hooks today. They must be added to `custom-thumbnail-generator.php`, which is a pre-existing file and needs a `.bak` first.
- Some servers answer `HEAD` with `405`/`501`. The reachability check retries once with `GET` using the same limits.
- The `DISABLED` comment in §3.1 is stated to have no effect.

### Where each fix lands

| Fix | Milestone |
|---|---|
| 2 (manifest shape for `unreachable`), 3 (rebuild rule) | R1 |
| 3 (health check), 5 (regression test), 6 (cron per site, deactivation), 8 (hooks) | R2 |
| 1, 4 (help text, readme), 6 (uninstall), 7 | R3 |
| 2 (setting and clearing the mark), 5 (verification wording), 8 (HEAD → GET retry) | R4 |
