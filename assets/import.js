(function ($) {
	var MAX_LOG_ROWS = 200; // cap DOM rows to keep browser responsive on large imports
	var logRowCount  = 0;
	var logVisible   = false;

	var wxrImport = {
		complete: {
			posts:    0,
			media:    0,
			users:    0,
			comments: 0,
			terms:    0,
		},

		updateDelta: function ( type, delta ) {
			this.complete[ type ] += delta;
			var self = this;
			requestAnimationFrame( function () {
				self.render( type );
			});
		},

		updateStat: function ( type, done, total ) {
			var statEl = document.getElementById( 'completed-' + type );
			if ( ! statEl ) return;

			if ( total > 0 ) {
				var pct = Math.min( Math.round( ( done / total ) * 100 ), 100 );
				statEl.textContent = done + ' / ' + total;

				// Mark card complete
				var card = document.getElementById( 'stat-' + type );
				if ( card && pct >= 100 ) {
					card.classList.add( 'is-complete' );
				}
			} else {
				statEl.textContent = '\u2014'; // em dash — nothing to import
			}
		},

		render: function ( updatedType ) {
			var types   = Object.keys( this.complete );
			var done    = 0;
			var total   = 0;

			for ( var i = 0; i < types.length; i++ ) {
				var type = types[ i ];
				var typeDone  = this.complete[ type ];
				var typeTotal = this.data.count[ type ];

				this.updateStat( type, typeDone, typeTotal );

				done  += typeDone;
				total += typeTotal;
			}

			// Overall progress bar
			var pct = ( total > 0 ) ? Math.min( Math.round( ( done / total ) * 100 ), 100 ) : 0;
			var fill = document.getElementById( 'wxr-overall-fill' );
			var pctEl = document.getElementById( 'progress-total' );
			if ( fill )  fill.style.width = pct + '%';
			if ( pctEl ) pctEl.textContent = pct + '%';
		}
	};

	wxrImport.data = wxrImportData;
	wxrImport.render();

	// Show the log toggle button once we have log entries
	function ensureLogVisible() {
		var toggle = document.getElementById( 'wxr-log-toggle' );
		if ( toggle ) toggle.style.display = 'inline';
	}

	$( '#wxr-log-toggle' ).on( 'click', function () {
		logVisible = ! logVisible;
		var log = $( '#import-log' );
		if ( logVisible ) {
			log.show();
			$( this ).text( wxrImportData.strings.hideLog || 'Hide log' );
		} else {
			log.hide();
			$( this ).text( wxrImportData.strings.showLog || 'Show log' );
		}
	});

	function appendLogRow( level, message ) {
		ensureLogVisible();

		// Cap rows to avoid freezing the browser on large imports
		if ( logRowCount >= MAX_LOG_ROWS ) {
			var tbody = document.querySelector( '#import-log tbody' );
			if ( tbody && tbody.firstChild ) {
				tbody.removeChild( tbody.firstChild );
			}
		} else {
			logRowCount++;
		}

		var row     = document.createElement( 'tr' );
		row.className = 'log-' + level;

		var levelCell = document.createElement( 'td' );
		levelCell.textContent = level;
		row.appendChild( levelCell );

		var msgCell = document.createElement( 'td' );
		msgCell.textContent = message;
		row.appendChild( msgCell );

		$( '#import-log tbody' ).append( row );
	}

	// ── Cancel button ──────────────────────────────────────────
	$( '#wxr-cancel-btn' ).on( 'click', function () {
		if ( ! confirm( 'Cancel the import? Posts already imported will remain. You can re-run the import later — duplicates will be skipped automatically.' ) ) {
			return;
		}

		// Stop the SSE stream
		evtSource.close();

		// Tell the server to clear the import settings so it won't auto-resume
		$.post( wxrImport.data.cancelUrl, {
			_wpnonce: wxrImport.data.cancelNonce,
			id:       wxrImport.data.importId,
		});

		var statusEl = $( '#import-status-message' );
		statusEl.html( 'Import cancelled. Posts imported so far have been saved. You can re-run the import at any time — already-imported posts will be skipped.' );
		statusEl.removeClass( 'notice-info notice-success notice-error' ).addClass( 'notice-warning' );

		$( '#wxr-cancel-btn' ).prop( 'disabled', true ).text( 'Cancelled' );
	});

	// ── SSE connection ─────────────────────────────────────────
	var evtSource = new EventSource( wxrImport.data.url );

	evtSource.onmessage = function ( message ) {
		var data = JSON.parse( message.data );
		var statusEl = $( '#import-status-message' );

		switch ( data.action ) {
			case 'connected':
				statusEl.html( wxrImport.data.strings.importing || 'Importing&hellip;' );
				break;

			case 'updateDelta':
				wxrImport.updateDelta( data.type, data.delta );
				break;

			case 'complete':
				evtSource.close();
				$( '#wxr-cancel-btn' ).hide();
				$( '#wxr-header-icon' ).removeClass().addClass( 'dashicons dashicons-yes-alt' ).css( 'color', '#00a32a' );
				if ( data.error ) {
					statusEl.html( data.error );
					statusEl.removeClass( 'notice-info notice-success' ).addClass( 'notice-error' );
				} else {
					statusEl.html( wxrImport.data.strings.complete );
					statusEl.removeClass( 'notice-info' ).addClass( 'notice-success' );
					// Fill all bars to 100%
					$( '#wxr-overall-fill' ).css( 'width', '100%' );
					$( '#progress-total' ).text( '100%' );
				}
				break;

			case 'error':
				evtSource.close();
				$( '#wxr-cancel-btn' ).hide();
				statusEl.html( data.error || wxrImport.data.strings.error );
				statusEl.removeClass( 'notice-info notice-success' ).addClass( 'notice-error' );
				break;
		}
	};

	evtSource.onerror = function () {
		if ( evtSource.readyState === EventSource.CLOSED ) return;
		evtSource.close();
		var statusEl = $( '#import-status-message' );
		if ( ! statusEl.hasClass( 'notice-success' ) ) {
			statusEl.html( wxrImport.data.strings.interrupted );
			statusEl.removeClass( 'notice-info' ).addClass( 'notice-error' );
		}
	};

	evtSource.addEventListener( 'log', function ( message ) {
		var data = JSON.parse( message.data );
		appendLogRow( data.level, data.message );
	});

})(jQuery);
