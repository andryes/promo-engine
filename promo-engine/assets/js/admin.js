/**
 * Promo Engine admin: show/hide type- and scope-specific fields, tier rows.
 */
jQuery( function ( $ ) {
	'use strict';

	function toggleRows() {
		var type = $( '#pe_type' ).val();
		$( '.pe-type-row' ).each( function () {
			var types = String( $( this ).data( 'pe-types' ) || '' ).split( ' ' );
			$( this ).toggle( -1 !== types.indexOf( type ) );
		} );

		var scope = $( '#pe_scope_type' ).val();
		$( '.pe-scope-row' ).each( function () {
			var scopes = String( $( this ).data( 'pe-scopes' ) || '' ).split( ' ' );
			$( this ).toggle( -1 !== scopes.indexOf( scope ) );
		} );
	}

	$( '#pe_type, #pe_scope_type' ).on( 'change', toggleRows );
	toggleRows();

	$( '#pe-add-tier' ).on( 'click', function () {
		var $body = $( '#pe-tiers tbody' );
		var index = $body.find( 'tr' ).length;
		var $row = $body.find( 'tr' ).last().clone();
		$row.find( 'input' ).each( function () {
			this.value = '';
			this.name = this.name.replace( /\[\d+\]/, '[' + index + ']' );
		} );
		$body.append( $row );
	} );
} );
