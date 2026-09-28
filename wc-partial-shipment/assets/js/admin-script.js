jQuery(function ($) {
	var settings = window.wxp_partial_ship || {};
	var i18n = settings.i18n || {};
	var labels = settings.labels || {};

	function esc(str) {
		return $('<div>').text(str == null ? '' : String(str)).html();
	}
	function attr(str) {
		return esc(str).replace(/"/g, '&quot;');
	}
	// Plain-text sprintf for the localized strings (%s and %1$s placeholders); escape the result where it is used.
	function fmt(tpl) {
		var args = arguments;
		var next = 0;
		return String(tpl || '').replace(/%(?:(\d+)\$)?s/g, function (m, pos) {
			next++;
			var v = args[pos ? parseInt(pos, 10) : next];
			return v == null ? '' : String(v);
		});
	}

	// An expired nonce ("-1"), a missing capability or a server error must not leave the items box blocked.
	function wxp_ajax_failed() {
		wxp_meta_boxes_order_items.unblock();
		WXPModal.open({ title: i18n.title, body: '<p class="wxp-modal-message">' + esc(settings.wxp_error) + '</p>' });
	}

	/* ---------- Shipment popup ---------- */

	var shipment = {
		rows: [],
		orderId: 0,
		check: '',
		mode: 'order',

		editable: function (r) {
			return !r.virtual && !r.refunded && r.qty > 0;
		},

		state: function (r) {
			if (r.refunded) {
				return 'refunded';
			}
			if (r.virtual) {
				return 'virtual';
			}
			if (r.value <= 0) {
				return 'not';
			}
			return r.value >= r.qty ? 'shipped' : 'partial';
		},

		// Same labels, colours and counts as the badges in the order items table.
		badge: function (r) {
			var s = this.state(r);
			if ('virtual' === s) {
				return '<span class="wxp-ship-status wxp-neutral">' + esc(i18n.virtual) + '</span>';
			}
			var map = {
				shipped: ['wxp-shipped', labels.shipped, r.value],
				partial: ['wxp-partial-shipped', labels['partially-shipped'], r.value],
				refunded: ['wxp-refunded', labels.refunded, r.ordered],
				not: ['wxp-not-shipped', labels['not-shipped'], r.qty]
			};
			var b = map[s];
			return '<span class="wxp-ship-status ' + b[0] + '">' + esc(b[1]) + ' - ' + esc(b[2]) + '</span>';
		},

		rowHtml: function (r, i) {
			var meta = [];
			if (r.sku) {
				meta.push(esc(fmt(i18n.sku, r.sku)));
			}
			meta.push(esc(fmt(i18n.ordered, r.ordered)));
			if (r.refunded_qty > 0 && !r.refunded) {
				meta.push(esc(fmt(i18n.refunded_qty, r.refunded_qty)));
			}

			var control;
			if (this.editable(r)) {
				// "2 / 12" inside one control; the input grows with the number of digits it can hold.
				control =
					'<div class="wxp-stepper">' +
						'<button type="button" class="wxp-step" data-step="-1" tabindex="-1" aria-label="' + attr(i18n.decrease) + '">&minus;</button>' +
						'<label class="wxp-qty-wrap">' +
							'<input type="number" class="wxp-qty" data-index="' + i + '" min="0" max="' + r.qty + '" step="1" inputmode="numeric" value="' + r.value + '" style="width:' + (String(r.qty).length + 0.6) + 'ch" aria-label="' + attr(fmt(i18n.qty_label, r.name, r.qty)) + '">' +
							'<span class="wxp-qty-max" aria-hidden="true">/ ' + esc(r.qty) + '</span>' +
						'</label>' +
						'<button type="button" class="wxp-step" data-step="1" tabindex="-1" aria-label="' + attr(i18n.increase) + '">+</button>' +
					'</div>' +
					// Always rendered (hidden until the row changes) so nothing shifts while editing.
					'<button type="button" class="wxp-undo" data-index="' + i + '" tabindex="-1" aria-hidden="true">' +
						'<span class="dashicons dashicons-undo" aria-hidden="true"></span>' +
					'</button>';
			} else {
				control = '<span class="wxp-note">' + esc(r.refunded ? i18n.refunded_note : i18n.virtual_note) + '</span>';
			}

			return '<li class="wxp-ship-row' + (this.editable(r) ? '' : ' is-locked') + '" data-index="' + i + '">' +
				'<img class="wxp-ship-thumb" src="' + attr(r.image) + '" alt="" width="40" height="40" loading="lazy">' +
				'<div class="wxp-ship-info">' +
					'<span class="wxp-ship-name">' + esc(r.name) + '</span>' +
					'<span class="wxp-ship-meta">' + meta.join(' &middot; ') + '</span>' +
				'</div>' +
				'<div class="wxp-ship-badge">' + this.badge(r) + '</div>' +
				'<div class="wxp-ship-control">' + control + '</div>' +
			'</li>';
		},

		summaryHtml: function () {
			return '<div class="wxp-ship-summary">' +
				'<div class="wxp-ship-summary__row">' +
					'<span class="wxp-ship-summary__text"></span>' +
					'<span class="wxp-chip"></span>' +
					'<span class="wxp-dirty" hidden>' + esc(i18n.unsaved) + '</span>' +
				'</div>' +
				'<div class="wxp-progress" aria-hidden="true"><span></span></div>' +
			'</div>';
		},

		open: function (data, mode) {
			var self = this;
			this.mode = mode;
			this.orderId = data.order_id;
			this.check = data.check;
			this.rows = $.map(data.products, function (p) {
				var qty = parseInt(p.qty, 10) || 0;
				var shipped = Math.min(parseInt(p.shipped, 10) || 0, qty);
				return {
					id: p.id,
					name: p.name,
					sku: p.sku || '',
					image: p.image || '',
					virtual: !!p.virtual,
					refunded: !!p.refunded,
					ordered: parseInt(p.ordered, 10) || qty,
					refunded_qty: parseInt(p.refunded_qty, 10) || 0,
					qty: qty,
					saved: shipped,
					value: shipped
				};
			});

			var anyEditable = $.grep(this.rows, function (r) { return self.editable(r); }).length > 0;
			var body =
				'<form class="wxp-ship" id="wxp-ship-form" novalidate>' +
					('order' === mode ? this.summaryHtml() : '') +
					'<ul class="wxp-ship-list">' + $.map(this.rows, function (r, i) { return self.rowHtml(r, i); }).join('') + '</ul>' +
					'<p class="wxp-ship-error" role="alert" hidden></p>' +
				'</form>';
			var foot =
				'<div class="wxp-ship-foot">' +
					'<div class="wxp-ship-quick">' + (anyEditable && this.rows.length > 1 ?
						'<button type="button" class="button-link wxp-fill" data-fill="max">' + esc(i18n.ship_all) + '</button>' +
						'<button type="button" class="button-link wxp-fill" data-fill="0">' + esc(i18n.clear_all) + '</button>' : '') +
					'</div>' +
					'<div class="wxp-ship-actions">' +
						'<button type="button" class="button wxp-cancel">' + esc(i18n.cancel) + '</button>' +
						(anyEditable ? '<button type="submit" form="wxp-ship-form" class="button button-primary wxp-save" disabled>' + esc(i18n.save) + '</button>' : '') +
					'</div>' +
				'</div>';

			WXPModal.open({
				title: data.order_number ? fmt(i18n.title_order, data.order_number) : i18n.title,
				body: body,
				foot: foot,
				className: 'wxp-modal--shipment',
				onOpen: function (dialog) {
					self.bind($(dialog));
					self.refresh();
					var first = dialog.querySelector('.wxp-qty');
					if (first) {
						first.focus();
						first.select();
					} else {
						dialog.querySelector('.wxp-cancel').focus();
					}
				}
			});
		},

		bind: function ($d) {
			var self = this;
			$d.off('.wxpship');
			$d.on('input.wxpship', '.wxp-qty', function () {
				var r = self.rows[$(this).data('index')];
				var v = parseInt(this.value, 10);
				r.value = isNaN(v) ? 0 : Math.max(0, Math.min(r.qty, v));
				self.refresh();
			});
			$d.on('change.wxpship', '.wxp-qty', function () {
				this.value = self.rows[$(this).data('index')].value; // clamp what is shown once editing is done
			});
			$d.on('click.wxpship', '.wxp-step', function () {
				var $input = $(this).closest('.wxp-stepper').find('.wxp-qty');
				var r = self.rows[$input.data('index')];
				r.value = Math.max(0, Math.min(r.qty, r.value + parseInt($(this).data('step'), 10)));
				$input.val(r.value);
				self.refresh();
			});
			$d.on('click.wxpship', '.wxp-undo', function () {
				var index = $(this).data('index');
				var r = self.rows[index];
				r.value = r.saved;
				self.refresh();
				$d.find('.wxp-qty[data-index="' + index + '"]').val(r.value).trigger('focus');
			});
			$d.on('click.wxpship', '.wxp-fill', function () {
				var fill = $(this).data('fill');
				$.each(self.rows, function (i, r) {
					if (self.editable(r)) {
						r.value = 'max' === fill ? r.qty : 0;
					}
				});
				$d.find('.wxp-qty').each(function () {
					this.value = self.rows[$(this).data('index')].value;
				});
				self.refresh();
			});
			$d.on('click.wxpship', '.wxp-cancel', function () {
				WXPModal.close();
			});
			$d.on('submit.wxpship', '#wxp-ship-form', function (e) {
				e.preventDefault();
				self.save($d);
			});
		},

		changed: function () {
			var self = this;
			return $.grep(this.rows, function (r) { return self.editable(r) && r.value !== r.saved; });
		},

		refresh: function () {
			var self = this;
			var $d = $(WXPModal.dialog);
			$.each(this.rows, function (i, r) {
				var $row = $d.find('.wxp-ship-row[data-index="' + i + '"]');
				var dirty = r.value !== r.saved;
				var undo = dirty ? fmt(i18n.undo, r.saved) : '';
				$row.toggleClass('is-changed', dirty);
				$row.find('.wxp-ship-badge').html(self.badge(r));
				$row.find('.wxp-undo').toggleClass('is-visible', dirty).attr({
					title: undo,
					'aria-label': undo,
					'aria-hidden': dirty ? 'false' : 'true',
					tabindex: dirty ? '0' : '-1'
				});
			});

			var changes = this.changed().length;
			$d.find('.wxp-save').prop('disabled', 0 === changes);
			$d.find('.wxp-dirty').prop('hidden', 0 === changes);

			if ('order' !== this.mode) {
				return;
			}
			// Mirrors the automatic order status: virtual and fully refunded items do not count.
			var total = 0;
			var done = 0;
			$.each(this.rows, function (i, r) {
				if (!r.virtual && !r.refunded) {
					total += r.qty;
					done += Math.min(r.value, r.qty);
				}
			});
			var state = 0 === total ? 'empty' : (0 === done ? 'none' : (done >= total ? 'full' : 'partial'));
			$d.find('.wxp-ship-summary__text').text(fmt(i18n.summary, done, total));
			$d.find('.wxp-chip').attr('class', 'wxp-chip is-' + state).text(i18n['state_' + state]);
			$d.find('.wxp-progress').attr('class', 'wxp-progress is-' + state)
				.children('span').css('width', (total ? Math.round(done / total * 100) : 0) + '%');
		},

		save: function ($d) {
			var self = this;
			var changed = this.changed();
			if (!changed.length) {
				return;
			}
			var $save = $d.find('.wxp-save');
			var $error = $d.find('.wxp-ship-error');
			// Keep the popup (and the edits) open so the save can be retried.
			var failed = function () {
				$save.removeClass('is-busy').text(i18n.save).prop('disabled', false);
				$error.text(i18n.save_error).prop('hidden', false);
			};
			$error.prop('hidden', true).text('');
			$save.prop('disabled', true).addClass('is-busy').text(i18n.saving);

			$.ajax({
				type: 'POST',
				cache: false,
				url: settings.wxp_ajax,
				dataType: 'json',
				data: {
					action: 'wxp_order_set_shipped',
					order_id: this.orderId,
					wxp_check: this.check,
					shipped: $.map(changed, function (r) {
						return { order_id: self.orderId, item_id: r.id, shipped: r.value, type: r.value > 0 ? 'shipped' : 'not-shipped' };
					})
				},
				success: function (data) {
					if (!data || typeof data !== 'object') {
						failed();
						return;
					}
					WXPModal.close();
					if (typeof data.status !== 'undefined' && data.status !== '') {
						wxp_meta_boxes_order_items.set_status(data.status);
					}
					wxp_meta_boxes_order_items.reload_items(self.orderId, true);
				},
				error: failed
			});
		}
	};

	/* ---------- Order items meta box ---------- */

	var wxp_meta_boxes_order_items = {
		init: function () {
			$('#woocommerce-order-items').on('click', 'button.wxp-order-shipment', this.load_order);
			$('#woocommerce-order-items').on('click', 'a.icon-wxp-set-shipping', this.load_item);
		},
		load_order: function (e) {
			e.stopPropagation();
			e.preventDefault();
			wxp_meta_boxes_order_items.fetch({
				action: 'wxp_order_shipment',
				order_id: $(this).attr('data-order-id'),
				wxp_check: $(this).attr('data-check')
			}, 'order');
		},
		load_item: function (e) {
			e.preventDefault();
			wxp_meta_boxes_order_items.fetch({
				action: 'wxp_order_item_shipment',
				order_id: $(this).attr('data-order-id'),
				item_id: $(this).attr('data-item-id'),
				wxp_check: $(this).attr('data-check')
			}, 'item');
		},
		fetch: function (data, mode) {
			wxp_meta_boxes_order_items.block();
			$.ajax({
				type: 'POST',
				cache: false,
				url: settings.wxp_ajax,
				dataType: 'json',
				data: data,
				error: wxp_ajax_failed,
				success: function (resp) {
					if (!resp || !resp.valid || !resp.products || !resp.products.length) {
						wxp_ajax_failed();
						return;
					}
					wxp_meta_boxes_order_items.unblock();
					shipment.open(resp, mode);
				}
			});
		},
		block: function () {
			$('#woocommerce-order-items').block({
				message: null,
				overlayCSS: {
					background: '#fff',
					opacity: 0.6
				}
			});
		},
		unblock: function () {
			$('#woocommerce-order-items').unblock();
		},
		reload_items: function (order_id, refocus) {
			wxp_meta_boxes_order_items.block();
			$.ajax({
				url: settings.wxp_ajax,
				data: {
					order_id: order_id,
					action: 'woocommerce_load_order_items',
					security: settings.wxp_order_nonce
				},
				type: 'POST',
				error: wxp_ajax_failed,
				success: function (response) {
					$('#woocommerce-order-items').find('.inside').empty().append(response);
					// Re-attach WooCommerce's tooltips to the freshly loaded rows.
					$(document.body).trigger('init_tooltips');
					wxp_meta_boxes_order_items.unblock();
					if (refocus) {
						// The button that opened the popup was replaced by the reload.
						$('#woocommerce-order-items button.wxp-order-shipment').trigger('focus');
					}
				}
			});
		},
		set_status: function (status) {
			$('#order_status').val(status).trigger('change');
		}
	};
	wxp_meta_boxes_order_items.init();
});
