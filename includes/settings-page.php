<?php
/**
 * Plugin Settings Page. UI for user to create custom thumbnails.
 *
 * @package Custom_Thumbnail_Generator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed early.
}

/** Add the top-level menu with its Settings and Attachment Summary pages. */
add_action(
	'admin_menu',
	function () {
		add_menu_page(
			__( 'Custom Thumbnail Generator', 'custom-thumbnail-generator' ),
			__( 'Custom Thumbnail Generator', 'custom-thumbnail-generator' ),
			'manage_options',
			'ctgen-admin-settings',
			'ctgen_render_settngs_page',
			'dashicons-images-alt2',
			11
		);

		// Same slug as the parent: replaces the auto-generated first submenu item.
		$settings_hook = add_submenu_page(
			'ctgen-admin-settings',
			__( 'Custom Thumbnail Generator', 'custom-thumbnail-generator' ),
			__( 'Settings', 'custom-thumbnail-generator' ),
			'manage_options',
			'ctgen-admin-settings',
			'ctgen_render_settngs_page'
		);

		$summary_hook = add_submenu_page(
			'ctgen-admin-settings',
			__( 'Attachment Summary', 'custom-thumbnail-generator' ),
			__( 'Attachment Summary', 'custom-thumbnail-generator' ),
			'manage_options',
			'ctgen-attachment-summary',
			'ctgen_render_attachment_summary_page'
		);

		ctgen_admin_page_hook( 'settings', $settings_hook );
		ctgen_admin_page_hook( 'attachment_summary', $summary_hook );

		if ( $summary_hook ) {
			add_action( 'load-' . $summary_hook, array( 'CTGEN_Attachment_Summary', 'on_load' ) );
		}
	}
);

/**
 * Stores and returns the admin hook suffix of a plugin page.
 *
 * The suffix is derived from the (translated) menu title, so it is recorded
 * from add_submenu_page()'s return value instead of being hard-coded.
 *
 * @param string      $page        Page key: 'settings' or 'attachment_summary'.
 * @param string|null $hook_suffix Hook suffix to store; omit to read.
 * @return string The stored hook suffix, or '' if the page is not registered.
 */
function ctgen_admin_page_hook( $page, $hook_suffix = null ) {
	static $hooks = array();

	if ( is_string( $hook_suffix ) && '' !== $hook_suffix ) {
		$hooks[ $page ] = $hook_suffix;
	}

	return isset( $hooks[ $page ] ) ? $hooks[ $page ] : '';
}

/**
 * HTML to render on Settings page
 */
function ctgen_render_settngs_page() {
	$ctg_functions = new CTGEN_Functions(); ?>
	
	<div class="wrap">
		<?php
		$ctg_functions->ctgen_render_settings_page_form();
		?>
	</div>

	<?php
}

/**
 * The Attachment Summary page controller (one instance per request).
 *
 * @return CTGEN_Attachment_Summary
 */
function ctgen_attachment_summary() {
	static $instance = null;

	if ( null === $instance ) {
		$instance = new CTGEN_Attachment_Summary();
	}

	return $instance;
}

/**
 * HTML for the Attachment Summary page.
 */
function ctgen_render_attachment_summary_page() {
	ctgen_attachment_summary()->render();
}