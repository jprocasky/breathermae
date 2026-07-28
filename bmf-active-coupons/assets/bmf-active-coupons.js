(function ($) {
	'use strict';

	function initTable() {
		var $table = $('#bmf-active-coupons-table');
		if (!$table.length || $.fn.DataTable.isDataTable($table)) {
			return;
		}

		$table.DataTable({
			pageLength: 25,
			lengthMenu: [10, 25, 50, 100],
			order: [[0, 'asc']], // Code A→Z
			language: {
				search: 'Filter:',
				lengthMenu: 'Show _MENU_',
				info: 'Showing _START_–_END_ of _TOTAL_ coupons',
				infoEmpty: 'No coupons',
				infoFiltered: '(filtered from _MAX_ total)',
				zeroRecords: 'No matching coupons',
				paginate: {
					previous: '‹',
					next: '›'
				}
			},
			columnDefs: [
				{ orderable: true, targets: '_all' },
				{ className: 'dt-left', targets: '_all' }
			],
			dom: '<"bmf-ac-controls"lf>rtip',
			responsive: true
		});
	}

	/**
	 * Click / keyboard: copy coupon code to clipboard.
	 * Uses event delegation so it works after DataTables redraws.
	 */
	function initCopy() {
		$(document)
			.off('click.bmfAcCopy keydown.bmfAcCopy')
			.on('click.bmfAcCopy', '.bmf-ac-code', function (e) {
				e.preventDefault();
				copyCode($(this));
			})
			.on('keydown.bmfAcCopy', '.bmf-ac-code', function (e) {
				if (e.key === 'Enter' || e.key === ' ') {
					e.preventDefault();
					copyCode($(this));
				}
			});
	}

	function copyCode($el) {
		var code = $el.data('code') || $el.text().trim();
		if (!code) {
			return;
		}

		function feedback() {
			var original = $el.text();
			$el.addClass('is-copied').text('Copied!');
			$el.attr('title', 'Copied!');
			setTimeout(function () {
				$el.removeClass('is-copied').text(original);
				$el.attr('title', 'Click to copy');
			}, 1400);
		}

		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(code).then(feedback).catch(function () {
				fallbackCopy(code, feedback);
			});
		} else {
			fallbackCopy(code, feedback);
		}
	}

	function fallbackCopy(text, done) {
		var $ta = $('<textarea>').val(text).css({
			position: 'fixed',
			left: '-9999px',
			top: '0'
		}).appendTo('body');
		$ta[0].select();
		try {
			document.execCommand('copy');
			done();
		} catch (err) {
			// silent fail
		}
		$ta.remove();
	}

	$(document).ready(function () {
		initTable();
		initCopy();
	});

	// Elementor frontend re-init (if shortcode is rendered dynamically)
	$(window).on('elementor/frontend/init', function () {
		if (typeof elementorFrontend !== 'undefined') {
			elementorFrontend.hooks.addAction('frontend/element_ready/shortcode.default', function () {
				setTimeout(function () {
					initTable();
					initCopy();
				}, 150);
			});
		}
	});
})(jQuery);
