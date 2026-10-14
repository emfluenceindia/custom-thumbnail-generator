<?php
/**
 * Reusable, standalone WebP conversion service.
 *
 * Converts one explicitly supplied JPEG/PNG/GIF file to a separately named
 * WebP file using WordPress's own image-editor APIs. This class knows
 * nothing about attachments, bulk loops, AJAX, or UI state; callers decide
 * *which* file to convert and *where* the result should go.
 *
 * @package Custom_Thumbnail_Generator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Converts a single image file to WebP.
 */
class CTGEN_Webp_Converter {

	/**
	 * MIME types this service will ever attempt to convert.
	 *
	 * Membership is decided from the file's real, content-sniffed MIME
	 * type (via wp_getimagesize()), never from its filename extension.
	 *
	 * @var string[]
	 */
	const ALLOWED_SOURCE_MIME_TYPES = array( 'image/jpeg', 'image/png', 'image/gif' );

	/**
	 * The WebP MIME type this service always produces.
	 *
	 * @var string
	 */
	const OUTPUT_MIME_TYPE = 'image/webp';

	/**
	 * Converts one source image file to a WebP file at the given destination.
	 *
	 * Collision policy: the destination path is fully controlled by the
	 * caller. This service never invents an alternate filename. A pre-existing
	 * destination is replaced only via an atomic rename of a verified-good
	 * temporary file, so retries are stable (no growing duplicate filenames)
	 * and a failed attempt never destroys a previously valid destination.
	 *
	 * @param string $source_path      Absolute path to an existing JPEG, PNG, or GIF file.
	 * @param string $destination_path Absolute path (ending in .webp) to write the result to.
	 * @return array|WP_Error {
	 *     Array on success, WP_Error on failure.
	 *
	 *     @type string $path      Absolute path to the generated WebP file.
	 *     @type string $file      Basename of the generated WebP file.
	 *     @type int    $width     Width of the generated WebP file, in pixels.
	 *     @type int    $height    Height of the generated WebP file, in pixels.
	 *     @type string $mime-type Always 'image/webp'.
	 * }
	 */
	public function convert( $source_path, $destination_path ) {
		$validated_source = $this->validate_source( $source_path );

		if ( is_wp_error( $validated_source ) ) {
			return $validated_source;
		}

		$validated_destination = $this->validate_destination( $source_path, $destination_path );

		if ( is_wp_error( $validated_destination ) ) {
			return $validated_destination;
		}

		if ( $this->is_animated_gif( $source_path ) ) {
			return new WP_Error(
				'ctgen_webp_animated_gif_unsupported',
				__( 'This GIF is animated. The active WordPress image editor selects only the first frame when loading a file, so converting it would silently discard the animation. This file was skipped instead of producing a flattened, misleading result.', 'custom-thumbnail-generator' ),
				array( 'source' => $source_path )
			);
		}

		$editor = wp_get_image_editor( $source_path );

		if ( is_wp_error( $editor ) ) {
			return new WP_Error(
				'ctgen_webp_editor_unavailable',
				__( 'No WordPress image editor is able to load this file.', 'custom-thumbnail-generator' ),
				array( 'source' => $source_path )
			);
		}

		if ( ! $editor->supports_mime_type( self::OUTPUT_MIME_TYPE ) ) {
			return new WP_Error(
				'ctgen_webp_output_unsupported',
				__( 'The active WordPress image editor cannot produce WebP output on this server.', 'custom-thumbnail-generator' ),
				array(
					'source' => $source_path,
					'editor' => get_class( $editor ),
				)
			);
		}

		return $this->save_via_temp_file( $editor, $destination_path, $source_path );
	}

	/**
	 * Reports whether any registered WordPress image editor can produce WebP
	 * output at all in this environment, without loading a specific file.
	 *
	 * @return bool
	 */
	public static function environment_supports_webp() {
		return (bool) wp_image_editor_supports( array( 'mime_type' => self::OUTPUT_MIME_TYPE ) );
	}

	/**
	 * Validates that the source file exists, is readable, and is one of the
	 * supported formats according to its real, content-sniffed MIME type.
	 *
	 * @param string $source_path Absolute path to the candidate source file.
	 * @return true|WP_Error
	 */
	private function validate_source( $source_path ) {
		if ( '' === $source_path || ! is_string( $source_path ) ) {
			return new WP_Error(
				'ctgen_webp_source_not_found',
				__( 'No source file path was supplied.', 'custom-thumbnail-generator' )
			);
		}

		if ( ! file_exists( $source_path ) ) {
			return new WP_Error(
				'ctgen_webp_source_not_found',
				__( 'The source file does not exist.', 'custom-thumbnail-generator' ),
				array( 'source' => $source_path )
			);
		}

		if ( ! is_readable( $source_path ) ) {
			return new WP_Error(
				'ctgen_webp_source_unreadable',
				__( 'The source file exists but cannot be read.', 'custom-thumbnail-generator' ),
				array( 'source' => $source_path )
			);
		}

		$image_info = wp_getimagesize( $source_path );

		if ( false === $image_info || empty( $image_info['mime'] ) ) {
			return new WP_Error(
				'ctgen_webp_source_corrupt',
				__( 'The source file is not a readable image; it may be corrupt or truncated.', 'custom-thumbnail-generator' ),
				array( 'source' => $source_path )
			);
		}

		if ( ! in_array( $image_info['mime'], self::ALLOWED_SOURCE_MIME_TYPES, true ) ) {
			return new WP_Error(
				'ctgen_webp_unsupported_source_type',
				/* translators: %s: the detected MIME type of the rejected file. */
				sprintf( __( 'Unsupported source type "%s". Only JPEG, PNG, and GIF are supported.', 'custom-thumbnail-generator' ), $image_info['mime'] ),
				array(
					'source' => $source_path,
					'mime'   => $image_info['mime'],
				)
			);
		}

		return true;
	}

	/**
	 * Validates that the destination is safe to write to: a .webp path that
	 * cannot resolve to the source file, inside a directory that exists and
	 * is writable.
	 *
	 * @param string $source_path      Absolute path to the validated source file.
	 * @param string $destination_path Absolute path the caller wants the WebP written to.
	 * @return true|WP_Error
	 */
	private function validate_destination( $source_path, $destination_path ) {
		if ( '' === $destination_path || ! is_string( $destination_path ) ) {
			return new WP_Error(
				'ctgen_webp_invalid_destination',
				__( 'No destination path was supplied.', 'custom-thumbnail-generator' )
			);
		}

		if ( 'webp' !== strtolower( pathinfo( $destination_path, PATHINFO_EXTENSION ) ) ) {
			return new WP_Error(
				'ctgen_webp_invalid_destination',
				__( 'The destination path must end in .webp.', 'custom-thumbnail-generator' ),
				array( 'destination' => $destination_path )
			);
		}

		$destination_dir = dirname( $destination_path );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Local, internal media-processing check; consistent with core's own WP_Image_Editor, which also uses direct filesystem calls rather than WP_Filesystem.
		if ( ! is_dir( $destination_dir ) || ! is_writable( $destination_dir ) ) {
			return new WP_Error(
				'ctgen_webp_invalid_destination',
				__( 'The destination directory does not exist or is not writable.', 'custom-thumbnail-generator' ),
				array( 'destination' => $destination_path )
			);
		}

		$real_source      = realpath( $source_path );
		$real_dest_target = realpath( $destination_dir ) . DIRECTORY_SEPARATOR . basename( $destination_path );

		if ( false !== $real_source && $real_source === $real_dest_target ) {
			return new WP_Error(
				'ctgen_webp_invalid_destination',
				__( 'The destination cannot be the same file as the source.', 'custom-thumbnail-generator' ),
				array( 'destination' => $destination_path )
			);
		}

		return true;
	}

	/**
	 * Saves the editor's output to a verified temporary file first, then
	 * atomically renames it onto the real destination only after confirming
	 * the temporary file is a genuinely valid WebP image. This guarantees a
	 * failed or partial write never reports success and never overwrites a
	 * previously valid destination with a corrupt one.
	 *
	 * @param WP_Image_Editor $editor           A loaded, WebP-capable image editor instance.
	 * @param string          $destination_path Absolute destination path (ending in .webp).
	 * @param string          $source_path      Absolute source path, for error context only.
	 * @return array|WP_Error
	 */
	private function save_via_temp_file( $editor, $destination_path, $source_path ) {
		$temp_dir  = dirname( $destination_path );
		$temp_hint = $temp_dir . '/.ctgen-webp-tmp-' . wp_generate_password( 12, false, false ) . '.webp';

		$saved = $editor->save( $temp_hint, self::OUTPUT_MIME_TYPE );

		if ( is_wp_error( $saved ) ) {
			$this->delete_temp_file( $temp_hint );

			return new WP_Error(
				'ctgen_webp_save_failed',
				__( 'The image editor failed to write the WebP file.', 'custom-thumbnail-generator' ),
				array(
					'source' => $source_path,
					'reason' => $saved->get_error_message(),
				)
			);
		}

		/*
		 * WP_Image_Editor::save() rewrites the extension of the path it is
		 * given to match the target mime type (see get_output_format()), so
		 * the file it actually wrote may not be at $temp_hint verbatim.
		 * $saved['path'] is the one authoritative location; trust that, not
		 * the input hint.
		 */
		$temp_path = $saved['path'];

		$verified = $this->verify_output( $temp_path );

		if ( is_wp_error( $verified ) ) {
			$this->delete_temp_file( $temp_path );

			return $verified;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- Atomic move of a verified-good temp file; a race with an external process is the only realistic failure and is handled via the return-value check, not the warning.
		if ( ! @rename( $temp_path, $destination_path ) ) {
			$this->delete_temp_file( $temp_path );

			return new WP_Error(
				'ctgen_webp_save_failed',
				__( 'The generated WebP file could not be moved into place.', 'custom-thumbnail-generator' ),
				array( 'destination' => $destination_path )
			);
		}

		return array(
			'path'      => $destination_path,
			'file'      => wp_basename( $destination_path ),
			'width'     => $verified['width'],
			'height'    => $verified['height'],
			'mime-type' => self::OUTPUT_MIME_TYPE,
		);
	}

	/**
	 * Confirms a freshly-written file is a genuinely valid, decodable WebP
	 * image with sane dimensions before it is allowed to become the real
	 * destination.
	 *
	 * @param string $temp_path Absolute path to the just-written temporary file.
	 * @return array|WP_Error Dimensions on success, WP_Error on failure.
	 */
	private function verify_output( $temp_path ) {
		if ( ! file_exists( $temp_path ) || 0 === filesize( $temp_path ) ) {
			return new WP_Error(
				'ctgen_webp_output_invalid',
				__( 'The conversion produced an empty file.', 'custom-thumbnail-generator' )
			);
		}

		$image_info = wp_getimagesize( $temp_path );

		if ( false === $image_info || empty( $image_info['mime'] ) || self::OUTPUT_MIME_TYPE !== $image_info['mime'] ) {
			return new WP_Error(
				'ctgen_webp_output_invalid',
				__( 'The conversion did not produce a valid, readable WebP file.', 'custom-thumbnail-generator' )
			);
		}

		if ( empty( $image_info[0] ) || empty( $image_info[1] ) ) {
			return new WP_Error(
				'ctgen_webp_output_invalid',
				__( 'The conversion produced a WebP file with invalid dimensions.', 'custom-thumbnail-generator' )
			);
		}

		return array(
			'width'  => (int) $image_info[0],
			'height' => (int) $image_info[1],
		);
	}

	/**
	 * Removes a leftover temporary file, if any. Never touches the real
	 * destination path.
	 *
	 * @param string $temp_path Absolute path to the temporary file.
	 * @return void
	 */
	private function delete_temp_file( $temp_path ) {
		if ( file_exists( $temp_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Deleting our own temp file, guarded by file_exists() above; wp_delete_file() is intended for attachment-tracked files, not scratch temp files.
			unlink( $temp_path );
		}
	}

	/**
	 * Determines whether a GIF file contains more than one frame.
	 *
	 * Uses Imagick's own frame count when the Imagick PHP extension is
	 * available, since that is exact. Falls back to a byte-level scan for
	 * the well-known Graphic Control Extension + Image Descriptor pattern
	 * when Imagick is not loaded; this heuristic is a fallback, not the
	 * primary method, precisely because it is not exact.
	 *
	 * @param string $source_path Absolute path to a file already confirmed to be a GIF.
	 * @return bool
	 */
	private function is_animated_gif( $source_path ) {
		$image_info = wp_getimagesize( $source_path );

		if ( false === $image_info || 'image/gif' !== $image_info['mime'] ) {
			return false;
		}

		if ( class_exists( 'Imagick' ) ) {
			try {
				$imagick = new Imagick( $source_path );
				$frames  = $imagick->getNumberImages();
				$imagick->clear();

				return $frames > 1;
			} catch ( Exception $e ) {
				return false;
			}
		}

		return $this->scan_gif_bytes_for_multiple_frames( $source_path );
	}

	/**
	 * Fallback, non-exact GIF frame detector for environments without the
	 * Imagick PHP extension.
	 *
	 * @param string $source_path Absolute path to a file already confirmed to be a GIF.
	 * @return bool
	 */
	private function scan_gif_bytes_for_multiple_frames( $source_path ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$raw = file_get_contents( $source_path );

		if ( false === $raw ) {
			return false;
		}

		$offset = 0;
		$frames = 0;

		while ( $frames < 2 ) {
			$control_extension = strpos( $raw, "\x00\x21\xF9\x04", $offset );

			if ( false === $control_extension ) {
				break;
			}

			$offset           = $control_extension + 1;
			$image_descriptor = strpos( $raw, "\x00\x2C", $offset );

			if ( false === $image_descriptor ) {
				break;
			}

			if ( $control_extension + 8 === $image_descriptor ) {
				++$frames;
			}

			$offset = $image_descriptor + 1;
		}

		return $frames > 1;
	}
}
