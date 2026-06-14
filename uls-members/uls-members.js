/**
 * ULS Members – client-side replacement (v3)
 * Adds a Price column for orders using resp.data[vw_wc_orders_full][i].line_total (text).
 * If the server already formats with wc_price, we output as-is; otherwise, we try to format as currency.
 */
(function ($, W) {
  'use strict';

  // Elementor guard (optional)
  try { if (window.elementorFrontend && elementorFrontend.isEditMode && elementorFrontend.isEditMode()) { console.info('[uls-members] Elementor edit mode; live handlers enabled but you can disable by returning early.'); } } catch (e) {}

  console.info('[uls-members] replacement JS v3 loaded');
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

// ---- RENDER: Results Link ----
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

  function renderKeysTable(list){
    var $box = $(SEL.keysTarget); if (!$box.length) return;
    var html = '<table class="uls-members__table uls-keys__table" cellspacing="0" cellpadding="0">';
    html += '<thead><tr>' +
            '<th>Datetime</th>' +
            '<th>Form</th>' +
            '<th>Average Score</th>' +
            '</tr></thead><tbody>';
    if (Array.isArray(list) && list.length){
      list.forEach(function(r){
        var avg = r['Average Score'] || '';
        var cls = pctClass(avg, 30, 70);
        html += '<tr>' +
                '<td>' + escHtml(r.Datetime) + '</td>' +
                '<td>' + escHtml(r['Form Label']) + '</td>' +
                '<td><span class="pct ' + cls + '" data-low="30" data-high="70">' + escHtml(avg) + '</span></td>' +
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
      var dataProfile = resp.data['uls_uls_cf_bio']            || {};
      var dataOrders  = resp.data['vw_wc_orders_full']         || [];
      var dataKeys    = resp.data['uls_key_essentials']        || [];
      var dataBsi     = resp.data['uls_bm_bsi_results_latest'] || null;
      var dataRsi = resp.data['uls_bm_rsi_results_latest'] || null;
      var bsiColors = resp.data['uls_bm_bsi_colors'] || {};
      var rsiColors = resp.data['uls_bm_rsi_colors'] || {};
      var dataRewards = resp.data['uls_rewards'] || {};
``

      $('.uls-member-field').each(function(){
        var $el = $(this), src = ($el.data('src') || '').toString(), key = ($el.data('key') || '').toString();
        function read(obj,k){ if (!obj || !k) return ''; if (Object.prototype.hasOwnProperty.call(obj,k)) return obj[k]; var kl = k.toLowerCase(); for (var p in obj){ if(!Object.prototype.hasOwnProperty.call(obj,p)) continue; if (p.toLowerCase() === kl) return obj[p]; } return ''; }
        
        // Apply lookup color per field
        if (src === 'bsi' && key && bsiColors[key]) {
            $el.css('color', bsiColors[key]);
        }
        if (src === 'rsi' && key && rsiColors[key]) {
            $el.css('color', rsiColors[key]);
        }        
        var val = '';
        if (src === 'uls_wptm_tbl_4')      val = read(dataWptm, key);
        else if (src === 'uls_ULS_CF_BIO') val = read(dataProfile, key);
        else if (src === 'bsi')            val = read(dataBsi || {}, key);
        else if (src === 'rsi')            val = read(dataRsi || {}, key);
        else if (src === 'uls_rewards')    val = read(dataRewards, key);
        else                               val = read(dataWptm, key);
          
          // --- Normalize & round percent values for BSI and RSI ---
          if (src === 'bsi' || src === 'rsi') {
              var num = parseFloat(val);
              if (Number.isFinite(num)) {
                  // Normalize BSI fractional scores (0–1 → 0–100)
                  if (src === 'bsi' && num > 0 && num <= 1) {
                      num = num * 100;
                  }

                  // Integer percent display (applies to both BSI + RSI)
                  val = Math.round(num);
              }
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

      const dataProfile = resp.data['uls_uls_cf_bio'] || {};

      const memberId = dataProfile.user_id 
                    || resp.data?.member_id 
                    || resp.data?.user_id 
                    || 0;

      if (memberId > 0) {
          console.log('[uls] Found memberId:', memberId);
          updateScopedResultsLink(memberId);
      } else {
          console.warn('[uls] ⚠️ Still no member_id for this user! WP user may not exist.');
      }


      // Notify other modules (e.g., notes) which email is selected
      try {
        document.dispatchEvent(new CustomEvent('uls:selected-member', { detail: { email: email } }));
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

      var $allRows = $tbody.find('.uls-members__row, tr[data-email]')
          .addClass('uls-members__paged-row');

      var $prev = $wrap.find(SEL.pagerPrev);
      var $next = $wrap.find(SEL.pagerNext);
      var $cur  = $wrap.find(SEL.pagerCur);
      var $tot  = $wrap.find(SEL.pagerTot);
      var $search = $wrap.find('.uls-members__search-input');
      var $clear = $wrap.find('.uls-members__search-clear');

      var current = 1;
      var $filtered = $allRows;

      function totalPages(){
          return Math.max(1, Math.ceil($filtered.length / perPage));
      }

      function render(){
          var start = (current - 1) * perPage;
          var end   = start + perPage;

          $allRows.hide();
          $filtered.slice(start, end).show();

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

      // ✅ Search binding
      if ($search.length) {
        $search.off('input.uls').on('input.uls', function(){
            var term = normalizeText(this.value);

            if ($clear.length) {
                $clear.toggle(!!term);
            }

            if (!term) {
                $filtered = $allRows;
            } else {
                $filtered = $allRows.filter(function(){
                    return normalizeText($(this).text()).indexOf(term) !== -1;
                });
            }

            current = 1;

            // Clear selection if filtered out
            $allRows.filter('.is-selected').each(function(){
                if (!$filtered.is(this)) {
                    $(this).removeClass('is-selected');
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
      initEmptyMemberFields(); // ✅ NEW

      $(document).off('click.uls', SEL.rows).on('click.uls', SEL.rows, onRowClick);

      $('.uls-members').each(function(){
          initPager($(this));
      });
  }

  $(bindAll);

})(jQuery, window.ULS_MEMBERS || {});
