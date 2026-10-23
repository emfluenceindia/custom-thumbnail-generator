=== Custom Thumbnail Generator ===  
Contributors: subrataemfluence  
Tags: thumbnails, media library, regenerate, custom image size, webp  
Requires at least: 6.5  
Tested up to: 7.1
Requires PHP: 7.0
Stable tag: 1.1
License: GPLv2 or later  
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Custom Thumbnail Generator manages image sizes via an AJAX interface. It decouples sizes from themes, ensuring they persist and remain functional.

== Description ==

Custom Thumbnail Generator is a powerful tool for defining and managing custom image sizes in WordPress.

**Key Features**

    * **AJAX-powered Interface:** Add, remove, and regenerate thumbnails instantly without a page reload.  

    * **Theme Independence:** Custom image sizes are decoupled from themes, ensuring they remain accessible and functional even when themes are switched.  

    * **Consistent Image Handling:** Provides developers, photographers, and content creators with precise control over image dimensions.  

    * **Attachment Summary:** See every image in the Media Library, the post it was uploaded to, and where it is actually used on the site.  

    * **WebP Conversion:** Create WebP copies of existing JPEG and PNG images and their thumbnails, for all sizes at once or for one thumbnail size at a time, with a live progress bar and a summary of converted, already converted, skipped and failed thumbnails.  

**WebP Conversion**

Go to **Custom Thumbnail Generator > Settings** and use **Convert All Thumbnails to WebP** to convert the full-size image and every thumbnail size, or the **WebP** button in a size's table row to convert only that thumbnail size. Buttons are available for WordPress core, theme and custom sizes.

    * A WebP file is created next to each existing file, with the same name and a `.webp` extension (for example `photo.jpg` gets `photo.webp` and `photo-300x200.jpg` gets `photo-300x200.webp`).  
    * For large uploads that WordPress scales down, both the scaled image WordPress displays (`photo-scaled.jpg`) and the original upload (`photo.jpg`) get a WebP copy.  
    * Original images, existing thumbnails and attachment data are never changed or deleted.  
    * Only existing files are converted. Missing thumbnails are skipped, not created.  
    * If two images in the same folder share a name with different extensions (for example `photo.jpg` and `photo.png`), their WebP copies would get the same name, so they are not converted. A WebP file that is a Media Library item of its own is never overwritten.  
    * When a JPEG or PNG image is permanently deleted from the Media Library, its WebP copies are deleted with it, so the deleted image does not stay online as WebP. A WebP file that is a Media Library item of its own is never deleted this way.  
    * GIF images are not converted, because animated GIFs would lose their animation.  
    * Running the conversion again is safe: files that already have a current WebP copy are reported as "already WebP" and left as they are.  
    * The browser sends one image per request, so a large media library is never converted in one long request. Keep the page open until the run finishes.  
    * If the server returns an error for an image, or does not answer within 2 minutes, that image is listed as failed and the run continues with the next one. After three such errors in a row the run stops and says why.  
    * Only one conversion runs at a time; the buttons are disabled until it finishes.
    * The plugin creates the WebP files but does not change which image WordPress displays. Serve them with your theme, a CDN or a server rule that delivers WebP.  

**Attachment Summary**

Go to **Custom Thumbnail Generator > Attachment Summary** for a table of every image in the Media Library with its type and dimensions, an **Attachment** list of the post it is attached to and every place it is used, the size of all its files, and the size of its WebP copies. **View** opens the image in a popup. Filter by type, attachment and usage, search by title or file name, and sort by title, type or number of places.

    * The usage of the images shown on the page is refreshed automatically in the background every time the page loads, and the refresh icon next to an image's Attachment label rescans that image. To filter or sort by usage across all pages, click **Scan all images** (or **Scan images not scanned yet**); images are scanned ten at a time with a progress bar.  
    * The scan looks at the content of posts, pages and other post types, custom fields (including page builder data such as Elementor), featured images, galleries, WooCommerce product galleries, widgets, theme settings, the site icon and the logo. It recognises every size of an image, its scaled and edited versions and its WebP copies.  
    * Images used only in theme files, CSS or on other websites cannot be detected, so "No usage found" does not prove that an image is unused. Revisions and automatic drafts are not counted.  
    * For JPEG and PNG images the WebP status shows the size of the WebP copies, the compression ratio compared with the original files, a bar with the disk space that could be saved, and a **Regenerate WebP** button that re-creates the WebP copies of the main file and every size one file at a time, with a progress bar showing which size is being generated (original files are never changed).  
    * Scanning only reads your content. The only data it stores is the scan result on each image, which is removed when the plugin is deleted.  
    * Scanning time grows with the size of the site: on a test site with 1,262 posts it took about 0.15 seconds per image.  

== Installation ==

    * Upload the plugin folder to `/wp-content/plugins/`.  
    * Activate the plugin via the **Plugins** menu in WordPress.  
    * Go to **Custom Thumbnail Generator > Settings** in the admin menu to configure your image sizes.  

== Screenshots ==

1. A screenshot of the main settings page, showing all available options.  

== Frequently Asked Questions ==

= Will this delete existing thumbnails? =  
No. Removing a size only prevents future generation. Existing files remain untouched.

= Does it support multisite? =  
Yes, activate network-wide to manage thumbnails across subsites.

= Can this generate or regenerate a single thubnail size? =  
Yes, the plugin allows you to create and generate a specific thumbnail size instead of always using in bulk option.

= Can theme generated thumbnails be deleted? =  
No. Option to remove any thumbnail size registered by a theme or WordPress core is not possible.

= Is there any restriction on the number of thumbnail sizes that can be created? =  
No. There is absolutely no restriction on the number of thumbnail sizes. Unlimited number of thumbnail sizes can be registered and made avaialble across all themes.

= Does WebP conversion change or delete my original images? =  
No. WebP copies are separate files created next to the existing image files. Original images, thumbnails and attachment data stay exactly as they were. When you permanently delete an image, its WebP copies are deleted too.

= Why were my GIF images not converted? =  
GIF images are skipped on purpose. Converting an animated GIF to WebP would lose its animation, so GIFs are not converted.

= What does my server need for WebP conversion? =  
The image library used by WordPress on your server (Imagick or GD) must support WebP. If it does not, each file is reported as failed with the message "This server cannot produce WebP output." and no files are created. Very large images need more memory: converting a 6000 x 4000 pixel upload used about 220 MB in testing, so hosts with a low PHP memory limit may report such images as failed.

= Will my site start showing the WebP images automatically? =  
No. The plugin creates the WebP files only. To show them to visitors, use your theme, a CDN or a server rule that serves WebP files.

= What do "skipped" and "failed" mean in the conversion summary? =  
"Skipped" means a thumbnail size has no file to convert, for example because the file is missing. "Failed" means a file could not be converted, for example because it is corrupt, because another image in the same folder has the same name, because a Media Library file already uses the WebP name, or because the server did not answer in time; the reason is listed below the progress bar.

== Changelog ==

= 1.1 =  
* New: Create WebP copies of existing JPEG and PNG images, including the full-size image, the scaled and original versions of large uploads, and every thumbnail, or of a single thumbnail size.  
* New: "Convert All Thumbnails to WebP" button and a WebP button for every size in the default and custom size tables.  
* New: Live progress bar with converted, already converted, skipped and failed counts, and a list of failures.  
* Each conversion request has a 2-minute time limit, so a server that stops answering cannot stall a run.  
* GIF images are excluded from WebP conversion so that animated GIFs never lose their animation.  
* Original images, thumbnails and attachment data are never modified by the conversion.  
* Code updated to the WordPress Coding Standards.  
* Translation template updated with the new strings.  
* New: Attachment Summary page listing every image with the post it is attached to and every place it is used (batched, resumable usage scan), file sizes and WebP copy sizes, an image preview popup, filters, search, sorting and pagination.  
* The plugin now has its own admin menu: **Custom Thumbnail Generator > Settings** (previously **Settings > Custom Thumbnail Generator**).  
* Security: WebP copies of an image are deleted when the image is permanently deleted.  
* Security: custom sizes are validated on the server (whole numbers from 25 to 10000 pixels).  
* Security: the plugin's scripts, styles and security token load only on its own admin pages; size names and server messages are always shown as text.  
* Fix: regenerating one size no longer records a full-size copy as that size when an image is smaller than the size.  
* Fix: "Regenerate Thumbnails" no longer stops when one image fails; error messages from the server are shown instead of "Thumbnail size exists!".  
* All messages of the settings page are now translatable.  

= 1.0.2 =  
* WordPress version compatibility  

= 1.0.1 =  
* WordPress version compatibility  
* Small CSS modification  

= 1.0.0 =  
* Initial release  
* AJAX table loader and thumbnail manager  
* Nonce validation and security best practices  

== Upgrade Notice ==

= 1.1 =  
Adds WebP conversion for existing JPEG and PNG thumbnails. Original images and thumbnails are not changed.

== License ==

This plugin is licensed under the [GNU General Public License v2 or later (GPLv2+)](https://www.gnu.org/licenses/gpl-2.0.html).

You are free to use, modify, and distribute this plugin under the terms of the GPL license.