<?php
/**
 * Removes data stored by the Attachment Summary usage scan when the plugin is deleted.
 *
 * Custom thumbnail sizes (option ctgen_custom_image_sizes), image files and
 * WebP copies are deliberately left untouched.
 *
 * @package Custom_Thumbnail_Generator
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_post_meta_by_key( '_ctgen_usage' );
delete_post_meta_by_key( '_ctgen_usage_count' );
delete_metadata( 'user', 0, 'ctgen_attachment_summary_per_page', '', true );
