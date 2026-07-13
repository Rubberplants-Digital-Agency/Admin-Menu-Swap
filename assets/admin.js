/**
 * Admin Menu Swap — sortable list, separator management, serialization.
 *
 * Only the sortable() call needs jQuery (WP-bundled jquery-ui-sortable);
 * the rest is plain event handling.
 */
( function( $ ) {
	'use strict';

	$( function() {
		var $list       = $( '#amswap-menu-list' );
		var newSepCount = 0;

		// Seed from the saved removed-separator list so removals persist
		// across saves; current-session removals are appended below.
		var removed = [];
		try {
			removed = JSON.parse( $( '#amswap_removed' ).val() || '[]' );
		} catch ( e ) {}
		if ( ! Array.isArray( removed ) ) {
			removed = [];
		}

		$list.sortable( {
			axis: 'y',
			handle: '.amswap-handle',
			placeholder: 'amswap-placeholder',
			forcePlaceholderSize: true,
			cursor: 'move',
			opacity: 0.8
		} );

		$( '#amswap-add-separator' ).on( 'click', function() {
			var template = document.getElementById( 'amswap-separator-template' );
			var node     = template.content.firstElementChild.cloneNode( true );

			newSepCount++;
			// Unique placeholder slug; the server renumbers positionally on save.
			node.setAttribute( 'data-slug', 'separator-amswap-new-' + newSepCount );

			$list.append( node );
			node.scrollIntoView( { block: 'nearest', behavior: 'smooth' } );
		} );

		$list.on( 'click', '.amswap-remove', function() {
			var $li = $( this ).closest( 'li' );

			if ( '1' === $li.attr( 'data-core-separator' ) ) {
				removed.push( $li.attr( 'data-slug' ) );
			}

			$li.remove();
		} );

		$( '#amswap-form' ).on( 'submit', function() {
			var order = [];

			$list.children( 'li[data-slug]' ).each( function() {
				order.push( $( this ).attr( 'data-slug' ) );
			} );

			$( '#amswap_order' ).val( JSON.stringify( order ) );
			$( '#amswap_removed' ).val( JSON.stringify( removed ) );
		} );

		$( '#amswap-reset-form' ).on( 'submit', function( event ) {
			if ( ! window.confirm( window.amswapL10n.confirmReset ) ) {
				event.preventDefault();
			}
		} );
	} );
}( jQuery ) );
