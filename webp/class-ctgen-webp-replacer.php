<?php
/**
 * Serves verified WebP copies in place of JPEG/PNG in rendered front-end output.
 *
 * Only ever rewrites URLs in output being rendered: nothing stored (post
 * content, attachment metadata, options) is changed, so turning serving off
 * restores the original output immediately. Every URL decision is delegated
 * to CTGEN_Webp_Url_Mapper using the attachment's stored serve manifest; no
 * file is touched while rendering.
 *
 * @package Custom_Thumbnail_Generator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Front-end filters that swap image URLs for their WebP copies.
 */
class CTGEN_Webp_Replacer {

	/**
	 * Manifest service (read only here).
	 *
	 * @var CTGEN_Webp_Manifest
	 */
	private $manifest;

	/**
	 * URL mapper.
	 *
	 * @var CTGEN_Webp_Url_Mapper
	 */
	private $mapper;

	/**
	 * Sets up the replacer.
	 *
	 * @param CTGEN_Webp_Manifest   $manifest Manifest service.
	 * @param CTGEN_Webp_Url_Mapper $mapper   URL mapper.
	 */
	public function __construct( $manifest, $mapper ) {
		$this->manifest = $manifest;
		$this->mapper   = $mapper;
	}

	/**
	 * Hooks the replacer into WordPress's image output.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'wp_get_attachment_image_src', array( $this, 'filter_image_src' ), 10, 2 );
		add_filter( 'wp_calculate_image_srcset_meta', array( $this, 'filter_srcset_meta' ), 10, 4 );
		add_filter( 'wp_calculate_image_srcset', array( $this, 'filter_srcset' ), 10, 5 );
		add_filter( 'wp_content_img_tag', array( $this, 'filter_content_img_tag' ), 10, 3 );
		add_filter( 'render_block', array( $this, 'filter_render_block' ), 10, 2 );
	}

	/**
	 * Removes everything register() added.
	 *
	 * @return void
	 */
	public function unregister() {
		remove_filter( 'wp_get_attachment_image_src', array( $this, 'filter_image_src' ), 10 );
		remove_filter( 'wp_calculate_image_srcset_meta', array( $this, 'filter_srcset_meta' ), 10 );
		remove_filter( 'wp_calculate_image_srcset', array( $this, 'filter_srcset' ), 10 );
		remove_filter( 'wp_content_img_tag', array( $this, 'filter_content_img_tag' ), 10 );
		remove_filter( 'render_block', array( $this, 'filter_render_block' ), 10 );
	}

	/**
	 * Filter callback for 'wp_get_attachment_image_src'.
	 *
	 * @param array|false $image         {url, width, height, is_intermediate}, or false.
	 * @param int         $attachment_id Attachment ID.
	 * @return array|false
	 */
	public function filter_image_src( $image, $attachment_id ) {
		if ( ! isset( $image[0] ) || ! is_string( $image[0] ) ) {
			return $image;
		}

		$manifest = $this->servable_manifest( $attachment_id );

		if ( null === $manifest ) {
			return $image;
		}

		$image[0] = $this->mapper->map( $image[0], $manifest, $this->folder_url( $attachment_id ) );

		return $image;
	}

	/**
	 * Filter callback for 'wp_calculate_image_srcset_meta'.
	 *
	 * Core only builds a srcset when the image's src is one of the files in
	 * its metadata. Once filter_image_src() has turned that src into a WebP,
	 * wp_get_attachment_image() would lose its srcset. So, only when the src
	 * is one of this attachment's served WebPs, the srcset calculation gets a
	 * copy of the metadata in which the served sizes point at their WebP
	 * files. Nothing is stored; any other src leaves the metadata untouched.
	 *
	 * @param array  $image_meta    Attachment metadata.
	 * @param array  $size_array    {width, height} (unused).
	 * @param string $image_src     The 'src' of the image.
	 * @param int    $attachment_id Attachment ID.
	 * @return array
	 */
	public function filter_srcset_meta( $image_meta, $size_array, $image_src, $attachment_id ) {
		$manifest = $this->servable_manifest( $attachment_id );

		if ( null === $manifest || ! is_string( $image_src ) || ! isset( $image_meta['file'] ) || ! is_string( $image_meta['file'] ) ) {
			return $image_meta;
		}

		$src_file = wp_basename( substr( $image_src, 0, strcspn( $image_src, '?#' ) ) );

		if ( ! in_array( $src_file, wp_list_pluck( $manifest['sizes'], 'webp' ), true ) ) {
			return $image_meta;
		}

		foreach ( $manifest['sizes'] as $part => $entry ) {
			if ( 'full' === $part && wp_basename( $image_meta['file'] ) === $entry['source'] ) {
				$image_meta['file'] = substr( $image_meta['file'], 0, -strlen( $entry['source'] ) ) . $entry['webp'];
			} elseif ( isset( $image_meta['sizes'][ $part ]['file'] ) && $image_meta['sizes'][ $part ]['file'] === $entry['source'] ) {
				$image_meta['sizes'][ $part ]['file'] = $entry['webp'];
			}
		}

		return $image_meta;
	}

	/**
	 * Filter callback for 'wp_calculate_image_srcset'.
	 *
	 * @param array  $sources       Width/density => {url, descriptor, value}.
	 * @param array  $size_array    {width, height} (unused).
	 * @param string $image_src     The 'src' of the image (unused).
	 * @param array  $image_meta    The attachment metadata (unused).
	 * @param int    $attachment_id Attachment ID.
	 * @return array
	 */
	public function filter_srcset( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
		if ( ! is_array( $sources ) ) {
			return $sources;
		}

		$manifest = $this->servable_manifest( $attachment_id );

		if ( null === $manifest ) {
			return $sources;
		}

		$folder_url = $this->folder_url( $attachment_id );

		foreach ( $sources as $key => $source ) {
			if ( isset( $source['url'] ) ) {
				$sources[ $key ]['url'] = $this->mapper->map( $source['url'], $manifest, $folder_url );
			}
		}

		return $sources;
	}

	/**
	 * Filter callback for 'wp_content_img_tag': rewrites the src and srcset
	 * attributes of an <img> in post content, leaving every other byte of
	 * the tag as it was.
	 *
	 * @param string $html          The <img> tag.
	 * @param string $context       Filter context (unused).
	 * @param int    $attachment_id Attachment ID, or 0 when unknown.
	 * @return string
	 */
	public function filter_content_img_tag( $html, $context, $attachment_id ) {
		$manifest = $this->servable_manifest( $attachment_id );

		if ( null === $manifest ) {
			return $html;
		}

		$folder_url = $this->folder_url( $attachment_id );
		$tag        = new WP_HTML_Tag_Processor( $html );

		if ( ! $tag->next_tag( 'img' ) ) {
			return $html;
		}

		$src = $tag->get_attribute( 'src' );

		if ( is_string( $src ) ) {
			$mapped = $this->mapper->map( $src, $manifest, $folder_url );

			if ( $mapped !== $src ) {
				$tag->set_attribute( 'src', $mapped );
			}
		}

		$srcset = $tag->get_attribute( 'srcset' );

		if ( is_string( $srcset ) ) {
			$mapped = $this->map_srcset( $srcset, $manifest, $folder_url );

			if ( $mapped !== $srcset ) {
				$tag->set_attribute( 'srcset', $mapped );
			}
		}

		return $tag->get_updated_html();
	}

	/**
	 * Filter callback for 'render_block': image URLs that blocks print
	 * outside an <img>. Only core/cover's saved fixed/repeated background
	 * (a CSS background-image on its background element) needs this; every
	 * other image block output goes through the <img> filters.
	 *
	 * @param string $block_content Rendered block HTML.
	 * @param array  $block         Parsed block.
	 * @return string
	 */
	public function filter_render_block( $block_content, $block ) {
		if ( ! isset( $block['blockName'], $block['attrs']['id'] ) || 'core/cover' !== $block['blockName'] || ! is_string( $block_content ) ) {
			return $block_content;
		}

		$attachment_id = (int) $block['attrs']['id'];
		$manifest      = $this->servable_manifest( $attachment_id );

		if ( null === $manifest ) {
			return $block_content;
		}

		$folder_url = $this->folder_url( $attachment_id );
		$mapper     = $this->mapper;
		$tags       = new WP_HTML_Tag_Processor( $block_content );

		while ( $tags->next_tag( array( 'class_name' => 'wp-block-cover__image-background' ) ) ) {
			$style = $tags->get_attribute( 'style' );

			if ( ! is_string( $style ) ) {
				continue;
			}

			$mapped = preg_replace_callback(
				'/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/',
				function ( $found ) use ( $mapper, $manifest, $folder_url ) {
					return 'url(' . $found[1] . $mapper->map( $found[2], $manifest, $folder_url ) . $found[1] . ')';
				},
				$style
			);

			if ( $mapped !== $style ) {
				$tags->set_attribute( 'style', $mapped );
			}
		}

		return $tags->get_updated_html();
	}

	/**
	 * Maps every URL in a srcset attribute value, keeping descriptors and
	 * separators exactly as they were.
	 *
	 * @param string $srcset     Decoded srcset value.
	 * @param array  $manifest   Serve manifest.
	 * @param string $folder_url Attachment folder URL.
	 * @return string
	 */
	private function map_srcset( $srcset, $manifest, $folder_url ) {
		$mapper = $this->mapper;

		return preg_replace_callback(
			'/(^|,)(\s*)(\S+)/',
			function ( $candidate ) use ( $mapper, $manifest, $folder_url ) {
				return $candidate[1] . $candidate[2] . $mapper->map( $candidate[3], $manifest, $folder_url );
			},
			$srcset
		);
	}

	/**
	 * The attachment's manifest when its WebPs may be served in this request,
	 * or null.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array|null
	 */
	private function servable_manifest( $attachment_id ) {
		if ( ! $this->should_replace( $attachment_id ) ) {
			return null;
		}

		return $this->manifest->get( $attachment_id );
	}

	/**
	 * The front-end gate: whether this request may have an attachment's image
	 * URLs replaced. Never in admin or editing contexts, where a WebP URL
	 * could be saved into post content.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	private function should_replace( $attachment_id ) {
		if ( is_admin() || wp_doing_cron() || $this->is_rest_request() || $this->is_cli() ) {
			return false;
		}

		// Feeds keep the originals: feed readers and e-mail digests may not support WebP.
		// So does oEmbed output, which is also what the editor shows as an embed preview.
		if ( isset( $GLOBALS['wp_query'] ) && ( is_feed() || is_embed() ) ) {
			return false;
		}

		/**
		 * Filters whether an attachment's verified WebP copies are served in
		 * place of its JPEG/PNG files in this request. Return false to keep
		 * the original URLs (site-wide or per attachment).
		 *
		 * @param bool $replace       Whether to serve WebP. Default true.
		 * @param int  $attachment_id Attachment ID.
		 */
		return (bool) apply_filters( 'ctgen_webp_should_replace', true, $attachment_id );
	}

	/**
	 * Whether this runs under WP-CLI. Separate so tests can simulate it
	 * (WP_CLI is a constant).
	 *
	 * @return bool
	 */
	protected function is_cli() {
		return defined( 'WP_CLI' ) && WP_CLI;
	}

	/**
	 * Whether this is a REST API or other JSON request. Separate so tests can
	 * simulate a REST request (REST_REQUEST is a constant).
	 *
	 * @return bool
	 */
	protected function is_rest_request() {
		return wp_is_serving_rest_request() || wp_is_json_request();
	}

	/**
	 * URL of the uploads folder holding an attachment's files, from its
	 * stored relative path (no filesystem access).
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	private function folder_url( $attachment_id ) {
		$relative = get_post_meta( $attachment_id, '_wp_attached_file', true );
		$uploads  = wp_get_upload_dir();
		$folder   = dirname( $relative );

		return trailingslashit( $uploads['baseurl'] ) . ( '.' === $folder ? '' : trailingslashit( $folder ) );
	}
}
