/**
 * Admin Menu Swap — sortable list, separator management, show/hide
 * toggles, separator color, serialization.
 *
 * Only the sortable() and wpColorPicker() calls need jQuery (both
 * WP-bundled); the rest is plain event handling.
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

		// Preview the separator color on the real admin menu next to the
		// form. Rewrites the rule print_separator_css() prints, or adds one
		// when no color was saved yet.
		function previewSeparatorColor( color ) {
			var style = document.getElementById( 'amswap-separator-css' );

			if ( ! style ) {
				style    = document.createElement( 'style' );
				style.id = 'amswap-separator-css';
				document.head.appendChild( style );
			}

			style.textContent = color ? '#adminmenu li.wp-menu-separator div.separator { background-color: ' + color + '; }' : '';
		}

		$( '#amswap_separator_color' ).wpColorPicker( {
			change: function( event, ui ) {
				previewSeparatorColor( ui.color.toString() );
			},
			clear: function() {
				previewSeparatorColor( '' );
			}
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

		// Flip a row between shown and hidden. The label carries the state,
		// so it reads as the action available next: pressed means hidden.
		$list.on( 'click', '.amswap-toggle', function() {
			var $button = $( this );
			var $li     = $button.closest( 'li' );
			var hide    = '1' !== $li.attr( 'data-hidden' );
			var label   = hide ? window.amswapL10n.showLabel : window.amswapL10n.hideLabel;

			$li.attr( 'data-hidden', hide ? '1' : '0' ).toggleClass( 'amswap-is-hidden', hide );

			$button.attr( {
				'aria-pressed': hide ? 'true' : 'false',
				'aria-label': label,
				title: label
			} );

			$button.find( '.dashicons' )
				.toggleClass( 'dashicons-visibility', ! hide )
				.toggleClass( 'dashicons-hidden', hide );
		} );

		$( '#amswap-form' ).on( 'submit', function() {
			var order  = [];
			var hidden = [];

			// The hidden list is rebuilt from the page rather than carried
			// forward: every hideable row is present here, unlike removed
			// separators, whose rows are gone from the DOM.
			$list.children( 'li[data-slug]' ).each( function() {
				var $li  = $( this );
				var slug = $li.attr( 'data-slug' );

				order.push( slug );

				if ( '1' === $li.attr( 'data-hidden' ) ) {
					hidden.push( slug );
				}
			} );

			$( '#amswap_order' ).val( JSON.stringify( order ) );
			$( '#amswap_hidden' ).val( JSON.stringify( hidden ) );
			$( '#amswap_removed' ).val( JSON.stringify( removed ) );
		} );

		$( '#amswap-reset-form' ).on( 'submit', function( event ) {
			if ( ! window.confirm( window.amswapL10n.confirmReset ) ) {
				event.preventDefault();
			}
		} );
	} );
}( jQuery ) );
