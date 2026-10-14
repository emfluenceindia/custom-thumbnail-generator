<?php
/**
 * Plugin Name: Custom Thumbnail Generator
 * Plugin URI: https://github.com/emfluenceindia/Custom-Thumbnail-Generator
 * Description: Create theme-independent custom thumbnail sizes with Regenerate feature.
 * Version: 1.0.2
 * Author: Subrata Sarkar
 * Author URI: https://profiles.wordpress.org/subrataemfluence
 * Requires PHP: 7.0
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: custom-thumbnail-generator
 * Domain Path: /languages
 * Method Prefix: ctgen_
 */

 if( ! defined( 'ABSPATH' ) ) exit;

 add_action( 'admin_init', function() {
   if( ! defined( 'CTGEN_PLUGIN_VERSION' ) ) {
      $plugin_data = get_plugin_data( __FILE__ );
      define( 'CTGEN_PLUGIN_VERSION', $plugin_data[ 'Version' ] );
   }
 } );
 
 require_once plugin_dir_path( __FILE__ ) . 'includes/init.php';

 /**
  * Add a Settings link (plugin action link) under the plugin name on main plugin page
  * 
  * @param  array $links An array of plugin action links
  * @return array An updated array of plugin action links
 */
 function ctgen_add_plugin_settings_links( $links ) {
    // Build the URL
    $settings_url = admin_url( 'options-general.php?page=ctgen-admin-settings' );

    // Create the HTML for the link
    $settings_link = '<a href="' . esc_url( $settings_url ) . '">' . __( 'Settings', 'custom-thumbnail-generator' ) . '</a>';

    // Add the link to the beginning of the links array
    array_unshift( $links, $settings_link );

    // Return the modified $links array
    return $links;
 }

 add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'ctgen_add_plugin_settings_links' );