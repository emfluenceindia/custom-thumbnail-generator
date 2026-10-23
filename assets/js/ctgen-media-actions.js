/**
 * Settings page: add/remove custom sizes and regenerate thumbnails.
 *
 * Readable source of ctgen-media-actions.min.js (built by v2/tools/build-js.js).
 * Text from the server or from the page (size names, messages) is always
 * inserted as text, never as HTML.
 */
/* global jQuery, CTGENMediaAction */
( function ( $ ) {
	'use strict';

	var i18n = CTGENMediaAction.i18n || {};

	/**
	 * Replaces %s / %1$s placeholders, like sprintf for strings.
	 *
	 * @param {string} text Translated text.
	 * @return {string} Text with the arguments filled in.
	 */
	function format( text ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var next = 0;

		return String( text ).replace( /%(\d+\$)?s/g, function ( match, position ) {
			var index = position ? parseInt( position, 10 ) - 1 : next++;
			return undefined === args[ index ] ? '' : String( args[ index ] );
		} );
	}

	/**
	 * Error message sent by the server, or a generic one.
	 *
	 * @param {Object} response Parsed JSON response.
	 * @return {string} Message to show.
	 */
	function serverMessage( response ) {
		var data = response && response.data;

		if ( 'string' === typeof data && data ) {
			return data;
		}

		return data && 'string' === typeof data.message && data.message ? data.message : i18n.requestFailed;
	}

	/**
	 * Reloads the custom sizes table (server-rendered, escaped markup).
	 * Also available as window.reloadThumbList, as in earlier versions.
	 */
	function reloadThumbList() {
		$.post( CTGENMediaAction.ajax_url, {
			action: 'ctgen_reload_thumb_list',
			security: CTGENMediaAction.nonce,
		}, function ( response ) {
			if ( response && response.data && response.data.success ) {
				$( '#ctgen-thumb-list' ).html( response.data.data_list );
			} else {
				// eslint-disable-next-line no-console
				console.log( 'Something went wrong!' );
			}
		} );
	}

	/**
	 * Regenerate button content: recycle icon and label.
	 *
	 * @param {jQuery} $button Button to fill.
	 */
	function resetRegenerateButton( $button ) {
		$button.prop( 'disabled', false ).empty()
			.append( $( '<i class="fa fa-recycle" aria-hidden="true"></i>' ) )
			.append( document.createTextNode( ' ' + i18n.regenerate ) );
	}

	window.reloadThumbList = reloadThumbList;

	$( function () {
		$( '#ctgen-form' ).on( 'submit', function ( event ) {
			var fields = $( this ).serialize();
			var $response = $( '#ctgen-form-response' );
			var $width = $( '#ctgen-width' );
			var $height = $( '#ctgen-height' );
			var $crop = $( '#ctgen-crop' );

			event.preventDefault();

			$.post( CTGENMediaAction.ajax_url, $.extend( {
				action: 'ctgen_add_custom_size',
				security: CTGENMediaAction.nonce,
			}, Object.fromEntries( new URLSearchParams( fields ) ) ), function ( response ) {
				if ( response && response.success ) {
					$width.val( '' );
					$height.val( '' );
					$crop.prop( 'checked', false );
					$response.empty();
					reloadThumbList();
				} else {
					$response.empty().append( $( '<p style="color: red"></p>' ).text( serverMessage( response ) ) );
				}
			} ).fail( function () {
				$response.text( i18n.requestFailed );
			} );
		} );

		$( '#ctgen-thumb-list' ).on( 'click', '.ctgen-delete-custom-size', function () {
			var $button = $( this );
			var $row = $button.closest( 'tr' );
			var slug = String( $button.data( 'slug' ) );

			if ( ! window.confirm( format( i18n.confirmRemove, slug ) ) ) {
				return false;
			}

			$button.text( i18n.working );
			$row.find( 'td' ).css( 'color', '#aeaeae' );

			$.post( CTGENMediaAction.ajax_url, {
				action: 'ctgen_remove_custom_size',
				security: CTGENMediaAction.nonce,
				slug: slug,
			}, function ( response ) {
				if ( response && response.data && response.data.success ) {
					$row.slideUp( 1200 );
				} else {
					window.alert( serverMessage( response ) );
				}
			} );
		} );

		$( '#ctgen-thumb-list' ).on( 'click', '.ctgen-regen-thumbs', function () {
			var $button = $( this );
			var $title = $button.closest( 'tr' ).find( '.ctgen-title-cell' );
			var slug = String( $button.data( 'slug' ) );

			if ( ! window.confirm( format( i18n.confirmRegenerate, slug ) ) ) {
				return false;
			}

			$title.text( slug + ' ' ).append( $( '<span class="ctgen-working-hl"></span>' ).text( i18n.workingLower ) );
			$button.prop( 'disabled', true );

			$.post( CTGENMediaAction.ajax_url, {
				action: 'ctgen_regenerate_single_slug',
				security: CTGENMediaAction.nonce,
				slug: slug,
			}, function ( response ) {
				if ( response && response.success ) {
					$title.text( slug );
					$button.prop( 'disabled', false );
				} else {
					window.alert( serverMessage( response ) );
				}
			} );
		} );

		$( '#ctgen-regenerate' ).on( 'click', function () {
			$.post( CTGENMediaAction.ajax_url, {
				action: 'ctgen_get_attachments',
				security: CTGENMediaAction.nonce,
			}, function ( response ) {
				var ids;
				var total;
				var done = 0;

				if ( ! response || ! response.success ) {
					return;
				}

				ids = response.data.ids;
				total = ids.length;

				if ( total <= 0 ) {
					window.alert( i18n.noImages );
					return false;
				}

				$( '#ctgen-regenerate' ).prop( 'disabled', true ).text( i18n.processing );
				$( '#ctgen-progressbar' ).css( 'background-color', '#cecece' );

				function advance() {
					var percent;

					done++;
					percent = Math.round( done / total * 100 );

					$( '#ctgen-progress' ).css( 'width', percent + '%' );
					if ( percent >= 50 ) {
						$( '#ctgen-percentage-increment' ).css( 'color', '#ffffff' );
					}
					$( '#ctgen-percentage-increment' ).text( percent + '%' );
					$( '#ctgen-generator-status' ).text( format( i18n.processed, done, total ) );

					if ( done < total ) {
						setTimeout( next, 250 );
						return;
					}

					$( '#ctgen-generator-status' ).text( i18n.complete );
					resetRegenerateButton( $( '#ctgen-regenerate' ) );
					setTimeout( function () {
						$( '#ctgen-progress' ).css( 'width', '0%' );
						$( '#ctgen-percentage-increment' ).text( '' );
						$( '#ctgen-generator-status' ).text( '' );
						$( '#ctgen-progressbar' ).css( 'background-color', '#eeeeee' );
					}, 1000 );
				}

				function next() {
					if ( done >= total ) {
						return;
					}

					// A failed request is counted too, so the run always finishes.
					$.post( CTGENMediaAction.ajax_url, {
						action: 'ctgen_regenerate_single',
						security: CTGENMediaAction.nonce,
						attachment_id: ids[ done ],
					} ).always( advance );
				}

				next();
			} );
		} );

		$( '#ctgen-btn-shift-focus' ).on( 'click', function ( event ) {
			event.preventDefault();
			$( '#ctgen-width' ).trigger( 'focus' ).trigger( 'select' );
		} );
	} );
}( jQuery ) );
