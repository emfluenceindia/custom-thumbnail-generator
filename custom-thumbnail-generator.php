<?php
/**
 * Plugin Name: Custom Thumbnail Generator
 * Plugin URI: https://github.com/emfluenceindia/Custom-Thumbnail-Generator
 * Description: Create theme-independent custom thumbnail sizes with Regenerate feature.
 * Version: 1.1
 * Author: Subrata Sarkar
 * Author URI: https://profiles.wordpress.org/subrataemfluence
 * Requires PHP: 7.0
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: custom-thumbnail-generator
 * Domain Path: /languages
 * Method Prefix: ctgen_
 *
 * @package Custom_Thumbnail_Generator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Keep equal to the "Version:" header above (the release script checks this).
if ( ! defined( 'CTGEN_PLUGIN_VERSION' ) ) {
	define( 'CTGEN_PLUGIN_VERSION', '1.1' );
}

require_once plugin_dir_path( __FILE__ ) . 'includes/init.php';

/**
 * Plugin activation: schedules the WebP serving health check.
 *
 * @return void
 */
function ctgen_webp_activate() {
	ctgen_webp_health_check()->activate();
}

/**
 * Plugin deactivation: removes the WebP serving health check (on every site
 * when deactivated network-wide). Serving state is kept.
 *
 * @param bool $network_wide Whether the plugin is being deactivated network-wide.
 * @return void
 */
function ctgen_webp_deactivate( $network_wide ) {
	ctgen_webp_health_check()->deactivate( (bool) $network_wide );
}

register_activation_hook( __FILE__, 'ctgen_webp_activate' );
register_deactivation_hook( __FILE__, 'ctgen_webp_deactivate' );

/**
 * Add a Settings link (plugin action link) under the plugin name on main plugin page.
 *
 * @param  array $links An array of plugin action links.
 * @return array An updated array of plugin action links.
 */
function ctgen_add_plugin_settings_links( $links ) {
	// Build the URL.
	$settings_url = admin_url( 'admin.php?page=ctgen-admin-settings' );

	// Create the HTML for the link.
	$settings_link = '<a href="' . esc_url( $settings_url ) . '">' . __( 'Settings', 'custom-thumbnail-generator' ) . '</a>';

	// Add the link to the beginning of the links array.
	array_unshift( $links, $settings_link );

	// Return the modified $links array.
	return $links;
}

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'ctgen_add_plugin_settings_links' );
