/**
 * Theme Builder — portable mobile menu drawer (foreign themes only).
 *
 * The unysonplus theme renders a #primary-navigation-drawer and binds the
 * .menu-toggle to it. Under any OTHER theme the Theme Builder renders the header
 * preset's [menu_toggle] button (aria-controls="primary-navigation-drawer") but no
 * drawer — so the hamburger would do nothing. This builds a drawer with the SAME
 * id / class contract (#primary-navigation-drawer, .is-open, body.menu-drawer-open,
 * [data-drawer-close]), populated with a clone of the header's navigation menu, so
 * Off-canvas / Fullscreen-overlay header types (and any mobile hamburger) work
 * everywhere. Styled by portable-drawer.css.
 */
( function () {
	'use strict';

	var DRAWER_ID = 'primary-navigation-drawer';

	function ready( fn ) {
		if ( document.readyState !== 'loading' ) { fn(); }
		else { document.addEventListener( 'DOMContentLoaded', fn ); }
	}

	ready( function () {
		// The native theme (or a theme that already provides one) wins — don't double up.
		if ( document.getElementById( DRAWER_ID ) ) {
			return;
		}

		var toggles = document.querySelectorAll( '.fw-tb-header .menu-toggle, .fw-tb-header [aria-controls="' + DRAWER_ID + '"]' );
		if ( ! toggles.length ) {
			return;
		}

		var header  = document.querySelector( '.fw-tb-header' );
		var menuSrc = header && ( header.querySelector( 'nav' ) || header.querySelector( 'ul.menu, .menu' ) );
		var overlay = header && header.getAttribute( 'data-hf-type' ) === 'fullscreen-overlay';

		// Build the drawer.
		var drawer = document.createElement( 'div' );
		drawer.id        = DRAWER_ID;
		drawer.className = 'fw-tb-drawer' + ( overlay ? ' fw-tb-drawer--overlay' : '' );
		drawer.setAttribute( 'hidden', '' );

		var scrim = document.createElement( 'div' );
		scrim.className = 'fw-tb-drawer__scrim';
		scrim.setAttribute( 'data-drawer-close', '' );

		var panel = document.createElement( 'div' );
		panel.className = 'fw-tb-drawer__panel';
		panel.setAttribute( 'role', 'dialog' );
		panel.setAttribute( 'aria-modal', 'true' );

		var closeBtn = document.createElement( 'button' );
		closeBtn.type = 'button';
		closeBtn.className = 'fw-tb-drawer__close';
		closeBtn.setAttribute( 'data-drawer-close', '' );
		closeBtn.setAttribute( 'aria-label', 'Close menu' );
		closeBtn.innerHTML = '&times;';
		panel.appendChild( closeBtn );

		if ( menuSrc ) {
			var clone = menuSrc.cloneNode( true );
			clone.removeAttribute( 'id' );
			// Drop any Menu Toggle the nav may wrap — inside the drawer it would be a
			// duplicate button with a now-dangling aria-controls.
			var dupes = clone.querySelectorAll( '.menu-toggle, [aria-controls="' + DRAWER_ID + '"]' );
			for ( var di = 0; di < dupes.length; di++ ) {
				if ( dupes[ di ].parentNode ) {
					dupes[ di ].parentNode.removeChild( dupes[ di ] );
				}
			}
			panel.appendChild( clone );
		}

		drawer.appendChild( scrim );
		drawer.appendChild( panel );
		document.body.appendChild( drawer );

		function isOpen() { return drawer.classList.contains( 'is-open' ); }
		function open() {
			drawer.hidden = false;
			void drawer.offsetWidth; // reflow so the transition runs
			drawer.classList.add( 'is-open' );
			document.body.classList.add( 'menu-drawer-open' );
			setExpanded( true );
		}
		function close() {
			drawer.classList.remove( 'is-open' );
			document.body.classList.remove( 'menu-drawer-open' );
			setExpanded( false );
			window.setTimeout( function () { if ( ! isOpen() ) { drawer.hidden = true; } }, 320 );
		}
		function setExpanded( on ) {
			for ( var i = 0; i < toggles.length; i++ ) {
				toggles[ i ].setAttribute( 'aria-expanded', on ? 'true' : 'false' );
			}
		}

		var i;
		for ( i = 0; i < toggles.length; i++ ) {
			toggles[ i ].addEventListener( 'click', function ( e ) {
				e.preventDefault();
				isOpen() ? close() : open();
			} );
		}
		var closers = drawer.querySelectorAll( '[data-drawer-close]' );
		for ( i = 0; i < closers.length; i++ ) {
			closers[ i ].addEventListener( 'click', close );
		}
		document.addEventListener( 'keydown', function ( e ) {
			if ( ( e.key === 'Escape' || e.keyCode === 27 ) && isOpen() ) { close(); }
		} );
	} );
}() );
