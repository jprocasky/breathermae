/**
 * BreatherMae User Monitor List – AJAX search + pagination
 * Version 1.4.0
 */
(function ($) {
    'use strict';

    function getConfig($root) {
        return {
            status_tags: $root.data('status-tags') || '',
            exclude:     $root.data('exclude') || '',
            show_ip:     $root.data('show-ip') || '0',
            show_geo:    $root.data('show-geo') || '0',
            per_page:    $root.data('per-page') || 50,
            search:      $root.find('.uml-search-input').val() || '',
            paged:       1
        };
    }

    function setLoading($root, isLoading) {
        var $results = $root.find('.uml-results');
        if (isLoading) {
            $results.addClass('uml-loading');
            if (!$results.find('.uml-spinner').length) {
                $results.prepend('<div class="uml-spinner" aria-hidden="true"></div>');
            }
        } else {
            $results.removeClass('uml-loading');
            $results.find('.uml-spinner').remove();
        }
    }

    function fetchPage($root, paged) {
        var cfg = getConfig($root);
        cfg.paged = paged || 1;

        setLoading($root, true);

        $.post(bmfUml.ajaxUrl, {
            action:       bmfUml.action,
            nonce:        bmfUml.nonce,
            status_tags:  cfg.status_tags,
            exclude:      cfg.exclude,
            show_ip:      cfg.show_ip,
            show_geo:     cfg.show_geo,
            per_page:     cfg.per_page,
            search:       cfg.search,
            paged:        cfg.paged
        })
        .done(function (resp) {
            if (resp && resp.success && resp.data && resp.data.html) {
                $root.find('.uml-results').html(resp.data.html);
                // Toggle clear button
                if (cfg.search) {
                    $root.find('.uml-clear-btn').show();
                } else {
                    $root.find('.uml-clear-btn').hide();
                }
            } else {
                var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Request failed';
                $root.find('.uml-results').html('<p style="color:#dc2626;">' + msg + '</p>');
            }
        })
        .fail(function () {
            $root.find('.uml-results').html('<p style="color:#dc2626;">Network error. Please try again.</p>');
        })
        .always(function () {
            setLoading($root, false);
        });
    }

    // Export (same logic as before, kept client-side)
    function exportTableToCSV(tableId, filename) {
        var table = document.getElementById(tableId);
        if (!table) {
            alert('Table not found');
            return;
        }
        var rows = table.querySelectorAll('tr');
        var csv = [];
        for (var i = 0; i < rows.length; i++) {
            var cols = rows[i].querySelectorAll('td, th');
            var row = [];
            for (var j = 0; j < cols.length; j++) {
                var text = cols[j].innerText.trim().replace(/"/g, '""');
                if (cols[j].querySelector('.dashicons-yes')) text = '✓';
                else if (cols[j].querySelector('.dashicons-minus')) text = '☐';
                row.push('"' + text + '"');
            }
            csv.push(row.join(','));
        }
        var blob = new Blob([csv.join('\n')], { type: 'text/csv;charset=utf-8;' });
        var link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = filename;
        link.style.visibility = 'hidden';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }

    // Event delegation (works after AJAX re-renders)
    $(document).on('click', '.uml-search-btn', function () {
        var $root = $(this).closest('.breathermae-user-monitor');
        fetchPage($root, 1);
    });

    $(document).on('click', '.uml-clear-btn', function () {
        var $root = $(this).closest('.breathermae-user-monitor');
        $root.find('.uml-search-input').val('');
        fetchPage($root, 1);
    });

    $(document).on('keypress', '.uml-search-input', function (e) {
        if (e.which === 13) {
            e.preventDefault();
            var $root = $(this).closest('.breathermae-user-monitor');
            fetchPage($root, 1);
        }
    });

    $(document).on('click', '.uml-page-btn', function () {
        var $btn = $(this);
        if ($btn.prop('disabled') || $btn.hasClass('current')) return;
        var page = parseInt($btn.data('page'), 10);
        if (!page || page < 1) return;
        var $root = $btn.closest('.breathermae-user-monitor');
        fetchPage($root, page);
        // Scroll results into view (nice UX on long pages)
        $('html, body').animate({
            scrollTop: $root.offset().top - 40
        }, 200);
    });

    $(document).on('click', '.uml-export-btn', function () {
        var filename = $(this).data('filename') || 'breathermae-user-monitor.csv';
        exportTableToCSV('breathermae-user-monitor-table', filename);
    });

})(jQuery);
