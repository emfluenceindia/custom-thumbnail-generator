/**
 * WebP conversion controls for the Custom Thumbnail Generator settings page.
 *
 * Readable source. The enqueued ctgen-webp-convert.min.js is generated from
 * this file by v2/tools/build-js.js - never edit the .min.js by hand.
 *
 * Talks only to the WebP endpoints, one attachment per request:
 *   ctgen_webp_get_eligible_attachments -> { ids: [ ... ] }
 *   ctgen_webp_convert_attachment       -> { attachment_id, sizes, counts }
 *
 * Every response is checked before the counters move: a request that merely
 * completed is never counted as a success.
 */
( function ( $ ) {
	'use strict';

	var config = window.CTGENWebpConvert || {};
	var i18n = config.i18n || {};

	var REQUEST_DELAY_MS = 100;
	var MAX_CONSECUTIVE_REQUEST_ERRORS = 3;
	var ANNOUNCE_STEP_PERCENT = 25;
	var MAX_LISTED_ERRORS = 50;
	var OUTCOMES = [ 'converted', 'already_current', 'skipped', 'failed' ];

	// Request-level errors that would repeat for every attachment: stop the run.
	var STOP_CODES = [ 'ctgen_webp_unknown_size', 'ctgen_webp_missing_size', 'ctgen_webp_invalid_scope' ];

	var running = false;
	var ui = {};
	var bulkLabel = '';

	/**
	 * Fills %s, %1$s ... placeholders (as used in the PHP translations) and %%.
	 *
	 * @param {string} template Translated string.
	 * @return {string} Formatted string.
	 */
	function format( template ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var next = 0;

		return String( template || '' ).replace( /%(?:(\d+)\$s|s|%)/g, function ( match, position ) {
			var value;

			if ( '%%' === match ) {
				return '%';
			}

			value = position ? args[ position - 1 ] : args[ next++ ];

			return undefined === value ? match : String( value );
		} );
	}

	function isNonNegativeInt( value ) {
		return 'number' === typeof value && value >= 0 && Math.floor( value ) === value;
	}

	/**
	 * A successful conversion response must carry consistent, numeric counts.
	 *
	 * @param {Object} data Response data.
	 * @return {boolean} Whether the data can be trusted.
	 */
	function isValidResult( data ) {
		var total = 0;
		var i;

		if ( ! data || 'object' !== typeof data.counts || null === data.counts || 'object' !== typeof data.sizes || null === data.sizes ) {
			return false;
		}

		for ( i = 0; i < OUTCOMES.length; i++ ) {
			if ( ! isNonNegativeInt( data.counts[ OUTCOMES[ i ] ] ) ) {
				return false;
			}
			total += data.counts[ OUTCOMES[ i ] ];
		}

		return total === Object.keys( data.sizes ).length;
	}

	function isAuthFailure( xhr ) {
		var body = xhr && 'string' === typeof xhr.responseText ? $.trim( xhr.responseText ) : '';

		return 403 === xhr.status || '-1' === body || '0' === body;
	}

	function failureReason( xhr, textStatus ) {
		if ( 0 === xhr.status && 'parsererror' !== textStatus ) {
			return i18n.network;
		}

		return i18n.malformed;
	}

	function cacheUi() {
		ui.root = $( '#ctgen-webp-ui' );
		ui.bulk = $( '#ctgen-webp-convert-all' );
		ui.bar = $( '#ctgen-webp-progressbar' );
		ui.fill = $( '#ctgen-webp-progress' );
		ui.percent = $( '#ctgen-webp-percentage' );
		ui.current = $( '#ctgen-webp-current' );
		ui.counts = $( '#ctgen-webp-counts' );
		ui.status = $( '#ctgen-webp-status' );
		ui.errors = $( '#ctgen-webp-errors' );
	}

	function setBusy( busy ) {
		running = busy;
		ui.bulk.prop( 'disabled', busy ).text( busy ? i18n.busy : bulkLabel );
		$( '.ctgen-webp-convert-size' ).prop( 'disabled', busy );
	}

	/**
	 * Live-region text. Only called at start, at every ANNOUNCE_STEP_PERCENT
	 * and at the end, so assistive technology is not flooded.
	 *
	 * @param {string} text Message.
	 */
	function setStatus( text ) {
		ui.status.text( text );
	}

	function newRun( scope, size, trigger ) {
		return {
			scope: scope,
			size: size,
			trigger: trigger,
			ids: [],
			index: 0,
			total: 0,
			imagesFailed: 0,
			consecutiveErrors: 0,
			lastAnnounced: 0,
			sizes: { converted: 0, already_current: 0, skipped: 0, failed: 0 },
			errors: []
		};
	}

	function percentOf( run ) {
		return run.total ? Math.floor( ( run.index / run.total ) * 100 ) : 0;
	}

	function renderProgress( run ) {
		var pct = percentOf( run );
		var text = format( i18n.counts, run.sizes.converted, run.sizes.already_current, run.sizes.skipped, run.sizes.failed );

		if ( run.imagesFailed ) {
			text += ' ' + format( i18n.imagesFailed, run.imagesFailed );
		}

		ui.fill.css( 'width', pct + '%' );
		ui.percent.text( pct + '%' ).toggleClass( 'ctgen-webp-percentage-light', pct >= 50 );
		ui.bar.attr( 'aria-valuenow', pct );
		ui.counts.text( text ).attr( {
			'data-processed': run.index,
			'data-total': run.total,
			'data-converted': run.sizes.converted,
			'data-already-current': run.sizes.already_current,
			'data-skipped': run.sizes.skipped,
			'data-failed': run.sizes.failed,
			'data-images-failed': run.imagesFailed
		} );

		// Rows re-rendered by the custom-size table reload must stay disabled too.
		if ( running ) {
			$( '.ctgen-webp-convert-size' ).prop( 'disabled', true );
		}
	}

	function announce( run ) {
		var pct = percentOf( run );
		var step = Math.floor( pct / ANNOUNCE_STEP_PERCENT ) * ANNOUNCE_STEP_PERCENT;

		if ( step > run.lastAnnounced && pct < 100 ) {
			run.lastAnnounced = step;
			setStatus( format( i18n.announce, step, run.index, run.total ) );
		}
	}

	function renderErrors( run ) {
		var i;

		ui.errors.empty();

		for ( i = 0; i < run.errors.length && i < MAX_LISTED_ERRORS; i++ ) {
			ui.errors.append( $( '<li></li>' ).text( run.errors[ i ] ) );
		}

		if ( run.errors.length > MAX_LISTED_ERRORS ) {
			ui.errors.append( $( '<li></li>' ).text( format( i18n.more, run.errors.length - MAX_LISTED_ERRORS ) ) );
		}
	}

	function resetUi() {
		ui.fill.css( 'width', '0%' );
		ui.percent.text( '' ).removeClass( 'ctgen-webp-percentage-light' );
		ui.bar.attr( 'aria-valuenow', 0 );
		ui.current.text( '' );
		ui.counts.text( '' ).removeAttr( 'data-processed data-total data-converted data-already-current data-skipped data-failed data-images-failed' );
		ui.errors.empty();
	}

	/**
	 * Terminal state. state: 'empty', 'complete', 'complete-with-errors' or 'stopped'.
	 *
	 * @param {Object} run    Run state.
	 * @param {string} state  Terminal state.
	 * @param {string} reason Stop reason (state 'stopped' only).
	 */
	function finish( run, state, reason ) {
		ui.current.text( '' );

		if ( 'empty' === state ) {
			setStatus( i18n.nothing );
		} else {
			renderProgress( run );
			renderErrors( run );

			if ( 'stopped' === state ) {
				setStatus( format( i18n.stopped, run.index, run.total, reason ) );
			} else if ( 'complete-with-errors' === state ) {
				setStatus( format( i18n.doneErrors, run.index, run.total ) );
			} else {
				setStatus( format( i18n.doneOk, run.index, run.total ) );
			}
		}

		ui.root.attr( 'data-state', state );
		setBusy( false );

		// Disabling the clicked button drops keyboard focus; give it back.
		if ( run.trigger && document.body.contains( run.trigger ) && ( ! document.activeElement || document.body === document.activeElement ) ) {
			run.trigger.focus();
		}
	}

	function advance( run ) {
		run.index++;
		renderProgress( run );
		announce( run );
		window.setTimeout( function () {
			convertNext( run );
		}, REQUEST_DELAY_MS );
	}

	/**
	 * A request that produced no usable answer (network, malformed JSON, server error).
	 * Counted against the image; several in a row stop the run.
	 *
	 * @param {Object} run    Run state.
	 * @param {number} id     Attachment ID.
	 * @param {string} reason Message.
	 */
	function requestError( run, id, reason ) {
		run.imagesFailed++;
		run.errors.push( format( i18n.itemError, id, reason ) );
		run.consecutiveErrors++;

		if ( run.consecutiveErrors >= MAX_CONSECUTIVE_REQUEST_ERRORS ) {
			run.index++;
			finish( run, 'stopped', reason );
			return;
		}

		advance( run );
	}

	function handleItem( run, id, response ) {
		var slug;

		if ( response && true === response.success && isValidResult( response.data ) ) {
			run.consecutiveErrors = 0;

			$.each( OUTCOMES, function ( i, outcome ) {
				run.sizes[ outcome ] += response.data.counts[ outcome ];
			} );

			for ( slug in response.data.sizes ) {
				if ( Object.prototype.hasOwnProperty.call( response.data.sizes, slug ) && 'failed' === response.data.sizes[ slug ].outcome ) {
					run.errors.push( format( i18n.sizeError, id, slug, response.data.sizes[ slug ].message || i18n.malformed ) );
				}
			}

			advance( run );
			return;
		}

		if ( response && false === response.success && response.data && 'string' === typeof response.data.code ) {
			run.consecutiveErrors = 0;

			if ( -1 !== $.inArray( response.data.code, STOP_CODES ) ) {
				finish( run, 'stopped', response.data.message || i18n.malformed );
				return;
			}

			run.imagesFailed++;
			run.errors.push( format( i18n.itemError, id, response.data.message || i18n.malformed ) );
			advance( run );
			return;
		}

		requestError( run, id, i18n.malformed );
	}

	function convertNext( run ) {
		var id;
		var data;

		if ( run.index >= run.total ) {
			finish( run, run.imagesFailed || run.sizes.failed ? 'complete-with-errors' : 'complete' );
			return;
		}

		id = run.ids[ run.index ];
		ui.current.text( format( i18n.processing, run.index + 1, run.total ) );

		data = {
			action: 'ctgen_webp_convert_attachment',
			security: config.nonce,
			attachment_id: id,
			scope: run.scope
		};

		if ( 'size' === run.scope ) {
			data.size = run.size;
		}

		$.ajax( { url: config.ajax_url, type: 'POST', dataType: 'json', data: data } )
			.done( function ( response ) {
				handleItem( run, id, response );
			} )
			.fail( function ( xhr, textStatus ) {
				if ( isAuthFailure( xhr ) ) {
					finish( run, 'stopped', i18n.auth );
					return;
				}
				requestError( run, id, failureReason( xhr, textStatus ) );
			} );
	}

	/**
	 * Starts a run. scope 'all_sizes' (bulk) or 'size' (one row's size slug).
	 *
	 * @param {string}      scope   Conversion scope.
	 * @param {string}      size    Size slug for scope 'size'.
	 * @param {HTMLElement} trigger The clicked control.
	 */
	function start( scope, size, trigger ) {
		var run;

		if ( running ) {
			return;
		}

		run = newRun( scope, size, trigger );
		setBusy( true );
		resetUi();
		ui.root.attr( 'data-state', 'running' );
		setStatus( 'size' === scope ? format( i18n.findingSize, size ) : i18n.finding );

		$.ajax( {
			url: config.ajax_url,
			type: 'POST',
			dataType: 'json',
			data: { action: 'ctgen_webp_get_eligible_attachments', security: config.nonce }
		} )
			.done( function ( response ) {
				var ids = response && true === response.success && response.data ? response.data.ids : null;
				var i;

				if ( ! $.isArray( ids ) ) {
					finish( run, 'stopped', i18n.malformed );
					return;
				}

				for ( i = 0; i < ids.length; i++ ) {
					if ( ! isNonNegativeInt( ids[ i ] ) || 0 === ids[ i ] ) {
						finish( run, 'stopped', i18n.malformed );
						return;
					}
				}

				run.ids = ids;
				run.total = ids.length;

				if ( 0 === run.total ) {
					finish( run, 'empty' );
					return;
				}

				renderProgress( run );
				convertNext( run );
			} )
			.fail( function ( xhr, textStatus ) {
				finish( run, 'stopped', isAuthFailure( xhr ) ? i18n.auth : failureReason( xhr, textStatus ) );
			} );
	}

	$( function () {
		cacheUi();

		if ( ! ui.root.length ) {
			return;
		}

		bulkLabel = $.trim( ui.bulk.text() );

		ui.bulk.on( 'click', function ( event ) {
			event.preventDefault();
			start( 'all_sizes', '', this );
		} );

		// Delegated: rows in #ctgen-thumb-list are re-rendered after a size is added or removed.
		$( document ).on( 'click', '.ctgen-webp-convert-size', function ( event ) {
			// attr(), not data(): data() would turn a numeric slug such as "300" into a number.
			var slug = $( this ).attr( 'data-slug' );

			event.preventDefault();

			if ( slug ) {
				start( 'size', String( slug ), this );
			}
		} );
	} );
}( jQuery ) );
