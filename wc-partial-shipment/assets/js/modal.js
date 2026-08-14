(function () {
	'use strict';

	var WXPModal = {
		dialog: null,

		init: function () {
			if (this.dialog) {
				return;
			}

			var d = document.createElement('dialog');
			d.id = 'wxp-modal';
			d.className = 'wxp-modal';
			d.innerHTML =
				'<div class="wxp-modal-inner">' +
					'<div class="wxp-modal-head">' +
						'<h2 class="wxp-modal-title"></h2>' +
						'<button type="button" class="wxp-modal-close" aria-label="Close">&times;</button>' +
					'</div>' +
					'<div class="wxp-modal-body"></div>' +
					'<div class="wxp-modal-foot"></div>' +
				'</div>';

			document.body.appendChild(d);
			this.dialog = d;

			var self = this;

			d.querySelector('.wxp-modal-close').addEventListener('click', function () {
				self.close();
			});

			d.addEventListener('click', function (e) {
				if (e.target === d) {
					self.close();
				}
			});

			document.addEventListener('keydown', function (e) {
				if (e.key === 'Escape' && d.open) {
					self.close();
				}
			});
		},

		open: function (opts) {
			this.init();
			opts = opts || {};

			this.dialog.querySelector('.wxp-modal-title').textContent = opts.title || '';
			this.dialog.querySelector('.wxp-modal-body').innerHTML = opts.body || '';
			this.dialog.querySelector('.wxp-modal-foot').innerHTML = opts.foot || '';

			if (typeof opts.onOpen === 'function') {
				opts.onOpen(this.dialog);
			}

			if (! this.dialog.open) {
				this.dialog.showModal();
			}
		},

		setBody: function (html) {
			if (this.dialog) {
				this.dialog.querySelector('.wxp-modal-body').innerHTML = html;
			}
		},

		close: function () {
			if (this.dialog && this.dialog.open) {
				this.dialog.close();
			}
		}
	};

	window.WXPModal = WXPModal;
})();
