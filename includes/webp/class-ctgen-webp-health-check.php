<?php
/**
 * Keeps "serve WebP" manifests current.
 *
 * The front end never checks files, so a manifest is the only thing between
 * a missing WebP and a broken image. This class rebuilds an attachment's
 * manifest whenever its files change. It has no rules of its own: every
 * decision is CTGEN_Webp_Manifest's. It never writes or deletes a file and
 * never turns serving on or off.
 *
 * @package Custom_Thumbnail_Generator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Lifecycle rebuilds for serve manifests.
 */
class CTGEN_Webp_Health_Check {

	/**
	 * Option holding the last attachment ID the scheduled run handled.
	 *
	 * @var string
	 */
	const CURSOR_OPTION = 'ctgen_webp_health_cursor';

	/**
	 * Cron hook of the scheduled run.
	 *
	 * @var string
	 */
	const HOOK = 'ctgen_webp_health_check';

	/**
	 * Manifest service.
	 *
	 * @var CTGEN_Webp_Manifest
	 */
	private $manifest;

	/**
	 * Sets up the health check.
	 *
	 * @param CTGEN_Webp_Manifest $manifest Manifest service.
	 */
	public function __construct( $manifest ) {
		$this->manifest = $manifest;
	}

	/**
	 * Hooks into WordPress.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'added_post_meta', array( $this, 'on_meta_change' ), 10, 3 );
		add_action( 'updated_post_meta', array( $this, 'on_meta_change' ), 10, 3 );
		add_action( 'ctgen_webp_files_changed', array( $this->manifest, 'rebuild' ) );
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( 'admin_init', array( $this, 'maybe_schedule' ) );
	}

	/**
	 * Removes everything register() added.
	 *
	 * @return void
	 */
	public function unregister() {
		remove_action( 'added_post_meta', array( $this, 'on_meta_change' ), 10 );
		remove_action( 'updated_post_meta', array( $this, 'on_meta_change' ), 10 );
		remove_action( 'ctgen_webp_files_changed', array( $this->manifest, 'rebuild' ) );
		remove_action( self::HOOK, array( $this, 'run' ) );
		remove_action( 'admin_init', array( $this, 'maybe_schedule' ) );
	}

	/**
	 * Plugin activation: schedules the run on this site.
	 *
	 * @return void
	 */
	public function activate() {
		$this->maybe_schedule();
	}

	/**
	 * Schedules the run on this site unless it already is. Hooked to
	 * admin_init, so every site of a network gets it (a network activation
	 * runs the activation hook once only).
	 *
	 * @return void
	 */
	public function maybe_schedule() {
		if ( wp_next_scheduled( self::HOOK ) ) {
			return;
		}

		/**
		 * Filters how often the health check runs.
		 *
		 * @param string $interval A recurrence known to wp_get_schedules(). Default 'daily'.
		 */
		$interval = apply_filters( 'ctgen_webp_health_check_interval', 'daily' );

		if ( ! is_string( $interval ) || ! array_key_exists( $interval, wp_get_schedules() ) ) {
			$interval = 'daily';
		}

		wp_schedule_event( time(), $interval, self::HOOK );
	}

	/**
	 * Plugin deactivation: removes the scheduled run. Serving state is kept,
	 * so reactivating restores it.
	 *
	 * @param bool $network_wide Whether the plugin is being deactivated network-wide.
	 * @return void
	 */
	public function deactivate( $network_wide ) {
		$unschedule = function () {
			wp_clear_scheduled_hook( self::HOOK );
		};

		if ( ! $network_wide || ! $this->is_network() ) {
			$unschedule();
			return;
		}

		foreach ( $this->site_ids() as $site_id ) {
			$this->on_site( $site_id, $unschedule );
		}
	}

	/**
	 * Whether this is a multisite network. Separate so tests can simulate one.
	 *
	 * @return bool
	 */
	protected function is_network() {
		return is_multisite();
	}

	/**
	 * IDs of every site in the network.
	 *
	 * @return int[]
	 */
	protected function site_ids() {
		return array_map(
			'intval',
			get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			)
		);
	}

	/**
	 * Runs $callback in the context of site $site_id.
	 *
	 * @param int      $site_id  Site ID.
	 * @param callable $callback What to do there.
	 * @return void
	 */
	protected function on_site( $site_id, $callback ) {
		switch_to_blog( $site_id );

		try {
			call_user_func( $callback );
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * The scheduled run: rebuilds serving attachments' manifests from the
	 * files on disk, so a WebP removed outside the plugin stops being served.
	 * Works through them in small batches, in ID order, resuming where the
	 * previous run stopped.
	 *
	 * @return void
	 */
	public function run() {
		/**
		 * Filters how many serving attachments one health check run rebuilds.
		 *
		 * @param int $batch Batch size. Default 50.
		 */
		$batch  = max( 1, (int) apply_filters( 'ctgen_webp_health_check_batch', 50 ) );
		$cursor = (int) get_option( self::CURSOR_OPTION, 0 );
		$ids    = $this->serving_ids_after( $cursor, $batch );

		foreach ( $ids as $id ) {
			$this->manifest->rebuild( $id );
		}

		// A short batch means the end was reached: start from the beginning next time.
		update_option( self::CURSOR_OPTION, count( $ids ) < $batch ? 0 : (int) end( $ids ), false );
	}

	/**
	 * IDs of serving attachments above $cursor, lowest first.
	 *
	 * @param int $cursor Last ID handled.
	 * @param int $limit  How many.
	 * @return int[]
	 */
	private function serving_ids_after( $cursor, $limit ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Background job; a keyset query on indexed columns, no cache needed.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id > %d ORDER BY post_id ASC LIMIT %d",
				CTGEN_Webp_Manifest::META_KEY,
				$cursor,
				$limit
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Rebuilds a serving attachment's manifest when its metadata changes:
	 * thumbnails regenerated, image edited or restored in the media editor.
	 *
	 * @param int    $meta_id   Meta row ID (unused).
	 * @param int    $object_id Post ID.
	 * @param string $meta_key  Meta key.
	 * @return void
	 */
	public function on_meta_change( $meta_id, $object_id, $meta_key ) {
		if ( '_wp_attachment_metadata' === $meta_key ) {
			$this->manifest->rebuild( $object_id );
		}
	}
}
