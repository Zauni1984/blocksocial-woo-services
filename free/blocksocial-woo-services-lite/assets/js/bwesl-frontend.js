/* global bwesl, jQuery */
( function ( $ ) {
	'use strict';
	if ( typeof bwesl === 'undefined' ) { return; }
	var t = null;

	function collect() {
		var sel = {}, msg = {};
		$( '#bwesl-services .bwesl-service' ).each( function () {
			var id = $( this ).data( 'service' );
			var $c = $( this ).find( 'input.bwesl-input' );
			if ( $c.length ) { sel[ id ] = $c.is( ':checked' ) ? '1' : ''; }
			var $m = $( this ).find( '.bwesl-message-input' );
			if ( $m.length ) { msg[ id ] = $m.val(); }
		} );
		return { selections: sel, messages: msg };
	}

	function save( refresh ) {
		var d = collect();
		$.ajax( {
			url: bwesl.ajaxUrl, method: 'POST',
			data: { action: 'bwesl_update', nonce: bwesl.nonce, selections: d.selections, messages: d.messages }
		} ).done( function () {
			if ( ! refresh ) { return; }
			if ( bwesl.isCart ) {
				$( document.body ).trigger( 'wc_update_cart' );
				var $u = $( '[name="update_cart"]' );
				if ( $u.length ) { $u.prop( 'disabled', false ).trigger( 'click' ); }
			} else {
				$( document.body ).trigger( 'update_checkout' );
			}
		} );
	}

	$( document ).on( 'change', '#bwesl-services .bwesl-input', function () { save( true ); } );
	$( document ).on( 'input', '#bwesl-services .bwesl-message-input', function () {
		window.clearTimeout( t );
		t = window.setTimeout( function () { save( false ); }, 600 );
	} );
} )( jQuery );
