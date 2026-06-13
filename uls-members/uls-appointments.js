/*!
 * ULS Appointments Panel
 * - Multiple panels per page (list/editor/both)
 * - Works with members table selection (uls:selected-member)
 * - Self mode: <div class="uls-appts-panel" data-self="1" data-email="..."></div>
 * - data-mode: list | editor | both (default: both)
 */
(function ($, W) {
  'use strict';
  if (!W || !W.ajaxurl) { console.error('[uls-appts] missing ajaxurl'); }

  var SEL = {
    panel: '.uls-appts-panel',
    list: '.uls-appts-list',
    editor: '.uls-appts-editor',
    status: '.uls-appts-status',
    // inputs
    subject: '.uls-appt-subject',
    date: '.uls-appt-date',
    start: '.uls-appt-start',
    end: '.uls-appt-end',
    location: '.uls-appt-location',
    desc: '.uls-appt-desc',
    saveBtn: '.uls-appt-save',
    delBtn: '.uls-appt-del',
    icsBtn: '.uls-appt-ics'
  };

  function escHtml(s){ return $('<div/>').text(String(s == null ? '' : s)).html(); }

  /**
   * Build the inner markup for a panel if it doesn't already contain structure.
   * This allows you to place an empty <div class="uls-appts-panel" ...></div> anywhere,
   * and we inject the UI once.
   */
  function ensureMarkup($panel){
    if ($panel.data('uls-appts-init')) return;

    var html = [
      '  <div class="uls-appts-status"></div>',
      '  <div class="uls-appts-list"></div>',
      '  <div class="uls-appts-editor">',
      '    <div class="uls-appt-row">',
      '      <label>Subject</label>',
      '      <input type="text" class="uls-appt-subject" maxlength="200" placeholder="Subject" />',
      '    </div>',
      '    <div class="uls-appt-row uls-appt-row-inline">',
      '      <div>',
      '        <label>Date</label>',
      '        <input type="date" class="uls-appt-date" />',
      '      </div>',
      '      <div>',
      '        <label>Start</label>',
      '        <input type="time" class="uls-appt-start" step="900" />',
      '      </div>',
      '      <div>',
      '        <label>End (optional)</label>',
      '        <input type="time" class="uls-appt-end" step="900" />',
      '      </div>',
      '    </div>',
      '    <div class="uls-appt-row">',
      '      <label>Location (optional)</label>',
      '      <input type="text" class="uls-appt-location" maxlength="255" placeholder="Location" />',
      '    </div>',
      '    <div class="uls-appt-row">',
      '      <label>Description (optional)</label>',
      '      <textarea class="uls-appt-desc" rows="3" placeholder="Notes or instructions"></textarea>',
      '    </div>',
      '    <div class="uls-appt-row">',
      '      <button type="button" class="uls-appt-save">Add to Calendar</button>',
      '    </div>',
      '  </div>'
    ].join('');

    // Only inject inner UI if panel is empty (lets you customize if you want)
    if ($panel.children().length === 0) {
      $panel.append(html);
    }
    $panel.data('uls-appts-init', 1);
  }

    function enforceTimeStep($panel) {
        $panel.find('.uls-appt-start,.uls-appt-end').attr('step', '900');
    }
    // Round a "HH:MM" string to nearest 15 minutes; returns "HH:MM"
    function snapTo15(hhmm) {
        hhmm = String(hhmm || '').trim();
        if (!/^\d{2}:\d{2}$/.test(hhmm)) return hhmm;
        var parts = hhmm.split(':');
        var h = parseInt(parts[0], 10), m = parseInt(parts[1], 10);
        if (isNaN(h) || isNaN(m)) return hhmm;
        var snapped = Math.round(m / 15) * 15;
        if (snapped === 60) { h = (h + 1) % 24; snapped = 0; }
        var hs = ('0' + h).slice(-2);
        var ms = ('0' + snapped).slice(-2);
        return hs + ':' + ms;
    }

    function fmtLocal(ts) {
    if (!ts) return '';
    try {
        // ts is seconds; Date expects milliseconds
        var d = new Date(ts * 1000);
        // Use the site’s date/time style you like; this uses the viewer’s locale & tz
        return new Intl.DateTimeFormat(undefined, {
        year: 'numeric', month: 'short', day: 'numeric',
        hour: 'numeric', minute: '2-digit'
        }).format(d);
    } catch (e) {
        return '';
    }
    }

    // Attach change/blur listeners that snap values to 15-min grid
    function bindTimeSnapping($panel) {
    $panel.off('change.snap15 blur.snap15', '.uls-appt-start, .uls-appt-end')
        .on('change.snap15 blur.snap15', '.uls-appt-start, .uls-appt-end', function(){
        var v = $(this).val();
        var sv = snapTo15(v);
        if (sv !== v) $(this).val(sv).trigger('input');
        });
    }

  function setStatus($panel, msg, good){
    $panel.find(SEL.status).text(String(msg || ''))
      .css('color', good ? '#3b7e23' : '#c00');
  }

  function toggleEditor($panel, enabled){
    $panel.find(SEL.editor).find('input,textarea,button').prop('disabled', !enabled);
  }

    function fmtRange(a){
    if (a.start_ts) {
        var s = fmtLocal(a.start_ts);
        var e = a.end_ts ? fmtLocal(a.end_ts) : '';
        return e ? (s + ' – ' + e) : s;
    }
    // Fallback to server strings if ts not present
    return a.end_fmt ? (a.start_fmt + ' – ' + a.end_fmt) : a.start_fmt;
    }

  function renderList($panel, appts){
    var $list = $panel.find(SEL.list);
    if (!Array.isArray(appts) || !appts.length) {
      $list.html('<div class="uls-appts-empty">No reminders at this time.</div>');
      return;
    }
    var out = [];
    appts.forEach(function(a){
      out.push(
        '<div class="uls-appt-item" data-id="'+escHtml(a.id)+'">',
          '<div class="uls-appt-top">',
            '<div class="uls-appt-subj">'+escHtml(a.subject)+'</div>',
            '<div class="uls-appt-when">'+escHtml(fmtRange(a))+'</div>',
          '</div>',
          (a.location ? ('<div class="uls-appt-loc">'+escHtml(a.location)+'</div>') : ''),
          (a.description ? (
            '<div class="uls-appt-desc-display">'+a.description+'</div>'
          ) : ''),
          '<div class="uls-appt-actions">',
            '<a href="#" class="uls-appt-ics">Add to Calendar</a>',
            '<button type="button" class="uls-appt-del">Delete</button>',
          '</div>',
        '</div>'
      );
    });
    $list.html(out.join(''));
  }

  function buildIsoLocal(dStr, tStr){
    dStr = String(dStr||'').trim();
    tStr = String(tStr||'').trim();
    if (!dStr || !tStr) return '';
    return dStr + 'T' + tStr; // HTML datetime-local string
  }

  /**
   * Apply view mode for a panel: list | editor | both
   */
  function applyMode($panel){
    var mode = ($panel.attr('data-mode') || 'both').toLowerCase();
    var $list = $panel.find(SEL.list);
    var $editor = $panel.find(SEL.editor);
    if (mode === 'list') { $editor.hide(); $list.show(); }
    else if (mode === 'editor') { $list.hide(); $editor.show(); }
    else { $list.show(); $editor.show(); }
  }

    // Build a Date in the user's local time and return an ISO string in UTC (with 'Z')
    function toUtcIsoFromLocal(dateStr, timeStr) {
    dateStr = String(dateStr || '').trim();
    timeStr = String(timeStr || '').trim();
    if (!dateStr || !timeStr) return '';

    // Compose "YYYY-MM-DDTHH:MM" as local time and convert to UTC ISO
    var d = new Date(dateStr + 'T' + timeStr); // browser treats as local
    if (isNaN(d.getTime())) return '';
    return d.toISOString(); // UTC 'YYYY-MM-DDTHH:MM:SS.sssZ'
    }

  /**
   * Bind event handlers for a specific panel instance
   */
  function bindPanelHandlers($panel, email, appts){
    // Save (create)
    $panel.off('click.ulsSave', SEL.saveBtn).on('click.ulsSave', SEL.saveBtn, function(){
      var subject = String($panel.find(SEL.subject).val()||'').trim();
      var d = $panel.find(SEL.date).val();
      var s = $panel.find(SEL.start).val();
      var e = $panel.find(SEL.end).val();
      var location = String($panel.find(SEL.location).val()||'').trim();
      var desc = String($panel.find(SEL.desc).val()||'').trim();

      if (!email) { setStatus($panel, 'No member selected.', false); return; }
      if (!subject) { setStatus($panel, 'Please enter a subject.', false); return; }
      var start = buildIsoLocal(d, s);
      if (!start) { setStatus($panel, 'Please choose Date and Start time.', false); return; }

      toggleEditor($panel, false);
      setStatus($panel, 'Saving…');

        var startUtc = toUtcIsoFromLocal(d, s);
        var endUtc   = e ? toUtcIsoFromLocal(d, e) : '';

        $.post(W.ajaxurl, {
        action: W.saveAction,
        nonce: W.nonce,
        email: email,
        subject: subject,

        // legacy fields (still sent for compatibility)
        start: d && s ? (d + 'T' + s) : '',
        end:   d && e ? (d + 'T' + e) : '',

        // new canonical fields (preferred by server)
        start_utc: startUtc,
        end_utc:   endUtc,

        location: location,
        description: desc
        }).done(function(resp2){
        if (!resp2 || !resp2.success || !resp2.data || !resp2.data.appt){
          setStatus($panel, 'Save failed.', false);
          return;
        }
        appts.unshift(resp2.data.appt);
        renderList($panel, appts);
        // clear inputs
        $panel.find(SEL.subject).val('');
        $panel.find(SEL.location).val('');
        $panel.find(SEL.desc).val('');
        $panel.find(SEL.date).val('');
        $panel.find(SEL.start).val('');
        $panel.find(SEL.end).val('');
        setStatus($panel, 'Saved.', true);
      }).fail(function(){
        setStatus($panel, 'Save failed (network).', false);
      }).always(function(){
        toggleEditor($panel, true);
      });
    });

    // Delete
    $panel.off('click.ulsDel', SEL.delBtn).on('click.ulsDel', SEL.delBtn, function(e){
      e.preventDefault();
      var $item = $(this).closest('.uls-appt-item');
      var id = parseInt($item.attr('data-id'), 10) || 0;
      if (!id) return;
      if (!confirm('Delete this appointment?')) return;
      $.post(W.ajaxurl, { action: W.delAction, nonce: W.nonce, id: id })
        .done(function(resp3){ if (resp3 && resp3.success) { $item.remove(); } });
    });

    // ICS download
    $panel.off('click.ulsICS', SEL.icsBtn).on('click.ulsICS', SEL.icsBtn, function(e){
      e.preventDefault();
      var $item = $(this).closest('.uls-appt-item');
      var id = parseInt($item.attr('data-id'), 10) || 0;
      if (!id) return;
      var url = W.ajaxurl + '?action=' + encodeURIComponent(W.icsAction) +
                '&nonce=' + encodeURIComponent(W.nonce) +
                '&id=' + encodeURIComponent(id);
      window.location.href = url;
    });
  }

  /**
   * Load appointments into every matching panel on the page.
   * If there are no explicit panels, create a fallback one (back-compat).
   */
  function loadAppts(email){
    var $panels = $(SEL.panel);                 // all explicit containers
    if (!$panels.length) {                      // backwards compat: if none, inject one
      var $fallback = $('<div class="uls-appts-panel"></div>').appendTo('body');
      $panels = $fallback;
    }

    
    $panels.each(function(){
      var $panel = $(this);
      ensureMarkup($panel);
      enforceTimeStep($panel);
        bindTimeSnapping($panel);
      // respect canEdit
      if (!W || !W.canEdit) { $panel.find(SEL.editor).hide(); }

      // apply mode (list/editor/both)
      applyMode($panel);

      setStatus($panel, 'Loading reminders…');

      $.post(W.ajaxurl, {
          action: W.getAction,
          nonce:  W.nonce,
          email:  email,
          future: (W.future === '1' ? '1' : '0')
      })
      .done(function(resp){
        if (!resp || !resp.success || !resp.data) {
          setStatus($panel, 'Failed to load reminders', false);
          return;
        }
        var appts = resp.data.appts || [];
        renderList($panel, appts);
        bindPanelHandlers($panel, email, appts);
        setStatus($panel, '');
      }).fail(function(){
        setStatus($panel, 'Failed to load reminders (network).', false);
      });
    });
  }

  /**
   * Self-init panels (e.g., member’s own page) that declare data-self="1".
   * If data-email is present, load immediately.
   */
  function trySelfInit(){
    $(SEL.panel + '[data-self="1"]').each(function(){
      var $panel = $(this);
      ensureMarkup($panel);
      applyMode($panel);
      var email = ($panel.attr('data-email') || '').trim();
      if (email) loadAppts(email);
    });
  }

  /**
   * Listen for member selection and remember the current email
   */
  document.addEventListener('uls:selected-member', function (e) {
    var email = e && e.detail && e.detail.email ? String(e.detail.email) : '';
    if (email) {
      window.ULS_SELECTED_EMAIL = email; // cache for accordion re-open, etc.
      loadAppts(email);
    }
  });

  /**
   * If your accordion hides content at load time, refresh when it opens so
   * the just-opened editor-only panel initializes & binds.
   * The selectors below include common Elementor titles.
   */
  $(document).on('click', '.elementor-accordion-title, .elementor-tab-title, .e-accordion-item .e-accordion-title', function(){
    var email = (window.ULS_SELECTED_EMAIL || '').trim();
    if (email) {
      loadAppts(email);
    }
  });

  // DOM ready
  $(function(){
    trySelfInit();
  });

})(jQuery, window.ULS_APPTS || {});
