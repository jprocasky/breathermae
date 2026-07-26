/**
 * BioVoicePrint – Guided session wizard.
 * Loads/creates group → wellness → mic check → protocol steps → complete.
 */
(function () {
  'use strict';

  if (typeof window.bmfBioVoiceSession === 'undefined') {
    return;
  }

  const cfg = window.bmfBioVoiceSession;

  const WELLNESS_ITEMS = [
    { key: 'balanced', label: 'How balanced or centered do you feel right now?', low: 'Very Unbalanced', high: 'Very Balanced' },
    { key: 'clear', label: 'How mentally clear and focused do you feel at this moment?', low: 'Very Unclear', high: 'Very Clear' },
    { key: 'energized', label: 'How physically energized do you feel right now?', low: 'Very Low Energy', high: 'Very High Energy' },
    { key: 'ready', label: 'How comfortable and ready do you feel for this recording?', low: 'Not Comfortable/Ready', high: 'Very Comfortable/Ready' },
    { key: 'restored', label: 'How restored or recovered do you feel today overall?', low: 'Not Restored', high: 'Fully Restored' }
  ];

  function micKey(groupId) { return 'bmf_biovoice_mic_ok_' + groupId; }
  function hasMicPass(groupId) { try { return sessionStorage.getItem(micKey(groupId)) === '1'; } catch (e) { return false; } }
  function setMicPass(groupId) { try { sessionStorage.setItem(micKey(groupId), '1'); } catch (e) {} }

  function api(path, options) {
    const opts = options || {};
    const headers = Object.assign({ 'X-WP-Nonce': cfg.nonce }, opts.headers || {});
    return fetch(cfg.restBase + path, Object.assign({}, opts, { headers: headers, credentials: 'same-origin' }))
      .then(function (res) {
        return res.json().then(function (data) {
          if (!res.ok) {
            const msg = (data && data.message) ? data.message : ('Request failed (' + res.status + ')');
            const err = new Error(msg);
            err.status = res.status;
            err.data = data;
            throw err;
          }
          return data;
        });
      });
  }

  function escapeHtml(s) {
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
  function escapeAttr(s) { return escapeHtml(s).replace(/'/g, '&#39;'); }

  function recorderMarkup(step, groupId) {
    const task = step.task_code || '';
    const min = step.min_seconds != null ? step.min_seconds : 0;
    const max = step.max_seconds != null ? step.max_seconds : 0;
    const title = step.title ? '<div class="bmf-bv-step-title">' + escapeHtml(step.title) + '</div>' : '';
    const dirs = step.directions ? '<p class="bmf-bv-step-directions">' + escapeHtml(step.directions) + '</p>' : '';
    const prompt = step.prompt_text ? '<blockquote class="bmf-bv-step-prompt">' + escapeHtml(step.prompt_text) + '</blockquote>' : '';
    const limits = (min || max) ? ('<p class="bmf-bv-limits">' + (min ? ('Min ' + min + 's') : '') + (min && max ? ' · ' : '') + (max ? ('Max ' + max + 's') : '') + '</p>') : '';
    return '<div class="bmf-biovoice-recorder" data-bmf-biovoice-recorder data-task="' + escapeAttr(task) + '" data-min="' + escapeAttr(String(min)) + '" data-max="' + escapeAttr(String(max)) + '" data-group="' + escapeAttr(String(groupId || 0)) + '">' +
      title + dirs + prompt +
      '<div class="bmf-bv-status" data-status>Ready to record</div>' +
      '<div class="bmf-bv-device-wrap" data-device-wrap hidden><label class="bmf-bv-device-label" for="bmf-bv-device-wiz">Microphone</label><select class="bmf-bv-device" data-device id="bmf-bv-device-wiz"></select></div>' +
      '<div class="bmf-bv-meter" data-meter aria-hidden="true"><div class="bmf-bv-meter-bar" data-meter-bar></div></div>' +
      '<div class="bmf-bv-controls"><button type="button" class="bmf-bv-btn bmf-bv-btn-record" data-action="start"><span class="bmf-bv-dot"></span> Start Recording</button><button type="button" class="bmf-bv-btn bmf-bv-btn-stop" data-action="stop" disabled>Stop</button></div>' +
      '<div class="bmf-bv-timer" data-timer>00:00</div>' + limits +
      '<div class="bmf-bv-preview" data-preview hidden><p class="bmf-bv-preview-label">Last take</p><audio controls data-player></audio><div class="bmf-bv-preview-actions"><button type="button" class="bmf-bv-btn bmf-bv-btn-save" data-action="save">Save Recording</button><button type="button" class="bmf-bv-btn bmf-bv-btn-discard" data-action="discard">Discard</button></div></div>' +
      '<div class="bmf-bv-message" data-message hidden></div></div>';
  }

  function initWizard(root) {
    const purpose = root.getAttribute('data-purpose') || cfg.purpose || 'baseline';
    const panel = root.querySelector('[data-wizard-panel]');
    let state = null;

    function setPanel(html) { panel.innerHTML = html; }

    function progressHtml(group) {
      const bp = group.baseline_progress || { complete_groups: 0, required: 3 };
      const steps = (group.steps || []).filter(function (s) { return s.task_code !== 'mic_check'; });
      const done = group.completed_tasks || {};
      const doneCount = Object.keys(done).length;
      const total = steps.length || 1;
      const pct = Math.min(100, Math.round((doneCount / total) * 100));
      let chips = '';
      steps.forEach(function (s) {
        chips += '<span class="bmf-bv-chip' + (done[s.task_code] ? ' is-done' : '') + '">' + escapeHtml(s.title || s.task_code) + '</span>';
      });
      return '<div class="bmf-bv-progress"><div class="bmf-bv-progress-meta"><span>Session steps: ' + doneCount + ' / ' + total + '</span><span>Baseline groups: ' + bp.complete_groups + ' / ' + bp.required + '</span></div>' +
        '<div class="bmf-bv-progress-bar"><div class="bmf-bv-progress-fill" style="width:' + pct + '%"></div></div><div class="bmf-bv-chips">' + chips + '</div>' +
        (group.device_mismatch ? '<p class="bmf-bv-warn">Different device or mic detected vs earlier takes in this session.</p>' : '') + '</div>';
    }

    function wellnessHtml() {
      let items = '';
      WELLNESS_ITEMS.forEach(function (item) {
        let opts = '';
        for (let i = 1; i <= 5; i++) {
          opts += '<label class="bmf-bv-scale-opt"><input type="radio" name="w_' + item.key + '" value="' + i + '" required><span>' + i + '</span></label>';
        }
        items += '<fieldset class="bmf-bv-wellness-item"><legend>' + escapeHtml(item.label) + '</legend><div class="bmf-bv-scale-ends"><span>' + escapeHtml(item.low) + '</span><span>' + escapeHtml(item.high) + '</span></div><div class="bmf-bv-scale">' + opts + '</div></fieldset>';
      });
      return '<div class="bmf-bv-wellness"><h3 class="bmf-bv-heading">Before you begin</h3><p class="bmf-bv-lead">Answer these five questions, then we will walk through the recording steps.</p><form data-wellness-form>' + items + '<button type="submit" class="bmf-bv-btn bmf-bv-btn-record">Continue</button></form></div>';
    }

    function completeHtml(group) {
      const bp = group.baseline_progress || { complete_groups: 0, required: 3 };
      const done = bp.complete_groups >= bp.required;
      return '<div class="bmf-bv-complete"><h3 class="bmf-bv-heading">Session complete</h3><p class="bmf-bv-lead">All steps for this session are saved.</p>' + progressHtml(group) +
        (done ? '<p class="bmf-bv-success-note">Baseline complete (' + bp.required + ' sessions).</p>' :
          '<p class="bmf-bv-lead">You still need ' + (bp.required - bp.complete_groups) + ' more full session(s) for baseline.</p><button type="button" class="bmf-bv-btn bmf-bv-btn-record" data-action="start-next-group">Start next session</button>') +
        '</div>';
    }

    function mountRecorder(step, groupId) {
      window.bmfBioVoice = {
        restUrl: cfg.sessionsUrl, nonce: cfg.nonce, sessionType: purpose, userId: cfg.userId,
        taskCode: step.task_code || '', sessionGroupId: groupId || 0,
        minSeconds: step.min_seconds || 0, maxSeconds: step.max_seconds || 0
      };
      setPanel(progressHtml(state) + '<div class="bmf-bv-step-panel">' + recorderMarkup(step, groupId) + '</div>');
      if (typeof window.bmfBioVoiceBootRecorders === 'function') {
        window.bmfBioVoiceBootRecorders();
      }
    }

    function showForState(group) {
      state = group;
      if (!group || !group.group_id) {
        setPanel('<p class="bmf-bv-empty">Unable to start a session. Please refresh and try again.</p>');
        return;
      }
      if (!group.wellness_anchor) {
        setPanel(progressHtml(group) + wellnessHtml());
        const form = panel.querySelector('[data-wellness-form]');
        if (form) form.addEventListener('submit', onWellnessSubmit);
        return;
      }
      if (group.is_group_complete || group.status === 'complete') {
        setPanel(completeHtml(group));
        const btn = panel.querySelector('[data-action="start-next-group"]');
        if (btn) btn.addEventListener('click', startFreshGroup);
        return;
      }
      if (!hasMicPass(group.group_id)) {
        const micStep = (group.steps || []).find(function (s) { return s.task_code === 'mic_check'; }) || {
          task_code: 'mic_check', title: 'Microphone check',
          directions: 'Speak naturally for a couple of seconds so we can confirm audio is coming through.',
          prompt_text: 'Testing one two three.', min_seconds: 2, max_seconds: 5
        };
        mountRecorder(micStep, group.group_id);
        return;
      }
      if (group.next_step) {
        mountRecorder(group.next_step, group.group_id);
        return;
      }
      setPanel(completeHtml(group));
    }

    function onWellnessSubmit(e) {
      e.preventDefault();
      const form = e.target;
      const answers = {};
      let valid = true;
      WELLNESS_ITEMS.forEach(function (item) {
        const el = form.querySelector('input[name="w_' + item.key + '"]:checked');
        if (!el) { valid = false; return; }
        answers[item.key] = parseInt(el.value, 10);
      });
      if (!valid) { alert('Please answer all five questions.'); return; }
      answers.captured_at = new Date().toISOString();
      const btn = form.querySelector('button[type="submit"]');
      if (btn) btn.disabled = true;
      api('/groups/' + state.group_id + '/wellness', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ wellness_anchor: answers })
      }).then(function (data) { showForState(data); })
        .catch(function (err) { alert(err.message || 'Could not save wellness answers.'); if (btn) btn.disabled = false; });
    }

    function startFreshGroup() {
      setPanel('<p class="bmf-bv-empty">Starting next session…</p>');
      api('/groups', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ purpose: purpose })
      }).then(function (data) { showForState(data); })
        .catch(function (err) { setPanel('<p class="bmf-bv-empty--error">' + escapeHtml(err.message) + '</p>'); });
    }

    function load() {
      setPanel('<p class="bmf-bv-empty">Loading session…</p>');
      Promise.all([
        api('/protocol?purpose=' + encodeURIComponent(purpose)),
        api('/groups?purpose=' + encodeURIComponent(purpose)).catch(function () { return { group: null }; })
      ]).then(function (results) {
        const current = results[1];
        if (current && current.group_id) { showForState(current); return; }
        return api('/groups', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ purpose: purpose })
        }).then(function (data) { showForState(data); });
      }).catch(function (err) {
        setPanel('<p class="bmf-bv-empty--error">' + escapeHtml(err.message || 'Failed to load session.') + '</p>');
      });
    }

    root.addEventListener('bmf-biovoice-mic-check-passed', function () {
      if (state && state.group_id) { setMicPass(state.group_id); showForState(state); }
    });
    root.addEventListener('bmf-biovoice-saved', function (ev) {
      const detail = ev.detail || {};
      if (detail.group) { showForState(detail.group); }
      else if (state && state.group_id) {
        api('/groups?purpose=' + encodeURIComponent(purpose)).then(function (data) {
          if (data && data.group_id) showForState(data);
        }).catch(function () {});
      }
    });
    load();
  }

  function boot() {
    document.querySelectorAll('[data-bmf-biovoice-session]').forEach(initWizard);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
