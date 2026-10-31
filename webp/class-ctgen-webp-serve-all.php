<?php
/**
 * The "Serve WebP" switch for all images.
 *
 * Switching on serves every JPEG/PNG image that has a valid, smaller WebP
 * (every rule is CTGEN_Webp_Manifest's: sizes whose WebP is larger keep their
 * original, images that are already WebP are never candidates) and keeps
 * serving images whose WebP copies are created later. Switching off serves
 * the originals of every image again. Images are switched in small batches,
 * one AJAX request each, through the same code path as the per-image switch.
 *
 * @package Custom_Thumbnail_Generator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Serving WebP for all images at once.
 */
class CTGEN_Webp_Serve_All {

	/**
	 * Option holding whether the switch for all images is on.
	 *
	 * @var string
	 */
	const OPTION = 'ctgen_webp_serve_all';

	/**
	 * Most visible rows whose cells one batch returns.
	 *
	 * @var int
	 */
	const MAX_VISIBLE = 200;

	/**
	 * The per-image switch (turns one image on or off).
	 *
	 * @var CTGEN_Webp_Serve_Ajax
	 */
	private $serve;

	/**
	 * Sets up the service.
	 *
	 * @param CTGEN_Webp_Serve_Ajax $serve The per-image switch.
	 */
	public function __construct( $serve ) {
		$this->serve = $serve;
	}

	/**
	 * Hooks into WordPress.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_ajax_ctgen_webp_serve_all', array( $this, 'ajax_serve_all' ) );
		// After the manifest's own rebuild (priority 10).
		add_action( 'ctgen_webp_files_changed', array( $this, 'on_files_changed' ), 20 );
	}

	/**
	 * Whether the switch for all images is on.
	 *
	 * @return bool
	 */
	public function is_on() {
		return (bool) get_option( self::OPTION, false );
	}

	/**
	 * Remembers the new position of the switch. Images are switched by
	 * run_batch() afterwards.
	 *
	 * @param string $state 'on' or 'off'.
	 * @return true|WP_Error
	 */
	public function start( $state ) {
		if ( ! in_array( $state, array( 'on', 'off' ), true ) ) {
			return new WP_Error(
				'ctgen_webp_invalid_state',
				__( 'Serving can only be turned on or off.', 'custom-thumbnail-generator' )
			);
		}

		update_option( self::OPTION, 'on' === $state ? 1 : 0, false );

		return true;
	}

	/**
	 * How many images a run will go through: JPEG/PNG images when switching
	 * on, serving images when switching off.
	 *
	 * @param string $state 'on' or 'off'.
	 * @return int
	 */
	public function count( $state ) {
		global $wpdb;

		if ( 'on' === $state ) {
			list( $jpeg, $png ) = self::source_types();

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- One count per run.
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status <> 'trash' AND post_mime_type IN (%s, %s)",
					$jpeg,
					$png
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- One count per run.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s", CTGEN_Webp_Manifest::META_KEY ) );
	}

	/**
	 * Switches the next batch of images above $after, lowest ID first.
	 *
	 * @param string $state 'on' or 'off'.
	 * @param int    $after Last ID handled by the previous batch (0 to begin).
	 * @return array|WP_Error {ids, served, skipped, next, done}
	 */
	public function run_batch( $state, $after ) {
		if ( ! in_array( $state, array( 'on', 'off' ), true ) ) {
			return new WP_Error(
				'ctgen_webp_invalid_state',
				__( 'Serving can only be turned on or off.', 'custom-thumbnail-generator' )
			);
		}

		/**
		 * Filters how many images one "Serve WebP for all images" request switches.
		 *
		 * @param int $batch Batch size. Default 20.
		 */
		$limit   = max( 1, (int) apply_filters( 'ctgen_webp_serve_all_batch', 20 ) );
		$ids     = 'on' === $state ? $this->candidate_ids_after( (int) $after, $limit ) : $this->serving_ids_after( (int) $after, $limit );
		$served  = 0;
		$skipped = 0;

		foreach ( $ids as $id ) {
			$result = $this->serve->set_serve( $id, $state );

			if ( is_wp_error( $result ) ) {
				++$skipped;
			} elseif ( 'on' === $result['state'] ) {
				++$served;
			}
		}

		return array(
			'ids'     => $ids,
			'served'  => $served,
			'skipped' => $skipped,
			'next'    => $ids ? (int) end( $ids ) : (int) $after,
			'done'    => count( $ids ) < $limit,
		);
	}

	/**
	 * Serves an image whose WebP copies were just created, while the switch
	 * for all images is on. Images with nothing to serve are left alone.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	public function on_files_changed( $attachment_id ) {
		if ( ! $this->is_on() || metadata_exists( 'post', $attachment_id, CTGEN_Webp_Manifest::META_KEY ) ) {
			return;
		}

		$this->serve->set_serve( $attachment_id, 'on' );
	}

	/**
	 * AJAX: step 'start' remembers the new position and returns the total;
	 * step 'batch' switches one batch and returns its counts plus the
	 * refreshed "Serve WebP" cells of the visible rows it changed.
	 *
	 * @return void
	 */
	public function ajax_serve_all() {
		check_ajax_referer( 'ctgen_media_actions', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not authorized to perform this action!', 'custom-thumbnail-generator' ) );
		}

		$step  = isset( $_POST['step'] ) ? sanitize_text_field( wp_unslash( $_POST['step'] ) ) : '';
		$state = isset( $_POST['state'] ) ? sanitize_text_field( wp_unslash( $_POST['state'] ) ) : '';

		if ( 'start' === $step ) {
			$started = $this->start( $state );

			if ( is_wp_error( $started ) ) {
				$this->send_error( $started );
			}

			wp_send_json_success(
				array(
					'state' => $state,
					'total' => $this->count( $state ),
				)
			);
		}

		$after   = isset( $_POST['after'] ) ? absint( wp_unslash( $_POST['after'] ) ) : 0;
		$visible = isset( $_POST['visible'] ) ? array_slice( array_map( 'absint', (array) wp_unslash( $_POST['visible'] ) ), 0, self::MAX_VISIBLE ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each value is cast with absint().
		$result  = $this->run_batch( $state, $after );

		if ( is_wp_error( $result ) ) {
			$this->send_error( $result );
		}

		$cells = array();

		foreach ( array_intersect( $result['ids'], $visible ) as $id ) {
			$cells[ $id ] = CTGEN_Attachment_Summary::render_serve_cell( $id );
		}

		wp_send_json_success(
			array(
				'state'     => $state,
				'processed' => count( $result['ids'] ),
				'served'    => $result['served'],
				'skipped'   => $result['skipped'],
				'next'      => $result['next'],
				'done'      => $result['done'],
				'cells'     => (object) $cells,
			)
		);
	}

	/**
	 * Sends a WP_Error as a JSON error response.
	 *
	 * @param WP_Error $error Error.
	 * @return void
	 */
	private function send_error( $error ) {
		wp_send_json_error(
			array(
				'code'    => $error->get_error_code(),
				'message' => $error->get_error_message(),
			)
		);
	}

	/**
	 * MIME types of the images that can be served as WebP (JPEG and PNG, as
	 * the converter accepts). The queries hold one placeholder for each.
	 *
	 * @return string[]
	 */
	private static function source_types() {
		return array_values( CTGEN_Webp_Converter::ALLOWED_SOURCE_MIME_TYPES );
	}

	/**
	 * IDs of JPEG/PNG images (not in the trash) above $after, lowest first.
	 *
	 * @param int $after Last ID handled.
	 * @param int $limit How many.
	 * @return int[]
	 */
	private function candidate_ids_after( $after, $limit ) {
		global $wpdb;

		list( $jpeg, $png ) = self::source_types();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Batched admin job; a keyset query on the primary key.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status <> 'trash' AND post_mime_type IN (%s, %s) AND ID > %d ORDER BY ID ASC LIMIT %d",
				$jpeg,
				$png,
				$after,
				$limit
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * IDs of serving images above $after, lowest first.
	 *
	 * @param int $after Last ID handled.
	 * @param int $limit How many.
	 * @return int[]
	 */
	private function serving_ids_after( $after, $limit ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Batched admin job; a keyset query on indexed columns.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id > %d ORDER BY post_id ASC LIMIT %d",
				CTGEN_Webp_Manifest::META_KEY,
				$after,
				$limit
			)
		);

		return array_map( 'intval', $ids );
	}
}
