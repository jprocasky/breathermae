/**
 * ULS Members – client-side replacement (v3.2)
 * - Hierarchy: children initially hidden; toggle via first-column icon
 * - Paging operates on parent rows only (children stay grouped under parents)
 * - Robust child lookup by data-parent-id + data-user-id
 * - Key Essentials latest values fill <span class="uls-member-field" data-src="keys">
 * Adds a Price column for orders using resp.data[vw_wc_orders_full][i].line_total (text).
 * If the server already formats with wc_price, we output as-is; otherwise, we try to format as currency.
 */
(function ($, W) {
  'use strict';

  // Elementor guard (optional)
  try { if (window.elementorFrontend && elementorFrontend.isEditMode && elementorFrontend.isEditMode()) { console.info('[uls-members] Elementor edit mode; live handlers enabled but you can disable by returning early.'); } } catch (e) {}

  console.info('[uls-members] replacement JS v3.2 loaded (hierarchy + keys spans)');
  if (!W || !W.ajaxurl) console.error('[uls-members] ULS_MEMBERS missing ajaxurl.', W);

  var SEL = {
    rows: '.uls-members__row, tbody tr[data-email]',
    pagerPrev: '.uls-pager__prev',
    pagerNext: '.uls-pager__next',
    pagerCur:  '.uls-pager__current',
    pagerTot:  '.uls-pager__total',
    ordersTarget: '.uls-orders-target',
    keysTarget:   '.uls-keys-target'
  };

  function escHtml(s) { return $('<div>').text(String(s == null ? '' : s)).html(); }

  function parseCurrencyTextToNumber(str){
    // Accepts text like "$1,234.56", "1 234,56", "1234.56", etc. Returns Number or NaN.
    if (str == null) return NaN;
    var s = String(str).trim();
    if (!s) return NaN;
    // If it's already a plain number string
    if (/^[-+]?\d+(?:[\.,]\d+)?$/.test(s)) {
      // normalize comma decimal to dot
      if (/,\d{1,2}$/.test(s) && s.indexOf('.') === -1) s = s.replace(',', '.');
      return parseFloat(s.replace(/,/g,'').replace(/\s+/g,''));
    }
    // Remove currency symbols and spaces, keep digits, dot, comma, minus
    s = s.replace(/[^0-9,.-]/g, '');
    // Heuristic: if both comma and dot present, assume comma thousands, dot decimal
    if (s.indexOf(',') > -1 && s.indexOf('.') > -1) {
      // remove thousands commas
      s = s.replace(/,/g,'');
    } else if (s.indexOf(',') > -1 && s.indexOf('.') === -1) {
      // only comma present, treat as decimal
      s = s.replace(',', '.');
    }
    return parseFloat(s);
  }
  function initEmptyMemberFields() {
      $('.uls-member-field').each(function () {
          var $el = $(this);
          var txt = ($el.text() || '').trim();
          if (!txt) {
              var emptyText = ($el.data('empty') || '').toString();
              if (emptyText) {
                  $el
                    .text(emptyText)
                    .addClass('uls-empty');
              }
          }
      });
  }

  function formatCurrency(value, currency){
    try {
      if (value === '' || value === null || typeof value === 'undefined') return '';
      // If server already passed an HTML-formatted currency string (e.g., wc_price)
      if (/(?:\$|€|£|¥|₩|₹|฿|₱|₦|₽|R\$|C\$|A\$)/.test(String(value))) return String(value);
      var num = parseCurrencyTextToNumber(value);
      if (!isFinite(num)) return String(value);
      return new Intl.NumberFormat(undefined, { style: 'currency', currency: currency || 'USD' }).format(num);
    } catch (e) {
      return String(value);
    }
  }


  function pctClass(v, low, high) {
    var n = Number(String(v).toString().replace('%','')); if (isNaN(n)) return '';
    if (n < low) return 'is-low';
    if (n > high) return 'is-high';
    return 'is-mid';
  }

  // ---- RENDER: ORDERS (with Price column + Total footer) ----
  function renderOrdersTable(list){
    var $box = $(SEL.ordersTarget);
    if (!$box.length) return;

    var totalNum = 0; // numeric accumulator (unformatted)

    var html = '<table class="uls-members__table uls-orders__table" cellspacing="0" cellpadding="0">';
    html += '<thead><tr>' +
            '<th>Order Date</th>' +
            '<th>Product ID</th>' +
            '<th>Product Name</th>' +
            '<th>Price</th>' +
            '</tr></thead><tbody>';

    if (Array.isArray(list) && list.length) {
      list.forEach(function (r) {
        var od    = r.order_date   || '';
        var pid   = r.product_id   || '';
        var pname = r.product_name || '';
        var price = r.line_total;    // text (server may already format)
        // 1) Try to parse to number for the running total
        var asNumber = parseCurrencyTextToNumber(price);
        if (isFinite(asNumber)) totalNum += asNumber;
        // 2) Format for display (pass-through if server already formatted)
        var priceOut = formatCurrency(price, (W && W.currency) || 'USD');

        html += '<tr>' +
                '<td>' + escHtml(od)      + '</td>' +
                '<td>' + escHtml(pid)     + '</td>' +
                '<td>' + escHtml(pname)   + '</td>' +
                '<td>' + escHtml(priceOut)+ '</td>' +
                '</tr>';
      });
    } else {
      html += '<tr><td colspan="4">No orders found for this member.</td></tr>';
    }

    html += '</tbody>';

    // Add a tfoot with the grand total (only when we had rows)
    if (Array.isArray(list) && list.length) {
      var grand = formatCurrency(totalNum, (W && W.currency) || 'USD');
      html += '<tfoot><tr>' +
              '<th colspan="3" style="text-align:right">Total</th>' +
              '<th>' + escHtml(grand) + '</th>' +
              '</tr></tfoot>';

      // Optional: also publish total to a standalone target if present
      $('.uls-orders-total-target').text(grand);
    }

    html += '</table>';
    $box.html(html);
  }

    // ---- TAG EDITOR (double-click on All Tags) ----
    function initTagEditor() {
        $(document).off('dblclick.ulsTags', 'td[data-col="all_tags"]')
                .on('dblclick.ulsTags', 'td[data-col="all_tags"]', function(e) {
            e.stopImmediatePropagation();

            var $td     = $(this);
            var userId  = parseInt($td.data('user-id'), 10);
            var current = $td.text().trim();

            if (!userId) {
                alert('Cannot edit – missing user ID.');
                return;
            }

            var input = prompt('Edit tags (comma-separated):', current);
            if (input === null) return; // cancelled

            console.log('[uls] Saving tags for user', userId, '→', input);

            jQuery.post(ULS_MEMBERS.ajaxurl, {
                action: 'uls_update_user_tags',
                user_id: userId,
                tags: input,
                nonce: ULS_MEMBERS.nonce   // note: the localized object uses 'nonce'
            }, function(resp) {
                if (resp && resp.success) {
                    alert('Tags updated successfully!');
                    location.reload();        // simple full refresh for now
                } else {
                    alert('Update failed: ' + (resp?.data?.message || 'Unknown error'));
                }
            }).fail(function() {
                alert('AJAX error updating tags.');
            });
        });
    }


// ---- RENDER: Results Link (with heavy debugging) ----
function updateScopedResultsLink(memberId) {
    console.log('[uls] updateScopedResultsLink called with memberId:', memberId);

    document.querySelectorAll('.uls-view-selected-member-link').forEach(el => {
        const page = el.dataset.page;
        if (!page) {
            console.warn('[uls] link missing data-page attribute');
            return;
        }

        // Always attach memberId to the element itself
        el.dataset.memberId = memberId || '';

        el.onclick = function (e) {
            e.preventDefault();
            e.stopPropagation();

            const mid = parseInt(this.dataset.memberId || memberId || 0, 10);
            console.log('[uls] span clicked → memberId:', mid, 'page:', page);

            if (!mid || mid <= 0) {
                console.error('[uls] ❌ No valid member_id for impersonation');
                alert('Cannot generate link – missing member ID for this user. Check console.');
                return;
            }

            jQuery.post(
                ULS_MEMBERS.ajaxurl,
                {
                    action: 'uls_get_scoped_impersonation_url',
                    member_id: mid,
                    page: page,
                    _ajax_nonce: ULS_MEMBERS.nonce
                },
                function (resp) {
                    console.log('[uls] ✅ impersonation AJAX response:', resp);
                    if (resp && resp.success && resp.data && resp.data.url) {
                        console.log('[uls] Redirecting to:', resp.data.url);
                        window.location.assign(resp.data.url);
                    } else {
                        console.error('[uls] ❌ Bad response shape:', resp);
                        alert('Failed to generate view link. See console for details.');
                    }
                },
                'json'
            ).fail(function (xhr, status, error) {
                console.error('[uls] ❌ AJAX FAILED:', status, error, xhr.responseText);
                alert('Error generating impersonation link. See console.');
            });
        };
    });
}

  function formatScore15(v) {
    var n = parseFloat(v);
    if (!Number.isFinite(n)) return '';
    return (Math.round(n * 10) / 10).toFixed(1);
  }

  function formatMemberValue(val, fmt, src) {
    if (val == null || val === '') return '';
    fmt = (fmt || '').toString().trim().toLowerCase();
    if (!fmt || fmt === 'raw' || fmt === 'text') return String(val).trim();

    var num = parseFloat(val);
    var isNum = Number.isFinite(num);

    if (fmt === 'percent-int' || fmt === 'percent' || fmt === 'pct') {
      if (!isNum) return String(val).trim();
      // BSI stored 0–1; Key Essentials stored 1–5; already-percent left as-is.
      if ((src === 'bsi' || src === 'rsi') && num > 0 && num <= 1) num = num * 100;
      else if ((src === 'keys' || src === 'key_essentials' || src === 'uls_key_essentials') && num > 0 && num <= 5) num = (num / 5) * 100;
      return String(Math.round(num));
    }
    if (fmt === 'percent-1') {
      if (!isNum) return String(val).trim();
      if ((src === 'bsi' || src === 'rsi') && num > 0 && num <= 1) num = num * 100;
      else if ((src === 'keys' || src === 'key_essentials' || src === 'uls_key_essentials') && num > 0 && num <= 5) num = (num / 5) * 100;
      return (Math.round(num * 10) / 10).toFixed(1);
    }
    if (fmt === 'score' || fmt === 'number-1') {
      if (!isNum) return String(val).trim();
      return formatScore15(num);
    }
    if (fmt === 'number-2') {
      if (!isNum) return String(val).trim();
      return (Math.round(num * 100) / 100).toFixed(2);
    }
    return String(val).trim();
  }

  function isKeysSrc(src) {
    return src === 'keys' || src === 'key_essentials' || src === 'uls_key_essentials';
  }

  function renderKeysTable(list){
    var $box = $(SEL.keysTarget); if (!$box.length) return;
    var html = '<table class="uls-members__table uls-keys__table" cellspacing="0" cellpadding="0">';
    html += '<thead><tr>' +
            '<th>Datetime</th>' +
            '<th>Form</th>' +
            '<th>Score (1–5)</th>' +
            '</tr></thead><tbody>';
    if (Array.isArray(list) && list.length){
      list.forEach(function(r){
        var avg = r.average_score != null ? r.average_score : (r['Average Score'] || r.Average_Score || '');
        var label = r.form_label || r['Form Label'] || r.Form_Id || r.form_id || '';
        var dt = r.datetime || r.Datetime || '';
        var cls = pctClass((parseFloat(avg) / 5) * 100, 50, 90);
        html += '<tr>' +
                '<td>' + escHtml(dt) + '</td>' +
                '<td>' + escHtml(label) + '</td>' +
                '<td><span class="pct ' + cls + '">' + escHtml(formatScore15(avg) || avg) + '</span></td>' +
                '</tr>';
      });
    } else {
      html += '<tr><td colspan="3">No key essentials found for this member.</td></tr>';
    }
    html += '</tbody></table>';
    $box.html(html);
  }

  function renderBSI(latest) {
    var $box = $('.uls-bsi-target'); if (!$box.length) return;
    if (!latest || typeof latest !== 'object' || !Object.keys(latest).length) { $box.html('<p>No BSI results found.</p>'); return; }
    var html = ['<div class="uls-bsi"><h4>Latest BSI</h4><dl>'];
    Object.keys(latest).forEach(function (k) { html.push('<dt>' + escHtml(k) + '</dt><dd>' + escHtml(latest[k]) + '</dd>'); });
    html.push('</dl></div>');
    $box.html(html.join(''));
  }

  function colorizeMemberFields(){
    $('.uls-member-field').each(function(){
      var $el = $(this);
      var src = ($el.data('src') || '').toString();
      if (src !== 'uls_wptm_tbl_4' && src !== '') return; // only wptm or empty
      var txt = String($el.text() || '').trim();
      var clean = txt.replace('%','').trim();
      var num = Number(clean);
      var low  = parseFloat($el.data('low'));  if (!isFinite(low))  low  = 40;
      var high = parseFloat($el.data('high')); if (!isFinite(high)) high = 80;
      var color = '#808080';
      if (isFinite(num)) { num = Math.max(0, Math.min(100, Math.round(num)));
        if (num >= high) color = '#0070c0'; else if (num < low) color = '#ff0000'; else color = '#3b7e23';
        $el.text(String(num));
      }
      $el.css({ color: color, fontWeight: 'bold' });
    });
  }

  function postDetails(email){ if (!W || !W.ajaxurl) 
    return $.Deferred().reject('no-ajaxurl'); 
    return $.post(W.ajaxurl, { action: W.detailsAction, nonce: W.nonce, email: email }); 
  }
  function persistSelection(email){ if (!W || !W.ajaxurl) return $.Deferred().reject('no-ajaxurl'); return $.post(W.ajaxurl, { action: W.setSelectedAction, nonce: W.nonce, email: email }); }

  function onRowClick(){
    var $tr = $(this);
    var email = ($tr.data('email') || '').toString().trim();
    if (!email) email = $tr.find('[data-col="email"], td:first').text().trim();
    if (!email) { console.warn('[uls-members] click ignored; no email'); return; }

    var emailNorm = email.toLowerCase();

    $(SEL.rows).each(function() {
        var rowEmail = ($(this).data('email') || '').toString().toLowerCase();
        if (rowEmail === emailNorm) {
            $(this).addClass('is-selected');
        } else {
            $(this).removeClass('is-selected');
        }
    });

    postDetails(email).done(function(resp){
      if (!resp || !resp.success || !resp.data) { console.warn('[uls-members] details missing/invalid', resp); return; }
      var dataWptm    = resp.data['uls_wptm_tbl_4']            || {};
      const dataProfile = resp.data?.uls_uls_cf_bio            || {};
      var dataOrders  = resp.data['vw_wc_orders_full']         || [];
      var dataKeys    = resp.data['uls_key_essentials']        || [];
      var dataKeysLatest = resp.data['uls_key_essentials_latest'] || {};
      var keysColors  = resp.data['uls_key_essentials_colors'] || {};
      var dataBsi     = resp.data['uls_bm_bsi_results_latest'] || null;
      var dataRsi = resp.data['uls_bm_rsi_results_latest'] || null;
      var bsiColors = resp.data['uls_bm_bsi_colors'] || {};
      var rsiColors = resp.data['uls_bm_rsi_colors'] || {};
      var dataRewards = resp.data['uls_rewards'] || {};


      $('.uls-member-field').each(function(){
        var $el = $(this), 
            src = ($el.data('src') || '').toString().trim(), 
            key = ($el.data('key') || '').toString().trim();

        function read(obj, k){ 
            if (!obj || !k) return ''; 
            if (Object.prototype.hasOwnProperty.call(obj, k)) return obj[k]; 
            var kl = k.toLowerCase(); 
            for (var p in obj){ 
                if(!Object.prototype.hasOwnProperty.call(obj,p)) continue; 
                if (p.toLowerCase() === kl) return obj[p]; 
            } 
            return ''; 
        }
        
        // Apply lookup color per field (existing)
        if (src === 'bsi' && key && bsiColors[key]) {
            $el.css('color', bsiColors[key]);
        }
        if (src === 'rsi' && key && rsiColors[key]) {
            $el.css('color', rsiColors[key]);
        }
        if (isKeysSrc(src) && key && keysColors[key]) {
            $el.css('color', keysColors[key]);
        }

        var val = '';

        if (src === 'uls_wptm_tbl_4')      val = read(dataWptm, key);
        else if (src === 'uls_ULS_CF_BIO') val = read(dataProfile, key);
        else if (src === 'bsi')            val = read(dataBsi || {}, key);
        else if (src === 'rsi')            val = read(dataRsi || {}, key);
        else if (isKeysSrc(src))           val = read(dataKeysLatest || {}, key);
        else if (src === 'uls_rewards')    val = read(dataRewards, key);
        else if (src === 'usermeta')       val = read(resp.data.usermeta || {}, key);
        else                               val = read(dataWptm, key);  // fallback

        // Normalize BSI/RSI percentages when no explicit data-format is set
        var fmt = ($el.data('format') || '').toString().trim();
        if (!fmt && (src === 'bsi' || src === 'rsi')) {
            var num = parseFloat(val);
            if (Number.isFinite(num)) {
                if (src === 'bsi' && num > 0 && num <= 1) num = num * 100;
                val = Math.round(num);
            }
        }
        if (!fmt && isKeysSrc(src) && key && key.indexOf('_band') === -1 && key.indexOf('datetime') === -1 && key !== 'complete' && key !== 'complete_of') {
            var kn = parseFloat(val);
            if (Number.isFinite(kn) && kn <= 5) val = formatScore15(kn);
        }
        if (fmt) {
            val = formatMemberValue(val, fmt, src);
        }

        var out = (val == null ? '' : String(val)).trim();

        if (!out) {
            var emptyText = ($el.data('empty') || '').toString();
            if (emptyText) {
                out = emptyText;
                $el.addClass('uls-empty');
            }
        } else {
            $el.removeClass('uls-empty');
        }

        $el.text(out);
      });
      

      colorizeMemberFields();
      renderOrdersTable(dataOrders); // includes Price column
      renderKeysTable(dataKeys);
      renderBSI(dataBsi);


      console.log('Selected member response:', resp);

      
      const memberId = parseInt( dataProfile.user_id || 0, 10 );

      if (memberId > 0) {
          console.log('[uls] Found memberId:', memberId);
          updateScopedResultsLink(memberId);
      } else {
          console.warn('[uls] ⚠️ No member_id for this user');
      }


      // Notify other modules (e.g., notes, tag admin) which member is selected
      try {
        var rowUserId = parseInt($tr.data('user-id'), 10) || memberId || 0;
        document.dispatchEvent(new CustomEvent('uls:selected-member', {
          detail: { email: email, user_id: rowUserId }
        }));
      } catch (e) {
        console.warn('[uls-members] dispatch uls:selected-member failed', e);
      }

    }).fail(function(xhr){ console.error('[uls-members] details AJAX failed', xhr); });

    persistSelection(email); // fire-and-forget
    //updateScopedResultsLink(resp?.data?.member_id || resp?.data?.user_id);
  }
  


  function normalizeText(s) {
      return (s || '')
          .toString()
          .toLowerCase()
          .replace(/\s+/g, ' ')
          .trim();
  }

  function initPager($wrap){
      var perPage = parseInt($wrap.data('per-page'), 10) || 10;

      var $tbody = $wrap.find('.uls-members__tbody');
      if (!$tbody.length) {
          $tbody = $wrap.find('tbody').addClass('uls-members__tbody');
      }

      // Hierarchy-aware paging: only count / page over *parent* rows.
      // Children remain in the DOM immediately after their parent and are
      // shown/hidden solely by the expand/collapse icon.
      var $allParentRows = $tbody.find('tr.uls-parent-level, tr[data-level="1"]').addClass('uls-members__paged-row');
      var $allSubRows    = $tbody.find('tr.uls-sub-level, tr[data-level="2"]');
      var $allRows       = $tbody.find('.uls-members__row, tr[data-email]');

      var $prev = $wrap.find(SEL.pagerPrev);
      var $next = $wrap.find(SEL.pagerNext);
      var $cur  = $wrap.find(SEL.pagerCur);
      var $tot  = $wrap.find(SEL.pagerTot);
      var $search = $wrap.find('.uls-members__search-input');
      var $clear = $wrap.find('.uls-members__search-clear');

      var current = 1;
      var $filtered = $allParentRows;

      function totalPages(){
          return Math.max(1, Math.ceil($filtered.length / perPage));
      }

      function render(){
          var start = (current - 1) * perPage;
          var end   = start + perPage;

          // Hide all rows first
          $allParentRows.hide();
          $allSubRows.hide();

          // Show only the current page of parents
          var $pageParents = $filtered.slice(start, end);
          $pageParents.show();

          // For any parent on this page that is currently expanded, also show its children
          $pageParents.each(function(){
              var $p = $(this);
              var isExpanded = !!$p.data('expanded') || ($p.find('.toggle-downline').text().trim() === '▲');
              if (isExpanded) {
                  var pid = $p.data('user-id');
                  if (pid) {
                      $tbody.find('tr[data-parent-id="' + pid + '"]').show();
                  }
              }
          });

          $cur.text(current);
          $tot.text(totalPages());

          $prev.prop('disabled', current <= 1);
          $next.prop('disabled', current >= totalPages());
      }

      // Paging buttons
      $prev.off('click.uls').on('click.uls', function(){
          if (current > 1) {
              current--;
              render();
          }
      });

      $next.off('click.uls').on('click.uls', function(){
          if (current < totalPages()) {
              current++;
              render();
          }
      });

      // ✅ Search binding (searches parents + their children; matching child brings parent into view)
      if ($search.length) {
        $search.off('input.uls').on('input.uls', function(){
            var term = normalizeText(this.value);

            if ($clear.length) {
                $clear.toggle(!!term);
            }

            if (!term) {
                $filtered = $allParentRows;
            } else {
                $filtered = $allParentRows.filter(function(){
                    var $p = $(this);
                    // Parent text matches?
                    if (normalizeText($p.text()).indexOf(term) !== -1) {
                        return true;
                    }
                    // Or any of its children match?
                    var pid = $p.data('user-id');
                    if (!pid) return false;
                    var childMatch = false;
                    $tbody.find('tr[data-parent-id="' + pid + '"]').each(function(){
                        if (normalizeText($(this).text()).indexOf(term) !== -1) {
                            childMatch = true;
                            return false; // break
                        }
                    });
                    return childMatch;
                });
            }

            current = 1;

            // Clear selection if the selected row is no longer in the filtered set
            $allRows.filter('.is-selected').each(function(){
                var $sel = $(this);
                // If it's a parent and not in filtered, or a child whose parent is not in filtered
                if ($sel.hasClass('uls-parent-level') || $sel.data('level') == 1) {
                    if (!$filtered.is($sel)) {
                        $sel.removeClass('is-selected');
                    }
                } else {
                    // child: keep selection only if its parent is still filtered
                    var ppid = $sel.data('parent-id');
                    var parentStillVisible = $filtered.filter(function(){
                        return $(this).data('user-id') == ppid;
                    }).length > 0;
                    if (!parentStillVisible) {
                        $sel.removeClass('is-selected');
                    }
                }
            });

            render();
        });
      }
      if ($clear.length) {
          $clear.off('click.uls').on('click.uls', function(){
              $search.val('');
              $clear.hide();
              $search.trigger('input');
              $search.focus();
          });
      }
      

      render();
}

  function bindAll(){
      initEmptyMemberFields();

      $(document).off('click.uls', SEL.rows).on('click.uls', SEL.rows, onRowClick);

      $('.uls-members').each(function(){
          initPager($(this));
      });
      
      initTagEditor();

      // Hierarchy / Drill-down support
      initHierarchy();

      // Tag admin panel
      initTagAdmin();

      // Claim unlinked member (sales portal)
      initClaimMember();
  }

  $(bindAll);


    // ==================== HIERARCHY / DRILL-DOWN ====================
    function initHierarchy() {
        // Hide all sub-level rows by default (pager render also enforces this)
        $('.uls-sub-level').hide();

        // Toggle when clicking the ▼ / ▲ icon in the first column
        $(document).off('click.hierarchy', '.toggle-downline')
                   .on('click.hierarchy', '.toggle-downline', function(e) {
            e.preventDefault();
            e.stopImmediatePropagation(); // prevent row selection

            var $icon = $(this);
            var $row  = $icon.closest('tr');
            var parentId = $row.data('user-id');
            if (!parentId) {
                console.warn('[uls-members] toggle clicked but no data-user-id on parent row');
                return;
            }

            var $tbody = $row.closest('tbody');
            var $subs  = $tbody.find('tr[data-parent-id="' + parentId + '"]');
            var isExpanded = ($icon.text().trim() === '▲') || !!$row.data('expanded');

            if (isExpanded) {
                $subs.hide();
                $icon.text('▼');
                $row.removeData('expanded');
            } else {
                $subs.show();
                $icon.text('▲');
                $row.data('expanded', true);
            }
        });

        console.info('[uls-members] Hierarchy toggle initialized (data-user-id + data-parent-id)');
    }

  // ==================== TAG ADMIN PANEL ====================
  function initTagAdmin() {
    var $panel = $('#uls-tag-admin');
    if (!$panel.length) return;

    var currentUserId = parseInt($panel.data('user-id'), 10) || 0;

    function showMessage(text, isError) {
      var $msg = $panel.find('.uls-tag-admin__message');
      $msg.removeClass('is-success is-error')
          .addClass(isError ? 'is-error' : 'is-success')
          .text(text)
          .show();
      setTimeout(function(){ $msg.fadeOut(300); }, 3500);
    }

    function setLoading(on) {
      $panel.toggleClass('is-loading', !!on);
    }

    function renderStatus(status) {
      if (!status || !status.user_id) {
        $panel.find('.uls-tag-admin__placeholder').show();
        $panel.find('.uls-tag-admin__content').hide();
        $panel.find('.uls-tag-admin__name').text('Select a member');
        $panel.find('.uls-tag-admin__email').text('');
        return;
      }

      currentUserId = status.user_id;
      $panel.data('user-id', status.user_id);
      $panel.find('.uls-tag-admin__name').text(status.display_name || '');
      $panel.find('.uls-tag-admin__email').text(status.email || '');
      $panel.find('.uls-tag-admin__placeholder').hide();
      $panel.find('.uls-tag-admin__content').show();

      // Simple toggles
      $panel.find('.uls-tag-toggle').each(function() {
        var $cb = $(this);
        var tag = $cb.data('tag');
        var info = status.simple && status.simple[tag];
        $cb.prop('disabled', false);
        $cb.prop('checked', !!(info && info.has_tag));
      });

      // Sales section
      var $sales = $panel.find('.uls-tag-admin__sales-status').empty();
      if (status.sales && status.sales.is_sales) {
        (status.sales.codes || []).forEach(function(code) {
          $sales.append(
            $('<span class="uls-tag-admin__sales-code"></span>').text(code)
          );
        });
        $sales.append(
          $('<button type="button" class="uls-tag-admin__btn uls-tag-admin__btn--danger uls-tag-remove-sales"></button>')
            .text('Remove Sales Person')
        );
      } else {
        var next = (status.sales && status.sales.next_code) || 'SA???';
        $sales.append(
          $('<span class="uls-tag-admin__sales-code"></span>').text(next)
        );
        $sales.append(
          $('<button type="button" class="uls-tag-admin__btn uls-tag-admin__btn--primary uls-tag-make-sales"></button>')
            .text('Make Sales Person')
        );
      }

      // Optionally refresh the All Tags cell in the members table
      if (status.all_tags && status.user_id) {
        var tagsText = (status.all_tags || []).slice().sort(function(a,b){
          return a.toLowerCase().localeCompare(b.toLowerCase());
        }).join(', ');
        $('td[data-col="all_tags"][data-user-id="' + status.user_id + '"]').text(tagsText);
      }
    }

    function fetchStatus(userId) {
      if (!userId) {
        renderStatus(null);
        return;
      }
      setLoading(true);
      $.post(W.ajaxurl, {
        action: 'uls_get_tag_admin_status',
        nonce: W.nonce,
        user_id: userId
      }).done(function(resp) {
        if (resp && resp.success) {
          renderStatus(resp.data);
        } else {
          showMessage((resp && resp.data && resp.data.message) || 'Failed to load tag status', true);
        }
      }).fail(function() {
        showMessage('AJAX error loading tag status', true);
      }).always(function() {
        setLoading(false);
      });
    }

    // Toggle simple tags
    $panel.off('change.ulsTag', '.uls-tag-toggle').on('change.ulsTag', '.uls-tag-toggle', function() {
      var $cb = $(this);
      var tag = $cb.data('tag');
      var wantAdd = $cb.is(':checked');
      if (!currentUserId || !tag) return;

      setLoading(true);
      $.post(W.ajaxurl, {
        action: 'uls_toggle_simple_tag',
        nonce: W.nonce,
        user_id: currentUserId,
        tag: tag,
        action_type: wantAdd ? 'add' : 'remove'
      }).done(function(resp) {
        if (resp && resp.success) {
          renderStatus(resp.data);
          showMessage((wantAdd ? 'Added' : 'Removed') + ' ' + tag);
        } else {
          // Revert checkbox
          $cb.prop('checked', !wantAdd);
          showMessage((resp && resp.data && resp.data.message) || 'Update failed', true);
        }
      }).fail(function() {
        $cb.prop('checked', !wantAdd);
        showMessage('AJAX error', true);
      }).always(function() {
        setLoading(false);
      });
    });

    // Make sales person
    $panel.off('click.ulsTag', '.uls-tag-make-sales').on('click.ulsTag', '.uls-tag-make-sales', function() {
      if (!currentUserId) return;
      if (!confirm('Assign the next sales code and INTERNAL tag to this member?')) return;

      setLoading(true);
      $.post(W.ajaxurl, {
        action: 'uls_make_sales_person',
        nonce: W.nonce,
        user_id: currentUserId
      }).done(function(resp) {
        if (resp && resp.success) {
          renderStatus(resp.data);
          showMessage('Sales person created: ' + ((resp.data.sales && resp.data.sales.codes) || []).join(', '));
        } else {
          showMessage((resp && resp.data && resp.data.message) || 'Failed to make sales person', true);
          if (resp && resp.data && resp.data.status) renderStatus(resp.data.status);
        }
      }).fail(function() {
        showMessage('AJAX error', true);
      }).always(function() {
        setLoading(false);
      });
    });

    // Remove sales person
    $panel.off('click.ulsTag', '.uls-tag-remove-sales').on('click.ulsTag', '.uls-tag-remove-sales', function() {
      if (!currentUserId) return;
      if (!confirm('Remove sales-person status (SA tag + relation row)? INTERNAL tag will be left unchanged.')) return;

      setLoading(true);
      $.post(W.ajaxurl, {
        action: 'uls_remove_sales_person',
        nonce: W.nonce,
        user_id: currentUserId
      }).done(function(resp) {
        if (resp && resp.success) {
          renderStatus(resp.data);
          showMessage('Sales person status removed');
        } else {
          showMessage((resp && resp.data && resp.data.message) || 'Failed to remove sales person', true);
        }
      }).fail(function() {
        showMessage('AJAX error', true);
      }).always(function() {
        setLoading(false);
      });
    });

    // React to member selection (from the main table).
    // Panel stays blank until an explicit row click.
    document.addEventListener('uls:selected-member', function(ev) {
      var uid = parseInt((ev.detail && ev.detail.user_id) || 0, 10);
      if (!uid) {
        // Fallback: look at the selected row
        var $sel = $('.uls-members__row.is-selected').first();
        uid = parseInt($sel.data('user-id'), 10) || 0;
      }
      if (uid) {
        fetchStatus(uid);
      }
    });

    // Do NOT auto-hydrate from a previous session's persisted selection.
    // Start blank; wait for the user to click a row.

    console.info('[uls-members] Tag admin panel initialized (blank until selection)');
  }

  // ==================== CLAIM UNLINKED MEMBER ====================
  function initClaimMember() {
    var $panel = $('#uls-claim-member');
    if (!$panel.length) return;
    if (!$panel.find('#uls-claim-q').length) return;

    var parentCode = ($panel.data('parent') || '').toString();
    var linkTag    = ($panel.data('link-tag') || '').toString();
    var timer      = null;

    function showMessage(text, isError) {
      var $msg = $panel.find('.uls-claim__message');
      $msg.removeClass('is-success is-error')
          .addClass(isError ? 'is-error' : 'is-success')
          .text(text)
          .show();
    }

    function setLoading(on) {
      $panel.toggleClass('is-loading', !!on);
      $panel.find('.uls-claim__go').prop('disabled', !!on);
    }

    function statusHtml(row) {
      if (row.linked_to_viewer) {
        return '<span class="uls-claim__status is-yours">Linked to you</span>';
      }
      if (row.can_claim) {
        return '<span class="uls-claim__status is-open">Unassigned</span>';
      }
      return '<span class="uls-claim__status">' + escHtml(row.block_reason || 'Not claimable') + '</span>';
    }

    function actionHtml(row) {
      if (row.can_claim) {
        return '<button type="button" class="uls-tag-admin__btn uls-tag-admin__btn--primary uls-claim__btn"' +
               ' data-user-id="' + String(row.user_id) + '"' +
               ' data-email="' + escHtml(row.email) + '">Claim</button>';
      }
      return '';
    }

    function renderResults(list) {
      var $box = $panel.find('.uls-claim__results');
      if (!Array.isArray(list) || !list.length) {
        $box.html('<p class="uls-claim__empty">No members matched that search.</p>');
        return;
      }
      var html = '<table class="uls-claim__table"><thead><tr>' +
                 '<th>Name</th><th>Email</th><th>Registered</th><th>Status</th><th></th>' +
                 '</tr></thead><tbody>';
      list.forEach(function(row) {
        var name = (row.display_name || ((row.first_name || '') + ' ' + (row.last_name || '')).trim() || '—');
        html += '<tr data-user-id="' + String(row.user_id) + '">' +
                '<td>' + escHtml(name) + '</td>' +
                '<td>' + escHtml(row.email || '') + '</td>' +
                '<td>' + escHtml(row.registered || '') + '</td>' +
                '<td class="uls-claim__status-cell">' + statusHtml(row) + '</td>' +
                '<td class="uls-claim__action-cell">' + actionHtml(row) + '</td>' +
                '</tr>';
      });
      html += '</tbody></table>';
      $box.html(html);
    }

    function patchRow(member) {
      if (!member || !member.user_id) return;
      var $tr = $panel.find('tr[data-user-id="' + member.user_id + '"]');
      if (!$tr.length) return;
      $tr.find('.uls-claim__status-cell').html(statusHtml(member));
      $tr.find('.uls-claim__action-cell').html(actionHtml(member));
    }

    function runSearch() {
      var q = ($panel.find('#uls-claim-q').val() || '').toString().trim();
      if (!q) {
        $panel.find('.uls-claim__results').empty();
        $panel.find('.uls-claim__message').hide();
        return;
      }
      if (q.length < 3 && q.indexOf('@') === -1) {
        showMessage('Enter at least 3 characters, or a full email address.', true);
        return;
      }
      setLoading(true);
      $panel.find('.uls-claim__message').hide();
      $.post(W.ajaxurl, {
        action: 'uls_claim_search',
        nonce: W.nonce,
        q: q
      }).done(function(resp) {
        if (resp && resp.success) {
          renderResults(resp.data && resp.data.results);
        } else {
          showMessage((resp && resp.data && resp.data.message) || 'Search failed', true);
        }
      }).fail(function() {
        showMessage('AJAX error searching members', true);
      }).always(function() {
        setLoading(false);
      });
    }

    $panel.find('#uls-claim-q').off('input.ulsClaim keydown.ulsClaim')
      .on('input.ulsClaim', function() {
        if (timer) clearTimeout(timer);
        timer = setTimeout(runSearch, 350);
      })
      .on('keydown.ulsClaim', function(e) {
        if (e.key === 'Enter') {
          e.preventDefault();
          if (timer) clearTimeout(timer);
          runSearch();
        }
      });

    $panel.off('click.ulsClaim', '.uls-claim__go').on('click.ulsClaim', '.uls-claim__go', function() {
      if (timer) clearTimeout(timer);
      runSearch();
    });

    $panel.off('click.ulsClaim', '.uls-claim__btn').on('click.ulsClaim', '.uls-claim__btn', function() {
      var $btn = $(this);
      var userId = parseInt($btn.data('user-id'), 10) || 0;
      var email  = ($btn.data('email') || '').toString();
      if (!userId) return;
      var label = email || ('user #' + userId);
      if (!confirm('Link ' + label + ' to ' + (linkTag || parentCode) + '?')) return;

      setLoading(true);
      $.post(W.ajaxurl, {
        action: 'uls_claim_member',
        nonce: W.nonce,
        user_id: userId
      }).done(function(resp) {
        if (resp && resp.success) {
          showMessage((resp.data && resp.data.message) || 'Member linked.', false);
          if (resp.data && resp.data.member) {
            patchRow(resp.data.member);
          } else {
            runSearch();
          }
        } else {
          showMessage((resp && resp.data && resp.data.message) || 'Claim failed', true);
          if (resp && resp.data && resp.data.member) {
            patchRow(resp.data.member);
          }
        }
      }).fail(function() {
        showMessage('AJAX error claiming member', true);
      }).always(function() {
        setLoading(false);
      });
    });

    console.info('[uls-members] Claim member panel initialized', parentCode, linkTag);
  }

})(jQuery, window.ULS_MEMBERS || {});
