<?php
/**
 * Secure AJAX operations for bulk/per-size WebP thumbnail conversion.
 *
 * Wraps the milestone 1 CTGEN_Webp_Converter service with the same
 * nonce/capability pattern used by the plugin's existing AJAX handlers.
 * Every privileged method here is bounded to exactly one attachment per
 * request, and never accepts a client-supplied filesystem path: callers
 * may only pass an attachment ID and, optionally, a registered size slug.
 *
 * @package Custom_Thumbnail_Generator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * AJAX handlers for the WebP conversion feature.
 */
class CTGEN_Webp_Ajax_Handlers {

	/**
	 * The converter service used to perform each individual file conversion.
	 *
	 * @var CTGEN_Webp_Converter
	 */
	private $converter;

	/**
	 * Registers the AJAX actions.
	 *
	 * @param CTGEN_Webp_Converter|null $converter Optional converter instance, for testability.
	 */
	public function __construct( $converter = null ) {
		$this->converter = $converter ? $converter : new CTGEN_Webp_Converter();

		add_action( 'wp_ajax_ctgen_webp_get_eligible_attachments', array( $this, 'ajax_get_eligible_attachments' ) );
		add_action( 'wp_ajax_ctgen_webp_convert_attachment', array( $this, 'ajax_convert_attachment' ) );
	}

	/**
	 * AJAX entry point: returns the IDs of image attachments eligible for
	 * WebP conversion. Unlike the existing ctgen_get_attachments handler,
	 * this is explicitly filtered to JPEG and PNG images only (GIF is excluded).
	 *
	 * @return void
	 */
	public function ajax_get_eligible_attachments() {
		check_ajax_referer( 'ctgen_media_actions', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not authorized to perform this action!', 'custom-thumbnail-generator' ) );
		}

		wp_send_json_success(
			array(
				'ids' => $this->get_eligible_attachment_ids(),
			)
		);
	}

	/**
	 * AJAX entry point: converts one attachment's thumbnails to WebP, either
	 * for all sizes represented in its metadata or for one validated,
	 * registered size slug.
	 *
	 * @return void
	 */
	public function ajax_convert_attachment() {
		check_ajax_referer( 'ctgen_media_actions', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not authorized to perform this action!', 'custom-thumbnail-generator' ) );
		}

		$attachment_id = isset( $_POST['attachment_id'] ) ? absint( wp_unslash( $_POST['attachment_id'] ) ) : 0;
		$scope         = isset( $_POST['scope'] ) ? sanitize_text_field( wp_unslash( $_POST['scope'] ) ) : '';
		$size          = isset( $_POST['size'] ) ? sanitize_text_field( wp_unslash( $_POST['size'] ) ) : '';

		$result = $this->convert_attachment( $attachment_id, $scope, $size );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				)
			);
		}

		wp_send_json_success( $result );
	}

	/**
	 * Returns the IDs of every JPEG and PNG attachment currently in the media
	 * library. Public (not just an AJAX callback) so it can be unit tested
	 * directly and reused by the future UI milestone without going through
	 * an HTTP round-trip.
	 *
	 * @return int[]
	 */
	public function get_eligible_attachment_ids() {
		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => CTGEN_Webp_Converter::ALLOWED_SOURCE_MIME_TYPES,
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
			)
		);

		return $query->posts;
	}

	/**
	 * Converts one attachment's thumbnail(s) to WebP.
	 *
	 * @param int    $attachment_id Attachment post ID.
	 * @param string $scope         Either 'all_sizes' or 'size'.
	 * @param string $size_slug     Required registered size slug when $scope is 'size'; ignored otherwise.
	 * @return array|WP_Error {
	 *     Array on success (even if individual sizes failed/were skipped), WP_Error only when the
	 *     request itself is invalid (bad attachment, bad scope, bad slug).
	 *
	 *     @type int   $attachment_id Echoes the requested attachment ID.
	 *     @type array $sizes         Map of size slug => {outcome, width?, height?, file?, code?, message?}.
	 *     @type array $counts        {converted, already_current, skipped, failed} counts across $sizes.
	 * }
	 */
	public function convert_attachment( $attachment_id, $scope, $size_slug ) {
		if ( $attachment_id <= 0 ) {
			return new WP_Error(
				'ctgen_webp_invalid_attachment',
				__( 'No valid attachment was specified.', 'custom-thumbnail-generator' )
			);
		}

		if ( 'attachment' !== get_post_type( $attachment_id ) || ! wp_attachment_is_image( $attachment_id ) ) {
			return new WP_Error(
				'ctgen_webp_not_an_image',
				__( 'The specified item is not an image attachment.', 'custom-thumbnail-generator' )
			);
		}

		$attachment_mime = get_post_mime_type( $attachment_id );

		if ( CTGEN_Webp_Converter::EXCLUDED_GIF_MIME_TYPE === $attachment_mime ) {
			return new WP_Error(
				'ctgen_webp_gif_excluded',
				$this->safe_error_message( 'ctgen_webp_gif_excluded' )
			);
		}

		if ( ! in_array( $attachment_mime, CTGEN_Webp_Converter::ALLOWED_SOURCE_MIME_TYPES, true ) ) {
			return new WP_Error(
				'ctgen_webp_unsupported_attachment_type',
				__( 'Only JPEG and PNG images can be converted to WebP.', 'custom-thumbnail-generator' )
			);
		}

		if ( ! in_array( $scope, array( 'all_sizes', 'size' ), true ) ) {
			return new WP_Error(
				'ctgen_webp_invalid_scope',
				__( 'The conversion scope must be either "all_sizes" or "size".', 'custom-thumbnail-generator' )
			);
		}

		$metadata = wp_get_attachment_metadata( $attachment_id );

		if ( empty( $metadata ) || ! is_array( $metadata ) ) {
			return new WP_Error(
				'ctgen_webp_no_metadata',
				__( 'This attachment has no thumbnail metadata to convert.', 'custom-thumbnail-generator' )
			);
		}

		$existing_sizes = isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ? $metadata['sizes'] : array();

		$size_slugs_to_process = $this->resolve_sizes_to_process( $existing_sizes, $scope, $size_slug );

		if ( is_wp_error( $size_slugs_to_process ) ) {
			return $size_slugs_to_process;
		}

		$attached_file = get_attached_file( $attachment_id );

		if ( ! is_string( $attached_file ) || '' === $attached_file ) {
			return new WP_Error(
				'ctgen_webp_no_metadata',
				__( 'This attachment has no thumbnail metadata to convert.', 'custom-thumbnail-generator' )
			);
		}

		$thumbnail_dir = trailingslashit( dirname( $attached_file ) );

		$sizes  = array();
		$counts = array(
			'converted'       => 0,
			'already_current' => 0,
			'skipped'         => 0,
			'failed'          => 0,
		);

		foreach ( $size_slugs_to_process as $slug ) {
			$outcome        = $this->process_size( $existing_sizes, $slug, $thumbnail_dir );
			$sizes[ $slug ] = $outcome;
			++$counts[ $outcome['outcome'] ];
		}

		return array(
			'attachment_id' => $attachment_id,
			'sizes'         => $sizes,
			'counts'        => $counts,
		);
	}

	/**
	 * Determines which size slugs to attempt, and validates a client-chosen
	 * single slug against WordPress's currently registered sizes.
	 *
	 * @param array  $existing_sizes The attachment's existing 'sizes' metadata.
	 * @param string $scope          'all_sizes' or 'size'.
	 * @param string $size_slug      The requested slug when $scope is 'size'.
	 * @return string[]|WP_Error
	 */
	private function resolve_sizes_to_process( $existing_sizes, $scope, $size_slug ) {
		if ( 'all_sizes' === $scope ) {
			// Every size actually represented in this attachment's own metadata -
			// core, theme, and plugin-registered sizes are all represented the
			// same way here, and the full-size original is never a member of
			// 'sizes', so it can never be reached from this list.
			return array_keys( $existing_sizes );
		}

		if ( '' === $size_slug ) {
			return new WP_Error(
				'ctgen_webp_missing_size',
				__( 'A thumbnail size must be specified for a single-size conversion.', 'custom-thumbnail-generator' )
			);
		}

		$registered_sizes = wp_get_registered_image_subsizes();

		if ( ! isset( $registered_sizes[ $size_slug ] ) ) {
			return new WP_Error(
				'ctgen_webp_unknown_size',
				__( 'The requested thumbnail size is not a registered size.', 'custom-thumbnail-generator' )
			);
		}

		return array( $size_slug );
	}

	/**
	 * Converts (or reports on) a single size for an attachment whose
	 * metadata and thumbnail directory are already resolved.
	 *
	 * @param array  $existing_sizes The attachment's existing 'sizes' metadata.
	 * @param string $slug           The size slug to process.
	 * @param string $thumbnail_dir  Absolute, trailing-slashed directory containing the thumbnail files.
	 * @return array {
	 *     @type string $outcome One of 'converted', 'already_current', 'skipped', 'failed'.
	 *     @type int    $width   Present when outcome is 'converted' or 'already_current'.
	 *     @type int    $height  Present when outcome is 'converted' or 'already_current'.
	 *     @type string $file    Present when outcome is 'converted' or 'already_current'.
	 *     @type string $reason  Present when outcome is 'skipped'.
	 *     @type string $code    Present when outcome is 'failed'.
	 *     @type string $message Present when outcome is 'failed'.
	 * }
	 */
	private function process_size( $existing_sizes, $slug, $thumbnail_dir ) {
		if ( empty( $existing_sizes[ $slug ]['file'] ) || ! is_string( $existing_sizes[ $slug ]['file'] ) ) {
			return array(
				'outcome' => 'skipped',
				'reason'  => 'no_file_for_size',
			);
		}

		// Thumbnails always sit beside the original, so only the basename is ever used.
		$thumbnail_file = wp_basename( $existing_sizes[ $slug ]['file'] );
		$source_path    = $thumbnail_dir . $thumbnail_file;

		if ( ! file_exists( $source_path ) ) {
			return array(
				'outcome' => 'skipped',
				'reason'  => 'source_missing',
			);
		}

		$destination_path = $thumbnail_dir . pathinfo( $thumbnail_file, PATHINFO_FILENAME ) . '.webp';

		$already_current = $this->verify_existing_webp( $destination_path, $existing_sizes[ $slug ] );

		if ( false !== $already_current ) {
			return array(
				'outcome' => 'already_current',
				'width'   => $already_current['width'],
				'height'  => $already_current['height'],
				'file'    => wp_basename( $destination_path ),
			);
		}

		$result = $this->converter->convert( $source_path, $destination_path );

		if ( is_wp_error( $result ) ) {
			return array(
				'outcome' => 'failed',
				'code'    => $result->get_error_code(),
				'message' => $this->safe_error_message( $result->get_error_code() ),
			);
		}

		return array(
			'outcome' => 'converted',
			'width'   => $result['width'],
			'height'  => $result['height'],
			'file'    => $result['file'],
		);
	}

	/**
	 * Checks whether a destination WebP file already exists and is a
	 * genuinely valid WebP whose dimensions match the source thumbnail's
	 * recorded metadata. Only such a verified match is treated as
	 * "already current" so a stale or corrupt leftover is never trusted.
	 *
	 * @param string $destination_path Absolute path the WebP would be written to.
	 * @param array  $size_meta        The source size's metadata entry (must contain 'width'/'height').
	 * @return array|false Width/height on a verified match, false otherwise.
	 */
	private function verify_existing_webp( $destination_path, $size_meta ) {
		if ( ! file_exists( $destination_path ) ) {
			return false;
		}

		$image_info = wp_getimagesize( $destination_path );

		if ( false === $image_info || empty( $image_info['mime'] ) || 'image/webp' !== $image_info['mime'] ) {
			return false;
		}

		if ( empty( $size_meta['width'] ) || empty( $size_meta['height'] ) ) {
			return false;
		}

		if ( (int) $size_meta['width'] !== (int) $image_info[0] || (int) $size_meta['height'] !== (int) $image_info[1] ) {
			return false;
		}

		return array(
			'width'  => (int) $image_info[0],
			'height' => (int) $image_info[1],
		);
	}

	/**
	 * Maps a converter error code to a safe, generic, translatable message
	 * that never repeats a filesystem path back to the client.
	 *
	 * @param string $error_code One of CTGEN_Webp_Converter's WP_Error codes.
	 * @return string
	 */
	private function safe_error_message( $error_code ) {
		$messages = array(
			'ctgen_webp_source_not_found'        => __( 'The source thumbnail file could not be found.', 'custom-thumbnail-generator' ),
			'ctgen_webp_source_unreadable'       => __( 'The source thumbnail file could not be read.', 'custom-thumbnail-generator' ),
			'ctgen_webp_source_corrupt'          => __( 'The source thumbnail file appears to be corrupt.', 'custom-thumbnail-generator' ),
			'ctgen_webp_unsupported_source_type' => __( 'This thumbnail is not in a supported format.', 'custom-thumbnail-generator' ),
			'ctgen_webp_gif_excluded'            => __( 'GIF images are not converted to WebP. Animated GIFs would lose their animation, so all GIF files are skipped.', 'custom-thumbnail-generator' ),
			'ctgen_webp_invalid_destination'     => __( 'The WebP file could not be written to its destination.', 'custom-thumbnail-generator' ),
			'ctgen_webp_editor_unavailable'      => __( 'No image editor is available to process this thumbnail.', 'custom-thumbnail-generator' ),
			'ctgen_webp_output_unsupported'      => __( 'This server cannot produce WebP output.', 'custom-thumbnail-generator' ),
			'ctgen_webp_save_failed'             => __( 'The WebP file could not be saved.', 'custom-thumbnail-generator' ),
			'ctgen_webp_output_invalid'          => __( 'The conversion did not produce a valid WebP file.', 'custom-thumbnail-generator' ),
		);

		return isset( $messages[ $error_code ] )
			? $messages[ $error_code ]
			: __( 'The thumbnail could not be converted.', 'custom-thumbnail-generator' );
	}
}
