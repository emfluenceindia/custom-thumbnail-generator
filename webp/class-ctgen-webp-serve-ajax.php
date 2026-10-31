<?php
/**
 * Admin endpoint for turning "serve WebP" on or off for one image.
 *
 * Same nonce, capability and sanitising pattern as the plugin's other AJAX
 * handlers. Never returns a filesystem path to the browser.
 *
 * @package Custom_Thumbnail_Generator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * The per-image "Serve WebP" toggle.
 */
class CTGEN_Webp_Serve_Ajax {

	/**
	 * Manifest service.
	 *
	 * @var CTGEN_Webp_Manifest
	 */
	private $manifest;

	/**
	 * WebP handlers (the conversion engine).
	 *
	 * @var CTGEN_Webp_Ajax_Handlers
	 */
	private $handlers;

	/**
	 * Sets up the endpoint.
	 *
	 * @param CTGEN_Webp_Manifest      $manifest Manifest service.
	 * @param CTGEN_Webp_Ajax_Handlers $handlers WebP handlers.
	 */
	public function __construct( $manifest, $handlers ) {
		$this->manifest = $manifest;
		$this->handlers = $handlers;
	}

	/**
	 * Hooks the AJAX action.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_ajax_ctgen_webp_set_serve', array( $this, 'ajax_set_serve' ) );
	}

	/**
	 * AJAX: turns serving on or off for one image and returns the new state
	 * and the refreshed "Serve WebP" cell.
	 *
	 * @return void
	 */
	public function ajax_set_serve() {
		check_ajax_referer( 'ctgen_media_actions', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not authorized to perform this action!', 'custom-thumbnail-generator' ) );
		}

		$id    = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
		$state = isset( $_POST['state'] ) ? sanitize_text_field( wp_unslash( $_POST['state'] ) ) : '';

		$result = $this->set_serve( $id, $state );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				)
			);
		}

		wp_send_json_success(
			array(
				'state' => $result['state'],
				'html'  => CTGEN_Attachment_Summary::render_serve_cell( $id ),
			)
		);
	}

	/**
	 * Turns serving on or off for one image.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $state         'on' or 'off'.
	 * @return array|WP_Error {state}
	 */
	public function set_serve( $attachment_id, $state ) {
		if ( ! in_array( $state, array( 'on', 'off' ), true ) ) {
			return new WP_Error(
				'ctgen_webp_invalid_state',
				__( 'Serving can only be turned on or off.', 'custom-thumbnail-generator' )
			);
		}

		$was_on = metadata_exists( 'post', $attachment_id, CTGEN_Webp_Manifest::META_KEY );

		if ( 'off' === $state ) {
			if ( $was_on ) {
				$this->manifest->delete( $attachment_id );
				$this->announce( $attachment_id, 'off' );
			}

			return array( 'state' => 'off' );
		}

		$preview = $this->manifest->build( $attachment_id );

		if ( is_wp_error( $preview ) ) {
			return $preview;
		}

		if ( array() === $preview['sizes'] ) {
			return new WP_Error(
				'ctgen_webp_nothing_to_serve',
				__( 'This image has no valid WebP copy to serve yet. Generate WebP first.', 'custom-thumbnail-generator' )
			);
		}

		$this->manifest->enable( $attachment_id );

		if ( ! $was_on ) {
			$this->announce( $attachment_id, 'on' );
		}

		return array( 'state' => 'on' );
	}

	/**
	 * Tells site owners and cache plugins that an image's output changed,
	 * so they can purge cached pages.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $state         'on' or 'off'.
	 * @return void
	 */
	private function announce( $attachment_id, $state ) {
		/**
		 * Fires after "serve WebP" was turned on or off for an image. Pages
		 * showing it render different image URLs from now on; purge any page
		 * cache or CDN copy of them here.
		 *
		 * @param int    $attachment_id Attachment ID.
		 * @param string $state         'on' or 'off'.
		 */
		do_action( 'ctgen_webp_serving_changed', (int) $attachment_id, $state );
	}
}
