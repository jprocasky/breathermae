(function () {
  'use strict';

  function cfg() {
    return window.ULS_CF_VIEWER || {};
  }

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&')
      .replace(/</g, '<')
      .replace(/>/g, '>')
      .replace(/"/g, '"');
  }

  function panels() {
    return document.querySelectorAll('.ulscf-viewer');
  }

  function note(el, msg) {
    var body = el.querySelector('.ulscf-viewer__body');
    if (body) body.innerHTML = '<p class="ulscf-viewer__note">' + esc(msg) + '</p>';
  }

  function render(el, data) {
    var who = el.querySelector('.ulscf-viewer__who');
    if (who) who.textContent = data.email || 'No member selected';
    var body = el.querySelector('.ulscf-viewer__body');
    if (!body) return;

    var cols = data.columns || [];
    var latest = data.latest;
    var rows = data.rows || [];
    var mode = (el.getAttribute('data-mode') || 'latest');
    if (!latest && !rows.length) {
      note(el, data.empty || el.getAttribute('data-empty') || 'No record for this member.');
      return;
    }

    var html = '';
    if (mode !== 'history' && latest) {
      html += '<dl class="ulscf-viewer__dl">';
      cols.forEach(function (col) {
        var val = latest[col.key];
        if (val == null || val === '') val = '—';
        html += '<dt>' + esc(col.label) + '</dt><dd>' + esc(val).replace(/\n/g, '<br>') + '</dd>';
      });
      html += '</dl>';
    }
    if (mode !== 'latest' && rows.length) {
      html += '<div class="ulscf-viewer__scroll"><table class="ulscf-viewer__table"><thead><tr>';
      cols.forEach(function (col) { html += '<th>' + esc(col.label) + '</th>'; });
      html += '</tr></thead><tbody>';
      rows.forEach(function (row, i) {
        html += '<tr data-idx="' + i + '"' + (i === 0 ? ' class="is-current"' : '') + '>';
        cols.forEach(function (col) {
          var val = row[col.key];
          html += '<td>' + esc(val == null || val === '' ? '—' : val) + '</td>';
        });
        html += '</tr>';
      });
      html += '</tbody></table></div>';
    }
    body.innerHTML = html;
    el._ulscfRows = rows;
    el._ulscfCols = cols;
  }

  function load(el, email, userId) {
    var c = cfg();
    if (!c.ajaxurl) return;
    if (whoPending(el)) note(el, 'Loading record…');
    var body = new URLSearchParams();
    body.set('action', c.action || 'ulscf_viewer_record');
    body.set('nonce', c.nonce || '');
    body.set('form', el.getAttribute('data-form') || '');
    body.set('mode', el.getAttribute('data-mode') || 'latest');
    body.set('fields', el.getAttribute('data-fields') || '');
    body.set('hide', el.getAttribute('data-hide') || '');
    body.set('rows', el.getAttribute('data-rows') || '10');
    body.set('labels', el.getAttribute('data-labels') || '');
    body.set('email', email || '');
    body.set('user_id', userId || '');

    fetch(c.ajaxurl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: body.toString()
    }).then(function (r) { return r.json(); }).then(function (resp) {
      if (!resp || !resp.success) {
        note(el, (resp && resp.data && resp.data.message) || 'Could not load this record.');
        return;
      }
      render(el, resp.data || {});
    }).catch(function () {
      note(el, 'Could not load this record.');
    });
  }

  function whoPending(el) {
    return true;
  }

  function loadAll(email, userId) {
    panels().forEach(function (el) {
      if (email) el.setAttribute('data-email', email);
      if (userId) el.setAttribute('data-user-id', userId);
      load(el, email || el.getAttribute('data-email') || '', userId || el.getAttribute('data-user-id') || '');
    });
  }

  document.addEventListener('uls:selected-member', function (ev) {
    var d = ev.detail || {};
    loadAll(d.email || '', d.user_id || '');
  });

  document.addEventListener('click', function (ev) {
    var tr = ev.target && ev.target.closest ? ev.target.closest('.ulscf-viewer__table tbody tr') : null;
    if (!tr) return;
    var panel = tr.closest('.ulscf-viewer');
    if (!panel || panel.getAttribute('data-mode') === 'history') return;
    var idx = parseInt(tr.getAttribute('data-idx'), 10);
    var rows = panel._ulscfRows || [];
    var cols = panel._ulscfCols || [];
    if (!rows[idx]) return;
    panel.querySelectorAll('.ulscf-viewer__table tbody tr').forEach(function (r) { r.classList.remove('is-current'); });
    tr.classList.add('is-current');
    var dl = panel.querySelector('.ulscf-viewer__dl');
    if (!dl) return;
    var html = '';
    cols.forEach(function (col) {
      var val = rows[idx][col.key];
      if (val == null || val === '') val = '—';
      html += '<dt>' + esc(col.label) + '</dt><dd>' + esc(val).replace(/\n/g, '<br>') + '</dd>';
    });
    dl.innerHTML = html;
  });

  function boot() {
    panels().forEach(function (el) {
      var email = el.getAttribute('data-email') || '';
      if (email) load(el, email, el.getAttribute('data-user-id') || '');
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
