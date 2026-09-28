(function () {
	'use strict';

	var WXPModal = {
		dialog: null,
		returnTo: null,

		init: function () {
			if (this.dialog) {
				return;
			}

			var d = document.createElement('dialog');
			d.id = 'wxp-modal';
			d.className = 'wxp-modal';
			d.setAttribute('aria-labelledby', 'wxp-modal-title');
			d.innerHTML =
				'<div class="wxp-modal-inner">' +
					'<div class="wxp-modal-head">' +
						'<h2 class="wxp-modal-title" id="wxp-modal-title"></h2>' +
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

			// However the dialog closes, put focus back where it was (if that element still exists).
			d.addEventListener('close', function () {
				var el = self.returnTo;
				self.returnTo = null;
				if (el && typeof el.focus === 'function' && document.body.contains(el)) {
					el.focus();
				}
			});
		},

		/**
		 * @param {Object} opts title, body (HTML), foot (HTML), className (extra dialog class),
		 *                      onOpen(dialog) called after the content is in place.
		 */
		open: function (opts) {
			this.init();
			opts = opts || {};

			if (!this.dialog.open) {
				this.returnTo = document.activeElement;
			}
			this.dialog.className = 'wxp-modal' + (opts.className ? ' ' + opts.className : '');
			this.dialog.querySelector('.wxp-modal-title').textContent = opts.title || '';
			this.dialog.querySelector('.wxp-modal-body').innerHTML = opts.body || '';
			var foot = this.dialog.querySelector('.wxp-modal-foot');
			foot.innerHTML = opts.foot || '';
			foot.hidden = !opts.foot;

			if (!this.dialog.open) {
				this.dialog.showModal();
			}

			if (typeof opts.onOpen === 'function') {
				opts.onOpen(this.dialog);
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
