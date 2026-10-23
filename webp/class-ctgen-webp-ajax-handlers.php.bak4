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
	 * Image file extensions whose files share a base name with this
	 * attachment's files in a way that would make their WebP copies collide.
	 *
	 * @var string[]
	 */
	const SIBLING_IMAGE_EXTENSIONS = array( 'jpg', 'jpeg', 'jpe', 'png', 'gif', 'webp', 'avif', 'heic', 'heif', 'bmp', 'tif', 'tiff' );

	/**
	 * Converts one attachment's thumbnail(s) to WebP. For 'all_sizes' this
	 * also covers the full-size file WordPress serves (the "-scaled" copy for
	 * large uploads) and, when present, the untouched original upload.
	 *
	 * @param int    $attachment_id Attachment post ID.
	 * @param string $scope         Either 'all_sizes' or 'size'.
	 * @param string $size_slug     Required registered size slug when $scope is 'size'; ignored otherwise.
	 * @param bool   $force         Re-create WebP copies even when a current one exists (still never
	 *                              overwrites another attachment's file or a name-clashing image).
	 * @return array|WP_Error {
	 *     Array on success (even if individual sizes failed/were skipped), WP_Error only when the
	 *     request itself is invalid (bad attachment, bad scope, bad slug).
	 *
	 *     @type int   $attachment_id Echoes the requested attachment ID.
	 *     @type array $sizes         Map of size slug ('full' and 'original_image' for the full-size
	 *                                files) => {outcome, width?, height?, file?, code?, message?}.
	 *     @type array $counts        {converted, already_current, skipped, failed} counts across $sizes.
	 * }
	 */
	public function convert_attachment( $attachment_id, $scope, $size_slug, $force = false ) {
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

		$files_to_process = 'all_sizes' === $scope ? $this->full_size_entries( $metadata, $attached_file ) : array();

		foreach ( $size_slugs_to_process as $slug ) {
			$files_to_process[ $slug ] = isset( $existing_sizes[ $slug ] ) && is_array( $existing_sizes[ $slug ] ) ? $existing_sizes[ $slug ] : array();
		}

		$name_conflict = $this->has_name_conflict( $thumbnail_dir, $attached_file, $metadata );

		$sizes  = array();
		$counts = array(
			'converted'       => 0,
			'already_current' => 0,
			'skipped'         => 0,
			'failed'          => 0,
		);

		foreach ( $files_to_process as $slug => $entry ) {
			if ( $name_conflict ) {
				$outcome = array(
					'outcome' => 'failed',
					'code'    => 'ctgen_webp_name_conflict',
					'message' => $this->safe_error_message( 'ctgen_webp_name_conflict' ),
				);
			} else {
				$outcome = $this->process_file( $entry, $thumbnail_dir, $attachment_id, $force );
			}

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
			// same way here. The full-size files are added separately by
			// full_size_entries(), never mixed into this list.
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
	 * Entries for the full-size files: the file WordPress serves as "full"
	 * (the "-scaled" copy for large uploads) and the original upload that
	 * WordPress keeps when it scales an image down.
	 *
	 * @param array  $metadata      The attachment's metadata.
	 * @param string $attached_file Absolute path of the attached (full-size) file.
	 * @return array Map of 'full' / 'original_image' => {file, width, height}.
	 */
	private function full_size_entries( $metadata, $attached_file ) {
		$entries = array(
			'full' => array(
				'file'   => wp_basename( $attached_file ),
				'width'  => isset( $metadata['width'] ) ? (int) $metadata['width'] : 0,
				'height' => isset( $metadata['height'] ) ? (int) $metadata['height'] : 0,
			),
		);

		if ( ! empty( $metadata['original_image'] ) && is_string( $metadata['original_image'] ) && wp_basename( $metadata['original_image'] ) !== wp_basename( $attached_file ) ) {
			// The original's dimensions are not stored in metadata; process_file() reads them from the file.
			$entries['original_image'] = array(
				'file'   => wp_basename( $metadata['original_image'] ),
				'width'  => 0,
				'height' => 0,
			);
		}

		return $entries;
	}

	/**
	 * Whether another image file in the same folder shares this attachment's
	 * base name with a different extension (e.g. photo.jpg and photo.png).
	 * Their WebP copies would have identical names, so neither may be written.
	 *
	 * Uses only files on disk, except for a same-named .webp file, which may be
	 * this attachment's own earlier copy: that one counts as a conflict only
	 * when it is a Media Library item of its own.
	 *
	 * @param string $directory     Absolute, trailing-slashed directory of the attachment.
	 * @param string $attached_file Absolute path of the attached file.
	 * @param array  $metadata      The attachment's metadata.
	 * @return bool
	 */
	private function has_name_conflict( $directory, $attached_file, $metadata ) {
		$own_files = array( wp_basename( $attached_file ) );

		if ( ! empty( $metadata['original_image'] ) && is_string( $metadata['original_image'] ) ) {
			$own_files[] = wp_basename( $metadata['original_image'] );
		}

		// Sub-sizes and WebP copies are named after the original upload, not the "-scaled" copy.
		$base = pathinfo( end( $own_files ), PATHINFO_FILENAME );

		foreach ( array( $base, $base . '-scaled' ) as $name ) {
			foreach ( self::SIBLING_IMAGE_EXTENSIONS as $extension ) {
				$candidate = $name . '.' . $extension;

				if ( in_array( $candidate, $own_files, true ) || ! file_exists( $directory . $candidate ) ) {
					continue;
				}

				if ( 'webp' !== $extension || $this->is_attachment_file( $directory . $candidate ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Whether a file is the main file of a Media Library item.
	 *
	 * @param string $path Absolute file path inside the uploads directory.
	 * @return bool
	 */
	private function is_attachment_file( $path ) {
		return 0 !== $this->attachment_id_for_file( $path );
	}

	/**
	 * ID of the Media Library item whose main file is $path, or 0.
	 *
	 * @param string $path Absolute file path inside the uploads directory.
	 * @return int
	 */
	private function attachment_id_for_file( $path ) {
		$uploads = wp_get_upload_dir();
		$basedir = trailingslashit( $uploads['basedir'] );

		if ( empty( $uploads['basedir'] ) || 0 !== strpos( $path, $basedir ) ) {
			return 0;
		}

		return (int) attachment_url_to_postid( trailingslashit( $uploads['baseurl'] ) . substr( $path, strlen( $basedir ) ) );
	}

	/**
	 * Converts (or reports on) one file of an attachment: a thumbnail size or
	 * one of the full-size files.
	 *
	 * @param array  $entry         {file, width, height} of the source file; width/height may be 0 (read from the file).
	 * @param string $thumbnail_dir Absolute, trailing-slashed directory containing the attachment's files.
	 * @param int    $attachment_id Attachment ID (another attachment's file is never overwritten).
	 * @param bool   $force         Skip the "already current" check and convert again.
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
	private function process_file( $entry, $thumbnail_dir, $attachment_id, $force = false ) {
		if ( empty( $entry['file'] ) || ! is_string( $entry['file'] ) ) {
			return array(
				'outcome' => 'skipped',
				'reason'  => 'no_file_for_size',
			);
		}

		// Thumbnails always sit beside the original, so only the basename is ever used.
		$thumbnail_file = wp_basename( $entry['file'] );
		$source_path    = $thumbnail_dir . $thumbnail_file;

		if ( ! file_exists( $source_path ) ) {
			return array(
				'outcome' => 'skipped',
				'reason'  => 'source_missing',
			);
		}

		$destination_path = $thumbnail_dir . pathinfo( $thumbnail_file, PATHINFO_FILENAME ) . '.webp';

		// Never replace a file that is a Media Library item of its own (e.g. an uploaded photo.webp).
		if ( $destination_path !== $source_path && file_exists( $destination_path ) ) {
			$owner = $this->attachment_id_for_file( $destination_path );

			if ( 0 !== $owner && (int) $attachment_id !== $owner ) {
				return array(
					'outcome' => 'failed',
					'code'    => 'ctgen_webp_destination_in_use',
					'message' => $this->safe_error_message( 'ctgen_webp_destination_in_use' ),
				);
			}
		}

		if ( empty( $entry['width'] ) || empty( $entry['height'] ) ) {
			$source_info = wp_getimagesize( $source_path );

			if ( false !== $source_info ) {
				$entry['width']  = (int) $source_info[0];
				$entry['height'] = (int) $source_info[1];
			}
		}

		$already_current = $force ? false : $this->verify_existing_webp( $destination_path, $entry );

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
			'ctgen_webp_name_conflict'           => __( 'Another image in the same folder has the same name with a different extension, so both WebP copies would get the same file name. Nothing was written for this image.', 'custom-thumbnail-generator' ),
			'ctgen_webp_destination_in_use'      => __( 'A Media Library file already uses this WebP file name. It was left untouched.', 'custom-thumbnail-generator' ),
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
