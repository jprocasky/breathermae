/**
 * BioVoicePrint – admin status panel.
 * Listens for ULS member selection and loads that member's progress summary.
 *
 * Expects:
 *   window.bmfBioVoiceStatusAdmin = { restUrl, nonce, comparisonTarget }
 *   container: [data-bmf-biovoice-status-admin]
 *
 * Event (from uls-members):
 *   uls:selected-member  detail: { email, user_id? }
 */
(function () {
  'use strict';

  if (typeof window.bmfBioVoiceStatusAdmin === 'undefined') {
    return;
  }

  const cfg = window.bmfBioVoiceStatusAdmin;

  function escapeHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function nodesHtml(done, total, phase, activePhase) {
    let html = '';
    const n = Math.max(0, parseInt(total, 10) || 0);
    const d = Math.max(0, parseInt(done, 10) || 0);
    for (let i = 1; i <= n; i++) {
      let state = '';
      if (i <= d) {
        state = 'is-filled';
      } else if (phase === activePhase && i === d + 1) {
        state = 'is-current';
      }
      html += '<span class="bmf-bv-node ' + state + '"></span>';
    }
    return html;
  }

  function phaseClass(phase, name, complete, unlocked) {
    if (phase === name) return 'is-active';
    if (complete) return 'is-done';
    if (!unlocked) return 'is-locked';
    return '';
  }

  function renderPanel(container, data) {
    const phase = data.phase || 'baseline';
    const bl = data.baseline || {};
    const cp = data.comparison || {};
    const og = data.ongoing || {};
    const blDone = parseInt(bl.done, 10) || 0;
    const blReq = parseInt(bl.required, 10) || 3;
    const cpDone = parseInt(cp.done, 10) || 0;
    const cpTarget = parseInt(cp.target, 10) || (cfg.comparisonTarget || 6);
    const ogDone = parseInt(og.done, 10) || 0;
    const blPct = parseInt(bl.pct, 10) || 0;
    const cpPct = parseInt(cp.pct, 10) || 0;
    const blComplete = !!bl.complete;
    const cpComplete = !!cp.complete;

    const label = data.target_display
      ? data.target_display + (data.target_email ? ' (' + data.target_email + ')' : '')
      : (data.target_email || 'Selected member');

    let html = '';
    html += '<div class="bmf-bv-admin-header bmf-bv-admin-header--status">';
    html += '<span class="bmf-bv-admin-member">' + escapeHtml(label) + '</span>';
    html += '</div>';

    html += '<div class="bmf-bv-status-head">';
    html += '<div class="bmf-bv-status-kicker">BioVoicePrint</div>';
    html += '<div class="bmf-bv-status-headline">' + escapeHtml(data.headline || '') + '</div>';
    if (data.next_label) {
      html += '<div class="bmf-bv-status-next">' + escapeHtml(data.next_label) + '</div>';
    }
    html += '</div>';

    // Baseline
    html += '<div class="bmf-bv-status-phase ' + phaseClass(phase, 'baseline', blComplete, true) + '">';
    html += '<div class="bmf-bv-status-phase-label"><span>Baseline</span>';
    html += '<span class="bmf-bv-status-count">' + blDone + ' / ' + blReq + '</span></div>';
    html += '<div class="bmf-bv-status-nodes" aria-hidden="true">' + nodesHtml(blDone, blReq, 'baseline', phase) + '</div>';
    html += '<div class="bmf-bv-status-bar"><span style="width:' + blPct + '%;"></span></div>';
    html += '</div>';

    // Comparison
    html += '<div class="bmf-bv-status-phase ' + phaseClass(phase, 'comparison', cpComplete, blComplete) + '">';
    html += '<div class="bmf-bv-status-phase-label"><span>Comparison</span>';
    html += '<span class="bmf-bv-status-count">' + cpDone + ' / ' + cpTarget + '</span></div>';
    html += '<div class="bmf-bv-status-nodes" aria-hidden="true">' + nodesHtml(cpDone, cpTarget, 'comparison', phase) + '</div>';
    html += '<div class="bmf-bv-status-bar"><span style="width:' + cpPct + '%;"></span></div>';
    html += '</div>';

    // Ongoing
    html += '<div class="bmf-bv-status-phase ' + phaseClass(phase, 'ongoing', false, cpComplete) + '">';
    html += '<div class="bmf-bv-status-phase-label"><span>Ongoing</span>';
    html += '<span class="bmf-bv-status-count">' + ogDone + ' session' + (ogDone === 1 ? '' : 's') + '</span></div>';
    html += '<p class="bmf-bv-status-ongoing-note">Available after the comparison series. Count continues without a fixed target.</p>';
    html += '</div>';

    if (data.device_mismatch) {
      const n = parseInt(data.device_mismatch_n, 10) || 0;
      html += '<div class="bmf-bv-status-notice" role="status">';
      html += '<span class="bmf-bv-status-notice-icon" aria-hidden="true">◐</span>';
      html += '<span>Different device or microphone detected on ' + n + ' session group' + (n === 1 ? '' : 's') + '. Preferred, not required — consistency helps analysis.</span>';
      html += '</div>';
    }

    container.setAttribute('data-phase', phase);
    container.innerHTML = html;
  }

  function setStatus(container, text, isError) {
    container.innerHTML =
      '<p class="bmf-bv-empty' + (isError ? ' bmf-bv-empty--error' : '') + '" style="color:#94a3b8;margin:0;">' +
      escapeHtml(text) +
      '</p>';
  }

  async function loadForMember(container, detail) {
    const userId = detail && detail.user_id ? parseInt(detail.user_id, 10) : 0;
    const email = detail && detail.email ? String(detail.email).trim() : '';

    if (!userId && !email) {
      setStatus(container, 'Select a member to view BioVoicePrint status.');
      return;
    }

    setStatus(container, 'Loading status…');

    const params = new URLSearchParams();
    params.set('comparison_target', String(cfg.comparisonTarget || 6));
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

      renderPanel(container, data);
    } catch (err) {
      setStatus(container, 'Network error loading status.', true);
    }
  }

  function initPanel(container) {
    setStatus(container, 'Select a member to view BioVoicePrint status.');

    document.addEventListener('uls:selected-member', function (ev) {
      const detail = (ev && ev.detail) || {};
      loadForMember(container, detail);
    });
  }

  function boot() {
    document.querySelectorAll('[data-bmf-biovoice-status-admin]').forEach(initPanel);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
