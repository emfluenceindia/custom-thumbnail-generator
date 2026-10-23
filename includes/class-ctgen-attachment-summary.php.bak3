<?php
/**
 * Attachment Summary page: image inventory with a read-only usage scan.
 *
 * @package Custom_Thumbnail_Generator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Renders the Attachment Summary page and handles its AJAX scan requests.
 */
class CTGEN_Attachment_Summary {

	/**
	 * Screen option (user setting) holding the number of images per page.
	 *
	 * @var string
	 */
	const PER_PAGE_OPTION = 'ctgen_attachment_summary_per_page';

	/**
	 * Most attachment IDs accepted by one scan request.
	 *
	 * @var int
	 */
	const MAX_IDS_PER_REQUEST = 25;

	/**
	 * Usage scanner.
	 *
	 * @var CTGEN_Usage_Scanner
	 */
	private $scanner;

	/**
	 * Registers the AJAX actions, assets and screen option handling.
	 *
	 * @param CTGEN_Usage_Scanner|null $scanner Optional scanner instance, for testability.
	 */
	public function __construct( $scanner = null ) {
		$this->scanner = $scanner ? $scanner : new CTGEN_Usage_Scanner();

		add_action( 'wp_ajax_ctgen_usage_get_ids', array( $this, 'ajax_get_ids' ) );
		add_action( 'wp_ajax_ctgen_usage_scan', array( $this, 'ajax_scan' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'set_screen_option_' . self::PER_PAGE_OPTION, array( $this, 'save_per_page' ), 10, 3 );
	}

	/**
	 * Adds the "images per page" screen option (runs on the page's load hook).
	 *
	 * @return void
	 */
	public static function on_load() {
		add_screen_option(
			'per_page',
			array(
				'label'   => __( 'Images per page', 'custom-thumbnail-generator' ),
				'default' => 20,
				'option'  => self::PER_PAGE_OPTION,
			)
		);
	}

	/**
	 * Validates the saved "images per page" value.
	 *
	 * @param mixed  $status Default value to return (false = do not save).
	 * @param string $option Option name.
	 * @param mixed  $value  Submitted value.
	 * @return int|mixed
	 */
	public function save_per_page( $status, $option, $value ) {
		$value = absint( $value );

		return ( $value >= 1 && $value <= 200 ) ? $value : $status;
	}

	/**
	 * Enqueues the page script and styles on the Attachment Summary page only.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue( $hook_suffix ) {
		$page_hook = ctgen_admin_page_hook( 'attachment_summary' );

		if ( '' === $page_hook || $page_hook !== $hook_suffix ) {
			return;
		}

		wp_enqueue_script(
			'ctgen-attachment-summary-script',
			CTGEN_PLUGIN_DIR . 'assets/js/ctgen-attachment-summary.min.js',
			array( 'jquery' ),
			CTGEN_PLUGIN_VERSION,
			true
		);

		wp_localize_script(
			'ctgen-attachment-summary-script',
			'CTGENUsageScan',
			array(
				'ajax_url'        => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( 'ctgen_media_actions' ),
				'request_timeout' => 120000,
				'batch_size'      => 10,
				'i18n'            => array(
					'finding'    => __( 'Finding images to scan...', 'custom-thumbnail-generator' ),
					'busy'       => __( 'Scanning...', 'custom-thumbnail-generator' ),
					'nothing'    => __( 'There are no images to scan.', 'custom-thumbnail-generator' ),
					'nothingNew' => __( 'Every image has been scanned already. Use "Scan all images" to scan them again.', 'custom-thumbnail-generator' ),
					/* translators: 1: number of images scanned so far, 2: total number of images. */
					'processing' => __( 'Scanning images %1$s of %2$s...', 'custom-thumbnail-generator' ),
					/* translators: 1: percentage complete, 2: images scanned, 3: total images. */
					'announce'   => __( '%1$s%% done: %2$s of %3$s images scanned.', 'custom-thumbnail-generator' ),
					/* translators: %s: number of images scanned. */
					'doneOk'     => __( 'Scan complete: %s images scanned. Reload the page to update filters and sorting.', 'custom-thumbnail-generator' ),
					/* translators: 1: images scanned, 2: total images. */
					'doneErrors' => __( 'Scan finished with errors: %1$s of %2$s images scanned. See the list below.', 'custom-thumbnail-generator' ),
					/* translators: 1: images scanned, 2: total images, 3: reason. */
					'stopped'    => __( 'Scan stopped after %1$s of %2$s images: %3$s', 'custom-thumbnail-generator' ),
					/* translators: %s: reason. */
					'notStarted' => __( 'The scan could not start: %s', 'custom-thumbnail-generator' ),
					'autoStart'  => __( 'Updating usage for the images on this page...', 'custom-thumbnail-generator' ),
					/* translators: %s: number of images. */
					'autoDone'   => __( 'Usage updated for the %s images on this page.', 'custom-thumbnail-generator' ),
					/* translators: 1: images updated, 2: images on the page. */
					'autoErrors' => __( 'Usage updated for %1$s of %2$s images on this page. See the list below.', 'custom-thumbnail-generator' ),
					'auth'       => __( 'Your session has expired or you are not allowed to do this. Reload the page and try again.', 'custom-thumbnail-generator' ),
					'network'    => __( 'The server could not be reached.', 'custom-thumbnail-generator' ),
					'timeout'    => __( 'The server took too long to respond.', 'custom-thumbnail-generator' ),
					'malformed'  => __( 'The server sent an unexpected response.', 'custom-thumbnail-generator' ),
					/* translators: 1: attachment ID, 2: error message. */
					'itemError'  => __( 'Image ID %1$s: %2$s', 'custom-thumbnail-generator' ),
					/* translators: %s: error message. */
					'rowError'   => __( 'The scan failed: %s', 'custom-thumbnail-generator' ),
					/* translators: %s: number of further errors not listed. */
					'more'       => __( '...and %s more.', 'custom-thumbnail-generator' ),
				),
			)
		);

		wp_enqueue_style(
			'ctgen-attachment-summary-css',
			CTGEN_PLUGIN_DIR . 'assets/css/ctgen-attachment-summary.css',
			array( 'ctg-admin.css' ),
			CTGEN_PLUGIN_VERSION
		);
	}

	/**
	 * Renders the page.
	 *
	 * @return void
	 */
	public function render() {
		require_once CTGEN_PLUGIN_PATH . 'class-ctgen-attachment-summary-table.php';

		$table = new CTGEN_Attachment_Summary_Table();
		$table->prepare_items();
		?>
		<div class="wrap ctgen-attachment-summary">
			<?php CTGEN_Functions::ctgen_render_page_header( __( 'Custom Thumbnail Generator - Attachment Summary', 'custom-thumbnail-generator' ) ); ?>

			<div id="ctgen-usage-ui" data-state="idle">
				<p><?php esc_html_e( 'Every image in the Media Library, the post it is attached to, and where it is used. The usage of the images on this page is refreshed automatically when the page loads; use the refresh icon to update one image. Scanning only reads your content; nothing is changed.', 'custom-thumbnail-generator' ); ?></p>
				<p><?php esc_html_e( 'To filter or sort by usage across all pages, scan every image first:', 'custom-thumbnail-generator' ); ?></p>
				<p>
					<button type="button" id="ctgen-usage-scan-all" class="button button-primary"><?php esc_html_e( 'Scan all images', 'custom-thumbnail-generator' ); ?></button>
					<button type="button" id="ctgen-usage-scan-new" class="button"><?php esc_html_e( 'Scan images not scanned yet', 'custom-thumbnail-generator' ); ?></button>
				</p>
				<div id="ctgen-usage-progressbar" role="progressbar" aria-label="<?php esc_attr_e( 'Usage scan progress', 'custom-thumbnail-generator' ); ?>" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
					<div id="ctgen-usage-progress"></div>
					<div id="ctgen-usage-percentage" aria-hidden="true"></div>
				</div>
				<p id="ctgen-usage-current"></p>
				<div id="ctgen-usage-status" role="status" aria-live="polite"></div>
				<ul id="ctgen-usage-errors"></ul>
				<p class="description"><?php esc_html_e( 'Usage is found in post and page content, custom fields (including page builders), widgets, theme settings, the site icon and the logo. Images used only in theme files, CSS or on other websites cannot be detected, so "No usage found" does not prove that an image is unused.', 'custom-thumbnail-generator' ); ?></p>
			</div>

			<form method="get" id="ctgen-attachment-summary-form">
				<input type="hidden" name="page" value="ctgen-attachment-summary" />
				<?php
				$table->search_box( __( 'Search images', 'custom-thumbnail-generator' ), 'ctgen-image' );
				$table->display();
				?>
			</form>

			<?php // One viewer for every image: the script fills it in when a "View" link is used. ?>
			<div id="ctgen-image-viewer" class="ctgen-image-viewer" role="dialog" aria-modal="true" aria-labelledby="ctgen-image-viewer-title" hidden>
				<div class="ctgen-image-viewer-box">
					<div class="ctgen-image-viewer-head">
						<h2 id="ctgen-image-viewer-title"></h2>
						<button type="button" class="button ctgen-image-viewer-close" aria-label="<?php esc_attr_e( 'Close image preview', 'custom-thumbnail-generator' ); ?>">&times;</button>
					</div>
					<img class="ctgen-image-viewer-img" src="" alt="" />
					<p class="ctgen-image-viewer-about"></p>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX: returns the IDs of all image attachments, or only those never scanned.
	 *
	 * @return void
	 */
	public function ajax_get_ids() {
		check_ajax_referer( 'ctgen_media_actions', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not authorized to perform this action!', 'custom-thumbnail-generator' ) );
		}

		$only_unscanned = isset( $_POST['only_unscanned'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['only_unscanned'] ) );

		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image',
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
			)
		);
		$ids = array_map( 'intval', $ids );

		if ( $only_unscanned && $ids ) {
			update_meta_cache( 'post', $ids );
			$ids = array_values(
				array_filter(
					$ids,
					function ( $id ) {
						return ! metadata_exists( 'post', $id, CTGEN_Usage_Scanner::COUNT_META_KEY );
					}
				)
			);
		}

		wp_send_json_success( array( 'ids' => $ids ) );
	}

	/**
	 * AJAX: scans up to MAX_IDS_PER_REQUEST attachments and returns, per ID,
	 * the number of references and the refreshed "Used in" cell.
	 *
	 * @return void
	 */
	public function ajax_scan() {
		check_ajax_referer( 'ctgen_media_actions', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not authorized to perform this action!', 'custom-thumbnail-generator' ) );
		}

		$ids = isset( $_POST['ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) : array();
		$ids = array_values( array_unique( array_filter( $ids ) ) );

		if ( empty( $ids ) || count( $ids ) > self::MAX_IDS_PER_REQUEST ) {
			wp_send_json_error(
				array(
					'code'    => 'ctgen_usage_invalid_ids',
					/* translators: %d: maximum number of images per request. */
					'message' => sprintf( __( 'Send between 1 and %d image IDs per request.', 'custom-thumbnail-generator' ), self::MAX_IDS_PER_REQUEST ),
				)
			);
		}

		$results = array();
		$errors  = array();

		foreach ( $ids as $id ) {
			$result = $this->scanner->scan_and_store( $id );

			if ( is_wp_error( $result ) ) {
				$errors[ $id ] = $result->get_error_message();
				continue;
			}

			$results[ $id ] = array(
				'count' => $result['count'],
				'html'  => self::render_usage_cell( $id ),
			);
		}

		wp_send_json_success(
			array(
				'results' => (object) $results,
				'errors'  => (object) $errors,
			)
		);
	}

	/**
	 * HTML of the "Attachment" cell of one image (escaped, ready to output):
	 * the post it is attached to (uploaded to) plus every place the usage scan
	 * found it in, one line per post/page/setting with all its connections.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	public static function render_usage_cell( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		$cached        = CTGEN_Usage_Scanner::get_cached( $attachment_id );
		$parent_id     = (int) wp_get_post_parent_id( $attachment_id );
		$places        = array();

		if ( $parent_id && get_post( $parent_id ) ) {
			$places[ 'post|' . $parent_id ] = array(
				'ref'     => array(
					'kind' => 'post',
					'id'   => $parent_id,
				),
				'sources' => array( __( 'attached', 'custom-thumbnail-generator' ) ),
			);
		}

		foreach ( null === $cached ? array() : $cached['refs'] as $ref ) {
			$key = $ref['kind'] . '|' . $ref['id'];

			if ( ! isset( $places[ $key ] ) ) {
				$places[ $key ] = array(
					'ref'     => $ref,
					'sources' => array(),
				);
			}

			if ( 'post' === $ref['kind'] ) {
				$places[ $key ]['sources'][] = self::source_label( $ref );
			}
		}

		$title  = get_the_title( $attachment_id );
		$button = sprintf(
			'<button type="button" class="ctgen-usage-refresh" data-id="%1$d" aria-label="%2$s" title="%3$s"><span class="dashicons dashicons-update" aria-hidden="true"></span></button>',
			$attachment_id,
			/* translators: %s: image title. */
			esc_attr( sprintf( __( 'Refresh where "%s" is used', 'custom-thumbnail-generator' ), $title ) ),
			esc_attr__( 'Refresh', 'custom-thumbnail-generator' )
		);

		if ( null === $cached ) {
			$state = '';
		} else {
			/* translators: %s: time since the scan, e.g. "5 mins". */
			$when  = esc_html( sprintf( __( 'Scanned %s ago', 'custom-thumbnail-generator' ), human_time_diff( (int) $cached['scanned_at'] ) ) );
			$state = '<span class="ctgen-usage-when">' . ( empty( $cached['refs'] ) ? esc_html__( 'No usage found', 'custom-thumbnail-generator' ) . ' - ' : '' ) . $when . '</span>';
		}

		if ( empty( $places ) ) {
			$body = '<span class="ctgen-usage-none">' . esc_html( null === $cached ? __( 'Usage not scanned yet', 'custom-thumbnail-generator' ) : __( 'None', 'custom-thumbnail-generator' ) ) . '</span>';
		} elseif ( null === $cached ) {
			// Only the attachment is known before a scan: do not show it as a complete count of places.
			$items = '';
			foreach ( $places as $place ) {
				$items .= '<li>' . self::describe_place( $place['ref'], array_unique( $place['sources'] ) ) . '</li>';
			}
			$body = '<details class="ctgen-usage-list"><summary>' . esc_html__( 'Usage not scanned yet', 'custom-thumbnail-generator' ) . '</summary><ul>' . $items . '</ul></details>';
		} else {
			$items = '';
			foreach ( $places as $place ) {
				$items .= '<li>' . self::describe_place( $place['ref'], array_unique( $place['sources'] ) ) . '</li>';
			}
			/* translators: %s: number of posts, pages or settings. */
			$summary = sprintf( _n( '%s place', '%s places', count( $places ), 'custom-thumbnail-generator' ), number_format_i18n( count( $places ) ) );
			$body    = '<details class="ctgen-usage-list"><summary>' . esc_html( $summary ) . '</summary><ul>' . $items . '</ul></details>';
		}

		return sprintf(
			'<div class="ctgen-usage-cell" id="ctgen-usage-cell-%1$d" data-count="%2$s" data-places="%3$d" data-parent="%4$d"><div class="ctgen-usage-head">%5$s%6$s</div>%7$s</div>',
			$attachment_id,
			null === $cached ? '' : count( $cached['refs'] ),
			count( $places ),
			$parent_id,
			$body,
			$button,
			$state
		);
	}

	/**
	 * Readable label of how a post references the image.
	 *
	 * @param array $ref Post reference from CTGEN_Usage_Scanner::scan().
	 * @return string Unescaped label.
	 */
	private static function source_label( $ref ) {
		$key      = isset( $ref['key'] ) ? (string) $ref['key'] : '';
		$builders = CTGEN_Usage_Scanner::BUILDER_META_KEYS;
		$labels   = array(
			'content'         => __( 'content', 'custom-thumbnail-generator' ),
			'gallery'         => __( 'gallery', 'custom-thumbnail-generator' ),
			'featured_image'  => __( 'featured image', 'custom-thumbnail-generator' ),
			'product_gallery' => __( 'product gallery', 'custom-thumbnail-generator' ),
			/* translators: %s: page builder name. */
			'builder'         => sprintf( __( '%s data', 'custom-thumbnail-generator' ), isset( $builders[ $key ] ) ? $builders[ $key ] : $key ),
			/* translators: %s: custom field name. */
			'custom_field'    => sprintf( __( 'custom field "%s"', 'custom-thumbnail-generator' ), $key ),
		);

		return isset( $labels[ $ref['source'] ] ) ? $labels[ $ref['source'] ] : (string) $ref['source'];
	}

	/**
	 * Readable, escaped description of one place (post, page or site setting).
	 *
	 * @param array    $ref     Reference (kind, id, source).
	 * @param string[] $sources For posts: how the post is connected (attached, content, ...).
	 * @return string
	 */
	private static function describe_place( $ref, $sources ) {
		if ( 'setting' === $ref['kind'] ) {
			$labels = array(
				'site_icon'      => array( __( 'Site icon', 'custom-thumbnail-generator' ), admin_url( 'options-general.php' ) ),
				'custom_logo'    => array( __( 'Theme logo', 'custom-thumbnail-generator' ), admin_url( 'customize.php' ) ),
				'header_image'   => array( __( 'Theme header image', 'custom-thumbnail-generator' ), admin_url( 'customize.php' ) ),
				/* translators: %s: option name, e.g. widget_media_image. */
				'widget'         => array( sprintf( __( 'Widget settings (%s)', 'custom-thumbnail-generator' ), $ref['id'] ), admin_url( 'widgets.php' ) ),
				/* translators: %s: theme folder name. */
				'theme_settings' => array( sprintf( __( 'Theme settings (%s)', 'custom-thumbnail-generator' ), substr( (string) $ref['id'], strlen( 'theme_mods_' ) ) ), admin_url( 'customize.php' ) ),
				/* translators: %s: option name. */
				'option'         => array( sprintf( __( 'Site option "%s"', 'custom-thumbnail-generator' ), $ref['id'] ), '' ),
			);
			$label = isset( $labels[ $ref['source'] ] ) ? $labels[ $ref['source'] ] : array( (string) $ref['id'], '' );

			return '' === $label[1] ? esc_html( $label[0] ) : '<a href="' . esc_url( $label[1] ) . '">' . esc_html( $label[0] ) . '</a>';
		}

		$post = get_post( (int) $ref['id'] );

		if ( ! $post ) {
			/* translators: %d: post ID. */
			return esc_html( sprintf( __( 'Item #%d (no longer exists)', 'custom-thumbnail-generator' ), (int) $ref['id'] ) );
		}

		$title   = '' !== get_the_title( $post ) ? get_the_title( $post ) : __( '(no title)', 'custom-thumbnail-generator' );
		$link    = get_edit_post_link( $post->ID, 'raw' );
		$type    = get_post_type_object( $post->post_type );
		$details = array( $type ? $type->labels->singular_name : $post->post_type );
		$status  = get_post_status_object( $post->post_status );

		if ( 'publish' !== $post->post_status && $status ) {
			$details[] = $status->label;
		}

		$name = $link ? '<a href="' . esc_url( $link ) . '">' . esc_html( $title ) . '</a>' : esc_html( $title );

		return $name . ' <span class="ctgen-usage-meta">(' . esc_html( implode( ', ', $details ) ) . ') - ' . esc_html( implode( ', ', $sources ) ) . '</span>';
	}
}
