/* global jQuery */
( function ( $ ) {
	'use strict';

	/**
	 * Toggle which rows/columns are relevant for the selected input type.
	 */
	function syncTypeVisibility() {
		var type = $( '#bwes-type' ).val();
		var isOptions = ( 'radio' === type || 'radius' === type );
		var isRadius = ( 'radius' === type );

		$( '.bwes-row-simple-fee' ).toggle( 'checkbox' === type );
		$( '.bwes-row-options' ).toggle( isOptions );

		// Column visibility inside the options table.
		$( '.bwes-col-feetype' ).toggle( 'radio' === type );
		$( '.bwes-col-maxkm' ).toggle( isRadius );
		$( '.bwes-col-default' ).toggle( 'radio' === type );
	}

	$( function () {
		if ( ! $( '.bwes-service-form' ).length ) {
			return;
		}

		syncTypeVisibility();
		$( '#bwes-type' ).on( 'change', syncTypeVisibility );

		// Add a new option row from the template.
		$( '#bwes-add-option' ).on( 'click', function () {
			var tpl = $( '#bwes-option-template' ).html();
			$( '#bwes-options-body' ).append( tpl );
			syncTypeVisibility();
		} );

		// Remove an option row.
		$( '#bwes-options-body' ).on( 'click', '.bwes-remove-option', function () {
			var $rows = $( '#bwes-options-body .bwes-option-row' );
			if ( $rows.length > 1 ) {
				$( this ).closest( '.bwes-option-row' ).remove();
			} else {
				$( this ).closest( '.bwes-option-row' ).find( 'input[type="text"]' ).val( '' );
			}
		} );

		// Checkboxes only POST when checked, which would misalign the sparse
		// option_default[] array with the always-present text arrays. Give each
		// default checkbox an explicit row index on submit so the server can
		// map it back to the correct option.
		$( '.bwes-service-form' ).on( 'submit', function () {
			$( '#bwes-options-body .bwes-option-row' ).each( function ( index ) {
				$( this )
					.find( 'input[name^="option_default"]' )
					.attr( 'name', 'option_default[' + index + ']' );
			} );
		} );
	} );
} )( jQuery );
