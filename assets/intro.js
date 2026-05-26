(function ($) {
	var options = importUploadSettings;
	var uploader, statusTemplate, errorTemplate;

	// Render the upload progress state (while uploading)
	var renderProgress = function ( attachment ) {
		var attr = attachment.attributes;

		// Build the template with uploading=true so the progress branch renders
		var templateData = $.extend( {}, attr, { uploading: true } );
		var $status = $( jQuery.parseHTML( statusTemplate( templateData ).trim() ) );

		// Update the progress bar width and percentage text
		if ( attr.size && attr.loaded ) {
			$( '.bar', $status ).width( Math.round( ( attr.loaded / attr.size ) * 200 ) );
			$( '.percent', $status ).html( ( attr.percent || 0 ) + '%' );
		}

		$( '.drag-drop-status' ).empty().append( $status );
	};

	// Render the upload success state (after upload completes)
	var renderDone = function () {
		// uploading=false/undefined → template renders the success branch
		var $status = $( jQuery.parseHTML( statusTemplate( { uploading: false } ).trim() ) );
		$( '.drag-drop-status' ).empty().append( $status );
	};

	var renderError = function ( message ) {
		var $status = $( '.drag-drop-status' );
		$status.html( errorTemplate( { message: message } ) );
		$status.one( 'click', 'button', function () {
			$status.empty().hide();
			$( '.drag-drop-selector' ).show();
		});
	};

	var actions = {
		init: function () {
			var uploaddiv = $( '#plupload-upload-ui' );
			if ( uploader.supports.dragdrop ) {
				uploaddiv.addClass( 'drag-drop' );
			} else {
				uploaddiv.removeClass( 'drag-drop' );
			}
		},

		added: function ( attachment ) {
			$( '.drag-drop-selector' ).hide();
			$( '.drag-drop-status' ).show();
			renderProgress( attachment );
		},

		progress: function ( attachment ) {
			renderProgress( attachment );
		},

		success: function ( attachment ) {
			// Store the uploaded file ID so the form can submit it
			$( '#import-selected-id' ).val( attachment.id );
			// Show the success state with the "Continue" button
			renderDone();
		},

		error: function ( message, data, file ) {
			renderError( message );
		},
	};

	var init = function () {
		var isIE = navigator.userAgent.indexOf( 'Trident/' ) !== -1 ||
		           navigator.userAgent.indexOf( 'MSIE ' ) !== -1;

		if ( ! isIE && 'flash' === plupload.predictRuntime( options ) &&
			( ! options.required_features || ! options.required_features.hasOwnProperty( 'send_binary_string' ) ) ) {
			options.required_features = options.required_features || {};
			options.required_features.send_binary_string = true;
		}

		var instanceOptions = _.extend( {}, options, actions );
		instanceOptions.browser  = $( '#plupload-browse-button' );
		instanceOptions.dropzone = $( '#plupload-upload-ui' );

		uploader = new wp.Uploader( instanceOptions );
	};

	$( document ).ready( function () {
		statusTemplate = wp.template( 'import-upload-status' );
		errorTemplate  = wp.template( 'import-upload-error' );

		init();

		// Media library picker
		var frame = wp.media({
			id:       'import-select',
			title:    options.l10n.frameTitle,
			multiple: true,
			library:  { type: '', status: 'private' },
			button:   { text: options.l10n.buttonText, close: false },
		});

		$( '.upload-select' ).on( 'click', function ( event ) {
			event.preventDefault();
			frame.open();
		});

		// When a file is selected from the media library, submit the form directly
		frame.on( 'select', function () {
			var attachment = frame.state().get( 'selection' ).first().toJSON();
			$( '#import-selected-id' ).val( attachment.id );
			$( '#import-selected-id' ).parents( 'form' )[ 0 ].submit();
		});
	});

})( jQuery );
