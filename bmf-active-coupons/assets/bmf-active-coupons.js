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

	$(document).ready(function () {
		initTable();
	});

	// Elementor frontend re-init (if shortcode is rendered dynamically)
	$(window).on('elementor/frontend/init', function () {
		if (typeof elementorFrontend !== 'undefined') {
			elementorFrontend.hooks.addAction('frontend/element_ready/shortcode.default', function () {
				setTimeout(initTable, 150);
			});
		}
	});
})(jQuery);
