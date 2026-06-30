/**
 * Polling-based import progress UI for job-based imports.
 *
 * @package WordPress_Importer_v2
 * @since 3.0.0
 */
( function ( $ ) {
	'use strict';

	if ( typeof wxrJobData === 'undefined' ) {
		return;
	}

	var pollTimer = null;
	var batchElapsedTimer = null;
	var batchStartedAt = 0;
	var batchInFlight = false;
	var retryDelayMs = 300;
	var isPaused = false;
	var isTerminal = false;

	function ajaxErrorMessage( xhr, fallback ) {
		if ( xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ) {
			return xhr.responseJSON.data.message;
		}
		if ( xhr.status === 403 || xhr.status === -1 || xhr.responseText === '0' || xhr.responseText === '-1' ) {
			return wxrJobData.strings.sessionExpired || fallback;
		}
		return fallback;
	}

	function stopBatchElapsedTimer() {
		if ( batchElapsedTimer ) {
			clearInterval( batchElapsedTimer );
			batchElapsedTimer = null;
		}
		batchStartedAt = 0;
	}

	function updateBatchElapsedMessage() {
		if ( ! batchStartedAt || isPaused || isTerminal ) {
			return;
		}

		var elapsed = Math.max( 1, Math.round( ( Date.now() - batchStartedAt ) / 1000 ) );
		$( '#import-status-message' )
			.removeClass( 'is-error is-warning is-success' )
			.text( wxrJobData.strings.batchRunning.replace( '%d', elapsed ) );
	}

	function startBatchElapsedTimer() {
		stopBatchElapsedTimer();
		batchStartedAt = Date.now();
		updateBatchElapsedMessage();
		batchElapsedTimer = setInterval( updateBatchElapsedMessage, 1000 );
	}

	function toggleCountNote( selector, count, label ) {
		var $el = $( selector );
		if ( count > 0 ) {
			$el.text( Number( count ).toLocaleString() + ' ' + label ).show();
		} else {
			$el.text( '' ).hide();
		}
	}

	function syncPauseState( status ) {
		if ( ! status || ! status.status ) {
			return;
		}

		isPaused = status.status === 'paused';
		$( '#wxr-pause-btn' ).text( isPaused ? 'Resume' : 'Pause' );
	}

	function updateBatchNote( batchSize ) {
		if ( batchSize ) {
			$( '#wxr-batch-note' ).text( wxrJobData.strings.batchNote.replace( '%d', batchSize ) );
		}
	}

	function updateProgress( status ) {
		syncPauseState( status );
		updateBatchNote( status.batch_size );

		var percent = status.percent || 0;
		$( '#progress-total' ).text( percent + '%' );
		$( '#wxr-overall-fill' ).css( 'width', percent + '%' );

		if ( status.xml_cursor_item !== undefined && status.manifest_total ) {
			var entityText = wxrJobData.strings.entities
				.replace( '%1$s', Number( status.xml_cursor_item ).toLocaleString() )
				.replace( '%2$s', Number( status.manifest_total ).toLocaleString() );
			$( '#wxr-entity-progress' ).text( entityText );
		}

		if ( status.processed ) {
			$.each( status.processed, function ( type, done ) {
				var total = wxrJobData.count[ type ] || 0;
				var skipped = status.skipped && status.skipped[ type ] ? Number( status.skipped[ type ] ) : 0;
				var failed  = status.failed && status.failed[ type ] ? Number( status.failed[ type ] ) : 0;
				var handled = Number( done ) + skipped + failed;
				var pct     = total > 0 ? Math.min( 100, Math.round( ( handled / total ) * 100 ) ) : ( handled > 0 ? 100 : 0 );
				var doneText = Number( done ).toLocaleString() + ' imported';

				if ( total > 0 ) {
					doneText += ' / ' + Number( total ).toLocaleString();
				}

				$( '#completed-' + type ).text( doneText );
				toggleCountNote( '#skipped-' + type, skipped, 'skipped' );
				toggleCountNote( '#failed-' + type, failed, 'failed' );
				$( '#bar-' + type ).css( 'width', pct + '%' );

				var $stat = $( '#stat-' + type );
				if ( total > 0 && handled >= total ) {
					$stat.addClass( 'is-complete' );
				} else {
					$stat.removeClass( 'is-complete' );
				}
			} );
		}

		var phaseLabel = '';
		if ( status.status === 'remapping' ) {
			phaseLabel = wxrJobData.strings.remapping;
		} else if ( status.status === 'paused' ) {
			phaseLabel = wxrJobData.strings.paused;
		} else if ( status.status === 'processing' ) {
			phaseLabel = wxrJobData.strings.importing;
		} else if ( status.status === 'downloading_attachments' ) {
			phaseLabel = wxrJobData.strings.downloadingAttachments;
		}
		$( '#wxr-phase-label' ).text( phaseLabel );

		if ( status.recent_log && status.recent_log.length ) {
			renderLog( status.recent_log );
			var last = status.recent_log[ status.recent_log.length - 1 ];
			if ( last && last.message && status.status !== 'complete' ) {
				$( '#import-status-message' )
					.removeClass( 'is-error is-warning is-success' )
					.text( last.message );
			}
		}
	}

	function renderLog( entries ) {
		var $tbody = $( '#import-log tbody' );
		$tbody.empty();
		entries.forEach( function ( entry ) {
			$tbody.append(
				'<tr><td>' + escHtml( entry.level ) + '</td><td>' + escHtml( entry.message ) + '</td></tr>'
			);
		} );
		$( '#wxr-log-toggle' ).show();
		$( '#import-log' ).show();
		$( '#wxr-log-toggle' ).text( wxrJobData.strings.hideLog );
	}

	function escHtml( text ) {
		return $( '<div>' ).text( text ).html();
	}

	function showFinalReport( report ) {
		if ( ! report ) {
			return;
		}

		var lines = [];
		if ( report.title ) {
			lines.push( '<strong>' + escHtml( report.title ) + '</strong>' );
		}
		lines.push(
			escHtml( 'Posts: ' + ( report.imported.posts || 0 ) + ' / ' + ( report.totals.posts || 0 ) )
		);
		lines.push(
			escHtml( 'Terms: ' + ( report.imported.terms || 0 ) + ' / ' + ( report.totals.terms || 0 ) )
		);
		lines.push(
			escHtml( 'Users: ' + ( report.imported.users || 0 ) + ' / ' + ( report.totals.users || 0 ) )
		);
		lines.push(
			escHtml( 'Comments: ' + ( report.imported.comments || 0 ) + ' / ' + ( report.totals.comments || 0 ) )
		);
		lines.push(
			escHtml( 'Media: ' + ( report.imported.media || 0 ) + ' / ' + ( report.totals.media || 0 ) )
		);
		lines.push( escHtml( 'Skipped: ' + ( report.skipped || 0 ) ) );
		lines.push( escHtml( 'Failed: ' + ( report.failed || 0 ) ) );

		$( '#wxr-final-report-body' ).html( lines.join( '<br>' ) );
		$( '#wxr-final-report' ).show();
	}

	function handleTerminal( status ) {
		isTerminal = true;
		clearInterval( pollTimer );
		stopBatchElapsedTimer();
		batchInFlight = false;

		if ( status.status === 'complete' ) {
			$( '#import-status-message' )
				.removeClass( 'is-error is-warning' )
				.addClass( 'is-success' )
				.text( wxrJobData.strings.complete );
			$( '#wxr-header-icon' ).removeClass( 'dashicons-migrate' ).addClass( 'dashicons-yes-alt' );
			if ( status.report ) {
				showFinalReport( status.report );
			}
		} else if ( status.status === 'failed' ) {
			$( '#import-status-message' )
				.removeClass( 'is-success is-warning' )
				.addClass( 'is-error' )
				.text( wxrJobData.strings.error );
		} else if ( status.status === 'cancelled' ) {
			$( '#import-status-message' )
				.removeClass( 'is-success is-error' )
				.addClass( 'is-warning' )
				.text( 'Import cancelled.' );
		}

		$( '#wxr-pause-btn, #wxr-cancel-btn' ).prop( 'disabled', true );
	}

	function pollStatus() {
		$.ajax( {
			url: wxrJobData.statusUrl,
			method: 'GET',
			data: {
				action: 'wxr-import-status',
				job_id: wxrJobData.jobId,
				nonce: wxrJobData.nonce,
			},
		} )
			.done( function ( response ) {
				if ( ! response.success ) {
					if ( response.data && response.data.message ) {
						$( '#import-status-message' ).addClass( 'is-error' ).text( response.data.message );
					}
					return;
				}
				updateProgress( response.data );
				if ( response.data.is_terminal ) {
					handleTerminal( response.data );
				}
			} )
			.fail( function ( xhr ) {
				$( '#import-status-message' )
					.addClass( 'is-warning' )
					.text( ajaxErrorMessage( xhr, wxrJobData.strings.connectionLost ) );
			} );
	}

	function scheduleNextBatch( delay ) {
		if ( isPaused || isTerminal ) {
			return;
		}
		window.setTimeout( runBatch, delay || 300 );
	}

	function runBatch() {
		if ( isPaused || isTerminal || batchInFlight ) {
			return;
		}

		batchInFlight = true;
		startBatchElapsedTimer();

		$.ajax( {
			url: wxrJobData.batchUrl,
			method: 'POST',
			data: {
				action: 'wxr-import-batch',
				job_id: wxrJobData.jobId,
				nonce: wxrJobData.nonce,
			},
			timeout: 0,
		} )
			.done( function ( response ) {
				batchInFlight = false;
				stopBatchElapsedTimer();

				if ( ! response.success ) {
					$( '#import-status-message' )
						.addClass( 'is-error' )
						.text( response.data && response.data.message ? response.data.message : wxrJobData.strings.error );
					scheduleNextBatch( retryDelayMs );
					return;
				}

				retryDelayMs = 300;
				updateProgress( response.data );

				if ( response.data.is_terminal ) {
					handleTerminal( response.data );
					return;
				}

				if ( response.data.status === 'paused' ) {
					return;
				}

				if ( response.data.message && response.data.message.indexOf( 'already running' ) !== -1 ) {
					scheduleNextBatch( 3000 );
					return;
				}

				scheduleNextBatch();
			} )
			.fail( function ( xhr ) {
				batchInFlight = false;
				stopBatchElapsedTimer();
				$( '#import-status-message' )
					.addClass( 'is-warning' )
					.text( wxrJobData.strings.batchRetry.replace( '%d', Math.ceil( retryDelayMs / 1000 ) ) );
				scheduleNextBatch( retryDelayMs );
				retryDelayMs = Math.min( 30000, retryDelayMs * 2 );
			} );
	}

	$( '#wxr-log-toggle' ).on( 'click', function () {
		var $log = $( '#import-log' );
		var showing = $log.is( ':visible' );
		$log.toggle();
		$( this ).text( showing ? wxrJobData.strings.showLog : wxrJobData.strings.hideLog );
	} );

	$( '#wxr-pause-btn' ).on( 'click', function () {
		var action = isPaused ? 'wxr-import-resume' : 'wxr-import-pause';
		var requestingPause = ! isPaused;

		if ( requestingPause ) {
			isPaused = true;
			stopBatchElapsedTimer();
			$( '#wxr-pause-btn' ).text( 'Resume' );
			$( '#import-status-message' )
				.removeClass( 'is-error is-success' )
				.addClass( 'is-warning' )
				.text( wxrJobData.strings.pauseRequested );
		}

		$.post( wxrJobData.batchUrl, {
			action: action,
			job_id: wxrJobData.jobId,
			nonce: wxrJobData.nonce,
		} ).done( function ( response ) {
			if ( response.success ) {
				updateProgress( response.data );
				if ( ! isPaused && ! isTerminal ) {
					runBatch();
				}
			} else if ( requestingPause ) {
				isPaused = false;
				$( '#wxr-pause-btn' ).text( 'Pause' );
			}
		} ).fail( function () {
			if ( requestingPause ) {
				isPaused = false;
				$( '#wxr-pause-btn' ).text( 'Pause' );
			}
		} );
	} );

	$( '#wxr-cancel-btn' ).on( 'click', function () {
		if ( ! window.confirm( 'Cancel this import? Already-imported content will be kept.' ) ) {
			return;
		}
		$.post( wxrJobData.cancelUrl, {
			action: 'wxr-cancel-import',
			id: wxrJobData.importId,
			_wpnonce: wxrJobData.cancelNonce,
		} ).done( function () {
			isTerminal = true;
			clearInterval( pollTimer );
			stopBatchElapsedTimer();
			batchInFlight = false;
			handleTerminal( { status: 'cancelled' } );
		} );
	} );

	if ( wxrJobData.initialStatus && wxrJobData.initialStatus.is_terminal ) {
		updateProgress( wxrJobData.initialStatus );
		handleTerminal( wxrJobData.initialStatus );
	} else if ( wxrJobData.initialStatus && wxrJobData.initialStatus.status === 'paused' ) {
		updateProgress( wxrJobData.initialStatus );
		pollTimer = setInterval( pollStatus, wxrJobData.pollInterval );
	} else {
		runBatch();
		pollTimer = setInterval( pollStatus, wxrJobData.pollInterval );
	}
} )( jQuery );
