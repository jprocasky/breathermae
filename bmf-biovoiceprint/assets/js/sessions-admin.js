/**
 * BioVoicePrint – admin sessions panel (ULS members).
 * Listens for ULS member selection; loads groups + recordings.
 * Staff can unlock a completed group (optional clear takes).
 *
 * Expects:
 *   window.bmfBioVoiceSessionsAdmin = { restUrl, restBase, nonce, limit }
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
  const restBase = (cfg.restBase || '').replace(/\/$/, '') ||
    String(cfg.restUrl || '').replace(/\/sessions\/?$/, '');

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

  function setStatus(container, text, isError) {
    container.innerHTML =
      '<p class="bmf-bv-empty' + (isError ? ' bmf-bv-empty--error' : '') + '">' +
      escapeHtml(text) +
      '</p>';
  }

  function groupStatusLabel(g) {
    if (g.is_final || g.status === 'complete') return 'Complete / locked';
    if (g.status === 'in_progress' && g.is_current) return 'In progress (current)';
    if (g.status === 'in_progress') return 'In progress';
    return g.status || '—';
  }

  function renderGroups(groups) {
    if (!groups || !groups.length) {
      return '<div class="bmf-bv-admin-groups"><p class="bmf-bv-empty">No session groups yet.</p></div>';
    }

    let html = '<div class="bmf-bv-admin-groups">';
    html += '<div class="bmf-bv-admin-groups-title">Session groups</div>';
    html += '<ul class="bmf-bv-group-list">';

    groups.forEach(function (g) {
      const locked = g.is_final || g.status === 'complete';
      const tasks = (g.task_codes || []).join(', ') || '—';
      html += '<li class="bmf-bv-group-item' + (locked ? ' is-locked' : '') + '" data-group-id="' + g.group_id + '">';
      html += '<div class="bmf-bv-group-meta">';
      html += '<span class="bmf-bv-group-id">#' + g.group_id + '</span>';
      html += '<span class="bmf-bv-group-purpose">' + escapeHtml(g.purpose || '') + '</span>';
      html += '<span class="bmf-bv-group-status">' + escapeHtml(groupStatusLabel(g)) + '</span>';
      html += '<span class="bmf-bv-group-takes">' + (g.take_count || 0) + ' take' + (g.take_count === 1 ? '' : 's') + '</span>';
      if (g.started_at) {
        html += '<span class="bmf-bv-group-date">' + escapeHtml(g.started_at) + '</span>';
      }
      if (g.device_mismatch) {
        html += '<span class="bmf-bv-group-mismatch" title="Device mismatch">◐</span>';
      }
      html += '</div>';
      html += '<div class="bmf-bv-group-tasks">' + escapeHtml(tasks) + '</div>';
      html += '<div class="bmf-bv-group-actions">';
      html += '<button type="button" class="bmf-bv-btn bmf-bv-btn-sm bmf-bv-btn-unlock" data-unlock-group="' + g.group_id + '" data-clear="0">Unlock</button>';
      html += '<button type="button" class="bmf-bv-btn bmf-bv-btn-sm bmf-bv-btn-unlock-clear" data-unlock-group="' + g.group_id + '" data-clear="1">Unlock &amp; clear takes</button>';
      html += '</div>';
      html += '</li>';
    });

    html += '</ul>';
    html += '<p class="bmf-bv-admin-groups-hint">Unlock reopens a completed group for the member. “Clear takes” hard-deletes recordings in that group so they re-record from the start.</p>';
    html += '</div>';
    return html;
  }

  function renderSessions(sessions) {
    if (!sessions.length) {
      return '<p class="bmf-bv-empty">No recordings for this member yet.</p>';
    }

    let html = '<div class="bmf-bv-admin-recordings-title">Recordings</div>';
    html += '<ul class="bmf-bv-session-list">';
    sessions.forEach(function (s) {
      const dur = formatDuration(s.duration_sec);
      const size = formatBytes(s.file_size);
      const device = s.device_info ? String(s.device_info).split('|')[0].trim() : '';
      html += '<li class="bmf-bv-session-item" data-session-id="' + s.id + '">';
      html += '<div class="bmf-bv-session-meta">';
      html += '<span class="bmf-bv-session-date">' + escapeHtml(s.created_at || '') + '</span>';
      if (s.session_group_id) {
        html += '<span class="bmf-bv-session-group">group #' + s.session_group_id + '</span>';
      }
      if (s.task_code) {
        html += '<span class="bmf-bv-session-task">' + escapeHtml(s.task_code) + '</span>';
      }
      if (s.session_type) {
        html += '<span class="bmf-bv-session-type">' + escapeHtml(s.session_type) + '</span>';
      }
      if (s.status && s.status !== 'recorded') {
        html += '<span class="bmf-bv-session-status">' + escapeHtml(s.status) + '</span>';
      }
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
    return html;
  }

  function renderAll(container, groupsData, sessionsData) {
    const label = (sessionsData && sessionsData.target_display)
      ? sessionsData.target_display + (sessionsData.target_email ? ' (' + sessionsData.target_email + ')' : '')
      : (groupsData && groupsData.target_display)
        ? groupsData.target_display + (groupsData.target_email ? ' (' + groupsData.target_email + ')' : '')
        : (sessionsData && sessionsData.target_email) || 'Selected member';

    const sessions = (sessionsData && sessionsData.sessions) || [];
    const groups = (groupsData && groupsData.groups) || [];

    let html = '';
    html += '<div class="bmf-bv-admin-header">';
    html += '<span class="bmf-bv-admin-member">' + escapeHtml(label) + '</span>';
    html += '<span class="bmf-bv-admin-count">' + sessions.length + ' recording' + (sessions.length === 1 ? '' : 's') + '</span>';
    html += '</div>';
    html += renderGroups(groups);
    html += renderSessions(sessions);
    container.innerHTML = html;
  }

  async function fetchJson(url) {
    const res = await fetch(url, {
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
      const err = new Error(msg);
      err.status = res.status;
      err.data = data;
      throw err;
    }
    return data;
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
      const sessionsUrl = cfg.restUrl + (cfg.restUrl.indexOf('?') >= 0 ? '&' : '?') + params.toString();
      const groupsUrl = restBase + '/admin/groups?' + params.toString();

      const results = await Promise.all([
        fetchJson(groupsUrl).catch(function () { return { groups: [] }; }),
        fetchJson(sessionsUrl)
      ]);

      renderAll(container, results[0], results[1]);
      container._bmfLastDetail = detail;
    } catch (err) {
      setStatus(container, err.message || 'Network error loading recordings.', true);
    }
  }

  async function unlockGroup(container, groupId, clearTakes) {
    const mode = clearTakes
      ? 'UNLOCK and permanently DELETE all recordings in this group'
      : 'UNLOCK this group (keep existing recordings; member can use step retake)';
    const ok = window.confirm(
      mode + '?\n\nGroup #' + groupId + '\n\nThe member will see this session as in progress again.'
    );
    if (!ok) return;

    let reason = '';
    try {
      reason = window.prompt('Optional note (support reason):', '') || '';
    } catch (e) { /* ignore */ }

    const btnSelector = '[data-unlock-group="' + groupId + '"]';
    container.querySelectorAll(btnSelector).forEach(function (b) {
      b.disabled = true;
    });

    try {
      const res = await fetch(restBase + '/groups/' + groupId + '/unlock', {
        method: 'POST',
        headers: {
          'X-WP-Nonce': cfg.nonce,
          'Content-Type': 'application/json',
          'Accept': 'application/json'
        },
        credentials: 'same-origin',
        body: JSON.stringify({
          clear_takes: !!clearTakes,
          reason: reason
        })
      });
      const data = await res.json().catch(function () { return {}; });
      if (!res.ok) {
        alert((data && data.message) ? data.message : ('Unlock failed (' + res.status + ')'));
        container.querySelectorAll(btnSelector).forEach(function (b) {
          b.disabled = false;
        });
        return;
      }
      alert(data.message || 'Group unlocked.');
      if (container._bmfLastDetail) {
        loadForMember(container, container._bmfLastDetail);
      }
    } catch (err) {
      alert('Network error during unlock.');
      container.querySelectorAll(btnSelector).forEach(function (b) {
        b.disabled = false;
      });
    }
  }

  function initPanel(container) {
    setStatus(container, 'Select a member to view recordings.');

    document.addEventListener('uls:selected-member', function (ev) {
      const detail = (ev && ev.detail) || {};
      loadForMember(container, detail);
    });

    container.addEventListener('click', function (ev) {
      const btn = ev.target.closest('[data-unlock-group]');
      if (!btn || !container.contains(btn)) return;
      ev.preventDefault();
      const groupId = parseInt(btn.getAttribute('data-unlock-group'), 10);
      const clearTakes = btn.getAttribute('data-clear') === '1';
      if (groupId > 0) {
        unlockGroup(container, groupId, clearTakes);
      }
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
