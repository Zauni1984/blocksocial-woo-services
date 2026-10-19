/* global bwesFrontend, jQuery */
( function ( $ ) {
	'use strict';

	if ( typeof bwesFrontend === 'undefined' ) {
		return;
	}

	var saveTimer = null;

	/**
	 * Collect all selections and messages from the services panel.
	 */
	function collect() {
		var selections = {};
		var messages = {};

		$( '#bwes-services .bwes-service' ).each( function () {
			var $service = $( this );
			var id = $service.data( 'service' );

			// Checkbox / radius.
			var $checkbox = $service.find( 'input[type="checkbox"].bwes-input' );
			if ( $checkbox.length ) {
				selections[ id ] = $checkbox.is( ':checked' ) ? '1' : '';
			}

			// Radio.
			var $radio = $service.find( 'input[type="radio"].bwes-input:checked' );
			if ( $radio.length ) {
				selections[ id ] = $radio.val();
			}

			// Message.
			var $msg = $service.find( '.bwes-message-input' );
			if ( $msg.length ) {
				messages[ id ] = $msg.val();
			}
		} );

		return { selections: selections, messages: messages };
	}

	/**
	 * Persist selections/messages to the session.
	 *
	 * @param {boolean} refresh Whether to trigger a totals recalculation after.
	 */
	function save( refresh ) {
		var data = collect();

		$.ajax( {
			url: bwesFrontend.ajaxUrl,
			method: 'POST',
			data: {
				action: 'bwes_update_selections',
				nonce: bwesFrontend.nonce,
				selections: data.selections,
				messages: data.messages
			}
		} ).done( function () {
			if ( ! refresh ) {
				return;
			}
			if ( bwesFrontend.isCart ) {
				// Force the cart totals to refresh.
				$( document.body ).trigger( 'wc_update_cart' );
				var $update = $( '[name="update_cart"]' );
				if ( $update.length ) {
					$update.prop( 'disabled', false ).trigger( 'click' );
				}
			} else {
				$( document.body ).trigger( 'update_checkout' );
			}
		} );
	}

	/**
	 * When an "exclusive" service (e.g. local delivery) is selected, disable all
	 * other services so they can't be combined with it.
	 */
	function applyExclusivity() {
		var $exclusive = $( '#bwes-services .bwes-service[data-exclusive="1"]' ).filter( function () {
			return $( this ).find( 'input.bwes-input:checked' ).length > 0;
		} );
		var active = $exclusive.length > 0;

		$( '#bwes-services .bwes-service' ).each( function () {
			var $service = $( this );
			var isActiveOne = $exclusive.is( $service );

			if ( active && ! isActiveOne ) {
				$service.addClass( 'bwes-service--disabled' )
					.find( 'input, textarea, select' ).prop( 'disabled', true );
			} else {
				$service.removeClass( 'bwes-service--disabled' )
					.find( 'input, textarea, select' ).prop( 'disabled', false );
			}
		} );
	}

	// Immediate save + recalculation on checkbox / radio change (affects price).
	$( document ).on( 'change', '#bwes-services .bwes-input', function () {
		applyExclusivity();
		save( true );
	} );

	// Debounced save on message typing (no price impact, no fragment refresh).
	$( document ).on( 'input', '#bwes-services .bwes-message-input', function () {
		window.clearTimeout( saveTimer );
		saveTimer = window.setTimeout( function () {
			save( false );
		}, 600 );
	} );

	// Re-apply after the checkout fragment is re-rendered, and on initial load.
	$( document.body ).on( 'updated_checkout updated_cart_totals', applyExclusivity );
	$( applyExclusivity );
} )( jQuery );
