/**
 * BioVoicePrint – admin sessions panel.
 * Listens for ULS member selection and loads that member's recordings.
 *
 * Expects:
 *   window.bmfBioVoiceSessionsAdmin = { restUrl, nonce, limit }
 *   container: [data-bmf-biovoice-sessions-admin]
 *
 * Event (from uls-members):
 *   uls:selected-member  detail: { email, user_id? }
 */
(function () {
  'use strict';

  if (typeof window.bmfBioVoiceSessionsAdmin === 'undefined') {
    return;
  }

  const cfg = window.bmfBioVoiceSessionsAdmin;

  function formatBytes(n) {
    if (!n || n < 1) return '';
    if (n < 1024) return n + ' B';
    if (n < 1024 * 1024) return (n / 1024).toFixed(1) + ' KB';
    return (n / (1024 * 1024)).toFixed(1) + ' MB';
  }

  function formatDuration(sec) {
    if (sec === null || sec === undefined || sec === '') return '—';
    const n = Number(sec);
    if (isNaN(n)) return '—';
    return n.toFixed(1) + 's';
  }

  function escapeHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function renderList(container, data) {
    const sessions = (data && data.sessions) || [];
    const label = data && data.target_display
      ? data.target_display + (data.target_email ? ' (' + data.target_email + ')' : '')
      : (data && data.target_email) || 'Selected member';

    let html = '';
    html += '<div class="bmf-bv-admin-header">';
    html += '<span class="bmf-bv-admin-member">' + escapeHtml(label) + '</span>';
    html += '<span class="bmf-bv-admin-count">' + sessions.length + ' recording' + (sessions.length === 1 ? '' : 's') + '</span>';
    html += '</div>';

    if (!sessions.length) {
      html += '<p class="bmf-bv-empty">No recordings for this member yet.</p>';
      container.innerHTML = html;
      return;
    }

    html += '<ul class="bmf-bv-session-list">';
    sessions.forEach(function (s) {
      const dur = formatDuration(s.duration_sec);
      const size = formatBytes(s.file_size);
      const device = s.device_info ? String(s.device_info).split('|')[0].trim() : '';
      html += '<li class="bmf-bv-session-item" data-session-id="' + s.id + '">';
      html += '<div class="bmf-bv-session-meta">';
      html += '<span class="bmf-bv-session-date">' + escapeHtml(s.created_at || '') + '</span>';
      html += '<span class="bmf-bv-session-type">' + escapeHtml(s.session_type || '') + '</span>';
      html += '<span class="bmf-bv-session-status">' + escapeHtml(s.status || '') + '</span>';
      html += '<span class="bmf-bv-session-dur">' + escapeHtml(dur) + '</span>';
      if (size) {
        html += '<span class="bmf-bv-session-size">' + escapeHtml(size) + '</span>';
      }
      if (device) {
        html += '<span class="bmf-bv-session-device" title="' + escapeHtml(s.device_info || '') + '">' + escapeHtml(device) + '</span>';
      }
      html += '</div>';
      if (s.play_url) {
        html += '<audio controls preload="none" src="' + escapeHtml(s.play_url) + '"></audio>';
      }
      html += '</li>';
    });
    html += '</ul>';
    container.innerHTML = html;
  }

  function setStatus(container, text, isError) {
    container.innerHTML =
      '<p class="bmf-bv-empty' + (isError ? ' bmf-bv-empty--error' : '') + '">' +
      escapeHtml(text) +
      '</p>';
  }

  async function loadForMember(container, detail) {
    const userId = detail && detail.user_id ? parseInt(detail.user_id, 10) : 0;
    const email = detail && detail.email ? String(detail.email).trim() : '';

    if (!userId && !email) {
      setStatus(container, 'Select a member to view recordings.');
      return;
    }

    setStatus(container, 'Loading recordings…');

    const params = new URLSearchParams();
    params.set('limit', String(cfg.limit || 50));
    if (userId > 0) {
      params.set('user_id', String(userId));
    } else if (email) {
      params.set('email', email);
    }

    try {
      const res = await fetch(cfg.restUrl + '?' + params.toString(), {
        method: 'GET',
        headers: {
          'X-WP-Nonce': cfg.nonce,
          'Accept': 'application/json'
        },
        credentials: 'same-origin'
      });

      const data = await res.json().catch(function () { return {}; });

      if (!res.ok) {
        const msg = (data && data.message) ? data.message : ('Failed to load (' + res.status + ')');
        setStatus(container, msg, true);
        return;
      }

      renderList(container, data);
    } catch (err) {
      setStatus(container, 'Network error loading recordings.', true);
    }
  }

  function initPanel(container) {
    setStatus(container, 'Select a member to view recordings.');

    document.addEventListener('uls:selected-member', function (ev) {
      const detail = (ev && ev.detail) || {};
      loadForMember(container, detail);
    });
  }

  function boot() {
    document.querySelectorAll('[data-bmf-biovoice-sessions-admin]').forEach(initPanel);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
