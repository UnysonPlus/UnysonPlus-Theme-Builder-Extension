/**
 * Theme Builder — admin management page.
 *
 *  1. Confirm Template deletes before following the (nonce-carrying) link.
 *  2. Make the Template screen self-contained: next to each Header / Body / Footer
 *     dropdown, add a "＋ New" button (AJAX-creates a published part and selects it)
 *     and an "Edit design ↗" link (opens that part in the page builder, new tab).
 *     So a non-developer can build and assign parts without leaving the page.
 */
jQuery(function ($) {
	var cfg  = window.fwThemeBuilder || {};
	var i18n = cfg.i18n || {};

	/* ---- styled confirm modal ----
	 * A small self-contained dialog (styled in admin.css). We do NOT use Unyson's
	 * fw.confirm() here — its modal assets aren't enqueued on this custom admin
	 * screen, so it would render its message as a stray unstyled <p> at the bottom of
	 * the page. */
	function tbConfirm(message, confirmLabel, onConfirm) {
		var $overlay = $('<div class="fw-tb-modal-overlay"></div>');
		var $box = $('<div class="fw-tb-modal" role="dialog" aria-modal="true"></div>');
		$('<div class="fw-tb-modal__msg"></div>').text(message).appendTo($box);
		var $actions = $('<div class="fw-tb-modal__actions"></div>').appendTo($box);
		$('<button type="button" class="button fw-tb-modal__cancel"></button>').text(i18n.cancel || 'Cancel').appendTo($actions);
		$('<button type="button" class="button fw-tb-modal__ok"></button>').text(confirmLabel || 'OK').appendTo($actions);
		$overlay.append($box).appendTo('body');

		function close() { $overlay.remove(); $(document).off('keydown.fwtbconfirm'); }
		$box.find('.fw-tb-modal__ok').on('click', function () { close(); onConfirm(); }).trigger('focus');
		$box.find('.fw-tb-modal__cancel').on('click', close);
		$overlay.on('click', function (e) { if (e.target === $overlay[0]) { close(); } });
		$(document).on('keydown.fwtbconfirm', function (e) { if (e.key === 'Escape' || e.keyCode === 27) { close(); } });
	}

	var delMsg = cfg.confirmDelete || 'Delete this Template?';
	$(document).on('click', '.fw-tb-delete', function (e) {
		e.preventDefault();
		var href = this.href;
		tbConfirm(delMsg, i18n.deleteBtn || 'Delete', function () { window.location.href = href; });
	});

	/* ---- import a design bundle (upload a JSON exported with the row Export) ---- */
	$( document ).on( 'click', '.fw-tb-import-design', function ( e ) {
		e.preventDefault();
		var input = $( '<input type="file" accept=".json,application/json" style="display:none">' ).appendTo( 'body' );
		input.on( 'change', function () {
			var file = this.files && this.files[0];
			if ( ! file ) { input.remove(); return; }
			var reader = new FileReader();
			reader.onload = function () {
				$.post( window.ajaxurl, {
					action: 'fw_tb_import_design',
					_wpnonce: cfg.importNonce,
					data: String( reader.result || '' )
				} ).done( function ( r ) {
					if ( r && r.success && r.data && r.data.edit_url ) {
						window.location = r.data.edit_url;
					} else {
						window.alert( ( r && r.data && r.data.message ) || cfg.importFail || 'Import failed.' );
					}
				} ).fail( function () { window.alert( cfg.importFail || 'Import failed.' ); } );
			};
			reader.readAsText( file );
			input.remove();
		} );
		input.trigger( 'click' );
	} );

	/* ---- inline part create + edit, per dropdown ---- */
	function editUrl(id) {
		return (cfg.editPartBase || '') + '?post=' + encodeURIComponent(id) + '&action=edit';
	}

	function findSelect(optId) {
		var $s = $('select[name="' + optId + '"]').first();
		if (!$s.length) { $s = $('select[name$="[' + optId + ']"]').first(); }
		return $s;
	}

	function enhance(optId, meta) {
		var $select = findSelect(optId);
		if (!$select.length || $select.data('fwTbEnhanced')) { return; }
		$select.data('fwTbEnhanced', true);

		var $tools  = $('<div class="fw-tb-part-tools"></div>');
		var $newBtn = $('<button type="button" class="button button-small fw-tb-newpart"></button>').text(i18n.newPart || '＋ New');
		var $edit   = $('<a class="fw-tb-editdesign" target="_blank" rel="noopener noreferrer"></a>').text(i18n.editDesign || 'Edit design');

		// Inline name form (hidden until "＋ New").
		var $form   = $('<span class="fw-tb-newpart-form" style="display:none;"></span>');
		var $name   = $('<input type="text" class="regular-text fw-tb-newpart-name">')
			.attr('placeholder', (i18n.namePH || 'New %s name').replace('%s', meta.noun || ''));
		var $create = $('<button type="button" class="button button-primary button-small fw-tb-newpart-create"></button>').text(i18n.create || 'Create');
		var $cancel = $('<button type="button" class="button button-small fw-tb-newpart-cancel"></button>').text(i18n.cancel || 'Cancel');
		var $tip    = $('<span class="fw-tb-newpart-tip" style="display:none;"></span>');

		$form.append($name, ' ', $create, ' ', $cancel);
		$tools.append($newBtn, ' ', $edit, $form, $tip);
		$select.after($tools);

		function refreshEdit() {
			var v = parseInt($select.val(), 10);
			if (v > 0) { $edit.attr('href', editUrl(v)).show(); }
			else { $edit.hide(); }
		}
		$select.on('change', refreshEdit);
		refreshEdit();

		$newBtn.on('click', function () {
			$newBtn.hide(); $edit.hide(); $tip.hide();
			$form.show(); $name.val('').focus();
		});
		$cancel.on('click', function () {
			$form.hide(); $newBtn.show(); refreshEdit();
		});
		$name.on('keydown', function (e) {
			if (e.which === 13) { e.preventDefault(); $create.trigger('click'); }
		});

		$create.on('click', function () {
			$create.prop('disabled', true).text(i18n.creating || 'Creating…');
			$.post(window.ajaxurl, {
				action: 'fw_tb_create_part',
				nonce:  cfg.createNonce,
				cpt:    meta.cpt,
				name:   $.trim($name.val())
			}).done(function (res) {
				if (res && res.success && res.data && res.data.id) {
					var d = res.data;
					$('<option></option>').attr('value', d.id).text(d.title).appendTo($select);
					$select.val(String(d.id)).trigger('change');
					$form.hide(); $newBtn.show();
					$edit.attr('href', d.edit_url).show();
					$tip.text(i18n.createdTip || '').show();
				} else {
					window.alert((res && res.data && res.data.message) || i18n.error || 'Error');
				}
			}).fail(function () {
				window.alert(i18n.error || 'Error');
			}).always(function () {
				$create.prop('disabled', false).text(i18n.create || 'Create');
			});
		});
	}

	var parts = cfg.parts || {};
	Object.keys(parts).forEach(function (optId) { enhance(optId, parts[optId]); });
});
