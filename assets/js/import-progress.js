/**
 * Polling-based import progress UI.
 *
 * @package Better_WordPress_Importer
 * @since 1.2.0
 */
( function ( $ ) {
	'use strict';

	if ( typeof betterImporterProgress === 'undefined' ) {
		return;
	}

	var batchInFlight = false;
	var pollTimer = null;
	var isPaused = false;
	var isTerminal = false;

	function request( action, method, data ) {
		data = data || {};
		data.action = action;
		data.job_id = betterImporterProgress.jobId;
		data.nonce = betterImporterProgress.nonce;

		return $.ajax( {
			url: betterImporterProgress.ajaxUrl,
			type: method || 'POST',
			data: data
		} );
	}

	function renderCounts( status ) {
		var imported = status.counts && status.counts.imported ? status.counts.imported : {};
		var skipped = status.counts && status.counts.skipped ? status.counts.skipped : {};
		var total = status.counts && status.counts.total ? status.counts.total : {};
		var html = '';

		[ 'posts', 'media', 'terms', 'users' ].forEach( function ( type ) {
			html += '<div class="better-importer-count-card">';
			html += '<strong>' + type + '</strong><br>';
			html += ( imported[ type ] || 0 ) + ' imported';
			if ( skipped[ type ] ) {
				html += '<br>' + skipped[ type ] + ' skipped';
			}
			html += '<br><span class="description">' + ( total[ type ] || 0 ) + ' total</span>';
			html += '</div>';
		} );

		$( '#better-importer-count-grid' ).html( html );
	}

	function renderLogs( logs ) {
		if ( ! logs || ! logs.length ) {
			return;
		}

		var html = '';
		logs.forEach( function ( entry ) {
			html += '<li><code>' + entry.created_at + '</code> ' + entry.message + '</li>';
		} );
		$( '#better-importer-log-list' ).html( html );
	}

	function renderStatus( status ) {
		if ( ! status ) {
			return;
		}

		isPaused = status.status === 'paused';
		isTerminal = status.is_terminal;

		$( '#better-importer-progress-fill' ).css( 'width', ( status.percent || 0 ) + '%' );
		$( '#better-importer-progress-label' ).text(
			( status.processed || 0 ) + ' / ' + ( status.total_entities || 0 ) + ' (' + ( status.percent || 0 ) + '%) · ' + ( status.phase_label || '' )
		);

		if ( status.current_entity ) {
			$( '#better-importer-current-entity' ).removeAttr( 'hidden' );
			$( '#better-importer-current-title' ).text( status.current_entity.title || '' );
			$( '#better-importer-current-step' ).text(
				status.current_entity.step + ' ' + status.current_entity.step_cursor + '/' + status.current_entity.step_total
			);
		} else {
			$( '#better-importer-current-entity' ).attr( 'hidden', true );
		}

		renderCounts( status );
		renderLogs( status.logs );

		$( '#better-importer-pause-btn' ).text( isPaused ? betterImporterProgress.strings.resume : betterImporterProgress.strings.pause );

		if ( status.is_complete ) {
			$( '#better-importer-status-message' ).text( betterImporterProgress.strings.complete );
			$( '#better-importer-complete-actions' ).removeAttr( 'hidden' );
			stopPolling();
		} else if ( status.status === 'cancelled' ) {
			$( '#better-importer-status-message' ).text( betterImporterProgress.strings.cancelled );
			stopPolling();
		} else if ( status.status === 'failed' ) {
			$( '#better-importer-status-message' ).text( betterImporterProgress.strings.failed );
			stopPolling();
		} else if ( isPaused ) {
			$( '#better-importer-status-message' ).text( betterImporterProgress.strings.paused );
		} else {
			$( '#better-importer-status-message' ).text( betterImporterProgress.strings.background );
		}
	}

	function pollStatus() {
		request( 'better-import-status', 'GET' ).done( function ( response ) {
			if ( response.success ) {
				renderStatus( response.data );
			}
		} );
	}

	function runBatch() {
		if ( batchInFlight || isPaused || isTerminal ) {
			return;
		}

		batchInFlight = true;
		request( 'better-import-batch', 'POST' ).always( function () {
			batchInFlight = false;
		} ).done( function ( response ) {
			if ( response.success ) {
				if ( response.data.status ) {
					renderStatus( response.data.status );
				}
				if ( response.data.is_complete || ( response.data.status && response.data.status.is_complete ) ) {
					stopPolling();
					return;
				}
				if ( ! isPaused && ! isTerminal ) {
					runBatch();
				}
			} else {
				$( '#better-importer-status-message' ).text(
					response.data && response.data.message ? response.data.message : betterImporterProgress.strings.connectionLost
				);
			}
		} ).fail( function () {
			$( '#better-importer-status-message' ).text( betterImporterProgress.strings.connectionLost );
		} );
	}

	function startPolling() {
		if ( pollTimer ) {
			return;
		}
		pollTimer = window.setInterval( pollStatus, betterImporterProgress.pollInterval );
	}

	function stopPolling() {
		if ( pollTimer ) {
			window.clearInterval( pollTimer );
			pollTimer = null;
		}
	}

	$( '#better-importer-pause-btn' ).on( 'click', function () {
		var action = isPaused ? 'better-import-resume' : 'better-import-pause';
		request( action, 'POST' ).done( function ( response ) {
			if ( response.success ) {
				renderStatus( response.data );
				if ( ! isPaused && ! isTerminal ) {
					runBatch();
				}
			}
		} );
	} );

	$( '#better-importer-cancel-btn' ).on( 'click', function () {
		if ( ! window.confirm( 'Cancel this import?' ) ) {
			return;
		}
		request( 'better-import-cancel', 'POST' ).done( function ( response ) {
			if ( response.success ) {
				renderStatus( response.data );
			}
		} );
	} );

	renderStatus( betterImporterProgress.initial );
	startPolling();
	if ( ! betterImporterProgress.initial.is_terminal && betterImporterProgress.initial.status !== 'paused' ) {
		runBatch();
	}
}( jQuery ) );
