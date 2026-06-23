/**
 * Theme Builder — admin management page.
 *
 * Minimal: confirm Template deletes before following the (nonce-carrying) link.
 * Uses fw.confirm() when the framework is present, else the native confirm().
 */
jQuery(function ($) {
	var cfg = window.fwThemeBuilder || {};
	var msg = cfg.confirmDelete || 'Delete this Template?';

	$(document).on('click', '.fw-tb-delete', function (e) {
		e.preventDefault();
		var href = this.href;

		if (window.fw && typeof fw.confirm === 'function') {
			fw.confirm(msg, function () { window.location.href = href; });
		} else if (window.confirm(msg)) {
			window.location.href = href;
		}
	});
});
