/**
 * Turbo Search: Global Admin JS
 */
( function ( $ ) {
	'use strict';

	$( function () {
		// TTL Quick presets
		$( document ).on( 'click', '.wpts-ttl-preset', function ( e ) {
			e.preventDefault();
			$( '[name="cache_ttl"]' ).val( $( this ).data( 'val' ) );
		} );

		// Flush cache AJAX button
		$( document ).on( 'click', '#wpts-flush-cache-btn', function ( e ) {
			e.preventDefault();
			if ( ! confirm( 'Flush the entire search result cache?' ) ) return;

			var $btn    = $( this );
			var $status = $( '#wpts-flush-status' );

			$btn.prop( 'disabled', true ).text( 'Flushing…' );
			$status.css( 'color', '#64748b' ).text( '' );

			$.post( WPTS_Admin.ajax_url, {
				action: 'wpts_flush_cache_ajax',
				nonce:  WPTS_Admin.nonce,
			} )
				.done( function ( res ) {
					if ( res && res.success ) {
						$status.css( 'color', '#16a34a' ).text( res.data.message || '✅ Flushed!' );
					} else {
						$status.css( 'color', '#dc2626' ).text( '❌ ' + ( res.data || 'Error' ) );
					}
				} )
				.always( function () {
					$btn.prop( 'disabled', false ).text( '🧹 Flush Cache Now' );
				} );
		} );
	} );
} )( jQuery );
