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
 * Enqueue the WebP conversion script and styles on the plugin settings page only.
 */
add_action( 'admin_enqueue_scripts', 'ctgen_enqueue_webp_scripts' );

/**
 * Enqueues and localizes the WebP conversion UI assets.
 *
 * The readable source is assets/js/ctgen-webp-convert.js; the enqueued
 * .min.js is generated from it (see v2/tools/build-js.js).
 *
 * @param string $hook_suffix The current admin page hook suffix.
 * @return void
 */
function ctgen_enqueue_webp_scripts( $hook_suffix ) {
	$settings_hook = ctgen_admin_page_hook( 'settings' );

	if ( '' === $settings_hook || $settings_hook !== $hook_suffix ) {
		return;
	}

	wp_enqueue_script(
		'ctgen-webp-convert-script',
		CTGEN_PLUGIN_DIR . 'assets/js/ctgen-webp-convert.min.js',
		array( 'jquery' ),
		CTGEN_PLUGIN_VERSION,
		true
	);

	wp_localize_script(
		'ctgen-webp-convert-script',
		'CTGENWebpConvert',
		array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'ctgen_media_actions' ),
			'i18n'     => array(
				'finding'      => __( 'Finding images to convert...', 'custom-thumbnail-generator' ),
				/* translators: %s: image size name, e.g. "thumbnail". */
				'findingSize'  => __( 'Finding images to convert for the %s size...', 'custom-thumbnail-generator' ),
				'busy'         => __( 'Converting...', 'custom-thumbnail-generator' ),
				'nothing'      => __( 'No JPEG or PNG images found. Nothing to convert.', 'custom-thumbnail-generator' ),
				/* translators: 1: number of images processed so far, 2: total number of images. */
				'processing'   => __( 'Processing image %1$s of %2$s...', 'custom-thumbnail-generator' ),
				/* translators: 1: percentage complete, 2: number of images processed, 3: total number of images. */
				'announce'     => __( '%1$s%% done: %2$s of %3$s images processed.', 'custom-thumbnail-generator' ),
				/* translators: 1: converted thumbnails, 2: thumbnails that already had a current WebP copy, 3: skipped thumbnails, 4: failed thumbnails. */
				'counts'       => __( 'Thumbnails - converted: %1$s, already WebP: %2$s, skipped: %3$s, failed: %4$s.', 'custom-thumbnail-generator' ),
				/* translators: %s: number of images that could not be processed at all. */
				'imagesFailed' => __( 'Images that could not be processed: %s.', 'custom-thumbnail-generator' ),
				/* translators: 1: number of images processed, 2: total number of images. */
				'doneOk'       => __( 'WebP conversion complete: %1$s of %2$s images processed.', 'custom-thumbnail-generator' ),
				/* translators: 1: number of images processed, 2: total number of images. */
				'doneErrors'   => __( 'WebP conversion finished with errors: %1$s of %2$s images processed. See the list below.', 'custom-thumbnail-generator' ),
				/* translators: 1: number of images processed, 2: total number of images, 3: reason. */
				'stopped'      => __( 'WebP conversion stopped after %1$s of %2$s images: %3$s', 'custom-thumbnail-generator' ),
				'auth'         => __( 'Your session has expired or you are not allowed to do this. Reload the page and try again.', 'custom-thumbnail-generator' ),
				'network'      => __( 'The server could not be reached.', 'custom-thumbnail-generator' ),
				'malformed'    => __( 'The server sent an unexpected response.', 'custom-thumbnail-generator' ),
				/* translators: 1: attachment ID, 2: error message. */
				'itemError'    => __( 'Image ID %1$s: %2$s', 'custom-thumbnail-generator' ),
				/* translators: 1: attachment ID, 2: image size name, 3: error message. */
				'sizeError'    => __( 'Image ID %1$s, size %2$s: %3$s', 'custom-thumbnail-generator' ),
				/* translators: %s: number of further errors not listed. */
				'more'         => __( '...and %s more.', 'custom-thumbnail-generator' ),
			),
		)
	);

	wp_enqueue_style(
		'ctgen-webp-convert-css',
		CTGEN_PLUGIN_DIR . 'assets/css/ctgen-webp-convert.css',
		array( 'ctg-admin.css' ),
		CTGEN_PLUGIN_VERSION
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
