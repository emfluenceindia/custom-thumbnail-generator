<?php
/**
 * Plugin initialization: constants, includes, asset enqueueing and custom size registration.
 *
 * @package Custom_Thumbnail_Generator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed early.
}

/**
 * Plugin initialization
 */

if ( ! defined( 'CTGEN_PLUGIN_PATH' ) ) {
	define( 'CTGEN_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'CTGEN_PLUGIN_URL' ) ) {
	define( 'CTGEN_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'CTGEN_TEMPLATE_DIR' ) ) {
	define( 'CTGEN_TEMPLATE_DIR', plugin_dir_path( __DIR__ ) . 'templates/' );
}
if ( ! defined( 'CTGEN_PLUGIN_DIR' ) ) {
	define( 'CTGEN_PLUGIN_DIR', plugin_dir_url( __DIR__ ) );
}
if ( ! defined( 'CTGEN_WEBP_DIR' ) ) {
	define( 'CTGEN_WEBP_DIR', plugin_dir_path( __DIR__ ) . 'webp/' );
}

if ( ! defined( 'CTGEN_NATIVE_THUMB_SIZES' ) ) {
	define( 'CTGEN_NATIVE_THUMB_SIZES', array( 'thumbnail', 'medium', 'medium_large', 'large' ) );
}

if ( ! defined( 'CTGEN_LOGO' ) ) {
	define( 'CTGEN_LOGO', CTGEN_PLUGIN_DIR . 'assets/images/icon-80x80.png' );
}

if ( ! defined( 'CTGEN_ALLOWED_TAGS' ) ) {
	define(
		'CTGEN_ALLOWED_TAGS',
		array(
			'form'     => array(
				'action' => array(),
				'method' => array(),
				'id'     => array(),
				'class'  => array(),
			),
			'input'    => array(
				'type'    => array(),
				'name'    => array(),
				'value'   => array(),
				'checked' => array(),
				'id'      => array(),
				'class'   => array(),
			),
			'select'   => array(
				'name'  => array(),
				'id'    => array(),
				'class' => array(),
			),
			'option'   => array(
				'value'    => array(),
				'selected' => array(),
			),
			'textarea' => array(
				'name'  => array(),
				'id'    => array(),
				'class' => array(),
			),
			'label'    => array(
				'for'   => array(),
				'class' => array(),
			),
			'div'      => array(
				'class' => array(),
				'id'    => array(),
			),
			'span'     => array( 'class' => array() ),
			'p'        => array(),
			'button'   => array(
				'type'  => array(),
				'class' => array(),
				'name'  => array(),
				'value' => array(),
			),
		)
	);
}

require_once CTGEN_PLUGIN_PATH . 'settings-page.php';
require_once CTGEN_PLUGIN_PATH . 'class-ctgen-functions.php';

require_once CTGEN_WEBP_DIR . 'class-ctgen-webp-converter.php';
require_once CTGEN_WEBP_DIR . 'class-ctgen-webp-ajax-handlers.php';
new CTGEN_Webp_Ajax_Handlers();

require_once CTGEN_PLUGIN_PATH . 'class-ctgen-ajax-handlers.php';
new CTGEN_Ajax_Handlers();

/**
 * Equeue scripts and Localize for AJAX handling
 */
add_action( 'admin_enqueue_scripts', 'ctgen_enqueue_scripts' );

/**
 * Enqueues the admin script, its localized AJAX data, and the admin styles.
 *
 * @return void
 */
function ctgen_enqueue_scripts() {
	/**
	 * Enqueue media action scripts
	 */
	wp_enqueue_script(
		'ctgen-media-actions-script',
		CTGEN_PLUGIN_DIR . 'assets/js/ctgen-media-actions.min.js',
		array( 'jquery' ),
		CTGEN_PLUGIN_VERSION,
		true
	);

	/**
	 * Localize media action script
	 */
	wp_localize_script(
		'ctgen-media-actions-script',
		'CTGENMediaAction',
		array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'ctgen_media_actions' ),
		)
	);

	/** Enqueue admin settings page CSS */
	wp_enqueue_style(
		'ctg-admin.css',
		CTGEN_PLUGIN_DIR . 'assets/css/ctgen-admin.min.css',
		null,
		CTGEN_PLUGIN_VERSION
	);

	/** Enqueue FontSAwesome */
	wp_enqueue_style(
		'ctg-fa-css',
		CTGEN_PLUGIN_DIR . 'assets/css/font-awesome.min.css',
		null,
		'4.7.0',
		'all'
	);
}

/**
 * Register custom image sizes in WordPress
 */
add_action( 'init', 'ctgen_register_custom_sizes' );

/**
 * Registers every custom size stored by the plugin with add_image_size().
 *
 * @return void
 */
function ctgen_register_custom_sizes() {
	$sizes = get_option( 'ctgen_custom_image_sizes', array() );

	foreach ( $sizes as $slug => $args ) {
		add_image_size( $slug, $args['width'], $args['height'], $args['crop'] );
	}
}


/**
 * Make custom image sizes available in post
 */
add_filter( 'image_size_names_choose', 'ctgen_add_custom_sizes_to_media_dropdown' );

/**
 * Adds the plugin's custom sizes to the media size dropdown.
 *
 * @param array $sizes Size names keyed by slug.
 * @return array
 */
function ctgen_add_custom_sizes_to_media_dropdown( $sizes ) {
	$custom_sizes = get_option( 'ctgen_custom_image_sizes', array() );
	foreach ( $custom_sizes as $slug => $args ) {
		$sizes[ $slug ] = $slug;
	}

	return $sizes;
}
