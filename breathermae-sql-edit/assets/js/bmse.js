(function($){
    // ---------- Utilities ----------
    function htmlesc(s){ return $('<div/>').text(s).html(); }

    function renderTable(columns, rows){
        var html = '<table class="bmse-table"><thead><tr>';
        columns.forEach(function(c){ html += '<th>'+htmlesc(c)+'</th>'; });
        html += '</tr></thead><tbody>';
        if(rows && rows.length){
            rows.forEach(function(r){
                html += '<tr>';
                columns.forEach(function(c){
                    var v = (r[c]===null || typeof r[c]==='undefined') ? '' : String(r[c]);
                    html += '<td data-col="'+htmlesc(c)+'">'+htmlesc(v)+'</td>';
                });
                html += '</tr>';
            });
        } else {
            html += '<tr><td colspan="'+columns.length+'">(no rows)</td></tr>';
        }
        html += '</tbody></table>';
        return html;
    }

    function insertAtCursor(textarea, text){
        if(!textarea) return;
        var s=textarea.selectionStart, e=textarea.selectionEnd, v=textarea.value;
        textarea.value = v.substring(0,s)+text+v.substring(e);
        textarea.selectionStart = textarea.selectionEnd = s+text.length;
        textarea.focus();
    }

    // Smart table insert: blank editor → full exploratory SELECT, otherwise just the name
    function insertTableName(tableName) {
        var $ta = $('#bmse-sql');
        var ta  = $ta[0];
        if (!ta) return;

        if ($ta.val().trim() === '') {
            var sql = 'SELECT * FROM `' + tableName + '` ORDER BY ID DESC';
            $ta.val(sql);
            ta.selectionStart = ta.selectionEnd = sql.length;
            $ta.focus();
        } else {
            insertAtCursor(ta, tableName);
        }
    }

    // ---------- Toasts ----------
    function ensureToastHost(){
        var $host = $('#bmse-toast-host');
        if ($host.length === 0){
            $host = $('<div id="bmse-toast-host" aria-live="polite" aria-atomic="true"></div>');
            $('body').append($host);
        }
        return $host;
    }
    function showToast(msg, type){
        var $host = ensureToastHost();
        var $t = $('<div class="bmse-toast"></div>').addClass(type || 'ok').text(msg);
        $host.append($t);
        setTimeout(function(){ $t.addClass('show'); }, 20);
        setTimeout(function(){ $t.removeClass('show'); }, 2400);
        setTimeout(function(){ $t.remove(); }, 2900);
    }

    // ---------- Allow write helper ----------
    function ensureAllowWriteWithPrompt(message) {
        var $allow = $('#bmse-allow-write');
        if ($allow.is(':checked')) return true;
        var ok = window.confirm(message || 'Do you want to allow write queries?');
        if (ok) { $allow.prop('checked', true); }
        return ok;
    }

    // Visual hint: grey out Auto-run when writes are off
    function refreshAutoRunVisualState() {
        var writes = $('#bmse-allow-write').is(':checked');
        $('#bmse-auto-run').prop('disabled', !writes)
                           .closest('label').css('opacity', writes ? 1 : 0.6);
    }

    // ---------- Persist last SELECT for refresh ----------
    var lastSelect = null;  // { sql, params }
    function rememberLastSelect(sql, params){
        lastSelect = { sql: sql, params: params || {} };
        window.lastSelect = lastSelect; // expose for other handlers
    }
    function rerunLastSelect(){
        if (!lastSelect || !lastSelect.sql) return;
        var p = $.extend({}, lastSelect.params);
        p.action = 'bmse_sql_run';
        p.nonce  = BMSE.nonce;
        p.sql    = lastSelect.sql;

        $('#bmse-results').removeClass('error').html('<div class="bmse-status">Refreshing…</div>');
        $.post(BMSE.ajax, p, function(resp){
            if(!resp || !resp.success){
                $('#bmse-results').addClass('error').text('Refresh failed');
                return;
            }
            if (resp.data.type === 'select'){
                var columns = resp.data.columns || [];
                var rows    = resp.data.rows    || [];
                var meta    = resp.data.edit_meta || {};

                var tableHtml = renderTable(columns, rows);
                $('#bmse-results').html(
                    '<div class="bmse-status">'+(rows?rows.length:0)+' row(s) · '+resp.data.runtime_ms+'ms</div>'+
                    '<div class="bmse-hscroll-top"></div>'+
                    '<div class="bmse-scroll">'+tableHtml+'</div>'
                );

                // top scroll rail
                var $bottom = $('#bmse-results .bmse-scroll');
                var $top    = $('#bmse-results .bmse-hscroll-top');
                var phantomWidth = $bottom.get(0).scrollWidth;
                $top.html('<div style="width:'+phantomWidth+'px; height:2px"></div>');
                syncScroll($top,$bottom);

                // Hide PK alias column if present
                if(meta.hidden_pk_alias && columns.indexOf(meta.hidden_pk_alias)!==-1){
                    var idx = columns.indexOf(meta.hidden_pk_alias);
                    $('#bmse-results table thead th').eq(idx).addClass('bmse-hidden');
                    $('#bmse-results table tbody tr').each(function(){
                        $(this).children().eq(idx).addClass('bmse-hidden');
                    });
                }

                // Re-enable inline edit if eligible
                enableEditIfEligible(columns, rows, meta);
            }
        });
    }

    // ---------- Filter helpers for All WP Tables ----------
    var BMSE_ALL_TABLES = []; // cache the full list from AJAX

    function renderAllTables(filterText){
        var $all = $('#bmse-tables').empty();
        if (!BMSE_ALL_TABLES || BMSE_ALL_TABLES.length === 0){
            $all.append('<div class="bmse-row" style="opacity:.7">(no WP tables found for this prefix)</div>');
            return;
        }
        var needle = (filterText || '').toLowerCase();
        var matches = BMSE_ALL_TABLES.filter(function(t){
            return !needle || t.toLowerCase().indexOf(needle) !== -1;
        });

        if (matches.length === 0){
            $all.append('<div class="bmse-row" style="opacity:.7">(no matches)</div>');
            return;
        }
        matches.forEach(function(t){
            var $a = $('<a href="#" class="bmse-item"></a>').text(t).attr('data-name', t);
            $a.on('click', function(e){
                e.preventDefault();
                insertTableName($(this).data('name'));
            });
            $all.append($('<div class="bmse-row"></div>').append($a));
        });
    }

    function attachTablesFilterUI(){
        var $panelBody = $('#bmse-tables');

        // Build once
        if ($panelBody.prev('.bmse-table-search').length === 0) {
            var $search = $(
                '<div class="bmse-table-search">' +
                  '<div class="bmse-table-search__wrap">' +
                    '<input type="text" id="bmse-table-filter" ' +
                           'placeholder="Filter tables… (e.g., uls_)" ' +
                           'aria-label="Filter All WP Tables" />' +
                    '<button type="button" id="bmse-table-filter-clear" ' +
                            'class="bmse-table-filter-clear" ' +
                            'aria-label="Clear table filter" ' +
                            'title="Clear">×</button>' +
                  '</div>' +
                '</div>'
            );
            $panelBody.before($search);

            // Debounced input
            var debounceTimer = null;
            $('#bmse-table-filter').on('input', function(){
                var val = this.value;
                $('#bmse-table-filter-clear').toggleClass('visible', val.length > 0);
                window.clearTimeout(debounceTimer);
                debounceTimer = window.setTimeout(function(){
                    renderAllTables(val);
                }, 80);
            });

            // Clear by clicking the “×”
            $('#bmse-table-filter-clear').on('click', function(){
                var $inp = $('#bmse-table-filter');
                $inp.val('');
                $(this).removeClass('visible');
                renderAllTables('');
                $inp.focus();
            });

            // Clear by pressing Esc while focused
            $('#bmse-table-filter').on('keydown', function(e){
                if (e.key === 'Escape') {
                    $('#bmse-table-filter-clear').trigger('click');
                    e.preventDefault();
                }
            });
        }

        // Initial visibility (and ensure faint “×” appears on focus via CSS)
        var hasText = !!($('#bmse-table-filter').val() || '').length;
        $('#bmse-table-filter-clear').toggleClass('visible', hasText);
    }

    // ---------- Data panels ----------
    function loadTables(){
        $.post(BMSE.ajax, { action:'bmse_tables', nonce:BMSE.nonce }, function(resp){
            if(!resp || !resp.success){ console.warn('bmse_tables failed', resp); return; }

            // All WP Tables with filter
            BMSE_ALL_TABLES = resp.data.tables || [];
            attachTablesFilterUI();
            renderAllTables($('#bmse-table-filter').val() || '');

            // Tables Used (recent)
            var $recent = $('#bmse-recent').empty();
            var recent = resp.data.recent || [];
            if(recent.length===0){
                $recent.append('<div class="bmse-row" style="opacity:.7">(no recent tables yet)</div>');
            } else {
                recent.forEach(function(t){
                    var $a = $('<a href="#" class="bmse-item"></a>').text(t).attr('data-name', t);
                    $a.on('click', function(e){
                        e.preventDefault();
                        insertTableName($(this).data('name'));
                    });
                    $recent.append($('<div class="bmse-row"></div>').append($a));
                });
            }
        });
    }

    function loadHistory(){
        $.post(BMSE.ajax, { action:'bmse_history', nonce:BMSE.nonce }, function(resp){
            if(!resp || !resp.success){ console.warn('bmse_history failed', resp); return; }

            var $list = $('#bmse-history-list').empty();
            var items = resp.data.items || [];
            if(items.length===0){
                $list.append('<div class="bmse-row" style="opacity:.7">(no history yet)</div>');
                return;
            }
            items.forEach(function(item){
                var cls = item.is_select==1 ? 'select' : 'write';
                var title = (item.is_select==1 ? 'SELECT' : 'WRITE') + ' · ' + (item.runtime_ms||'') + 'ms';
                var $row = $('<div class="bmse-history-item '+cls+'"></div>');
                var $title = $('<div class="bmse-history-title"></div>').text(title+' · '+item.created_at);
                var $sql = $('<pre class="bmse-history-sql"></pre>').text(item.query_text);
                if(item.error_message){
                    $row.addClass('error');
                    $row.append($('<div class="bmse-history-error"></div>').text(item.error_message));
                }
                $row.append($title).append($sql);

                // Click: load SQL into editor; optionally auto-run if SELECT and toggle is on
                $row.on('click', function(){
                    var sqlText = item.query_text || '';
                    $('#bmse-sql').val(sqlText).focus();
                    var autoRunHistory = $('#bmse-auto-run-history').is(':checked');
                    var isSelect = /^\s*SELECT/i.test(sqlText);
                    if (autoRunHistory && isSelect) {
                        runQuery();
                    }
                });

                $list.append($row);
            });
        });
    }

    // ---------- Layout helpers ----------
    function syncScroll($a, $b){
        var lock=false;
        function link(src,dst){
            src.on('scroll',function(){
                if(lock) return; lock=true; dst.scrollLeft(src.scrollLeft()); lock=false;
            });
        }
        link($a,$b); link($b,$a);
    }

    // ---------- Edit mode ----------
    function enableEditIfEligible(columns, rows, meta){
        var editOn = $('#bmse-edit-mode').is(':checked');
        if (!editOn) return;

        var baseTable     = (meta && meta.base_table) ? meta.base_table : null;
        var pkCol         = (meta && meta.pk_column) ? meta.pk_column : null;
        var hiddenPkAlias = (meta && meta.hidden_pk_alias) ? meta.hidden_pk_alias : null;

        var hasVisiblePk  = !!(pkCol && columns.indexOf(pkCol) !== -1);
        var hasHiddenPk   = !!(hiddenPkAlias && columns.indexOf(hiddenPkAlias) !== -1);

        // Mark visible PK column cells
        if (hasVisiblePk) {
            var vIdx = columns.indexOf(pkCol);
            if (vIdx >= 0) {
                $('#bmse-results table thead th').eq(vIdx).addClass('bmse-pk');
                $('#bmse-results table tbody tr').each(function(){
                    $(this).children().eq(vIdx).addClass('bmse-pk');
                });
            }
        }
        // Mark hidden PK alias (already hidden elsewhere)
        if (hasHiddenPk) {
            var hIdx = columns.indexOf(hiddenPkAlias);
            if (hIdx >= 0) {
                $('#bmse-results table thead th').eq(hIdx).addClass('bmse-pk bmse-hidden');
                $('#bmse-results table tbody tr').each(function(){
                    $(this).children().eq(hIdx).addClass('bmse-pk bmse-hidden');
                });
            }
        }

        // Single-table + usable PK in the result
        var eligible = !!(meta && meta.eligible_single_table && (hasVisiblePk || hasHiddenPk));

        // Add the visual edit cue only to non-PK cells
        if (eligible) {
            $('#bmse-results td').not('.bmse-pk')
                .attr('title', BMSE.strings.editHint)
                .addClass('bmse-editable');
        }

        // Early stopper for dblclicks on PK cells
        $('#bmse-results').off('dblclick.bmse.pk')
          .on('dblclick.bmse.pk', 'td.bmse-pk', function(e){ e.stopImmediatePropagation(); });

        // Main dbl-click handler (replace any prior binding)
        $('#bmse-results').off('dblclick.bmse.edit')
          .on('dblclick.bmse.edit', 'td', function(){
            if (!$('#bmse-edit-mode').is(':checked')) return;

            var $td    = $(this);
            var oldVal = $td.text();
            var col    = $td.data('col');

            // Block on PK or when not eligible/unknown column
            if ($td.hasClass('bmse-pk') || !eligible || !col) {
                var tmpl =
                    "-- Not directly editable here.\n" +
                    "UPDATE " + (baseTable || '<table>') + "\n" +
                    "   SET " + (col || '<column>') + " = '" + String(oldVal).replace(/'/g, "''") + "'\n" +
                    " WHERE <add-condition-here>;";
                $('#bmse-sql').val(tmpl).focus();
                return;
            }

            if ($td.find('input').length) return;

            var $inp = $('<input type="text" class="bmse-inline" />').val(oldVal);
            $td.empty().append($inp);
            $inp.focus().select();

            $inp.on('keydown', function(e){
                if (e.key === 'Escape') { $td.text(oldVal); }
                if (e.key === 'Enter')  { $inp.blur(); }
            });

            $inp.on('blur', function(){
                var newVal = $inp.val();
                $td.text(newVal); // optimistic (revert on error)

                var $tr   = $td.closest('tr');
                var pk_val = null;
                var pk_col = pkCol;

                if (hasHiddenPk) {
                    var idxH = columns.indexOf(hiddenPkAlias);
                    pk_val = (idxH >= 0) ? $tr.children().eq(idxH).text() : null;
                } else if (hasVisiblePk) {
                    var idxV = columns.indexOf(pkCol);
                    pk_val = (idxV >= 0) ? $tr.children().eq(idxV).text() : null;
                }

                var formattedNew = isNaN(newVal)
                    ? "'" + String(newVal).replace(/'/g, "''") + "'"
                    : newVal;

                var formattedPk  = isNaN(pk_val)
                    ? "'" + String(pk_val).replace(/'/g, "''") + "'"
                    : pk_val;

                var updateSQL =
                    "UPDATE `" + baseTable + "`\n" +
                    "   SET `" + col + "` = " + formattedNew + "\n" +
                    " WHERE `" + (pk_col || 'id') + "` = " + formattedPk + ";";

                var autoRun    = $('#bmse-auto-run').is(':checked');
                var allowWrite = $('#bmse-allow-write').is(':checked');

                if (!autoRun) {
                    $('#bmse-sql').val(updateSQL).focus();
                    return;
                }

                if (!allowWrite) {
                    var ok = ensureAllowWriteWithPrompt('Auto-run will immediately write to the database. Allow write queries?');
                    if (!ok) {
                        $('#bmse-sql').val(updateSQL).focus();
                        return;
                    }
                    allowWrite = true;
                }

                var t0 = performance.now();
                $.post(BMSE.ajax, {
                    action: 'bmse_update_cell',
                    nonce:  BMSE.nonce,
                    table:  baseTable,
                    column: col,
                    pk_col: (pk_col || 'id'),
                    pk_val: pk_val,
                    new_val: newVal,
                    allow_write: 1
                }, function(resp){
                    var dt = Math.max(1, Math.round(performance.now() - t0));
                    if (!resp || !resp.success) {
                        var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Error';
                        showToast('Update failed: '+msg, 'err');
                        $td.text(oldVal);
                        return;
                    }
                    showToast('Update OK · '+(resp.data.affected_rows||0)+' row(s) · '+dt+'ms', 'ok');
                    rerunLastSelect();
                });
            });
        });
    }

    // ---------- Run ----------
    function runQuery(){
        if(!BMSE.enabled){ alert(BMSE.strings.disabled); return; }

        var sql=$('#bmse-sql').val();
        if(!sql.trim()) return;

        var allowWrite=$('#bmse-allow-write').is(':checked');

        $('#bmse-results').removeClass('error').html('<div class="bmse-status">Running…</div>');
        var payload = {
            action:'bmse_sql_run',
            nonce:BMSE.nonce,
            sql:sql,
            allow_write: allowWrite?1:0,
            row_limit: parseInt($('#bmse-row-limit').val(),10) || 200,
            append_limit: $('#bmse-append-limit').is(':checked')?1:0,
            edit_mode: $('#bmse-edit-mode').is(':checked')?1:0,
            auto_add_pk: $('#bmse-auto-add-pk').is(':checked')?1:0
        };
        $.post(BMSE.ajax, payload, function(resp){
            if(!resp){ $('#bmse-results').addClass('error').text('No response'); return; }
            if(!resp.success){
                var msg=(resp.data && resp.data.message)?resp.data.message:'Error';
                var ms=(resp.data && resp.data.runtime_ms)?(' · '+resp.data.runtime_ms+'ms'):'';
                $('#bmse-results').addClass('error').text(msg+ms);
                loadHistory(); loadTables();
                return;
            }

            if(resp.data.type==='select'){
                rememberLastSelect(sql, payload);

                var columns = resp.data.columns || [];
                var rows    = resp.data.rows    || [];
                var meta    = resp.data.edit_meta || {};

                var tableHtml = renderTable(columns, rows);
                $('#bmse-results').html(
                    '<div class="bmse-status">'+(rows?rows.length:0)+' row(s) · '+resp.data.runtime_ms+'ms</div>'+
                    '<div class="bmse-hscroll-top"></div>'+
                    '<div class="bmse-scroll">'+tableHtml+'</div>'
                );

                var $bottom = $('#bmse-results .bmse-scroll');
                var $top    = $('#bmse-results .bmse-hscroll-top');
                var phantomWidth = $bottom.get(0).scrollWidth;
                $top.html('<div style="width:'+phantomWidth+'px; height:2px"></div>');
                syncScroll($top,$bottom);

                if(meta.hidden_pk_alias && columns.indexOf(meta.hidden_pk_alias)!==-1){
                    var idx = columns.indexOf(meta.hidden_pk_alias);
                    $('#bmse-results table thead th').eq(idx).addClass('bmse-hidden');
                    $('#bmse-results table tbody tr').each(function(){
                        $(this).children().eq(idx).addClass('bmse-hidden');
                    });
                }

                enableEditIfEligible(columns, rows, meta);
            } else {
                $('#bmse-results').html('<div class="bmse-status">Affected rows: '+resp.data.affected_rows+' · '+resp.data.runtime_ms+'ms</div>');
            }

            loadHistory(); loadTables();
        });
    }

    // ---------- Init ----------
    $(function(){
        loadTables();
        loadHistory();

        // Inject the "Auto-run history SELECT" toggle into the controls row
        var $controls = $('.bmse-controls');
        if ($controls.length){
            var $toggle = $(
                '<label style="display:inline-flex;align-items:center;gap:6px">' +
                '<input type="checkbox" id="bmse-auto-run-history"> Auto-run history SELECT' +
                '</label>'
            );
            $controls.append($toggle);
        }

        $('#bmse-run').on('click', runQuery);
        $('#bmse-sql').on('keydown', function(e){ if((e.ctrlKey||e.metaKey) && e.key==='Enter'){ runQuery(); }});

        // Apply toolbar defaults (from BMSE.defaults)
        (function applyDefaults(){
            if (!window.BMSE || !BMSE.defaults) return;

            var map = {
                '#bmse-allow-write'     : 'allow_write',
                '#bmse-append-limit'    : 'append_limit',
                '#bmse-edit-mode'       : 'edit_mode',
                '#bmse-auto-run'        : 'auto_run',
                '#bmse-auto-add-pk'     : 'auto_add_pk',
                '#bmse-auto-run-history': 'auto_run_history'
            };
            Object.keys(map).forEach(function(sel){
                var key = map[sel];
                var val = !!BMSE.defaults[key];
                var $el = $(sel);
                if ($el.length) { $el.prop('checked', val); }
            });

            refreshAutoRunVisualState();

            if (BMSE.defaults.edit_mode) {
                $('#bmse-auto-add-pk').prop('checked', true);
                if (window.lastSelect && window.lastSelect.sql) {
                    rerunLastSelect();
                }
            }
        })();

        // Edit mode toggle: auto-enable Auto-add PK + refresh last SELECT
        $('#bmse-edit-mode').on('change', function () {
            if (this.checked) {
                $('#bmse-auto-add-pk').prop('checked', true).trigger('change');
                ensureAllowWriteWithPrompt('Edit mode can apply changes. Do you want to allow write queries now?');
                refreshAutoRunVisualState();
                if (window.lastSelect && window.lastSelect.sql) { rerunLastSelect(); }
            } else {
                // $('#bmse-auto-add-pk').prop('checked', false).trigger('change');
            }
        });

        $('#bmse-allow-write').on('change', refreshAutoRunVisualState);
        refreshAutoRunVisualState();
    });

})(jQuery);
