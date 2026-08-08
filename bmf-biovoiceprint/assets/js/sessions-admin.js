/**
 * BioVoicePrint – admin sessions panel (ULS members).
 * Listens for ULS member selection; loads groups + recordings with paging.
 * Staff can unlock a completed group (optional clear takes).
 *
 * Expects:
 *   window.bmfBioVoiceSessionsAdmin = { restUrl, restBase, nonce, limit, groupsLimit }
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
  const sessionsPerPage = Math.max(1, parseInt(cfg.limit, 10) || 20);
  const groupsPerPage = Math.max(1, parseInt(cfg.groupsLimit, 10) || sessionsPerPage);

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

  function renderPager(kind, page, pages, total, perPage) {
    page = Math.max(1, page || 1);
    pages = Math.max(1, pages || 1);
    total = total || 0;
    if (total <= perPage && pages <= 1) {
      return '<div class="bmf-bv-pager bmf-bv-pager--' + kind + '">' +
        '<span class="bmf-bv-pager-meta">' + total + ' total</span></div>';
    }
    const prevDisabled = page <= 1 ? ' disabled' : '';
    const nextDisabled = page >= pages ? ' disabled' : '';
    return (
      '<div class="bmf-bv-pager bmf-bv-pager--' + kind + '" data-pager="' + kind + '">' +
        '<button type="button" class="bmf-bv-btn bmf-bv-btn-sm bmf-bv-pager-prev"' +
          ' data-pager-kind="' + kind + '" data-pager-dir="-1"' + prevDisabled + '>Prev</button>' +
        '<span class="bmf-bv-pager-meta">Page ' + page + ' / ' + pages +
          ' · ' + total + ' total</span>' +
        '<button type="button" class="bmf-bv-btn bmf-bv-btn-sm bmf-bv-pager-next"' +
          ' data-pager-kind="' + kind + '" data-pager-dir="1"' + nextDisabled + '>Next</button>' +
      '</div>'
    );
  }

  function renderGroups(groups, meta) {
    meta = meta || {};
    if (!groups || !groups.length) {
      return '<div class="bmf-bv-admin-groups"><p class="bmf-bv-empty">No session groups yet.</p></div>';
    }

    let html = '<div class="bmf-bv-admin-groups">';
    html += '<div class="bmf-bv-admin-groups-title">Session groups</div>';
    html += renderPager('groups', meta.page, meta.pages, meta.total, groupsPerPage);
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
      if (g.analysis_status === 'failed') {
        html += '<span class="bmf-bv-group-analysis-failed" title="Analysis failed">Analysis failed</span>';
      } else if (g.analysis_status === 'ok') {
        html += '<span class="bmf-bv-group-analysis-ok" title="Analysis ok">Analyzed</span>';
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
    html += renderPager('groups', meta.page, meta.pages, meta.total, groupsPerPage);
    html += '<p class="bmf-bv-admin-groups-hint">Unlock reopens a completed group for the member. “Clear takes” hard-deletes recordings in that group so they re-record from the start.</p>';
    html += '</div>';
    return html;
  }

  function renderSessions(sessions, meta) {
    meta = meta || {};
    if (!sessions.length) {
      return '<p class="bmf-bv-empty">No recordings for this member yet.</p>';
    }

    let html = '<div class="bmf-bv-admin-recordings">';
    html += '<div class="bmf-bv-admin-recordings-title">Recordings</div>';
    html += renderPager('sessions', meta.page, meta.pages, meta.total, sessionsPerPage);
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
    html += renderPager('sessions', meta.page, meta.pages, meta.total, sessionsPerPage);
    html += '</div>';
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
    const sessionsTotal = (sessionsData && sessionsData.total != null) ? sessionsData.total : sessions.length;
    const groupsTotal = (groupsData && groupsData.total != null) ? groupsData.total : groups.length;

    const state = container._bmfPagerState || { groupsPage: 1, sessionsPage: 1 };

    let html = '';
    html += '<div class="bmf-bv-admin-header">';
    html += '<span class="bmf-bv-admin-member">' + escapeHtml(label) + '</span>';
    html += '<span class="bmf-bv-admin-count">' + sessionsTotal + ' recording' + (sessionsTotal === 1 ? '' : 's') +
      ' · ' + groupsTotal + ' group' + (groupsTotal === 1 ? '' : 's') + '</span>';
    html += '</div>';
    html += renderGroups(groups, {
      page: groupsData && groupsData.page ? groupsData.page : state.groupsPage,
      pages: groupsData && groupsData.pages ? groupsData.pages : 1,
      total: groupsTotal
    });
    html += renderSessions(sessions, {
      page: sessionsData && sessionsData.page ? sessionsData.page : state.sessionsPage,
      pages: sessionsData && sessionsData.pages ? sessionsData.pages : 1,
      total: sessionsTotal
    });
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

  function memberParams(detail) {
    const userId = detail && detail.user_id ? parseInt(detail.user_id, 10) : 0;
    const email = detail && detail.email ? String(detail.email).trim() : '';
    const params = new URLSearchParams();
    if (userId > 0) {
      params.set('user_id', String(userId));
    } else if (email) {
      params.set('email', email);
    }
    return params;
  }

  async function loadForMember(container, detail, opts) {
    opts = opts || {};
    const paramsBase = memberParams(detail);
    if (![...paramsBase.keys()].length) {
      setStatus(container, 'Select a member to view recordings.');
      return;
    }

    const state = container._bmfPagerState || { groupsPage: 1, sessionsPage: 1 };
    if (opts.resetPages) {
      state.groupsPage = 1;
      state.sessionsPage = 1;
    }
    if (opts.groupsPage) state.groupsPage = opts.groupsPage;
    if (opts.sessionsPage) state.sessionsPage = opts.sessionsPage;
    container._bmfPagerState = state;
    container._bmfLastDetail = detail;

    setStatus(container, 'Loading recordings…');

    try {
      const gParams = new URLSearchParams(paramsBase);
      gParams.set('limit', String(groupsPerPage));
      gParams.set('page', String(state.groupsPage));

      const sParams = new URLSearchParams(paramsBase);
      sParams.set('limit', String(sessionsPerPage));
      sParams.set('page', String(state.sessionsPage));

      const sessionsUrl = cfg.restUrl + (cfg.restUrl.indexOf('?') >= 0 ? '&' : '?') + sParams.toString();
      const groupsUrl = restBase + '/admin/groups?' + gParams.toString();

      const results = await Promise.all([
        fetchJson(groupsUrl).catch(function () {
          return { groups: [], total: 0, page: 1, pages: 1 };
        }),
        fetchJson(sessionsUrl)
      ]);

      renderAll(container, results[0], results[1]);
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
    container._bmfPagerState = { groupsPage: 1, sessionsPage: 1 };

    document.addEventListener('uls:selected-member', function (ev) {
      const detail = (ev && ev.detail) || {};
      loadForMember(container, detail, { resetPages: true });
    });

    container.addEventListener('click', function (ev) {
      const pagerBtn = ev.target.closest('[data-pager-kind]');
      if (pagerBtn && container.contains(pagerBtn) && !pagerBtn.disabled) {
        ev.preventDefault();
        const kind = pagerBtn.getAttribute('data-pager-kind');
        const dir = parseInt(pagerBtn.getAttribute('data-pager-dir'), 10) || 0;
        const state = container._bmfPagerState || { groupsPage: 1, sessionsPage: 1 };
        if (kind === 'groups') {
          state.groupsPage = Math.max(1, (state.groupsPage || 1) + dir);
        } else if (kind === 'sessions') {
          state.sessionsPage = Math.max(1, (state.sessionsPage || 1) + dir);
        }
        container._bmfPagerState = state;
        if (container._bmfLastDetail) {
          loadForMember(container, container._bmfLastDetail);
        }
        return;
      }

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
