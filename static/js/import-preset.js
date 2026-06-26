/**
 * Theme Builder — Import Preset (Header / Body / Footer lists).
 *
 * Adds an "Import Preset" button next to "Add New" on a preset list screen. Picking
 * a preset JSON (the same shape the bundled starters use) posts it to the import
 * AJAX, which creates the preset and redirects to its builder.
 */
( function () {
	'use strict';

	var cfg = window.fwTbImport || {};

	function ready( fn ) {
		if ( document.readyState !== 'loading' ) { fn(); }
		else { document.addEventListener( 'DOMContentLoaded', fn ); }
	}

	ready( function () {
		var addNew = document.querySelector( '.wrap .page-title-action' );
		if ( ! addNew ) { return; }

		var btn = document.createElement( 'a' );
		btn.href = '#';
		btn.className = 'page-title-action fw-tb-import-btn';
		btn.textContent = cfg.label || 'Import Preset';
		addNew.parentNode.insertBefore( btn, addNew.nextSibling );

		var input = document.createElement( 'input' );
		input.type = 'file';
		input.accept = '.json,application/json';
		input.style.display = 'none';
		document.body.appendChild( input );

		btn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			input.click();
		} );

		input.addEventListener( 'change', function () {
			var file = input.files && input.files[0];
			if ( ! file ) { return; }
			var reader = new FileReader();
			reader.onload = function () {
				btn.textContent = cfg.importing || 'Importing…';
				var body = new URLSearchParams();
				body.append( 'action', 'fw_tb_import_preset' );
				body.append( '_wpnonce', cfg.nonce || '' );
				body.append( 'kind', cfg.kind || '' );
				body.append( 'data', String( reader.result || '' ) );
				fetch( cfg.ajaxurl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: body.toString()
				} ).then( function ( r ) { return r.json(); } ).then( function ( r ) {
					if ( r && r.success && r.data && r.data.edit_url ) {
						window.location = r.data.edit_url;
					} else {
						window.alert( ( r && r.data && r.data.message ) || cfg.fail || 'Import failed.' );
						btn.textContent = cfg.label || 'Import Preset';
					}
				} ).catch( function () {
					window.alert( cfg.fail || 'Import failed.' );
					btn.textContent = cfg.label || 'Import Preset';
				} );
				input.value = '';
			};
			reader.readAsText( file );
		} );
	} );
}() );
