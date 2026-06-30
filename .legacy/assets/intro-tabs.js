/**
 * Tab switching for the intro upload source picker.
 *
 * @package WordPress_Importer_v2
 * @since 3.0.0
 */
( function ( $ ) {
	'use strict';

	$( document ).on( 'click', '.wxr-tab-nav .wxr-tab', function () {
		var tab = $( this ).data( 'wxr-tab' );

		$( '.wxr-tab-nav .wxr-tab' ).removeClass( 'is-active' ).attr( 'aria-selected', 'false' );
		$( this ).addClass( 'is-active' ).attr( 'aria-selected', 'true' );

		$( '.wxr-tab-panel' ).removeClass( 'is-active' ).attr( 'hidden', true );
		$( '#wxr-panel-' + tab ).addClass( 'is-active' ).removeAttr( 'hidden' );
	} );
} )( jQuery );
