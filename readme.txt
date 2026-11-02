=== Custom Thumbnail Generator – Image Sizes, WebP & Media Usage ===
Contributors: subrataemfluence
Tags: thumbnails, webp, image sizes, regenerate thumbnails, unused images
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.0
Stable tag: 1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Create image sizes that survive theme changes, convert and serve WebP, and see where every image in your Media Library is used.

== Description ==

Custom Thumbnail Generator puts your images under your control: the sizes WordPress creates, the format visitors download, and where each image appears on your site.

Everything runs on your own server. There are no API keys, no monthly quotas, no external services and no upsells. Your original images are never changed, and every WebP setting can be switched off again at any time.

= Image sizes that outlive your theme =

* Add custom thumbnail sizes (width, height, hard crop) from a simple screen, without code.
* Sizes are stored by the plugin, not the theme, so they keep working when you switch themes.
* Regenerate a single custom size, or every thumbnail, for all images, with a live progress bar.
* Custom sizes appear in the block editor's image size menu.

= WebP: create, serve and automate =

* **Convert** existing JPEG and PNG images and every thumbnail size to WebP, for the whole library, one size or one image.
* **Serve WebP to visitors** with one switch for all images, or image by image. URLs are swapped only in the pages visitors see. Nothing is written to your posts or database, so switching off restores the originals instantly.
* **Only when it is smaller:** a size whose WebP is larger than the original keeps the original.
* **Automatic WebP for new uploads:** every new JPEG or PNG gets WebP copies in the background, whether it comes from the Media Library, the editor, the REST API, WP-CLI or a plugin such as ACF.
* **Self-healing:** a daily check notices WebP files that went missing and switches those sizes back to the original.

= Know where every image is used =

The **Attachment Summary** page lists every image with the post it was uploaded to and **every place it is actually used**: post and page content, custom fields, page builders such as Elementor, featured images, galleries, WooCommerce product galleries, widgets, theme settings, the site logo and the site icon.

* Filter to images with no usage found, images that were never attached, or images not scanned yet.
* See the size of each image's files, its WebP copies, the compression ratio and the disk space WebP saves.
* Preview any image in a pop-up, search by title or file name, and sort by title, type or number of places used.

= Safe by design =

* Original images, thumbnails and attachment data are never modified.
* WebP copies are deleted together with their image, so a deleted image does not stay online as WebP.
* GIFs are never converted, so animated GIFs keep their animation.
* Large libraries are processed in small batches from the browser, so no request runs long enough to time out.
* Deleting the plugin removes all of its settings and stored data.

== Installation ==

1. Install the plugin from **Plugins > Add New**, or upload the `custom-thumbnail-generator` folder to `/wp-content/plugins/`.
2. Activate it from the **Plugins** screen.
3. Go to **Custom Thumbnail Generator > Settings** to add image sizes, convert images to WebP and turn on automatic WebP.
4. Go to **Custom Thumbnail Generator > Attachment Summary** to see where images are used and to serve WebP to visitors.

== Frequently Asked Questions ==

= How do I keep custom image sizes when I change my theme? =

Add the sizes in **Custom Thumbnail Generator > Settings**. They are registered by the plugin instead of the theme, so they stay available after a theme switch. Sizes registered by a theme still disappear with that theme.

= Can I regenerate a single thumbnail size? =

Yes. Each custom size in the table has its own **Regenerate** button, so you don't have to regenerate every size for every image. Every size, including WordPress and theme sizes, also has a **WebP** button that converts just that size.

= Will removing a size delete existing thumbnails? =

No. Removing a size only stops WordPress from creating it for new uploads. Existing files stay where they are.

= Can I remove sizes registered by my theme or WordPress? =

No. Only sizes created with this plugin can be removed.

= Is there a limit on the number of image sizes? =

No. Keep in mind that every size adds a file for every uploaded image.

= How do I serve WebP images instead of JPEG and PNG? =

1. Create WebP copies: use **Convert All Thumbnails to WebP** on the Settings page, or **Regenerate WebP** on an image's row of the Attachment Summary page.
2. On the Attachment Summary page, turn on the switch in the **Serve WebP** column header to serve every image that has a WebP copy, or use the switch on a single image's row.
3. Purge your page cache or CDN.

While the switch for all images is on, images converted later are served as WebP automatically. The switch on an image's row lets you make exceptions.

= Does serving WebP change my posts or my database? =

No. Image URLs are replaced only in the pages sent to visitors. Post content, attachment data and the editor keep the original JPEG and PNG URLs, so turning serving off restores the originals immediately. Feeds, embeds, the admin area, the REST API and WP-CLI always get the originals.

= Why is one of my image sizes still served as JPEG or PNG? =

A size is served as WebP only when its WebP copy exists, is valid, matches the size's dimensions and is smaller than the original. Expand the list under the image's switch to see which sizes keep the original and why. **Regenerate WebP** re-creates the copies.

= How do I convert new uploads to WebP automatically? =

Turn on **Automatic WebP for new images** on the Settings page. It is off by default. Conversion runs in the background through WP-Cron shortly after an image is added, so on quiet sites copies can appear a few minutes later. It adds a WebP file for every size and uses server CPU and memory while converting. If your host, CDN or another plugin already handles WebP, leave it off.

= Do all browsers support WebP? =

All current browsers do. The plugin serves the WebP URL directly, without a `<picture>` fallback, so very old browsers that cannot show WebP would not show the served images.

= Do I need to clear my cache after turning WebP on or off? =

Yes. Cached pages keep the URLs they were built with. Purge your caching plugin, host cache or CDN after switching. Developers can purge automatically with the `ctgen_webp_serving_changed` action.

= How do I find where an image is used in WordPress? =

Open **Custom Thumbnail Generator > Attachment Summary**. Each image lists every place it is used, with links. The images shown are rescanned every time the page loads. Click **Scan all images** to filter or sort the whole library by usage.

= How do I find unused images? =

On the Attachment Summary page, click **Scan all images**, then filter by **No usage found**. Images used only in theme files, CSS or on other websites cannot be detected, so treat "No usage found" as a list to check, not proof that an image is unused.

= Does WebP conversion change or delete my original images? =

No. WebP copies are separate files next to the originals, with the same name and a `.webp` extension (`photo.jpg` gets `photo.webp`). Originals, thumbnails and attachment data are not touched. When you permanently delete an image, its WebP copies are deleted with it.

= Why were my GIF images not converted? =

GIFs are skipped on purpose. Converting an animated GIF to WebP would lose its animation.

= What does my server need for WebP conversion? =

The image library WordPress uses on your server (Imagick or GD) must support WebP. If it does not, each file is reported as failed with "This server cannot produce WebP output." and nothing is created. Very large images need more memory: a 6000 x 4000 pixel upload used about 220 MB in testing.

= What do "skipped" and "failed" mean after a conversion? =

"Skipped" means a size has no file to convert, for example because the file is missing. "Failed" means a file could not be converted: it is corrupt, another image in the same folder has the same name, a Media Library file already uses the WebP name, or the server did not answer within 2 minutes. The reason is listed below the progress bar.

= Does it work on multisite? =

Yes. Activate it network-wide or on individual sites. Each site has its own sizes and settings.

= What is removed when I delete the plugin? =

Its settings, the usage scan results, the WebP serving data and its scheduled tasks, on every site of a network. Your custom image sizes, image files and WebP copies are kept, so images stay available and you can serve the WebP copies another way. Deactivating the plugin removes nothing.

= Are there hooks for developers? =

Yes:

* `ctgen_webp_should_replace` (filter): return false to keep the original URLs, site-wide or for one attachment. Arguments: `$replace`, `$attachment_id`.
* `ctgen_webp_img_url_attributes` (filter): the `<img>` attributes in post content that hold an image URL and are switched to WebP. Add the attribute your lightbox or lazy loader reads its image from. Default: `src`, `data-full-image`, `data-light-image`, `data-src`, `data-lazy-src`, `data-orig-file`, `data-medium-file`, `data-large-file`.
* `ctgen_webp_serving_changed` (action): fires when serving is turned on or off for an image. Arguments: `$attachment_id`, `$state` (`on` or `off`). Use it to purge caches.
* `ctgen_webp_files_changed` (action): fires after WebP copies of an image are written. Argument: `$attachment_id`.
* `ctgen_webp_serve_all_batch` (filter): images switched per request by the switch for all images. Default 20.
* `ctgen_webp_health_check_batch` (filter): images checked per health check run. Default 50.
* `ctgen_webp_health_check_interval` (filter): how often the health check runs. Default `daily`.

Scheduled events: `ctgen_webp_health_check` (daily) and `ctgen_webp_auto_convert` (one per new image while automatic WebP is on).

== Screenshots ==

1. Attachment Summary: every image with where it is used, its file sizes, WebP savings and the Serve WebP switches.
2. Where an image is used: posts, pages, custom fields, page builders, galleries and theme settings, with links.
3. Serve WebP for all images, with progress, and per-image exceptions.
4. WebP conversion with a live progress bar and converted, skipped and failed counts.
5. Custom image sizes that survive theme changes, with Regenerate and WebP buttons for each size.
6. Automatic WebP for new uploads, with the points to consider before turning it on.

== Changelog ==

= 1.1 =
* New: Serve WebP to visitors. One switch serves every image that has a WebP copy, and each image has its own switch. Only rendered pages change; post content and the database keep the original URLs.
* New: a size is served as WebP only when its copy is valid and smaller than the original.
* New: lightbox and lazy-load images in post content (for example Kadence gallery lightboxes, Jetpack and common lazy loaders) are served as WebP too.
* New: Automatic WebP for new uploads and regenerated thumbnails, in the background. Off by default.
* New: daily health check that switches a size back to the original when its WebP copy goes missing.
* New: Attachment Summary page listing every image with the post it is attached to and every place it is used (batched, resumable usage scan), file sizes, WebP sizes and savings, an image preview pop-up, filters, search, sorting and pagination.
* New: Create WebP copies of existing JPEG and PNG images, including the full-size image, the scaled and original versions of large uploads, and every thumbnail, or of a single thumbnail size or a single image.
* New: live progress bars with converted, already converted, skipped and failed counts, and a list of failures.
* New: hooks for developers (see the FAQ).
* The plugin now has its own admin menu: **Custom Thumbnail Generator > Settings** (previously **Settings > Custom Thumbnail Generator**).
* Each conversion request has a 2-minute time limit, so a server that stops answering cannot stall a run.
* GIF images are excluded from WebP conversion so that animated GIFs never lose their animation.
* Deleting the plugin now removes all of its settings and stored data, on every site of a network.
* Security: WebP copies of an image are deleted when the image is permanently deleted.
* Security: custom sizes are validated on the server (whole numbers from 25 to 10000 pixels).
* Security: the plugin's scripts, styles and security token load only on its own admin pages; size names and server messages are always shown as text.
* Fix: regenerating one size no longer records a full-size copy as that size when an image is smaller than the size.
* Fix: "Regenerate Thumbnails" no longer stops when one image fails; error messages from the server are shown instead of "Thumbnail size exists!".
* Code updated to the WordPress Coding Standards. All messages are translatable.

= 1.0.2 =
* WordPress version compatibility.

= 1.0.1 =
* WordPress version compatibility.
* Small CSS modification.

= 1.0.0 =
* Initial release.
* AJAX table loader and thumbnail manager.
* Nonce validation and security best practices.

== Upgrade Notice ==

= 1.1 =
Adds WebP conversion and serving, automatic WebP for new uploads, and a page showing where every image is used. Original images are never changed, and WebP serving is off until you turn it on.
