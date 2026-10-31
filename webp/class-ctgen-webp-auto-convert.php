<?php
/**
 * "Create WebP automatically for new uploads and regenerated thumbnails".
 *
 * WordPress saves an image's metadata after creating its thumbnail sizes,
 * whatever added the image (Media Library, the post editor, a featured
 * image, ACF, the REST API, WP-CLI) and whenever sizes are regenerated or
 * the image is edited. While the setting is on, that moment schedules a
 * background conversion (WP-Cron) of the main file and every size, so
 * uploads are not slowed down. Conversion goes through the plugin's own
 * engine, with all of its rules: JPEG/PNG only, never another item's file.
 *
 * @package Custom_Thumbnail_Generator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Automatic WebP creation.
 */
class CTGEN_Webp_Auto_Convert {

	/**
	 * Option holding whether the setting is on (1) or off (0).
	 *
	 * @var string
	 */
	const OPTION = 'ctgen_webp_auto_convert';

	/**
	 * Settings group the Settings page form saves.
	 *
	 * @var string
	 */
	const GROUP = 'ctgen_webp_auto';

	/**
	 * Cron hook of a background conversion (argument: attachment ID).
	 *
	 * @var string
	 */
	const HOOK = 'ctgen_webp_auto_convert';

	/**
	 * Seconds between the metadata save and the conversion, so an upload
	 * that is still creating its sizes can finish first.
	 *
	 * @var int
	 */
	const DELAY = 10;

	/**
	 * WebP handlers (the conversion engine).
	 *
	 * @var CTGEN_Webp_Ajax_Handlers
	 */
	private $handlers;

	/**
	 * Sets up the service.
	 *
	 * @param CTGEN_Webp_Ajax_Handlers $handlers WebP handlers.
	 */
	public function __construct( $handlers ) {
		$this->handlers = $handlers;
	}

	/**
	 * Hooks into WordPress.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'init', array( $this, 'register_setting' ) );
		add_action( 'added_post_meta', array( $this, 'on_meta_change' ), 10, 3 );
		add_action( 'updated_post_meta', array( $this, 'on_meta_change' ), 10, 3 );
		add_action( self::HOOK, array( $this, 'run' ) );
	}

	/**
	 * Registers the setting, so the Settings page form (options.php) can save it.
	 *
	 * @return void
	 */
	public function register_setting() {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'boolean',
				'description'       => __( 'Create WebP automatically for new uploads and regenerated thumbnails.', 'custom-thumbnail-generator' ),
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => false,
			)
		);
	}

	/**
	 * Stores the setting as 1 (on) or 0 (off). An unticked checkbox sends nothing.
	 *
	 * @param mixed $value Submitted value.
	 * @return int
	 */
	public function sanitize( $value ) {
		return rest_sanitize_boolean( $value ) ? 1 : 0;
	}

	/**
	 * Whether the setting is on.
	 *
	 * @return bool
	 */
	public function is_on() {
		return (bool) get_option( self::OPTION, false );
	}

	/**
	 * Schedules a background conversion when a JPEG/PNG image's metadata is
	 * saved while the setting is on. An upload saves its metadata several
	 * times while creating its sizes; WP-Cron keeps one pending conversion
	 * per image (it refuses an identical event while one is pending, even an
	 * overdue one).
	 *
	 * @param int    $meta_id   Meta row ID (unused).
	 * @param int    $object_id Post ID.
	 * @param string $meta_key  Meta key.
	 * @return void
	 */
	public function on_meta_change( $meta_id, $object_id, $meta_key ) {
		if ( '_wp_attachment_metadata' !== $meta_key || ! $this->is_on() ) {
			return;
		}

		if ( ! in_array( get_post_mime_type( $object_id ), CTGEN_Webp_Converter::ALLOWED_SOURCE_MIME_TYPES, true ) ) {
			return;
		}

		wp_schedule_single_event( time() + self::DELAY, self::HOOK, array( (int) $object_id ) );
	}

	/**
	 * The background conversion: creates the WebP copies of the main file and
	 * every size. A copy that already exists is kept when it is valid and
	 * at least as new as its JPEG/PNG; one older than its JPEG/PNG
	 * (thumbnails regenerated since) is re-created.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	public function run( $attachment_id ) {
		if ( ! $this->is_on() ) {
			return;
		}

		$parts = $this->handlers->list_parts( $attachment_id );

		if ( is_wp_error( $parts ) ) {
			return;
		}

		$dir = trailingslashit( dirname( (string) get_attached_file( $attachment_id ) ) );

		foreach ( $parts as $part => $entry ) {
			$file = isset( $entry['file'] ) && is_string( $entry['file'] ) ? wp_basename( $entry['file'] ) : '';

			$this->handlers->convert_part( $attachment_id, $part, '' !== $file && $this->is_outdated( $dir . $file ) );
		}
	}

	/**
	 * Whether a source file's WebP copy exists but is older than the source.
	 *
	 * @param string $source Absolute source path.
	 * @return bool
	 */
	private function is_outdated( $source ) {
		$webp = trailingslashit( dirname( $source ) ) . pathinfo( $source, PATHINFO_FILENAME ) . '.webp';

		clearstatcache( true, $source );
		clearstatcache( true, $webp );

		return is_file( $source ) && is_file( $webp ) && filemtime( $webp ) < filemtime( $source );
	}

	/**
	 * How many background conversions are waiting.
	 *
	 * @return int
	 */
	public function pending_count() {
		$count = 0;

		foreach ( (array) _get_cron_array() as $hooks ) {
			if ( isset( $hooks[ self::HOOK ] ) && is_array( $hooks[ self::HOOK ] ) ) {
				$count += count( $hooks[ self::HOOK ] );
			}
		}

		return $count;
	}

	/**
	 * Whether WP-Cron is turned off on this site. Separate so tests can simulate it.
	 *
	 * @return bool
	 */
	protected function is_cron_disabled() {
		return defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
	}

	/**
	 * HTML of the Settings page card (escaped): the switch, a one-line
	 * summary and the points to weigh before turning it on.
	 *
	 * @return string
	 */
	public function render_settings_card() {
		$on      = $this->is_on();
		$summary = admin_url( 'admin.php?page=ctgen-attachment-summary' );

		ob_start();
		settings_fields( self::GROUP );
		$fields = ob_get_clean();

		$notices = '';

		if ( $this->is_cron_disabled() ) {
			$notices .= '<div class="notice notice-error inline ctgen-auto-cron-warning"><p><strong>' . esc_html__( 'WP-Cron is turned off on this site.', 'custom-thumbnail-generator' ) . '</strong> '
				. esc_html__( 'Nothing is converted unless a server cron job runs wp-cron.php.', 'custom-thumbnail-generator' ) . '</p></div>';
		}

		$pending = $this->pending_count();

		if ( $pending > 0 ) {
			/* translators: %s: number of images. */
			$notices .= '<p class="ctgen-auto-queue">' . esc_html( sprintf( _n( '%s image is waiting to be converted in the background.', '%s images are waiting to be converted in the background.', $pending, 'custom-thumbnail-generator' ), number_format_i18n( $pending ) ) ) . '</p>';
		}

		$points = array(
			array( __( 'Does not change what visitors see.', 'custom-thumbnail-generator' ), __( 'Choose which images are served as WebP on the Attachment Summary page.', 'custom-thumbnail-generator' ) ),
			array( __( 'Existing images are not converted.', 'custom-thumbnail-generator' ), __( 'Use "Convert All Thumbnails to WebP" below.', 'custom-thumbnail-generator' ) ),
			array( __( 'Disk space:', 'custom-thumbnail-generator' ), __( 'adds a WebP file for every size. Check your storage limit.', 'custom-thumbnail-generator' ) ),
			array( __( 'Server load:', 'custom-thumbnail-generator' ), __( 'uses CPU and memory while converting; slower on modest hosting.', 'custom-thumbnail-generator' ) ),
			array( __( 'Timing:', 'custom-thumbnail-generator' ), __( 'runs through WP-Cron, so on quiet sites copies can appear late.', 'custom-thumbnail-generator' ) ),
			array( __( 'Already have a WebP tool', 'custom-thumbnail-generator' ), __( '(host, CDN or plugin)? Leave this off.', 'custom-thumbnail-generator' ) ),
			array( __( 'Turning it off', 'custom-thumbnail-generator' ), __( 'stops new conversions; existing copies are kept.', 'custom-thumbnail-generator' ) ),
		);

		$items = '';
		foreach ( $points as $point ) {
			$items .= '<li><strong>' . esc_html( $point[0] ) . '</strong> ' . esc_html( $point[1] ) . '</li>';
		}

		return '<div class="ctgen-auto-card ' . ( $on ? 'is-on' : 'is-off' ) . '" id="ctgen-webp-auto">'
			. '<div class="ctgen-auto-head">'
			. '<span class="dashicons dashicons-update" aria-hidden="true"></span>'
			. '<h2>' . esc_html__( 'Automatic WebP for new images', 'custom-thumbnail-generator' ) . '</h2>'
			. '<span class="ctgen-auto-badge">' . ( $on ? esc_html__( 'On', 'custom-thumbnail-generator' ) : esc_html__( 'Off', 'custom-thumbnail-generator' ) ) . '</span>'
			. '</div>'
			. '<p class="ctgen-auto-lead">' . esc_html__( 'Creates WebP copies of every new JPEG or PNG image and all its thumbnail sizes, in the background, however the image is added. Regenerated thumbnails get fresh copies. GIF and WebP images are skipped.', 'custom-thumbnail-generator' ) . '</p>'
			. '<form method="post" action="options.php" class="ctgen-auto-form">'
			. $fields
			. '<p class="ctgen-auto-control">'
			. '<input type="checkbox" role="switch" class="ctgen-switch" id="ctgen-webp-auto-convert" name="' . esc_attr( self::OPTION ) . '" value="1"' . ( $on ? ' checked="checked"' : '' ) . ' />'
			. '<label for="ctgen-webp-auto-convert">' . esc_html__( 'Create WebP automatically for new uploads and regenerated thumbnails', 'custom-thumbnail-generator' ) . '</label>'
			. '</p>'
			. get_submit_button( __( 'Save setting', 'custom-thumbnail-generator' ), 'primary', 'submit', false )
			. '</form>'
			. $notices
			. '<ul class="ctgen-auto-points">' . $items . '</ul>'
			. '<p class="ctgen-auto-more"><a href="' . esc_url( $summary ) . '">' . esc_html__( 'Open the Attachment Summary page', 'custom-thumbnail-generator' ) . '</a></p>'
			. '</div>';
	}
}
