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
				name:   ( $name.val() || '' ).trim()
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

	/* ================================================================
	 * Canvas cards
	 *
	 * Each card is one Template; each slot row is one of its three part
	 * references. Assigning / clearing a slot writes straight through
	 * fw_tb_set_part, so nothing here needs the two-step edit form. The slot
	 * markup rebuilt below mirrors render_slot() in the admin-page class —
	 * keep the two in step.
	 * ================================================================ */

	var choices = cfg.partChoices || {};

	function slotMeta($slot) {
		return parts[$slot.attr('data-key')] || {};
	}

	/** Redraw one slot for the given part id (0 = empty). */
	function drawSlot($slot, part, title, editUrl) {
		var $body = $slot.find('.fw-tb-slot__body').empty();
		$slot.attr('data-part', part);

		if (part > 0) {
			$slot.removeClass('is-empty').addClass('is-filled');
			$('<span class="fw-tb-slot__name"></span>').text(title).appendTo($body);
			var $acts = $('<span class="fw-tb-slot__acts"></span>').appendTo($body);
			$('<a class="fw-tb-slot__act fw-tb-slot-edit" target="_blank" rel="noopener noreferrer"></a>')
				.attr({ href: editUrl, title: i18n.editTitle || 'Edit design' })
				.append('<span class="dashicons dashicons-edit" aria-hidden="true"></span>')
				.appendTo($acts);
			$('<button type="button" class="fw-tb-slot__act fw-tb-slot-clear"></button>')
				.attr('title', i18n.remove || 'Remove')
				.append('<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>')
				.appendTo($acts);
			return;
		}

		$slot.removeClass('is-filled').addClass('is-empty');
		$('<button type="button" class="fw-tb-slot-add"></button>')
			.append($('<span aria-hidden="true">＋</span>'), ' ')
			.append(document.createTextNode(slotMeta($slot).empty || ''))
			.appendTo($body);
	}

	/** Point a slot at a part (or 0 to clear) and redraw it. */
	function setPart($slot, part, done) {
		$slot.addClass('is-busy');
		$.post(window.ajaxurl, {
			action:   'fw_tb_set_part',
			nonce:    cfg.createNonce,
			template: $slot.closest('.fw-tb-card').attr('data-template'),
			key:      $slot.attr('data-key'),
			part:     part
		}).done(function (res) {
			if (res && res.success && res.data) {
				drawSlot($slot, parseInt(res.data.part, 10) || 0, res.data.title, res.data.edit_url);
				if (done) { done(res.data); }
			} else {
				window.alert((res && res.data && res.data.message) || i18n.error || 'Error');
				drawSlot($slot, 0);
			}
		}).fail(function () {
			window.alert(i18n.error || 'Error');
			drawSlot($slot, 0);
		}).always(function () {
			$slot.removeClass('is-busy');
		});
	}

	/* ---- empty slot -> picker ---- */
	$(document).on('click', '.fw-tb-slot-add', function () {
		var $slot = $(this).closest('.fw-tb-slot');
		var $body = $slot.find('.fw-tb-slot__body').empty();
		var $pick = $('<span class="fw-tb-slot__picker"></span>').appendTo($body);
		var $sel  = $('<select class="fw-tb-slot__select"></select>').appendTo($pick);

		$('<option value=""></option>').text(i18n.pick || '').appendTo($sel);
		(choices[$slot.attr('data-key')] || []).forEach(function (part) {
			$('<option></option>').attr('value', part.id).text(part.title).appendTo($sel);
		});
		$('<option value="__new"></option>').text(i18n.pickNew || '').appendTo($sel);

		$('<button type="button" class="button-link fw-tb-slot__cancel"></button>')
			.text(i18n.cancel || 'Cancel').appendTo($pick);

		$sel.trigger('focus');
	});

	$(document).on('click', '.fw-tb-slot__cancel', function () {
		drawSlot($(this).closest('.fw-tb-slot'), 0);
	});

	/* ---- picker choice: an existing design, or a brand-new one ---- */
	$(document).on('change', '.fw-tb-slot__select', function () {
		var $sel  = $(this);
		var $slot = $sel.closest('.fw-tb-slot');
		var val   = $sel.val();

		if (!val) { return; }

		if (val !== '__new') {
			setPart($slot, parseInt(val, 10) || 0);
			return;
		}

		// "New design…" — name it, create it, then assign it in one go.
		var meta  = slotMeta($slot);
		var $body = $slot.find('.fw-tb-slot__body').empty();
		var $form = $('<span class="fw-tb-slot__picker"></span>').appendTo($body);
		var $name = $('<input type="text" class="fw-tb-slot__name-input">')
			.attr('placeholder', (i18n.namePH || 'New %s name').replace('%s', meta.noun || ''))
			.appendTo($form);
		var $go = $('<button type="button" class="button button-primary button-small"></button>')
			.text(i18n.create || 'Create').appendTo($form);
		$('<button type="button" class="button-link fw-tb-slot__cancel"></button>')
			.text(i18n.cancel || 'Cancel').appendTo($form);

		$name.trigger('focus').on('keydown', function (e) {
			if (e.which === 13) { e.preventDefault(); $go.trigger('click'); }
		});

		$go.on('click', function () {
			$go.prop('disabled', true).text(i18n.creating || 'Creating…');
			$.post(window.ajaxurl, {
				action: 'fw_tb_create_part',
				nonce:  cfg.createNonce,
				cpt:    meta.cpt,
				name:   ($name.val() || '').trim()
			}).done(function (res) {
				if (res && res.success && res.data && res.data.id) {
					// Keep the picker list in step so the new design is offered elsewhere.
					var key = $slot.attr('data-key');
					(choices[key] = choices[key] || []).push({ id: res.data.id, title: res.data.title });
					setPart($slot, res.data.id);
				} else {
					window.alert((res && res.data && res.data.message) || i18n.error || 'Error');
					drawSlot($slot, 0);
				}
			}).fail(function () {
				window.alert(i18n.error || 'Error');
				drawSlot($slot, 0);
			});
		});
	});

	/* ---- clear a filled slot ---- */
	$(document).on('click', '.fw-tb-slot-clear', function () {
		var $slot = $(this).closest('.fw-tb-slot');
		var msg   = (i18n.clearAsk || 'Remove this %s?').replace('%s', slotMeta($slot).noun || '');
		tbConfirm(msg, i18n.remove || 'Remove', function () { setPart($slot, 0); });
	});

	/* ---- card actions menu ---- */
	function closeMenus() {
		$('.fw-tb-menu').prop('hidden', true);
		$('.fw-tb-menu-toggle').attr('aria-expanded', 'false');
	}

	$(document).on('click', '.fw-tb-menu-toggle', function (e) {
		e.stopPropagation();
		var $menu = $(this).next('.fw-tb-menu');
		var wasOpen = !$menu.prop('hidden');
		closeMenus();
		if (!wasOpen) {
			$menu.prop('hidden', false);
			$(this).attr('aria-expanded', 'true');
		}
	});


	/* ---- card thumbnails: live, lazy, scaled front-end previews ----
	 * Each frame is a real render of the site through the nonce'd preview URL, so
	 * it is always current and needs no screenshot service. It costs a page load,
	 * which is why nothing loads until the card is near the viewport — and why the
	 * whole feature has an off switch in the screen header. */
	var $previews = $('.fw-tb-card__preview[data-src]');

	if ($previews.length) {
		var FRAME_W = 1280;

		function fitFrame(box) {
			var frame = box.querySelector('iframe');
			if (frame && box.clientWidth) {
				frame.style.transform = 'scale(' + (box.clientWidth / FRAME_W) + ')';
			}
		}

		function loadPreview(box) {
			if (box.getAttribute('data-loaded')) { return; }
			box.setAttribute('data-loaded', '1');

			var frame = document.createElement('iframe');
			frame.src = box.getAttribute('data-src');
			// Decorative: keyboard and pointer stay with the card, not the frame.
			frame.setAttribute('tabindex', '-1');
			frame.setAttribute('aria-hidden', 'true');
			frame.setAttribute('scrolling', 'no');
			frame.setAttribute('loading', 'lazy');
			frame.addEventListener('load', function () { box.classList.add('is-ready'); });
			box.appendChild(frame);
			fitFrame(box);
		}

		if (window.IntersectionObserver) {
			var io = new IntersectionObserver(function (entries) {
				entries.forEach(function (entry) {
					if (entry.isIntersecting) {
						loadPreview(entry.target);
						io.unobserve(entry.target);
					}
				});
			}, { rootMargin: '250px' });
			$previews.each(function () { io.observe(this); });
		} else {
			$previews.each(function () { loadPreview(this); });
		}

		var resizeTimer = null;
		$(window).on('resize', function () {
			window.clearTimeout(resizeTimer);
			resizeTimer = window.setTimeout(function () {
				$previews.each(function () { fitFrame(this); });
			}, 150);
		});
	}

	/* ---- enable / disable a Template from its card ---- */
	$(document).on('change', '.fw-tb-switch__input', function () {
		var $cb    = $(this);
		var $card  = $cb.closest('.fw-tb-card');
		var wanted = $cb.prop('checked');

		$card.toggleClass('is-disabled', !wanted);

		function revert() {
			$cb.prop('checked', !wanted);
			$card.toggleClass('is-disabled', wanted);
			window.alert(i18n.toggleFail || i18n.error || 'Error');
		}

		$.post(window.ajaxurl, {
			action:   'fw_tb_set_enabled',
			nonce:    cfg.createNonce,
			template: $card.attr('data-template'),
			enabled:  wanted ? 1 : 0
		}).done(function (res) {
			if (!res || !res.success) { revert(); }
		}).fail(revert);
	});

	$(document).on('click', closeMenus);
	$(document).on('keydown', function (e) {
		if (e.key === 'Escape' || e.keyCode === 27) { closeMenus(); }
	});


	/* ================================================================
	 * Condition rows (Step 2 on the Template edit screen)
	 *
	 * One row = one resolver rule. The rows are serialized to a hidden
	 * field on every change; PHP re-derives every field from the rule
	 * vocabulary on save, so nothing here is trusted.
	 * ================================================================ */

	var $cond = $('.fw-tb-cond');

	if ($cond.length) {
		var ruleTypes  = cfg.ruleTypes || {};
		var subChoices = cfg.subChoices || {};
		var $rowsWrap  = $cond.find('.fw-tb-cond__rows');
		var $json      = $cond.find('.fw-tb-cond__json');
		var rows       = [];

		try {
			rows = JSON.parse($rowsWrap.attr('data-rows') || '[]') || [];
		} catch (e) {
			rows = [];
		}

		var choiceCache = {};

		function opt($sel, value, label) {
			$('<option></option>').attr('value', value).text(label).appendTo($sel);
		}

		/** Push the rows into the hidden field the form posts. */
		function sync() {
			$json.val(JSON.stringify(rows.map(function (r) {
				return { side: r.side, type: r.type, sub_type: r.sub_type, ids: r.ids || [] };
			})));
		}

		/** Fetch the selectable values for a row, cached per type|sub|search. */
		function loadChoices(type, sub, search, cb) {
			var key = type + '|' + sub + '|' + search;
			if (choiceCache[key]) { cb(choiceCache[key]); return; }

			$.post(window.ajaxurl, {
				action:   'fw_tb_rule_choices',
				nonce:    cfg.createNonce,
				type:     type,
				sub_type: sub,
				search:   search
			}).done(function (res) {
				var items = (res && res.success && res.data && res.data.items) ? res.data.items : [];
				choiceCache[key] = items;
				cb(items);
			}).fail(function () { cb([]); });
		}

		/* ---- the values picker ---- */
		function renderChips(row, $cell) {
			$cell.find('.fw-tb-chips').remove();
			var $chips = $('<span class="fw-tb-chips"></span>').prependTo($cell);

			(row.labels || []).forEach(function (item) {
				var $chip = $('<span class="fw-tb-chip"></span>').text(item.label).appendTo($chips);
				$('<button type="button" class="fw-tb-chip__x" aria-label="remove">×</button>')
					.on('click', function () {
						row.ids = (row.ids || []).filter(function (id) { return id !== item.id; });
						row.labels = (row.labels || []).filter(function (l) { return l.id !== item.id; });
						renderChips(row, $cell);
						sync();
					})
					.appendTo($chip);
			});

			if (!(row.labels || []).length) {
				var spec = ruleTypes[row.type] || {};
				$('<span class="fw-tb-chips__empty"></span>')
					.text(spec.ids === 'optional' ? (i18n.allOf || '') : '')
					.appendTo($chips);
			}
		}

		function openPicker(row, $cell) {
			$('.fw-tb-picker').remove();

			var $panel  = $('<div class="fw-tb-picker"></div>');
			var $search = $('<input type="search" class="fw-tb-picker__search">')
				.attr('placeholder', i18n.searchPH || 'Search…').appendTo($panel);
			var $list   = $('<div class="fw-tb-picker__list"></div>').appendTo($panel);
			$('<button type="button" class="button button-small fw-tb-picker__done"></button>')
				.text(i18n.done || 'Done')
				.on('click', function () { $panel.remove(); })
				.appendTo($panel);

			function draw(items) {
				$list.empty();
				if (!items.length) {
					$('<p class="fw-tb-picker__empty"></p>').text(i18n.noItems || '').appendTo($list);
					return;
				}
				items.forEach(function (item) {
					var $lab = $('<label class="fw-tb-picker__item"></label>').appendTo($list);
					var $cb  = $('<input type="checkbox">')
						.prop('checked', (row.ids || []).indexOf(item.id) !== -1)
						.appendTo($lab);
					$lab.append(document.createTextNode(' ' + item.label));

					$cb.on('change', function () {
						row.ids    = row.ids || [];
						row.labels = row.labels || [];
						if (this.checked) {
							if (row.ids.indexOf(item.id) === -1) {
								row.ids.push(item.id);
								row.labels.push({ id: item.id, label: item.label });
							}
						} else {
							row.ids    = row.ids.filter(function (id) { return id !== item.id; });
							row.labels = row.labels.filter(function (l) { return l.id !== item.id; });
						}
						renderChips(row, $cell);
						sync();
					});
				});
			}

			$list.text(i18n.loading || '');
			loadChoices(row.type, row.sub_type, '', draw);

			var timer = null;
			$search.on('input', function () {
				var term = this.value;
				window.clearTimeout(timer);
				timer = window.setTimeout(function () {
					$list.text(i18n.loading || '');
					loadChoices(row.type, row.sub_type, term, draw);
				}, 250);
			});

			$cell.append($panel);
			$search.trigger('focus');
		}

		/* ---- one row ---- */
		function renderRow(row, index) {
			var spec = ruleTypes[row.type] || null;
			var $row = $('<div class="fw-tb-cond-row"></div>');

			// 1. Include / Exclude
			var $side = $('<select class="fw-tb-cond__side"></select>');
			opt($side, 'use_on', i18n.include || 'Include');
			opt($side, 'exclude_from', i18n.exclude || 'Exclude');
			$side.val(row.side || 'use_on').on('change', function () {
				row.side = this.value;
				$row.toggleClass('is-exclude', row.side === 'exclude_from');
				sync();
			});
			$row.toggleClass('is-exclude', (row.side || 'use_on') === 'exclude_from');
			$('<span class="fw-tb-cond__cell"></span>').append($side).appendTo($row);

			// 2. the rule type
			var $type = $('<select class="fw-tb-cond__type"></select>');
			opt($type, '', i18n.pickType || '—');
			Object.keys(ruleTypes).forEach(function (t) { opt($type, t, ruleTypes[t].label); });
			$type.val(row.type || '').on('change', function () {
				row.type     = this.value;
				row.sub_type = '';       // a new type invalidates the qualifier…
				row.ids      = [];       // …and any values chosen under the old one
				row.labels   = [];
				render();
				sync();
			});
			$('<span class="fw-tb-cond__cell"></span>').append($type).appendTo($row);

			// 3. the qualifier
			if (spec && spec.sub) {
				var $sub  = $('<select class="fw-tb-cond__sub"></select>');
				var subs  = subChoices[row.type] || {};
				opt($sub, '', spec.sub_label || '—');
				Object.keys(subs).forEach(function (k) { opt($sub, k, subs[k]); });
				$sub.val(row.sub_type || '').on('change', function () {
					row.sub_type = this.value;
					row.ids      = [];   // values belong to the old qualifier
					row.labels   = [];
					render();
					sync();
				});
				$('<span class="fw-tb-cond__cell"></span>').append($sub).appendTo($row);
			} else {
				$('<span class="fw-tb-cond__cell fw-tb-cond__cell--spacer"></span>').appendTo($row);
			}

			// 4. the values
			// A rule that takes NO qualifier (author, author archive) still takes
			// values, so gate on the qualifier only when the rule actually has one —
			// otherwise those rules could never be given any authors at all.
			if (spec && spec.ids && (!spec.sub || row.sub_type)) {
				var $cell = $('<span class="fw-tb-cond__cell fw-tb-cond__cell--ids"></span>');
				renderChips(row, $cell);
				$('<button type="button" class="button button-small fw-tb-cond__choose"></button>')
					.text((spec.ids_label || i18n.chooseVals || 'Choose…'))
					.on('click', function (e) { e.stopPropagation(); openPicker(row, $cell); })
					.appendTo($cell);
				$cell.appendTo($row);
			} else {
				$('<span class="fw-tb-cond__cell fw-tb-cond__cell--spacer"></span>').appendTo($row);
			}

			// 5. remove
			$('<button type="button" class="fw-tb-cond__del"></button>')
				.attr('title', i18n.removeRow || 'Remove')
				.append('<span class="dashicons dashicons-trash" aria-hidden="true"></span>')
				.on('click', function () {
					rows.splice(index, 1);
					render();
					sync();
				})
				.appendTo($row);

			// an incomplete row would silently match nothing — say so
			var incomplete = !row.type ||
				(spec && spec.sub && !row.sub_type) ||
				(spec && spec.ids === 'required' && !(row.ids || []).length);
			$row.toggleClass('is-incomplete', !!incomplete);

			if (spec && spec.hint) {
				$('<p class="fw-tb-cond-row__hint"></p>').text(spec.hint).appendTo($row);
			}

			return $row;
		}

		function render() {
			$rowsWrap.empty();
			if (!rows.length) {
				$('<p class="fw-tb-cond__none description"></p>').text(i18n.noRows || '').appendTo($rowsWrap);
				return;
			}
			rows.forEach(function (row, i) { $rowsWrap.append(renderRow(row, i)); });
		}

		$cond.find('.fw-tb-cond__add').on('click', function () {
			rows.push({ side: 'use_on', type: '', sub_type: '', ids: [], labels: [] });
			render();
			sync();
		});

		// Close a picker when clicking elsewhere.
		$(document).on('click', function (e) {
			if (!$(e.target).closest('.fw-tb-picker, .fw-tb-cond__choose').length) {
				$('.fw-tb-picker').remove();
			}
		});

		// Belt and braces: re-serialize on submit in case a control changed without
		// firing its handler (autofill, an extension, a stray script).
		$cond.closest('form').on('submit', sync);

		render();
		sync();
	}
});
