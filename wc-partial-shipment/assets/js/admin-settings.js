jQuery(function ($) {
	// Keep the "Backfill orders now" link in step with the statuses selected above it
	// (the current selection is used, even before the settings are saved).
	var $link = $('#wxp_backfill_link');
	var $select = $('#wxp_backfill_status');
	if (!$link.length || !$select.length) {
		return;
	}
	var base = String($link.data('base'));

	function sync() {
		var vals = $select.val() || [];
		$link.attr('href', base + '&wxp_backfill_status=' + encodeURIComponent(vals.join(',')));
	}

	$select.on('change', sync);
	// Re-sync once WooCommerce has initialised selectWoo on the field.
	$(document.body).on('wc-enhanced-select-init', sync);
	sync();
});
