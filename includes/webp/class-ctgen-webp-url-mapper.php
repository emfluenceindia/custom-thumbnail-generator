<?php
/**
 * Maps an image URL to the URL of its verified WebP copy.
 *
 * Pure string logic: no filesystem, database or hook access. A URL is only
 * ever mapped to a WebP that the attachment's serve manifest lists (see
 * CTGEN_Webp_Manifest); anything else is returned unchanged.
 *
 * @package Custom_Thumbnail_Generator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Source URL to WebP URL mapping for one attachment.
 */
class CTGEN_Webp_Url_Mapper {

	/**
	 * Returns the WebP URL for $url when it is one of the manifest's source
	 * files inside $folder_url, or $url unchanged.
	 *
	 * Anything malformed (non-string URL, manifest without a usable 'sizes'
	 * list, folder URL that isn't absolute) returns $url unchanged.
	 *
	 * @param mixed $url        URL to map.
	 * @param mixed $manifest   Serve manifest of the attachment the URL belongs to.
	 * @param mixed $folder_url URL of the folder holding the attachment's files, with trailing slash.
	 * @return mixed
	 */
	public function map( $url, $manifest, $folder_url ) {
		if ( ! is_string( $url ) || ! is_string( $folder_url ) || ! is_array( $manifest ) || ! isset( $manifest['sizes'] ) || ! is_array( $manifest['sizes'] ) ) {
			return $url;
		}

		$expected_folder = $this->normalize_folder( $folder_url );

		if ( '' === $expected_folder ) {
			return $url;
		}

		// Query string and fragment are carried over untouched.
		$suffix_at = strcspn( $url, '?#' );
		$path      = substr( $url, 0, $suffix_at );
		$suffix    = substr( $url, $suffix_at );

		// Split into the folder (kept exactly as given) and the file name.
		$folder_at = strrpos( $path, '/' );

		if ( false === $folder_at ) {
			return $url;
		}

		$folder = substr( $path, 0, $folder_at + 1 );
		$file   = substr( $path, $folder_at + 1 );

		if ( $this->normalize_folder( $folder ) !== $expected_folder ) {
			return $url;
		}

		foreach ( $manifest['sizes'] as $entry ) {
			if ( $this->is_usable_entry( $entry ) && $entry['source'] === $file ) {
				return $folder . $entry['webp'] . $suffix;
			}
		}

		return $url;
	}

	/**
	 * Whether a manifest size entry has a source and WebP file name, both
	 * plain basenames.
	 *
	 * @param mixed $entry Manifest 'sizes' entry.
	 * @return bool
	 */
	private function is_usable_entry( $entry ) {
		foreach ( array( 'source', 'webp' ) as $key ) {
			if ( ! isset( $entry[ $key ] ) || ! is_string( $entry[ $key ] ) || '' === $entry[ $key ] || strcspn( $entry[ $key ], '/\\' ) !== strlen( $entry[ $key ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * A folder URL in a form that compares equal across http, https and
	 * protocol-relative variants and host letter case.
	 *
	 * @param string $folder Absolute or protocol-relative folder URL.
	 * @return string
	 */
	private function normalize_folder( $folder ) {
		if ( ! preg_match( '#^(?:https?:)?//([^/]*)(.*)$#i', $folder, $parts ) ) {
			return '';
		}

		return '//' . strtolower( $parts[1] ) . $parts[2];
	}
}
