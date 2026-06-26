/**
 * Theme Builder — portable header scroll behaviors (foreign themes only).
 *
 * Toggles `.is-stuck` (sticky / sticky-shrink / transparent-overlay) and `.is-hidden`
 * (hide-on-scroll) on any `.fw-tb-header[data-hf-behavior]` the renderer emits, so a
 * preset's Scroll Behavior works the same under any theme. The CSS in
 * header-behaviors.css does the visual work; this only flips the two classes.
 */
( function () {
	'use strict';

	var headers = document.querySelectorAll( '.fw-tb-header[data-hf-behavior]' );
	if ( ! headers.length ) {
		return;
	}

	var STICK_AT  = 8;  // px scrolled before a header counts as "stuck"
	var HIDE_PAST = 80; // don't hide-on-scroll until past this
	var states    = [];
	var i;

	for ( i = 0; i < headers.length; i++ ) {
		states.push( {
			el:       headers[ i ],
			behavior: headers[ i ].getAttribute( 'data-hf-behavior' ),
			lastY:    window.pageYOffset || document.documentElement.scrollTop || 0
		} );
	}

	var ticking = false;

	function update() {
		var y = window.pageYOffset || document.documentElement.scrollTop || 0;
		for ( var j = 0; j < states.length; j++ ) {
			var s = states[ j ];
			if ( y > STICK_AT ) {
				s.el.classList.add( 'is-stuck' );
			} else {
				s.el.classList.remove( 'is-stuck' );
			}
			if ( s.behavior === 'hide-on-scroll' ) {
				if ( y > s.lastY && y > HIDE_PAST ) {
					s.el.classList.add( 'is-hidden' );
				} else {
					s.el.classList.remove( 'is-hidden' );
				}
			}
			s.lastY = y < 0 ? 0 : y;
		}
		ticking = false;
	}

	window.addEventListener( 'scroll', function () {
		if ( ! ticking ) {
			window.requestAnimationFrame( update );
			ticking = true;
		}
	}, { passive: true } );

	update();
}() );
