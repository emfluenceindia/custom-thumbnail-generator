<?php
/**
 * The per-attachment "serve WebP" manifest.
 *
 * Lists, for one attachment, exactly which of its files have a WebP copy
 * that is proven safe to serve in their place, and why every other file is
 * skipped. All filesystem checks happen here, once, in admin or cron; the
 * front end only ever reads the stored result.
 *
 * Never creates, writes, moves or deletes a file. Its only filesystem access
 * is reading, through read_file().
 *
 * @package Custom_Thumbnail_Generator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Builds, validates, reads and deletes the serve manifest.
 */
class CTGEN_Webp_Manifest {

	/**
	 * Post meta key holding the manifest. Present only while serving is on.
	 *
	 * @var string
	 */
	const META_KEY = '_ctgen_webp_serve';

	/**
	 * Manifest shape version. A stored manifest with any other version is treated as "off".
	 *
	 * @var int
	 */
	const VERSION = 1;

	/**
	 * The WebP AJAX handlers, whose rules for listing an attachment's files
	 * and judging a WebP copy are reused here rather than duplicated.
	 *
	 * @var CTGEN_Webp_Ajax_Handlers
	 */
	private $handlers;

	/**
	 * Sets up the manifest service.
	 *
	 * @param CTGEN_Webp_Ajax_Handlers $handlers The plugin's WebP handlers instance.
	 */
	public function __construct( $handlers ) {
		$this->handlers = $handlers;
	}

	/**
	 * Works out the manifest for an attachment from the files on disk. Does
	 * not store it.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $unreachable   'unreachable' marks from the stored manifest, kept
	 *                             while their WebP file is unchanged.
	 * @return array|WP_Error Manifest, or why the attachment can't have one.
	 */
	public function build( $attachment_id, $unreachable = array() ) {
		$parts = $this->handlers->list_parts( $attachment_id );

		if ( is_wp_error( $parts ) ) {
			if ( in_array( $parts->get_error_code(), array( 'ctgen_webp_gif_excluded', 'ctgen_webp_unsupported_attachment_type' ), true ) ) {
				return new WP_Error(
					'ctgen_webp_unsupported_source',
					__( 'Only JPEG and PNG images can be served as WebP.', 'custom-thumbnail-generator' )
				);
			}

			return $parts;
		}

		$attached_file = get_attached_file( $attachment_id );
		$directory     = trailingslashit( dirname( $attached_file ) );
		$sizes         = array();
		$skipped       = array();
		$kept_marks    = array();

		// Another image with this base name: no .webp here can be proven to be ours.
		if ( $this->handlers->has_name_conflict( $directory, $attached_file, wp_get_attachment_metadata( $attachment_id ) ) ) {
			return $this->shape( array(), array_fill_keys( array_keys( $parts ), 'not_owned' ), array() );
		}

		foreach ( $parts as $part => $entry ) {
			if ( empty( $entry['file'] ) || ! is_string( $entry['file'] ) ) {
				$skipped[ $part ] = 'source_missing';
				continue;
			}

			$source_file = wp_basename( $entry['file'] );
			$webp_file   = pathinfo( $source_file, PATHINFO_FILENAME ) . '.webp';
			$source      = $this->read_file( $directory . $source_file );
			$webp        = $this->read_file( $directory . $webp_file );

			if ( false === $source ) {
				$skipped[ $part ] = 'source_missing';
				continue;
			}

			if ( false === $webp ) {
				$skipped[ $part ] = 'missing';
				continue;
			}

			// A .webp that is a Media Library item of its own is not our copy.
			$owner = $this->handlers->attachment_id_for_file( $directory . $webp_file );

			if ( 0 !== $owner && (int) $attachment_id !== $owner ) {
				$skipped[ $part ] = 'not_owned';
				continue;
			}

			// The original upload's dimensions are not in the metadata; read them from the file.
			if ( ( empty( $entry['width'] ) || empty( $entry['height'] ) ) && false !== $source['image'] ) {
				$entry['width']  = (int) $source['image'][0];
				$entry['height'] = (int) $source['image'][1];
			}

			$problem = $this->handlers->webp_problem( $webp['image'], $entry );

			if ( '' !== $problem ) {
				$skipped[ $part ] = $problem;
				continue;
			}

			// A WebP that saves nothing is not worth serving.
			if ( $webp['bytes'] >= $source['bytes'] ) {
				$skipped[ $part ] = 'larger_than_source';
				continue;
			}

			// Its URL failed verification and the file hasn't changed since: still unreachable.
			if ( isset( $unreachable[ $part ] ) && $this->is_same_file( $unreachable[ $part ], $webp_file, $webp ) ) {
				$kept_marks[ $part ] = $unreachable[ $part ];
				$skipped[ $part ]    = 'unreachable';
				continue;
			}

			$sizes[ $part ] = array(
				'source'       => $source_file,
				'webp'         => $webp_file,
				'source_bytes' => $source['bytes'],
				'webp_bytes'   => $webp['bytes'],
			);
		}

		return $this->shape( $sizes, $skipped, $kept_marks );
	}

	/**
	 * Whether an 'unreachable' mark still describes the WebP file on disk.
	 *
	 * @param mixed  $mark      Stored mark: {webp, webp_bytes, webp_mtime}.
	 * @param string $webp_file WebP basename now.
	 * @param array  $webp      read_file() facts for it now.
	 * @return bool
	 */
	private function is_same_file( $mark, $webp_file, $webp ) {
		return isset( $mark['webp'], $mark['webp_bytes'], $mark['webp_mtime'] )
			&& $webp_file === $mark['webp']
			&& $webp['bytes'] === $mark['webp_bytes']
			&& $webp['mtime'] === $mark['webp_mtime'];
	}

	/**
	 * Turns serving on: builds the manifest and stores it. The only method
	 * that may create the meta, so nothing else can turn serving back on.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array|WP_Error The stored manifest; nothing is stored on error.
	 */
	public function enable( $attachment_id ) {
		$manifest = $this->build( $attachment_id );

		if ( is_wp_error( $manifest ) ) {
			return $manifest;
		}

		update_post_meta( $attachment_id, self::META_KEY, $manifest );

		return $manifest;
	}

	/**
	 * The stored manifest, or null when serving is off.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array|null
	 */
	public function get( $attachment_id ) {
		$stored = get_post_meta( $attachment_id, self::META_KEY, true );

		if ( ! $this->is_valid( $stored ) ) {
			return null;
		}

		// Malformed 'unreachable' marks are dropped, not fatal.
		$marks                 = isset( $stored['unreachable'] ) && is_array( $stored['unreachable'] ) ? $stored['unreachable'] : array();
		$stored['unreachable'] = array();

		foreach ( $marks as $part => $mark ) {
			if ( is_string( $part ) && isset( $mark['webp'], $mark['webp_bytes'], $mark['webp_mtime'] ) && $this->is_basename( $mark['webp'] ) && is_int( $mark['webp_bytes'] ) && is_int( $mark['webp_mtime'] ) ) {
				$stored['unreachable'][ $part ] = $mark;
			}
		}

		return $stored;
	}

	/**
	 * Rebuilds the manifest of an attachment that is serving, after its files
	 * changed. Never turns serving on: only enable() may create the meta.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array|string|WP_Error The stored manifest (only rewritten when it changed),
	 *                               'not_serving' when serving is off, or WP_Error.
	 */
	public function rebuild( $attachment_id ) {
		if ( ! $this->is_serving_now( $attachment_id ) ) {
			return 'not_serving';
		}

		$previous = $this->get( $attachment_id );
		$manifest = $this->build( $attachment_id, $previous ? $previous['unreachable'] : array() );

		if ( is_wp_error( $manifest ) ) {
			return $manifest;
		}

		// Serving may have been turned off while the files were being checked.
		if ( ! $this->is_serving_now( $attachment_id ) ) {
			return 'not_serving';
		}

		// Unchanged apart from the build time: leave the stored copy alone.
		if ( null !== $previous && array_diff_key( $previous, array( 'built' => 0 ) ) === array_diff_key( $manifest, array( 'built' => 0 ) ) ) {
			return $previous;
		}

		update_post_meta( $attachment_id, self::META_KEY, $manifest );

		return $manifest;
	}

	/**
	 * Whether serving is on, read straight from the database rather than the
	 * object cache, so a toggle-off by another request is seen.
	 * Protected so tests can simulate "turned off while rebuilding".
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	protected function is_serving_now( $attachment_id ) {
		wp_cache_delete( $attachment_id, 'post_meta' );

		return metadata_exists( 'post', $attachment_id, self::META_KEY );
	}

	/**
	 * Turns serving off: removes the manifest. Nothing else changes.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	public function delete( $attachment_id ) {
		delete_post_meta( $attachment_id, self::META_KEY );
	}

	/**
	 * Whether a stored value is a manifest of the current version and shape.
	 * Anything else is treated as "off", silently.
	 *
	 * @param mixed $stored Stored meta value.
	 * @return bool
	 */
	private function is_valid( $stored ) {
		if ( ! is_array( $stored ) || ! isset( $stored['version'], $stored['sizes'], $stored['skipped'] ) || self::VERSION !== $stored['version'] ) {
			return false;
		}

		if ( ! is_array( $stored['sizes'] ) || ! is_array( $stored['skipped'] ) ) {
			return false;
		}

		foreach ( $stored['sizes'] as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['source'], $entry['webp'], $entry['source_bytes'], $entry['webp_bytes'] ) ) {
				return false;
			}

			if ( ! $this->is_basename( $entry['source'] ) || ! $this->is_basename( $entry['webp'] ) || ! is_int( $entry['source_bytes'] ) || ! is_int( $entry['webp_bytes'] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether a value is a non-empty file name with no directory part.
	 *
	 * @param mixed $name Value to check.
	 * @return bool
	 */
	private function is_basename( $name ) {
		return is_string( $name ) && '' !== $name && strcspn( $name, '/\\' ) === strlen( $name );
	}

	/**
	 * A manifest in the stored shape.
	 *
	 * @param array $sizes       Part => entry, for parts safe to serve.
	 * @param array $skipped     Part => reason code.
	 * @param array $unreachable Part => mark, for parts whose URL failed verification.
	 * @return array
	 */
	private function shape( $sizes, $skipped, $unreachable ) {
		return array(
			'version'     => self::VERSION,
			'sizes'       => $sizes,
			'skipped'     => $skipped,
			'unreachable' => $unreachable,
			'built'       => time(),
		);
	}

	/**
	 * The one place this class touches the filesystem: facts about a file,
	 * or false when there is no such file.
	 *
	 * @param string $path Absolute path.
	 * @return array|false {bytes, mtime, image: wp_getimagesize() result}.
	 */
	protected function read_file( $path ) {
		clearstatcache( true, $path );

		if ( ! is_file( $path ) ) {
			return false;
		}

		return array(
			'bytes' => filesize( $path ),
			'mtime' => filemtime( $path ),
			'image' => wp_getimagesize( $path ),
		);
	}
}
