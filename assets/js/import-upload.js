/**
 * Upload UI for Better WordPress Importer.
 *
 * @package Better_WordPress_Importer
 * @since 1.2.0
 */
( function ( $, wp ) {
	'use strict';

	if ( typeof betterImporterUpload === 'undefined' ) {
		return;
	}

	var $status = $( '#better-importer-upload-status' );
	var uploadSession = betterImporterUpload.uploadSession || '';

	function showStatus( html ) {
		$status.removeAttr( 'hidden' ).html( html );
	}

	function createUploadSession() {
		if ( typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function' ) {
			return crypto.randomUUID().replace( /-/g, '' );
		}

		return String( Date.now() ) + Math.random().toString( 36 ).slice( 2 );
	}

	function redirectAfterUpload( attachmentId ) {
		var url = betterImporterUpload.settingsUrl;
		url += ( url.indexOf( '?' ) > -1 ? '&' : '?' ) + 'attachment_id=' + attachmentId;
		window.location.href = url;
	}

	function handleUploadResponse( data ) {
		if ( ! data.success ) {
			showStatus( '<p class="notice notice-error">' + ( data.data && data.data.message ? data.data.message : betterImporterUpload.strings.error ) + '</p>' );
			return;
		}

		if ( data.data && data.data.partial ) {
			return;
		}

		if ( ! data.data || ! data.data.attachment_id ) {
			showStatus( '<p class="notice notice-error">' + betterImporterUpload.strings.error + '</p>' );
			return;
		}

		redirectAfterUpload( data.data.attachment_id );
	}

	function initUploader() {
		if ( typeof wp === 'undefined' || typeof wp.Uploader === 'undefined' ) {
			return false;
		}

		var uploader = new wp.Uploader( {
			browser: $( '#better-importer-browse' ),
			dropzone: $( '#better-importer-plupload' ),
			params: {
				action: 'better-import-upload',
				nonce: betterImporterUpload.nonce,
				upload_session: uploadSession
			},
			plupload: {
				url: betterImporterUpload.ajaxUrl,
				file_data_name: 'async-upload',
				chunk_size: betterImporterUpload.chunkSize || '8mb',
				filters: {
					mime_types: [ { title: 'XML', extensions: 'xml' } ],
					max_file_size: betterImporterUpload.maxSize + 'b'
				}
			}
		} );

		if ( ! uploader || ! uploader.uploader ) {
			return false;
		}

		uploader.uploader.bind( 'FilesAdded', function () {
			uploadSession = createUploadSession();
			showStatus( '<p>' + betterImporterUpload.strings.uploading + '</p>' );
		} );

		uploader.uploader.bind( 'BeforeUpload', function ( up, file ) {
			up.settings.multipart_params = up.settings.multipart_params || {};
			up.settings.multipart_params.upload_session = uploadSession;
			up.settings.multipart_params.name = file.name;
		} );

		uploader.uploader.bind( 'UploadProgress', function ( up, file ) {
			showStatus( '<p>' + betterImporterUpload.strings.uploading + ' ' + file.percent + '%</p>' );
		} );

		uploader.uploader.bind( 'FileUploaded', function ( up, file, response ) {
			var data;
			try {
				data = JSON.parse( response.response );
			} catch ( e ) {
				showStatus( '<p class="notice notice-error">' + betterImporterUpload.strings.error + '</p>' );
				return;
			}

			handleUploadResponse( data );
		} );

		uploader.uploader.bind( 'Error', function ( up, error ) {
			showStatus( '<p class="notice notice-error">' + ( error.message || betterImporterUpload.strings.error ) + '</p>' );
		} );

		return true;
	}

	function initFallbackUploader() {
		var $input = $( '#better-importer-file-fallback' );
		if ( ! $input.length ) {
			return;
		}

		$( '#better-importer-browse' ).on( 'click', function ( event ) {
			event.preventDefault();
			$input.trigger( 'click' );
		} );

		$input.on( 'change', function () {
			var file = this.files && this.files[0];
			if ( ! file ) {
				return;
			}

			var formData = new window.FormData();
			formData.append( 'action', 'better-import-upload' );
			formData.append( 'nonce', betterImporterUpload.nonce );
			formData.append( 'async-upload', file );
			formData.append( 'name', file.name );

			showStatus( '<p>' + betterImporterUpload.strings.uploading + '</p>' );

			$.ajax( {
				url: betterImporterUpload.ajaxUrl,
				type: 'POST',
				data: formData,
				processData: false,
				contentType: false
			} ).done( function ( response ) {
				handleUploadResponse( response );
			} ).fail( function () {
				showStatus( '<p class="notice notice-error">' + betterImporterUpload.strings.error + '</p>' );
			} );
		} );
	}

	$( function () {
		if ( ! initUploader() ) {
			initFallbackUploader();
		}
	} );

	if ( typeof betterImporterStart !== 'undefined' ) {
		$( '#better-importer-start-form' ).on( 'submit', function ( event ) {
			event.preventDefault();
			var $btn = $( '#better-importer-start-btn' ).prop( 'disabled', true );
			var data = $( this ).serializeArray();

			data.push( { name: 'action', value: 'better-import-start' } );
			data.push( { name: 'nonce', value: betterImporterStart.nonce } );
			data.push( { name: 'fetch_attachments', value: $( '[name="fetch_attachments"]' ).is( ':checked' ) ? 1 : 0 } );

			$.post( betterImporterStart.ajaxUrl, data ).done( function ( response ) {
				if ( ! response.success ) {
					window.alert( response.data && response.data.message ? response.data.message : 'Import failed.' );
					$btn.prop( 'disabled', false );
					return;
				}
				var url = betterImporterStart.progressUrl;
				url += ( url.indexOf( '?' ) > -1 ? '&' : '?' ) + 'job_id=' + response.data.job_id;
				window.location.href = url;
			} ).fail( function () {
				window.alert( 'Import failed.' );
				$btn.prop( 'disabled', false );
			} );
		} );
	}
}( jQuery, window.wp ) );
