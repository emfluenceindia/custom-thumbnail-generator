=== Custom Thumbnail Generator ===  
Contributors: subrataemfluence  
Tags: thumbnails, media library, regenerate, custom image size, ajax  
Requires at least: 6.5  
Tested up to: 7.1.2
Requires PHP: 7.0
Stable tag: 1.0.2
License: GPLv2 or later  
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Custom Thumbnail Generator manages image sizes via an AJAX interface. It decouples sizes from themes, ensuring they persist and remain functional.

== Description ==

Custom Thumbnail Generator is a powerful tool for defining and managing custom image sizes in WordPress.

**Key Features**

    * **AJAX-powered Interface:** Add, remove, and regenerate thumbnails instantly without a page reload.  

    * **Theme Independence:** Custom image sizes are decoupled from themes, ensuring they remain accessible and functional even when themes are switched.  

    * **Consistent Image Handling:** Provides developers, photographers, and content creators with precise control over image dimensions.  

== Installation ==

    * Upload the plugin folder to `/wp-content/plugins/`.  
    * Activate the plugin via the **Plugins** menu in WordPress.  
    * Go to **Settings > Custom Thumbnail Generator** to configure your image sizes.  

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

== Changelog ==

= 1.0.2 =  
* WordPress version compatibility  

= 1.0.1 =  
* WordPress version compatibility  
* Small CSS modification  

= 1.0.0 =  
* Initial release  
* AJAX table loader and thumbnail manager  
* Nonce validation and security best practices  

== License ==

This plugin is licensed under the [GNU General Public License v2 or later (GPLv2+)](https://www.gnu.org/licenses/gpl-2.0.html).

You are free to use, modify, and distribute this plugin under the terms of the GPL license.