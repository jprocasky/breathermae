(function () {
  var CFG = window.bmfQaExtremesCfg || {};
  var state = {
    assessment: '',
    userId: '',
    email: '',
    direction: '',
    threshold: 0.75,
    date: '',
    lastRows: null,
    lastLabel: '',
    lastMember: '',
    lastComparison: null
  };

  function panel() {
    return document.querySelector('.bmf-qa-extremes-wrap');
  }

  function els() {
    var root = panel();
    if (!root) return null;
    return {
      root: root,
      bodyEl: root.querySelector('.bmf-qa-table-wrap'),
      memberEl: root.querySelector('.bmf-qa-member-label'),
      titleEl: root.querySelector('.bmf-qa-ext-title'),
      cmpEl: root.querySelector('.bmf-qa-comparison'),
      selectWrap: root.querySelector('.bmf-qa-select-wrap'),
      selectEl: root.querySelector('.bmf-qa-date-select'),
      showScores: root.getAttribute('data-show-scores') === '1'
    };
  }

  function setExportButtons(on) {
    var root = panel();
    if (!root) return;
    root.querySelectorAll('.bmf-qa-export-csv, .bmf-qa-export-pdf').forEach(function (b) {
      b.disabled = !on;
    });
  }

  function esc(s) {
    var d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }

  function normalizeDate(v) {
    var s = (v == null ? '' : String(v)).trim();
    if (!s) return '';
    var m = s.match(/^(\d{4}-\d{2}-\d{2})/);
    return m ? m[1] : '';
  }

  function setEmpty(msg) {
    var e = els();
    if (!e || !e.bodyEl) return;
    e.bodyEl.innerHTML = '<div class="bmf-qa-empty">' + esc(msg) + '</div>';
    if (e.selectWrap) e.selectWrap.style.display = 'none';
    if (e.cmpEl) {
      e.cmpEl.style.display = 'none';
      e.cmpEl.innerHTML = '';
    }
    state.lastRows = null;
    state.lastComparison = null;
    setExportButtons(false);
  }

  function applyMember(userId, email, displayName) {
    var e = els();
    state.userId = userId ? String(userId) : '';
    state.email = email || '';
    state.lastMember = displayName || email || '';
    if (e && e.memberEl) {
      e.memberEl.textContent =
        displayName || email || (userId ? 'User #' + userId : 'Select a User');
    }
  }

  function ensureMember() {
    if (state.email) return true;
    var row = document.querySelector(
      '.uls-members__row.is-selected[data-email], tr.is-selected[data-email], .is-selected[data-email]'
    );
    if (row) {
      var em = (row.getAttribute('data-email') || '').trim();
      if (em) {
        applyMember(0, em, em);
        return true;
      }
    }
    return !!(state.userId || state.email);
  }

  function renderComparison(cmp) {
    var e = els();
    if (!e || !e.cmpEl) return;
    state.lastComparison = cmp || null;
    if (!cmp || !cmp.items || !cmp.items.length) {
      e.cmpEl.style.display = 'none';
      e.cmpEl.innerHTML = '';
      return;
    }
    var html = '<h5>Perceived rank vs actual scores</h5>';
    if (cmp.master != null) {
      html +=
        '<p class="bmf-qa-meta" style="margin:0 0 8px">Overall average: <strong>' +
        esc(cmp.master) +
        '%</strong></p>';
    }
    html += '<div class="bmf-qa-cmp-grid">';
    html +=
      '<div class="bmf-qa-meta" style="font-weight:600">Perceived</div><div></div><div class="bmf-qa-meta" style="font-weight:600;text-align:right">Actual</div>';
    cmp.items.forEach(function (it) {
      var icon = '';
      var color = '#999';
      if (it.diff === 0) {
        icon = '\u2713';
        color = '#22c55e';
      } else if (it.diff > 0) {
        icon = '\u2191 ' + Math.abs(it.diff);
        color = '#3b82f6';
      } else if (it.diff < 0) {
        icon = '\u2193 ' + Math.abs(it.diff);
        color = '#f97316';
      }
      html += '<div class="bmf-qa-cmp-cell">' + esc(it.perceived) + '</div>';
      html +=
        '<div class="bmf-qa-cmp-ind" style="color:' + color + '">' + esc(icon) + '</div>';
      html +=
        '<div class="bmf-qa-cmp-cell right">' +
        esc(it.actual) +
        (it.score != null
          ? ' <span style="color:#666">(' + esc(it.score) + '%)</span>'
          : '') +
        '</div>';
    });
    html += '</div>';
    e.cmpEl.innerHTML = html;
    e.cmpEl.style.display = 'block';
  }

  function renderRows(data) {
    var e = els();
    if (!e || !e.bodyEl) return;
    var rows = data && data.rows ? data.rows : [];
    state.lastRows = rows;
    state.lastLabel = data && data.label ? data.label : state.assessment;
    if (e.titleEl) e.titleEl.textContent = state.lastLabel + ' - Extremes';
    renderComparison(data && data.comparison ? data.comparison : null);
    if (!rows.length) {
      setEmpty('No extreme answers found for this cycle.');
      if (data && data.comparison) renderComparison(data.comparison);
      return;
    }
    var html =
      '<table class="bmf-qa-table"><thead><tr><th>Form</th><th>Section</th><th class="bmf-qa-q-num">#</th><th>Question</th><th>Answer</th>';
    if (e.showScores) html += '<th class="bmf-qa-score">Score</th>';
    html += '</tr></thead><tbody>';
    rows.forEach(function (r) {
      html +=
        '<tr class="bmf-qa-extreme"><td>' +
        esc(r.form) +
        '</td><td>' +
        esc(r.section) +
        '</td><td class="bmf-qa-q-num">' +
        esc(r.order_index) +
        '</td><td>' +
        esc(r.prompt) +
        '</td><td class="bmf-qa-answer">' +
        esc(r.answer_label || '-') +
        '</td>';
      if (e.showScores) {
        html +=
          '<td class="bmf-qa-score">' +
          (r.score != null ? esc(r.score) : '-') +
          '</td>';
      }
      html += '</tr>';
    });
    html += '</tbody></table>';
    e.bodyEl.innerHTML = html;
    setExportButtons(true);
  }

  function csvEscape(v) {
    var s = v == null ? '' : String(v);
    if (/[",\n\r]/.test(s)) return '"' + s.replace(/"/g, '""') + '"';
    return s;
  }

  function exportCsv() {
    if (!state.lastRows) return;
    var lines = [
      ['Assessment', 'Date', 'Form', 'Section', '#', 'Question', 'Answer', 'Score', 'Extreme']
        .map(csvEscape)
        .join(',')
    ];
    state.lastRows.forEach(function (r) {
      lines.push(
        [
          state.lastLabel,
          state.date,
          r.form,
          r.section,
          r.order_index,
          r.prompt,
          r.answer_label,
          r.score != null ? r.score : '',
          'Yes'
        ]
          .map(csvEscape)
          .join(',')
      );
    });
    var blob = new Blob([lines.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    var member = (state.lastMember || state.email || 'member').replace(
      /[^a-z0-9._-]+/gi,
      '_'
    );
    var assess = (state.assessment || 'assessment').replace(/[^a-z0-9._-]+/gi, '_');
    a.href = url;
    a.download = 'extremes-' + member + '-' + assess + '-' + (state.date || '') + '.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
  }

  function exportPdf() {
    if (!state.lastRows || !state.lastRows.length) {
      alert('Nothing to export yet.');
      return;
    }
    var LOGO = CFG.logo || '';
    var title = (state.lastLabel || state.assessment || 'Assessment') + ' - Extremes';
    var member = state.lastMember || state.email || '';
    var headers = ['Form', 'Section', '#', 'Question', 'Answer'];
    if (els() && els().showScores) headers.push('Score');

    var html = '<!DOCTYPE html><html><head><meta charset="utf-8">';
    html += '<title>' + esc(title) + '</title>';
    html += '<style>';
    html +=
      'body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:#1e293b;margin:24px;font-size:11px;}';
    html +=
      '.brand{display:flex;align-items:center;gap:14px;margin-bottom:16px;border-bottom:2px solid #001d50;padding-bottom:12px;}';
    html += '.brand img{max-height:48px;max-width:160px;object-fit:contain;}';
    html += '.brand h1{margin:0;font-size:16px;color:#001d50;}';
    html += '.brand .sub{margin:2px 0 0;color:#64748b;font-size:11px;}';
    html += '.meta{margin:0 0 14px;color:#475569;}';
    html += 'table{width:100%;border-collapse:collapse;margin-top:6px;}';
    html +=
      'th,td{border:1px solid #cbd5e1;padding:5px 7px;text-align:left;vertical-align:top;}';
    html += 'th{background:#6ec1e4;color:#001d50;font-weight:600;}';
    html += 'tr.extreme td{background:#fef2f2;}';
    html += 'tr.extreme td.answer{color:#991b1b;font-weight:600;}';
    html += '.note{margin-top:14px;font-size:10px;color:#64748b;}';
    html += '@media print{body{margin:12px;} .no-print{display:none!important;}}';
    html += '</style></head><body>';
    html += '<div class="brand">';
    if (LOGO) html += '<img src="' + esc(LOGO) + '" alt="BreatherMae">';
    html += '<div><h1>' + esc(title) + '</h1>';
    if (member) html += '<div class="sub">Member: ' + esc(member) + '</div>';
    html += '</div></div>';
    html += '<div class="meta">Cycle: ' + esc(state.date || '') + '</div>';
    html += '<table><thead><tr>';
    headers.forEach(function (h) {
      html += '<th>' + esc(h) + '</th>';
    });
    html += '</tr></thead><tbody>';
    state.lastRows.forEach(function (r) {
      html += '<tr class="extreme">';
      html += '<td>' + esc(r.form) + '</td>';
      html += '<td>' + esc(r.section) + '</td>';
      html += '<td>' + esc(r.order_index) + '</td>';
      html += '<td>' + esc(r.prompt) + '</td>';
      html += '<td class="answer">' + esc(r.answer_label || '-') + '</td>';
      if (els() && els().showScores) {
        html += '<td>' + (r.score != null ? esc(r.score) : '-') + '</td>';
      }
      html += '</tr>';
    });
    html += '</tbody></table>';
    html +=
      '<p class="note no-print">Use your browser print dialog and choose Save as PDF.</p>';
    html += '</body></html>';

    var w = window.open('', '_blank');
    if (!w) {
      alert('Pop-up blocked. Allow pop-ups for this site to export PDF.');
      return;
    }
    w.document.open();
    w.document.write(html);
    w.document.close();
    setTimeout(function () {
      try {
        w.focus();
        w.print();
      } catch (err) {}
    }, 450);
  }

  function loadExtremes() {
    var e = els();
    if (!e) return;
    if (!CFG.nonce || !CFG.ajax) {
      setEmpty('Extremes config missing. Hard-refresh the page.');
      return;
    }
    if (!state.assessment) {
      setEmpty('Click an assessment extremes link (BSI, RSI, or Pillars).');
      return;
    }
    if (!(state.userId || state.email)) {
      setEmpty('Select a User to view extremes.');
      return;
    }
    state.date = normalizeDate(state.date);
    if (!state.date) {
      setEmpty('No finalized cycles found for this assessment.');
      return;
    }
    e.root.classList.add('bmf-qa-loading');
    if (e.bodyEl) {
      e.bodyEl.innerHTML = '<div class="bmf-qa-empty">Loading extremes...</div>';
    }
    var fd = new FormData();
    fd.append('action', 'bmf_get_assessment_extremes');
    fd.append('nonce', CFG.nonce);
    fd.append('assessment', state.assessment);
    fd.append('date', state.date);
    fd.append('threshold', String(state.threshold));
    if (state.direction) fd.append('direction', state.direction);
    if (state.userId) fd.append('user_id', state.userId);
    if (state.email) fd.append('email', state.email);
    fetch(CFG.ajax, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) {
        return r.json();
      })
      .then(function (resp) {
        var e2 = els();
        if (e2) e2.root.classList.remove('bmf-qa-loading');
        if (!resp || !resp.success) {
          setEmpty(
            (resp && resp.data && resp.data.message) || 'Could not load extremes.'
          );
          return;
        }
        renderRows(resp.data || {});
      })
      .catch(function () {
        var e2 = els();
        if (e2) e2.root.classList.remove('bmf-qa-loading');
        setEmpty('Error loading extremes.');
      });
  }

  function loadDates(thenLoad) {
    var e = els();
    if (!e) return;
    if (!CFG.nonce || !CFG.ajax) {
      setEmpty('Extremes config missing. Hard-refresh the page.');
      return;
    }
    if (!state.assessment) {
      setEmpty('Click an assessment extremes link (BSI, RSI, or Pillars).');
      return;
    }
    if (!(state.userId || state.email)) {
      if (!ensureMember()) {
        setEmpty('Select a User to view extremes.');
        return;
      }
    }
    e.root.classList.add('bmf-qa-loading');
    var fd = new FormData();
    fd.append('action', 'bmf_list_assessment_dates');
    fd.append('nonce', CFG.nonce);
    fd.append('assessment', state.assessment);
    if (state.userId) fd.append('user_id', state.userId);
    if (state.email) fd.append('email', state.email);
    fetch(CFG.ajax, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) {
        return r.json();
      })
      .then(function (resp) {
        var e2 = els();
        if (e2) e2.root.classList.remove('bmf-qa-loading');
        if (!resp || !resp.success) {
          setEmpty(
            (resp && resp.data && resp.data.message) || 'Could not load dates.'
          );
          return;
        }
        var data = resp.data || {};
        if (data.member_label) {
          state.lastMember = data.member_label;
          if (e2 && e2.memberEl) e2.memberEl.textContent = data.member_label;
        }
        if (data.user_id) state.userId = String(data.user_id);
        if (data.label && e2 && e2.titleEl) {
          e2.titleEl.textContent = data.label + ' - Extremes';
        }
        var dates = (data.dates || []).map(normalizeDate).filter(Boolean);
        if (!e2 || !e2.selectEl) return;
        e2.selectEl.innerHTML = '';
        if (!dates.length) {
          state.date = '';
          if (e2.selectWrap) e2.selectWrap.style.display = 'none';
          setEmpty('No finalized cycles found for this member and assessment.');
          return;
        }
        dates.forEach(function (d, i) {
          var opt = document.createElement('option');
          opt.value = d;
          opt.textContent = d;
          if (i === 0) opt.selected = true;
          e2.selectEl.appendChild(opt);
        });
        if (e2.selectWrap) e2.selectWrap.style.display = 'inline-flex';
        state.date = dates[0];
        if (thenLoad !== false) loadExtremes();
      })
      .catch(function () {
        var e2 = els();
        if (e2) e2.root.classList.remove('bmf-qa-loading');
        setEmpty('Error loading dates.');
      });
  }

  function setAssessment(key, label, direction) {
    key = (key || '').toString().trim().toLowerCase();
    if (!key) return;
    state.assessment = key;
    if (direction) state.direction = direction;
    var e = els();
    if (e && e.titleEl) {
      var title = label && label.trim() ? label.trim() : key.toUpperCase();
      if (!/extremes$/i.test(title)) title = title + ' - Extremes';
      e.titleEl.textContent = title;
    }
    ensureMember();
    loadDates(true);
  }

  function scrollToExtremes() {
    var el = panel();
    if (!el) return;
    if (history.replaceState) history.replaceState(null, '', '#extremes');
    else location.hash = 'extremes';
    el.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function initFromPanel() {
    var root = panel();
    if (!root) return;
    var a = root.getAttribute('data-assessment') || '';
    var d = root.getAttribute('data-direction') || '';
    var t = root.getAttribute('data-threshold') || '0.75';
    if (a) state.assessment = a;
    if (d) state.direction = d;
    state.threshold = parseFloat(t) || 0.75;
  }

  if (!window.__bmfQaExtremesBound) {
    window.__bmfQaExtremesBound = true;

    document.addEventListener('uls:selected-member', function (ev) {
      var email =
        ev && ev.detail && ev.detail.email
          ? String(ev.detail.email).trim()
          : '';
      if (!email) return;
      applyMember(0, email, email);
      if (state.assessment) loadDates(true);
      else setEmpty('Select a User, then click an assessment extremes link.');
    });

    document.addEventListener('click', function (ev) {
      var el = ev.target.closest('[data-bmf-qa-assessment]');
      if (!el) return;
      var a = (el.getAttribute('data-bmf-qa-assessment') || '').trim();
      if (!a) return;
      ev.preventDefault();
      document
        .querySelectorAll('[data-bmf-qa-assessment].is-active')
        .forEach(function (n) {
          n.classList.remove('is-active');
        });
      el.classList.add('is-active');
      scrollToExtremes();
      var dir = (el.getAttribute('data-bmf-qa-direction') || '').trim();
      setAssessment(a, (el.textContent || '').trim(), dir || null);
    });

    document.addEventListener('change', function (ev) {
      var root = panel();
      if (!root || !ev.target) return;
      if (
        ev.target.classList &&
        ev.target.classList.contains('bmf-qa-date-select') &&
        root.contains(ev.target)
      ) {
        state.date = normalizeDate(ev.target.value);
        loadExtremes();
      }
    });

    document.addEventListener('click', function (ev) {
      var root = panel();
      if (!root) return;
      var csv = ev.target.closest('.bmf-qa-export-csv');
      if (csv && root.contains(csv)) {
        exportCsv();
        return;
      }
      var pdf = ev.target.closest('.bmf-qa-export-pdf');
      if (pdf && root.contains(pdf) && !pdf.disabled) {
        ev.preventDefault();
        exportPdf();
      }
    });
  }

  initFromPanel();
})();
