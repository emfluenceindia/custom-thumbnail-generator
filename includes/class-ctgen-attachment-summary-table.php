<?php
/**
 * List table for the Attachment Summary page.
 *
 * Loaded only when the page is rendered (it depends on WP_List_Table).
 *
 * @package Custom_Thumbnail_Generator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Media Library-style table of image attachments with usage information.
 */
class CTGEN_Attachment_Summary_Table extends WP_List_Table {

	/**
	 * Image MIME types with their own filter entry.
	 *
	 * @var array<string, string>
	 */
	const TYPE_FILTERS = array(
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
		'gif'  => 'image/gif',
		'webp' => 'image/webp',
	);

	/**
	 * Sets up the table.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'ctgen-image',
				'plural'   => 'ctgen-images',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Columns.
	 *
	 * @return array<string, string>
	 */
	public function get_columns() {
		return array(
			'image'      => __( 'Image', 'custom-thumbnail-generator' ),
			'type'       => __( 'Type', 'custom-thumbnail-generator' ),
			'attachment' => __( 'Attachment', 'custom-thumbnail-generator' ),
			'size'       => __( 'Current size', 'custom-thumbnail-generator' ),
			'status'     => __( 'WebP status', 'custom-thumbnail-generator' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array<string, array>
	 */
	protected function get_sortable_columns() {
		return array(
			'image'      => array( 'title', false ),
			'type'       => array( 'type', false ),
			// Default sort (no orderby in the URL): most-used images first.
			'attachment' => array( 'usage', true, '', '', 'desc' ),
		);
	}

	/**
	 * Primary column (carries the row actions).
	 *
	 * @return string
	 */
	protected function get_default_primary_column_name() {
		return 'image';
	}

	/**
	 * Reads one filter/sort value from the URL, restricted to allowed values.
	 *
	 * @param string   $key     Query argument.
	 * @param string[] $allowed Allowed values.
	 * @return string The value, or '' if absent or not allowed.
	 */
	private function view_arg( $key, $allowed ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filtering and sorting of a list, like WordPress core list tables; the value is restricted to an allow-list.
		$value = isset( $_GET[ $key ] ) ? sanitize_key( wp_unslash( $_GET[ $key ] ) ) : '';

		return in_array( $value, $allowed, true ) ? $value : '';
	}

	/**
	 * Queries the images for the current page.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$per_page = $this->get_items_per_page( CTGEN_Attachment_Summary::PER_PAGE_OPTION, 20 );
		$type     = $this->view_arg( 'ctgen_type', array( 'jpeg', 'png', 'gif', 'webp', 'other' ) );
		$attached = $this->view_arg( 'ctgen_attached', array( 'attached', 'unattached' ) );
		$usage    = $this->view_arg( 'ctgen_usage', array( 'found', 'none', 'unscanned' ) );
		$orderby  = $this->view_arg( 'orderby', array( 'title', 'type', 'parent', 'usage', 'date' ) );
		$orderby  = '' === $orderby ? 'usage' : $orderby; // Default: most-used images first.
		$order    = 'asc' === $this->view_arg( 'order', array( 'asc', 'desc' ) ) ? 'ASC' : 'DESC';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only search of a list, like WordPress core list tables.
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => 'image',
			'posts_per_page' => $per_page,
			'paged'          => $this->get_pagenum(),
			'orderby'        => array(
				'date' => $order,
				'ID'   => $order,
			),
		);

		if ( isset( self::TYPE_FILTERS[ $type ] ) ) {
			$args['post_mime_type'] = self::TYPE_FILTERS[ $type ];
		} elseif ( 'other' === $type ) {
			$others                 = array_diff( array_filter( get_allowed_mime_types(), array( $this, 'is_image_mime' ) ), self::TYPE_FILTERS );
			$args['post_mime_type'] = $others ? array_values( $others ) : 'image/x-none';
		}

		if ( 'attached' === $attached ) {
			$args['post_parent__not_in'] = array( 0 );
		} elseif ( 'unattached' === $attached ) {
			$args['post_parent'] = 0;
		}

		$meta_query = array();

		if ( 'found' === $usage || 'none' === $usage ) {
			$meta_query['usage_count'] = array(
				'key'     => CTGEN_Usage_Scanner::COUNT_META_KEY,
				'value'   => 0,
				'compare' => 'found' === $usage ? '>' : '=',
				'type'    => 'NUMERIC',
			);
		} elseif ( 'unscanned' === $usage ) {
			$meta_query[] = array(
				'key'     => CTGEN_Usage_Scanner::COUNT_META_KEY,
				'compare' => 'NOT EXISTS',
			);
		}

		$sort_columns = array(
			'title'  => 'title',
			'type'   => 'post_mime_type',
			'parent' => 'parent',
			'date'   => 'date',
		);

		if ( isset( $sort_columns[ $orderby ] ) ) {
			$args['orderby'] = array(
				$sort_columns[ $orderby ] => $order,
				'ID'                      => $order,
			);
		} elseif ( 'usage' === $orderby && 'unscanned' !== $usage ) {
			if ( ! isset( $meta_query['usage_count'] ) ) {
				// Keep never-scanned images in the list: they sort as "no count".
				$meta_query = array(
					'relation'    => 'OR',
					'usage_count' => array(
						'key'     => CTGEN_Usage_Scanner::COUNT_META_KEY,
						'type'    => 'NUMERIC',
						'compare' => 'EXISTS',
					),
					array(
						'key'     => CTGEN_Usage_Scanner::COUNT_META_KEY,
						'compare' => 'NOT EXISTS',
					),
				);
			}
			// Ties (and never-scanned images, which sort last) by newest upload.
			$args['orderby'] = array(
				'usage_count' => $order,
				'date'        => 'DESC',
				'ID'          => 'DESC',
			);
		}

		if ( $meta_query ) {
			$args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Admin-only, paginated list filtered/sorted by the plugin's own usage count.
		}

		if ( '' !== $search ) {
			$args['s'] = $search;
			add_filter( 'wp_allow_query_attachment_by_filename', '__return_true' );
		}

		$query = new WP_Query( $args );

		$this->_column_headers = array( $this->get_columns(), get_hidden_columns( $this->screen ), $this->get_sortable_columns(), $this->get_primary_column_name() );
		$this->items           = $query->posts;
		$this->set_pagination_args(
			array(
				'total_items' => (int) $query->found_posts,
				'per_page'    => $per_page,
				'total_pages' => (int) $query->max_num_pages,
			)
		);
	}

	/**
	 * Whether a MIME type is an image type.
	 *
	 * @param string $mime MIME type.
	 * @return bool
	 */
	public function is_image_mime( $mime ) {
		return 0 === strpos( $mime, 'image/' );
	}

	/**
	 * Filters above the table.
	 *
	 * @param string $which 'top' or 'bottom'.
	 * @return void
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		$filters = array(
			'ctgen_type'     => array(
				__( 'Filter by type', 'custom-thumbnail-generator' ),
				array(
					''      => __( 'All types', 'custom-thumbnail-generator' ),
					'jpeg'  => 'JPEG',
					'png'   => 'PNG',
					'gif'   => 'GIF',
					'webp'  => 'WebP',
					'other' => __( 'Other', 'custom-thumbnail-generator' ),
				),
			),
			'ctgen_attached' => array(
				__( 'Filter by attachment', 'custom-thumbnail-generator' ),
				array(
					''           => __( 'Attached and unattached', 'custom-thumbnail-generator' ),
					'attached'   => __( 'Attached', 'custom-thumbnail-generator' ),
					'unattached' => __( 'Unattached', 'custom-thumbnail-generator' ),
				),
			),
			'ctgen_usage'    => array(
				__( 'Filter by usage', 'custom-thumbnail-generator' ),
				array(
					''          => __( 'Any usage', 'custom-thumbnail-generator' ),
					'found'     => __( 'Usage found', 'custom-thumbnail-generator' ),
					'none'      => __( 'No usage found', 'custom-thumbnail-generator' ),
					'unscanned' => __( 'Not scanned yet', 'custom-thumbnail-generator' ),
				),
			),
		);

		echo '<div class="alignleft actions">';

		foreach ( $filters as $name => $filter ) {
			$current = $this->view_arg( $name, array_keys( $filter[1] ) );
			printf( '<label class="screen-reader-text" for="%1$s">%2$s</label><select name="%1$s" id="%1$s">', esc_attr( $name ), esc_html( $filter[0] ) );
			foreach ( $filter[1] as $value => $label ) {
				printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $value ), selected( $current, $value, false ), esc_html( $label ) );
			}
			echo '</select>';
		}

		submit_button( __( 'Filter', 'custom-thumbnail-generator' ), '', 'filter_action', false, array( 'id' => 'ctgen-image-filter' ) );
		echo '</div>';
	}

	/**
	 * Message when there are no images.
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'No images found.', 'custom-thumbnail-generator' );
	}

	/**
	 * Image column: preview, title, file name and row actions.
	 *
	 * @param WP_Post $item Attachment.
	 * @return string
	 */
	protected function column_image( $item ) {
		$title   = '' !== get_the_title( $item ) ? get_the_title( $item ) : __( '(no title)', 'custom-thumbnail-generator' );
		$edit    = get_edit_post_link( $item->ID, 'raw' );
		$file    = get_attached_file( $item->ID );
		$preview = wp_get_attachment_image( $item->ID, array( 60, 60 ), true, array( 'alt' => '' ) );
		$name    = $edit ? '<a href="' . esc_url( $edit ) . '">' . esc_html( $title ) . '</a>' : esc_html( $title );
		$actions = array();

		if ( $edit ) {
			$actions['edit'] = '<a href="' . esc_url( $edit ) . '">' . esc_html__( 'Edit', 'custom-thumbnail-generator' ) . '</a>';
		}

		$meta  = wp_get_attachment_metadata( $item->ID );
		$about = ( $file ? wp_basename( $file ) : '' ) . ( is_array( $meta ) && ! empty( $meta['width'] ) ? ' - ' . (int) $meta['width'] . ' x ' . (int) $meta['height'] : '' );

		// Opens in the page's single image viewer (see assets/js); without JavaScript it opens the file.
		$actions['view'] = sprintf(
			'<a href="%1$s" class="ctgen-image-view" data-title="%2$s" data-about="%3$s" aria-haspopup="dialog">%4$s</a>',
			esc_url( wp_get_attachment_url( $item->ID ) ),
			esc_attr( $title ),
			esc_attr( $about ),
			esc_html__( 'View', 'custom-thumbnail-generator' )
		);

		return '<div class="ctgen-image-cell"><span class="ctgen-image-preview">' . $preview . '</span><span class="ctgen-image-name"><strong>' . $name . '</strong><span class="ctgen-file-name">' . esc_html( $file ? wp_basename( $file ) : '' ) . '</span></span></div>' . $this->row_actions( $actions );
	}

	/**
	 * Type column: format and dimensions.
	 *
	 * @param WP_Post $item Attachment.
	 * @return string
	 */
	protected function column_type( $item ) {
		$meta   = wp_get_attachment_metadata( $item->ID );
		$format = strtoupper( (string) preg_replace( '#^image/#', '', $item->post_mime_type ) );
		$dims   = is_array( $meta ) && ! empty( $meta['width'] ) ? (int) $meta['width'] . ' &times; ' . (int) $meta['height'] : '';

		return esc_html( $format ) . ( $dims ? '<br /><span class="ctgen-usage-meta">' . $dims . '</span>' : '' );
	}

	/**
	 * Attachment column: the post the image is attached to plus every place it is used.
	 *
	 * @param WP_Post $item Attachment.
	 * @return string
	 */
	protected function column_attachment( $item ) {
		return CTGEN_Attachment_Summary::render_usage_cell( $item->ID );
	}

	/**
	 * Absolute paths of the attachment's files on disk: the attached file, the
	 * original upload (for scaled images) and every generated size.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string[]
	 */
	private function attachment_files( $attachment_id ) {
		return CTGEN_Attachment_Summary::attachment_files( $attachment_id );
	}

	/**
	 * Current size column: total of all local files, with a per-file list.
	 *
	 * @param WP_Post $item Attachment.
	 * @return string
	 */
	protected function column_size( $item ) {
		$total = 0;
		$found = 0;
		$items = '';
		$files = $this->attachment_files( $item->ID );

		foreach ( $files as $path ) {
			if ( file_exists( $path ) ) {
				$bytes  = (int) filesize( $path );
				$total += $bytes;
				++$found;
				$items .= '<li>' . esc_html( wp_basename( $path ) . ' - ' . size_format( $bytes, 1 ) ) . '</li>';
			} else {
				$items .= '<li>' . esc_html( wp_basename( $path ) . ' - ' . __( 'not stored locally', 'custom-thumbnail-generator' ) ) . '</li>';
			}
		}

		if ( 0 === $found ) {
			return '<span class="ctgen-usage-meta">' . esc_html__( 'Not stored locally', 'custom-thumbnail-generator' ) . '</span>';
		}

		/* translators: 1: total size, 2: number of files. */
		$summary = sprintf( _n( '%1$s in %2$s file', '%1$s in %2$s files', $found, 'custom-thumbnail-generator' ), size_format( $total, 1 ), number_format_i18n( $found ) );

		return '<details class="ctgen-size-list" data-bytes="' . (int) $total . '"><summary>' . esc_html( $summary ) . '</summary><ul>' . $items . '</ul></details>';
	}

	/**
	 * WebP status column.
	 *
	 * @param WP_Post $item Attachment.
	 * @return string
	 */
	protected function column_status( $item ) {
		return CTGEN_Attachment_Summary::render_webp_cell( $item->ID );
	}
}
