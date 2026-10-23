<?php
/**
 * AJAX handlers for custom size management and thumbnail regeneration.
 *
 * @package Custom_Thumbnail_Generator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Registers and handles the plugin's existing admin AJAX actions.
 */
class CTGEN_Ajax_Handlers {

	/**
	 * Smallest width/height (px) accepted for a custom size (same as the form).
	 *
	 * @var int
	 */
	const MIN_DIMENSION = 25;

	/**
	 * Largest width/height (px) accepted for a custom size.
	 *
	 * @var int
	 */
	const MAX_DIMENSION = 10000;

	/**
	 * Registers the AJAX actions.
	 */
	public function __construct() {
		/** Action hooks */
		add_action( 'wp_ajax_ctgen_add_custom_size', array( $this, 'ctgen_ajax_add_custom_size' ) );
		add_action( 'wp_ajax_ctgen_remove_custom_size', array( $this, 'ctgen_ajax_remove_custom_size' ) );

		add_action( 'wp_ajax_ctgen_get_attachments', array( $this, 'ctgen_get_all_attachments' ) );
		add_action( 'wp_ajax_ctgen_regenerate_single', array( $this, 'ctgen_regenerate_single_attachment_thumbnail' ) );
		add_action( 'wp_ajax_ctgen_regenerate_single_slug', array( $this, 'ctgen_regenerate_thumbs_for_single_size' ) );

		add_action( 'wp_ajax_ctgen_reload_thumb_list', array( $this, 'ctgen_ajax_load_thumb_list' ) );
	}

	/** Add new thumbnail size */
	public function ctgen_ajax_add_custom_size() {
		check_ajax_referer( 'ctgen_media_actions', 'security' );

		self::authorize();

		$fld_validation_message = __( 'Not all field values are supplied!', 'custom-thumbnail-generator' );

		if ( ! isset( $_POST['ctgen-width'] ) || ! isset( $_POST['ctgen-height'] ) ) {
			wp_send_json_error( $fld_validation_message );
		}

		$width  = self::dimension( sanitize_text_field( wp_unslash( $_POST['ctgen-width'] ) ) );
		$height = self::dimension( sanitize_text_field( wp_unslash( $_POST['ctgen-height'] ) ) );

		if ( ! $width || ! $height ) {
			wp_send_json_error(
				sprintf(
					/* translators: 1: smallest allowed size in pixels, 2: largest allowed size in pixels. */
					__( 'Width and height must be whole numbers from %1$d to %2$d pixels.', 'custom-thumbnail-generator' ),
					self::MIN_DIMENSION,
					self::MAX_DIMENSION
				)
			);
		}

		$crop = isset( $_POST['ctgen-crop'] ) && '' !== sanitize_text_field( wp_unslash( $_POST['ctgen-crop'] ) );
		$slug = 'ctgen_' . $width . 'x' . $height;

		$sizes = get_option( 'ctgen_custom_image_sizes', array() );
		$sizes = is_array( $sizes ) ? $sizes : array();

		if ( isset( $sizes[ $slug ] ) ) {
			wp_send_json_error(
				/* translators: 1: width in pixels, 2: height in pixels. */
				sprintf( __( 'The requested thumbnail size (%1$d px x %2$d px) already exists!', 'custom-thumbnail-generator' ), $width, $height )
			);
		}

		$sizes[ $slug ] = compact( 'width', 'height', 'crop' );
		update_option( 'ctgen_custom_image_sizes', $sizes );

		// Register this image size immediately.
		add_image_size( $slug, $width, $height, $crop );

		$registered_sizes = wp_get_registered_image_subsizes();
		wp_send_json_success(
			array(
				'custom_sizes' => $registered_sizes,
			)
		);
	}

	/** Load custom thumbnail list table */
	public function ctgen_ajax_load_thumb_list() {
		check_ajax_referer( 'ctgen_media_actions', 'security' );

		self::authorize();

		$html_template = CTGEN_TEMPLATE_DIR . 'ctgen-custom-thumb-list-html.php';
		ob_start();
		include $html_template;
		$template = ob_get_clean();

		wp_send_json_success(
			array(
				'success'   => true,
				'data_list' => $template,
			)
		);
	}

	/** Get all attachments */
	public function ctgen_get_all_attachments() {
		check_ajax_referer( 'ctgen_media_actions', 'security' );

		self::authorize();

		$query_args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => -1,
			'orderby'        => 'DATE',
			'order'          => 'ASC',
			'fields'         => 'ids',
		);

		$query = new WP_Query( $query_args );

		wp_send_json_success( array( 'ids' => $query->posts ) );
	}

	/** Generate thumbnails for a single attachment by attachment ID */
	public function ctgen_regenerate_single_attachment_thumbnail() {
		check_ajax_referer( 'ctgen_media_actions', 'security' );

		self::authorize();

		$id = isset( $_POST['attachment_id'] ) ? absint( wp_unslash( $_POST['attachment_id'] ) ) : 0;

		if ( ! $id || 'attachment' !== get_post_type( $id ) || ! wp_attachment_is_image( $id ) ) {
			wp_send_json_error( array( 'message' => __( 'The specified item is not an image attachment.', 'custom-thumbnail-generator' ) ) );
		}

		$file = get_attached_file( $id );

		if ( ! $file || ! file_exists( $file ) ) {
			wp_send_json_error( array( 'message' => __( 'The image file could not be found.', 'custom-thumbnail-generator' ) ) );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		$metadata = wp_generate_attachment_metadata( $id, $file );

		if ( $metadata ) {
			wp_update_attachment_metadata( $id, $metadata );
			wp_send_json_success( array( 'message' => __( 'Success', 'custom-thumbnail-generator' ) ) );
		} else {
			wp_send_json_error( array( 'message' => __( 'Failed', 'custom-thumbnail-generator' ) ) );
		}
	}

	/**
	 * When regenerating thumbnail or a particular slug
	 * Useful when a new size is added and the thumbnails are regenerated for that size only
	 */
	public function ctgen_regenerate_thumbs_for_single_size() {
		check_ajax_referer( 'ctgen_media_actions', 'security' );

		self::authorize();

		if ( ! isset( $_POST['slug'] ) || empty( $_POST['slug'] ) || ! is_string( $_POST['slug'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid thumbnail size info.', 'custom-thumbnail-generator' ) ) );
		}

		$target_size            = sanitize_text_field( wp_unslash( $_POST['slug'] ) );
		$registered_thumb_sizes = wp_get_registered_image_subsizes();

		if ( ! isset( $registered_thumb_sizes[ $target_size ] ) ) {
			wp_send_json_error(
				array(
					'success' => false,
					'message' => __( 'Supplied thumb size is not defined.', 'custom-thumbnail-generator' ),
				)
			);
		}

		$query_args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => -1,
			'post_mime_type' => 'image',
		);

		$attachments = get_posts( $query_args );

		if ( 0 === count( $attachments ) ) {
			wp_send_json_error(
				array(
					'success' => false,
					'message' => __( 'Media library contains no image. Thumbnail generation failed.', 'custom-thumbnail-generator' ),
				)
			);
		}

		$count = 0;

		foreach ( $attachments as $att ) {
			$id   = $att->ID;
			$file = get_attached_file( $id );

			if ( ! $file || ! file_exists( $file ) ) {
				continue;
			}

			$editor = wp_get_image_editor( $file );

			if ( is_wp_error( $editor ) ) {
				continue;
			}

			// Create just the target size. Like core (_wp_make_subsizes), this never
			// upscales: an image smaller than the size gets no file for it.
			// make_subsize() is not part of the WP_Image_Editor base class, so
			// other editors fall back to multi_resize(), as core does.
			if ( method_exists( $editor, 'make_subsize' ) ) {
				$saved = $editor->make_subsize( $registered_thumb_sizes[ $target_size ] );
			} else {
				$made  = $editor->multi_resize( array( $target_size => $registered_thumb_sizes[ $target_size ] ) );
				$saved = isset( $made[ $target_size ] ) ? $made[ $target_size ] : new WP_Error( 'ctgen_no_subsize' );
			}

			if ( is_wp_error( $saved ) || ! is_array( $saved ) || empty( $saved['file'] ) ) {
				continue;
			}

			// Update metadata for the specific size.
			$meta = wp_get_attachment_metadata( $id );
			$meta = is_array( $meta ) ? $meta : array();

			$meta['sizes'][ $target_size ] = array(
				'file'      => wp_basename( $saved['file'] ),
				'width'     => $saved['width'],
				'height'    => $saved['height'],
				'mime-type' => $saved['mime-type'],
			);

			wp_update_attachment_metadata( $id, $meta );

			++$count;
		}

		wp_send_json_success(
			array(
				'success' => true,
				/* translators: 1: number of thumbnails, 2: thumbnail size name. */
				'message' => sprintf( __( '%1$d %2$s thumbnails generated successfully.', 'custom-thumbnail-generator' ), $count, $target_size ),
			)
		);
	}

	/** Remove a custom thumbnail size without removing any existing thumbnail files */
	public function ctgen_ajax_remove_custom_size() {
		check_ajax_referer( 'ctgen_media_actions', 'security' );

		self::authorize();

		if ( ! isset( $_POST['slug'] ) || empty( $_POST['slug'] ) ) {
			wp_send_json_error(
				array(
					'status'  => 'failed',
					'message' => __( 'There has been a critical error! Please try again later.', 'custom-thumbnail-generator' ),
				)
			);
		}

		$slug = sanitize_text_field( wp_unslash( $_POST['slug'] ) );

		$sizes = get_option( 'ctgen_custom_image_sizes', array() );

		if ( isset( $sizes[ $slug ] ) ) {
			unset( $sizes[ $slug ] );
			update_option( 'ctgen_custom_image_sizes', $sizes );
		}

		wp_send_json_success(
			array(
				'success'   => true,
				'size_slug' => $slug,
				/* translators: %s: thumbnail size name. */
				'message'   => sprintf( __( 'Thumbnail %s removed', 'custom-thumbnail-generator' ), $slug ),
			)
		);
	}

	/**
	 * Stops the request unless the current user may manage the plugin.
	 *
	 * @return void
	 */
	private static function authorize() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not authorized to perform this action!', 'custom-thumbnail-generator' ) );
		}
	}

	/**
	 * A submitted width/height: digits only, within MIN_DIMENSION..MAX_DIMENSION.
	 *
	 * @param string $value Submitted value.
	 * @return int The dimension, or 0 if invalid.
	 */
	private static function dimension( $value ) {
		$value = trim( (string) $value );

		if ( ! preg_match( '/^\d{1,6}$/', $value ) ) {
			return 0;
		}

		$value = (int) $value;

		return ( $value >= self::MIN_DIMENSION && $value <= self::MAX_DIMENSION ) ? $value : 0;
	}
}
