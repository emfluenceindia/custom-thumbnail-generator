<?php
/**
 * Removes the data the plugin stores when it is deleted from the Plugins screen.
 *
 * Loaded only by uninstall.php (the plugin itself is not loaded during
 * uninstall), so every option, meta key and cron hook is named here rather
 * than taken from the classes that use it. Keep this list in step with them.
 *
 * Deliberately kept: custom thumbnail sizes (option ctgen_custom_image_sizes),
 * image files and WebP copies.
 *
 * @package Custom_Thumbnail_Generator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin data cleanup on uninstall.
 */
class CTGEN_Uninstall {

	/**
	 * Post meta stored on attachments: usage scan results and WebP serving data.
	 *
	 * @var string[]
	 */
	const POST_META = array( '_ctgen_usage', '_ctgen_usage_count', '_ctgen_webp_serve' );

	/**
	 * Options: "Serve WebP for all images", automatic WebP, the health check cursor.
	 *
	 * @var string[]
	 */
	const OPTIONS = array( 'ctgen_webp_serve_all', 'ctgen_webp_auto_convert', 'ctgen_webp_health_cursor' );

	/**
	 * Cron hooks: the daily health check and pending automatic conversions
	 * (one event per image, each with its own arguments).
	 *
	 * @var string[]
	 */
	const CRON_HOOKS = array( 'ctgen_webp_health_check', 'ctgen_webp_auto_convert' );

	/**
	 * User meta (shared by every site of a network): Attachment Summary rows per page.
	 *
	 * @var string[]
	 */
	const USER_META = array( 'ctgen_attachment_summary_per_page' );

	/**
	 * Removes the plugin's data from every site, then its user meta.
	 *
	 * @return void
	 */
	public static function run() {
		self::for_each_site( array( __CLASS__, 'clean_site' ) );

		foreach ( self::USER_META as $key ) {
			delete_metadata( 'user', 0, $key, '', true );
		}
	}

	/**
	 * Calls $callback once for the current site, or once on every site of a
	 * multisite network (switched to that site).
	 *
	 * @param callable $callback Called with no arguments.
	 * @return void
	 */
	public static function for_each_site( $callback ) {
		if ( ! is_multisite() ) {
			call_user_func( $callback );
			return;
		}

		foreach ( get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		) as $site_id ) {
			switch_to_blog( $site_id );
			call_user_func( $callback );
			restore_current_blog();
		}
	}

	/**
	 * Removes the plugin's post meta, options and cron events from the current site.
	 *
	 * @return void
	 */
	public static function clean_site() {
		foreach ( self::POST_META as $key ) {
			delete_post_meta_by_key( $key );
		}

		foreach ( self::OPTIONS as $option ) {
			delete_option( $option );
		}

		// Unlike wp_clear_scheduled_hook(), this also removes events scheduled with arguments.
		foreach ( self::CRON_HOOKS as $hook ) {
			wp_unschedule_hook( $hook );
		}
	}
}
