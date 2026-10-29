/**
 * Admin Menu Swap — sortable list, separator management, show/hide
 * toggles, separator color, keyboard moves, unsaved-changes state and
 * serialization.
 *
 * Only the sortable() and wpColorPicker() calls need jQuery (both
 * WP-bundled); the rest is plain event handling.
 */
( function( $ ) {
	'use strict';

	$( function() {
		var $list       = $( '#amswap-menu-list' );
		var l10n        = window.amswapL10n;
		var newSepCount = 0;
		var dirty       = false;
		var submitting  = false;

		// Seed from the saved removed-separator list so removals persist
		// across saves; current-session removals are appended below.
		var removed = [];
		try {
			removed = JSON.parse( $( '#amswap_removed' ).val() || '[]' );
		} catch ( e ) {}
		if ( ! Array.isArray( removed ) ) {
			removed = [];
		}

		function format( template ) {
			var args = Array.prototype.slice.call( arguments, 1 );

			return template.replace( /%(\d)\$d/g, function( match, index ) {
				return args[ index - 1 ];
			} );
		}

		function markDirty() {
			dirty = true;
			$( '#amswap-dirty' ).prop( 'hidden', false );
		}

		function updateCount() {
			var $items = $list.children( 'li[data-separator="0"]' );

			$( '#amswap-count' ).text( format( l10n.count, $items.length, $items.filter( '[data-hidden="1"]' ).length ) );
		}

		$list.sortable( {
			axis: 'y',
			handle: '.amswap-handle',
			// The default cancel list includes "button", which would swallow
			// every mousedown on the handle (a button, for keyboard moves).
			cancel: 'input, textarea, select, option, button:not(.amswap-handle)',
			placeholder: 'amswap-placeholder',
			forcePlaceholderSize: true,
			cursor: 'move',
			opacity: 0.9,
			update: markDirty
		} );

		// Keyboard moves: the handle is a button; the arrow keys move its row.
		$list.on( 'keydown', '.amswap-handle', function( event ) {
			var $li = $( this ).closest( 'li' );
			var $target;

			if ( 'ArrowUp' === event.key ) {
				$target = $li.prev( 'li' );
				if ( $target.length ) {
					$li.insertBefore( $target );
				}
			} else if ( 'ArrowDown' === event.key ) {
				$target = $li.next( 'li' );
				if ( $target.length ) {
					$li.insertAfter( $target );
				}
			} else {
				return;
			}

			event.preventDefault();

			if ( ! $target.length ) {
				return;
			}

			$( this ).trigger( 'focus' );
			$li.addClass( 'is-moved' );
			window.setTimeout( function() {
				$li.removeClass( 'is-moved' );
			}, 600 );

			markDirty();
			wp.a11y.speak( format( l10n.moved, $li.index() + 1, $list.children( 'li' ).length ) );
		} );

		// Preview the separator color on the real admin menu next to the
		// form and on the list. Rewrites the rule print_separator_css()
		// prints, or adds one when no color was saved yet.
		function previewSeparatorColor( color ) {
			var style = document.getElementById( 'amswap-separator-css' );

			if ( ! style ) {
				style    = document.createElement( 'style' );
				style.id = 'amswap-separator-css';
				document.head.appendChild( style );
			}

			style.textContent = color ? '#adminmenu li.wp-menu-separator div.separator { border-bottom: 1px solid ' + color + '; margin-top: 5px; }' : '';

			if ( color ) {
				$list[ 0 ].style.setProperty( '--amswap-separator', color );
			} else {
				$list[ 0 ].style.removeProperty( '--amswap-separator' );
			}
		}

		$( '#amswap_separator_color' ).wpColorPicker( {
			change: function( event, ui ) {
				previewSeparatorColor( ui.color.toString() );
				markDirty();
			},
			clear: function() {
				previewSeparatorColor( '' );
				markDirty();
			}
		} );

		$( 'input[name="amswap_follow_default"]' ).on( 'change', markDirty );

		$( '#amswap-add-separator' ).on( 'click', function() {
			var template = document.getElementById( 'amswap-separator-template' );
			var node     = template.content.firstElementChild.cloneNode( true );

			newSepCount++;
			// Unique placeholder slug; the server renumbers positionally on save.
			node.setAttribute( 'data-slug', 'separator-amswap-new-' + newSepCount );

			$list.append( node );
			node.scrollIntoView( { block: 'nearest', behavior: 'smooth' } );
			$( node ).find( '.amswap-handle' ).trigger( 'focus' );
			markDirty();
		} );

		$list.on( 'click', '.amswap-remove', function() {
			var $li   = $( this ).closest( 'li' );
			var $next = $li.next( 'li' ).length ? $li.next( 'li' ) : $li.prev( 'li' );

			if ( '1' === $li.attr( 'data-core-separator' ) ) {
				removed.push( $li.attr( 'data-slug' ) );
			}

			$li.remove();
			$next.find( '.amswap-handle' ).trigger( 'focus' );
			markDirty();
		} );

		// Flip a row between shown and hidden. The label carries the state,
		// so it reads as the action available next: pressed means hidden.
		$list.on( 'click', '.amswap-toggle', function() {
			var $button = $( this );
			var $li     = $button.closest( 'li' );
			var hide    = '1' !== $li.attr( 'data-hidden' );
			var label   = hide ? l10n.showLabel : l10n.hideLabel;

			$li.attr( 'data-hidden', hide ? '1' : '0' ).toggleClass( 'amswap-is-hidden', hide );

			$button.attr( {
				'aria-pressed': hide ? 'true' : 'false',
				'aria-label': label,
				title: label
			} );

			$button.find( '.dashicons' )
				.toggleClass( 'dashicons-visibility', ! hide )
				.toggleClass( 'dashicons-hidden', hide );

			updateCount();
			markDirty();
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
			submitting = true;
		} );

		$( '#amswap-reset-form' ).on( 'submit', function( event ) {
			if ( ! window.confirm( l10n.confirmReset ) ) {
				event.preventDefault();
				return;
			}
			submitting = true;
		} );

		$( '#amswap-agency-form' ).on( 'submit', function() {
			submitting = true;
		} );

		window.addEventListener( 'beforeunload', function( event ) {
			if ( dirty && ! submitting ) {
				event.preventDefault();
				event.returnValue = l10n.leave;
			}
		} );
	} );
}( jQuery ) );
