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
