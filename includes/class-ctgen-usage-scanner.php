<?php
/**
 * Finds where an image attachment is used on the current site.
 *
 * Read-only: the only data written is the scan result cache stored on the
 * attachment itself (see store()).
 *
 * @package Custom_Thumbnail_Generator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Scans post content, custom fields, options and site settings for references
 * to one image attachment, by file path and by attachment ID.
 */
class CTGEN_Usage_Scanner {

	/**
	 * Post meta key holding the cached scan result.
	 *
	 * @var string
	 */
	const META_KEY = '_ctgen_usage';

	/**
	 * Post meta key holding the number of references found (for filtering and sorting).
	 *
	 * @var string
	 */
	const COUNT_META_KEY = '_ctgen_usage_count';

	/**
	 * Version of the scan rules; stored with every result.
	 *
	 * @var int
	 */
	const VERSION = 1;

	/**
	 * File extensions a reference to the image (or one of its copies) may have.
	 *
	 * @var string
	 */
	const EXTENSIONS = 'jpe?g|jpe|png|gif|webp|avif|bmp|tiff?|heic|heif';

	/**
	 * Custom field keys of page builders, for readable source labels.
	 *
	 * @var array<string, string>
	 */
	const BUILDER_META_KEYS = array(
		'_elementor_data'        => 'Elementor',
		'_fl_builder_data'       => 'Beaver Builder',
		'_et_builder_version'    => 'Divi',
		'_bricks_page_content_2' => 'Bricks',
		'ct_builder_shortcodes'  => 'Oxygen',
	);

	/**
	 * Scans for references to an attachment and stores the result.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array|WP_Error {count, refs, scanned_at} or an error for a non-image ID.
	 */
	public function scan_and_store( $attachment_id ) {
		$attachment_id = absint( $attachment_id );

		if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) || 0 !== strpos( (string) get_post_mime_type( $attachment_id ), 'image/' ) ) {
			return new WP_Error( 'ctgen_usage_not_an_image', __( 'The specified item is not an image attachment.', 'custom-thumbnail-generator' ) );
		}

		$result = array(
			'version'    => self::VERSION,
			'scanned_at' => time(),
			'refs'       => $this->scan( $attachment_id ),
		);

		update_post_meta( $attachment_id, self::META_KEY, $result );
		update_post_meta( $attachment_id, self::COUNT_META_KEY, count( $result['refs'] ) );

		return array(
			'count'      => count( $result['refs'] ),
			'refs'       => $result['refs'],
			'scanned_at' => $result['scanned_at'],
		);
	}

	/**
	 * Returns the cached scan result of an attachment, or null if never scanned.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array|null
	 */
	public static function get_cached( $attachment_id ) {
		$cached = get_post_meta( $attachment_id, self::META_KEY, true );

		return is_array( $cached ) && isset( $cached['refs'], $cached['scanned_at'] ) ? $cached : null;
	}

	/**
	 * Finds every reference to the attachment. Does not write anything.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array[] List of {kind: 'post'|'setting', id: int|string, source: string, key?: string}.
	 */
	public function scan( $attachment_id ) {
		$stems = $this->path_stems( $attachment_id );
		$refs  = array();

		if ( empty( $stems ) ) {
			return $refs;
		}

		$path_regex = $this->path_regex( $stems );
		$like_a     = '%' . $GLOBALS['wpdb']->esc_like( wp_basename( $stems[0] ) ) . '%';
		$like_b     = '%' . $GLOBALS['wpdb']->esc_like( wp_basename( end( $stems ) ) ) . '%';

		foreach ( $this->scan_post_content( $attachment_id, $path_regex, $like_a, $like_b ) as $ref ) {
			$refs[] = $ref;
		}

		foreach ( $this->scan_post_meta( $attachment_id, $path_regex, $like_a, $like_b ) as $ref ) {
			$refs[] = $ref;
		}

		foreach ( $this->scan_options( $attachment_id, $path_regex, $like_a, $like_b ) as $ref ) {
			$refs[] = $ref;
		}

		foreach ( $this->scan_settings( $attachment_id ) as $ref ) {
			$refs[] = $ref;
		}

		// One entry per place and source.
		$unique = array();
		foreach ( $refs as $ref ) {
			$unique[ $ref['kind'] . '|' . $ref['id'] . '|' . $ref['source'] . '|' . ( isset( $ref['key'] ) ? $ref['key'] : '' ) ] = $ref;
		}

		return array_values( $unique );
	}

	/**
	 * Upload-relative paths without extension that every file of the attachment
	 * starts with, e.g. "2025/12/photo" (sizes, "-scaled" and edited versions
	 * and WebP copies all extend it).
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string[]
	 */
	private function path_stems( $attachment_id ) {
		$relative = get_post_meta( $attachment_id, '_wp_attached_file', true );

		if ( ! is_string( $relative ) || '' === $relative ) {
			return array();
		}

		$dir      = dirname( $relative );
		$dir      = '.' === $dir ? '' : trailingslashit( $dir );
		$metadata = wp_get_attachment_metadata( $attachment_id );
		$stems    = array( $dir . preg_replace( '/-scaled$/', '', pathinfo( $relative, PATHINFO_FILENAME ) ) );

		if ( is_array( $metadata ) && ! empty( $metadata['original_image'] ) && is_string( $metadata['original_image'] ) ) {
			$stems[] = $dir . pathinfo( $metadata['original_image'], PATHINFO_FILENAME );
		}

		return array_values( array_unique( $stems ) );
	}

	/**
	 * Regular expression matching a reference to any file of the attachment,
	 * with plain or JSON-escaped ("\/") slashes.
	 *
	 * @param string[] $stems Path stems from path_stems().
	 * @return string
	 */
	private function path_regex( $stems ) {
		$alternatives = array();

		foreach ( $stems as $stem ) {
			$alternatives[] = str_replace( '/', '(?:/|\\\\/)', preg_quote( $stem, '#' ) );
		}

		return '#(?<![A-Za-z0-9_.-])(?:' . implode( '|', $alternatives ) . ')(?:-(?:e\d{10,}|scaled))*(?:-\d+x\d+)?\.(?:' . self::EXTENSIONS . ')(?![A-Za-z0-9_])#i';
	}

	/**
	 * Whether text references the attachment by ID (class, block attribute, gallery).
	 *
	 * @param string $text          Post content or block markup.
	 * @param int    $attachment_id Attachment ID.
	 * @return string|false 'content', 'gallery' or false.
	 */
	private function id_reference( $text, $attachment_id ) {
		$id = (int) $attachment_id;

		if ( preg_match( '/\bwp-image-' . $id . '\b/', $text ) || preg_match( '/<!--\s+wp:[^>]*"id":' . $id . '[,}]/', $text ) ) {
			return 'content';
		}

		if ( preg_match_all( '/\[gallery[^\]]*\bids=["\']([0-9,\s]+)["\']/i', $text, $matches ) ) {
			foreach ( $matches[1] as $list ) {
				if ( in_array( $id, array_map( 'intval', explode( ',', $list ) ), true ) ) {
					return 'gallery';
				}
			}
		}

		if ( preg_match_all( '/"ids":\[([0-9,\s]*)\]/', $text, $matches ) ) {
			foreach ( $matches[1] as $list ) {
				if ( in_array( $id, array_map( 'intval', explode( ',', $list ) ), true ) ) {
					return 'gallery';
				}
			}
		}

		return false;
	}

	/**
	 * References in the content of posts, pages and other post types.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $path_regex    From path_regex().
	 * @param string $like_a        LIKE pattern for the first stem's file name.
	 * @param string $like_b        LIKE pattern for the last stem's file name.
	 * @return array[]
	 */
	private function scan_post_content( $attachment_id, $path_regex, $like_a, $like_b ) {
		global $wpdb;

		$id = (int) $attachment_id;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only search of post content across all post types; no WordPress API covers this, and results must be fresh.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_content FROM {$wpdb->posts}
				WHERE post_type NOT IN ( 'revision', 'attachment', 'customize_changeset', 'oembed_cache', 'user_request' )
				AND post_status NOT IN ( 'auto-draft', 'inherit' )
				AND ( post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s )",
				$like_a,
				$like_b,
				'%' . $wpdb->esc_like( 'wp-image-' . $id ) . '%',
				'%' . $wpdb->esc_like( '"id":' . $id ) . '%',
				'%' . $wpdb->esc_like( '[gallery' ) . '%',
				'%' . $wpdb->esc_like( '"ids":[' ) . '%'
			)
		);

		$refs = array();

		foreach ( (array) $rows as $row ) {
			$source = preg_match( $path_regex, $row->post_content ) ? 'content' : $this->id_reference( $row->post_content, $id );

			if ( false !== $source ) {
				$refs[] = array(
					'kind'   => 'post',
					'id'     => (int) $row->ID,
					'source' => $source,
				);
			}
		}

		return $refs;
	}

	/**
	 * References in custom fields: file paths (page builders, URL fields),
	 * featured images and WooCommerce product galleries.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $path_regex    From path_regex().
	 * @param string $like_a        LIKE pattern for the first stem's file name.
	 * @param string $like_b        LIKE pattern for the last stem's file name.
	 * @return array[]
	 */
	private function scan_post_meta( $attachment_id, $path_regex, $like_a, $like_b ) {
		global $wpdb;

		$id = (int) $attachment_id;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only search of custom field values across all posts; no WordPress API covers this, and results must be fresh.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.post_id, m.meta_key, m.meta_value FROM {$wpdb->postmeta} m
				INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
				WHERE p.post_type NOT IN ( 'revision', 'attachment', 'customize_changeset', 'oembed_cache', 'user_request' )
				AND p.post_status NOT IN ( 'auto-draft', 'inherit' )
				AND m.meta_key NOT IN ( '_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes', '_ctgen_usage', '_ctgen_usage_count' )
				AND ( m.meta_value LIKE %s OR m.meta_value LIKE %s OR ( m.meta_key = '_thumbnail_id' AND m.meta_value = %s ) OR ( m.meta_key = '_product_image_gallery' AND m.meta_value LIKE %s ) )",
				$like_a,
				$like_b,
				(string) $id,
				'%' . $wpdb->esc_like( (string) $id ) . '%'
			)
		);

		$refs = array();

		foreach ( (array) $rows as $row ) {
			$source = false;

			if ( '_thumbnail_id' === $row->meta_key ) {
				$source = (int) $row->meta_value === $id ? 'featured_image' : false;
			} elseif ( '_product_image_gallery' === $row->meta_key ) {
				$source = in_array( $id, array_map( 'intval', explode( ',', (string) $row->meta_value ) ), true ) ? 'product_gallery' : false;
			} elseif ( preg_match( $path_regex, (string) $row->meta_value ) ) {
				$source = isset( self::BUILDER_META_KEYS[ $row->meta_key ] ) ? 'builder' : 'custom_field';
			}

			if ( false !== $source ) {
				$refs[] = array(
					'kind'   => 'post',
					'id'     => (int) $row->post_id,
					'source' => $source,
					'key'    => (string) $row->meta_key,
				);
			}
		}

		return $refs;
	}

	/**
	 * References by file path in options (widgets, theme settings, plugin
	 * settings), plus block widgets that reference the image by ID.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $path_regex    From path_regex().
	 * @param string $like_a        LIKE pattern for the first stem's file name.
	 * @param string $like_b        LIKE pattern for the last stem's file name.
	 * @return array[]
	 */
	private function scan_options( $attachment_id, $path_regex, $like_a, $like_b ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only search of option values; no WordPress API searches option contents, and results must be fresh.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options}
				WHERE option_name NOT LIKE %s AND option_name NOT LIKE %s
				AND ( option_value LIKE %s OR option_value LIKE %s OR option_name = 'widget_block' )",
				$wpdb->esc_like( '_transient_' ) . '%',
				$wpdb->esc_like( '_site_transient_' ) . '%',
				$like_a,
				$like_b
			)
		);

		$refs = array();

		foreach ( (array) $rows as $row ) {
			$value = (string) $row->option_value;

			if ( preg_match( $path_regex, $value ) || ( 'widget_block' === $row->option_name && false !== $this->id_reference( $value, $attachment_id ) ) ) {
				$refs[] = array(
					'kind'   => 'setting',
					'id'     => (string) $row->option_name,
					'source' => 0 === strpos( $row->option_name, 'widget_' ) ? 'widget' : ( 0 === strpos( $row->option_name, 'theme_mods_' ) ? 'theme_settings' : 'option' ),
				);
			}
		}

		return $refs;
	}

	/**
	 * References by attachment ID in site settings: site icon, the active
	 * theme's logo and header image, and image/gallery widgets.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array[]
	 */
	private function scan_settings( $attachment_id ) {
		$id   = (int) $attachment_id;
		$refs = array();

		if ( (int) get_option( 'site_icon' ) === $id ) {
			$refs[] = array(
				'kind'   => 'setting',
				'id'     => 'site_icon',
				'source' => 'site_icon',
			);
		}

		if ( (int) get_theme_mod( 'custom_logo' ) === $id ) {
			$refs[] = array(
				'kind'   => 'setting',
				'id'     => 'custom_logo',
				'source' => 'custom_logo',
			);
		}

		$header = get_theme_mod( 'header_image_data' );
		if ( ( is_object( $header ) && isset( $header->attachment_id ) && (int) $header->attachment_id === $id ) || ( is_array( $header ) && isset( $header['attachment_id'] ) && (int) $header['attachment_id'] === $id ) ) {
			$refs[] = array(
				'kind'   => 'setting',
				'id'     => 'header_image',
				'source' => 'header_image',
			);
		}

		foreach ( array( 'widget_media_image', 'widget_media_gallery' ) as $option ) {
			foreach ( (array) get_option( $option, array() ) as $instance ) {
				if ( ! is_array( $instance ) ) {
					continue;
				}

				$ids = isset( $instance['ids'] ) ? array_map( 'intval', (array) $instance['ids'] ) : array();

				if ( ( isset( $instance['attachment_id'] ) && (int) $instance['attachment_id'] === $id ) || in_array( $id, $ids, true ) ) {
					$refs[] = array(
						'kind'   => 'setting',
						'id'     => $option,
						'source' => 'widget',
					);
					break;
				}
			}
		}

		return $refs;
	}
}
