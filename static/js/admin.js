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

	/* ---- delete confirm ---- */
	var delMsg = cfg.confirmDelete || 'Delete this Template?';
	$(document).on('click', '.fw-tb-delete', function (e) {
		e.preventDefault();
		var href = this.href;
		if (window.fw && typeof fw.confirm === 'function') {
			fw.confirm(delMsg, function () { window.location.href = href; });
		} else if (window.confirm(delMsg)) {
			window.location.href = href;
		}
	});

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
