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
	var lastLogId = 0;
	var hasRenderedInitialLogs = false;

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
		var failed = status.counts && status.counts.failed ? status.counts.failed : {};
		var total = status.counts && status.counts.total ? status.counts.total : {};
		var html = '';

		[ 'posts', 'media', 'terms', 'users' ].forEach( function ( type ) {
			html += '<div class="better-importer-count-card">';
			html += '<strong>' + type + '</strong><br>';
			html += ( imported[ type ] || 0 ) + ' imported';
			if ( skipped[ type ] ) {
				html += '<br>' + skipped[ type ] + ' skipped';
			}
			if ( failed[ type ] ) {
				html += '<br><span class="better-importer-count-failed">' + failed[ type ] + ' failed</span>';
			}
			html += '<br><span class="description">' + ( total[ type ] || 0 ) + ' total</span>';
			html += '</div>';
		} );

		$( '#better-importer-count-grid' ).html( html );
	}

	function buildLogHtml( logs ) {
		var html = '';

		if ( logs && logs.length ) {
			logs.forEach( function ( entry ) {
				var createdAt = $( '<div>' ).text( entry.created_at || '' ).html();
				var message = $( '<div>' ).text( entry.message || '' ).html();
				var level = String( entry.level || 'info' ).replace( /[^a-z0-9_-]/gi, '' ).toLowerCase();
				var levelLabel = $( '<div>' ).text( level ).html();

				html += '<li class="better-importer-log-entry better-importer-log-entry-' + level + '"><code>' + createdAt + '</code> <strong>' + levelLabel + '</strong> ' + message + '</li>';
			} );
		}

		return html;
	}

	function renderLogs( logs ) {
		var $list = $( '#better-importer-log-list' );
		var html = buildLogHtml( logs );

		if ( ! hasRenderedInitialLogs ) {
			$list.html( html );
			hasRenderedInitialLogs = true;
		} else if ( html ) {
			$list.append( html );
		}

		while ( $list.children().length > 200 ) {
			$list.children().first().remove();
		}
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
		if ( status.log_cursor ) {
			lastLogId = Math.max( lastLogId, parseInt( status.log_cursor, 10 ) || 0 );
		}

		$( '#better-importer-pause-btn' ).text( isPaused ? betterImporterProgress.strings.resume : betterImporterProgress.strings.pause );

		if ( status.is_complete ) {
			$( '#better-importer-status-message' ).text(
				status.failed > 0 ? betterImporterProgress.strings.completeWithFailures : betterImporterProgress.strings.complete
			);
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
		request( 'better-import-status', 'GET', {
			log_after_id: lastLogId
		} ).done( function ( response ) {
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
		request( 'better-import-batch', 'POST', {
			log_after_id: lastLogId
		} ).always( function () {
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
