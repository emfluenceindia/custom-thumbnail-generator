<?php
/**
 * Removes the plugin's data when it is deleted from the Plugins screen:
 * usage scan results, WebP serving data, the WebP settings and scheduled
 * events, on every site of a network. See CTGEN_Uninstall.
 *
 * Custom thumbnail sizes (option ctgen_custom_image_sizes), image files and
 * WebP copies are deliberately left untouched.
 *
 * @package Custom_Thumbnail_Generator
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-ctgen-uninstall.php';

CTGEN_Uninstall::run();
