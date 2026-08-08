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
    {
      key: 'balanced',
      label: 'How balanced or centered do you feel right now?',
      low: 'Very Unbalanced',
      high: 'Very Balanced'
    },
    {
      key: 'clear',
      label: 'How mentally clear and focused do you feel at this moment?',
      low: 'Very Unclear',
      high: 'Very Clear'
    },
    {
      key: 'energized',
      label: 'How physically energized do you feel right now?',
      low: 'Very Low Energy',
      high: 'Very High Energy'
    },
    {
      key: 'ready',
      label: 'How comfortable and ready do you feel for this recording?',
      low: 'Not Comfortable/Ready',
      high: 'Very Comfortable/Ready'
    },
    {
      key: 'restored',
      label: 'How restored or recovered do you feel today overall?',
      low: 'Not Restored',
      high: 'Fully Restored'
    }
  ];

  function micKey(groupId) {
    return 'bmf_biovoice_mic_ok_' + groupId;
  }

  function hasMicPass(groupId) {
    try {
      return sessionStorage.getItem(micKey(groupId)) === '1';
    } catch (e) {
      return false;
    }
  }

  function setMicPass(groupId) {
    try {
      sessionStorage.setItem(micKey(groupId), '1');
    } catch (e) { /* ignore */ }
  }

  function api(path, options) {
    const opts = options || {};
    const headers = Object.assign(
      { 'X-WP-Nonce': cfg.nonce },
      opts.headers || {}
    );
    return fetch(cfg.restBase + path, Object.assign({}, opts, {
      headers: headers,
      credentials: 'same-origin'
    })).then(function (res) {
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

  function recorderMarkup(step, groupId, sessionType) {
    const task = step.task_code || '';
    const min = step.min_seconds != null ? step.min_seconds : 0;
    const max = step.max_seconds != null ? step.max_seconds : 0;
    const title = step.title ? '<div class="bmf-bv-step-title">' + escapeHtml(step.title) + '</div>' : '';
    const dirs = step.directions
      ? '<p class="bmf-bv-step-directions">' + escapeHtml(step.directions) + '</p>'
      : '';
    const prompt = step.prompt_text
      ? '<blockquote class="bmf-bv-step-prompt">' + escapeHtml(step.prompt_text) + '</blockquote>'
      : '';
    const limits = (min || max)
      ? '<p class="bmf-bv-limits">' +
        (min ? ('Min ' + min + 's') : '') +
        (min && max ? ' · ' : '') +
        (max ? ('Max ' + max + 's') : '') +
        '</p>'
      : '';

    const silenceAttr = step.is_silence ? ' data-silence="1"' : '';

    return (
      '<div class="bmf-biovoice-recorder" data-bmf-biovoice-recorder' +
      ' data-task="' + escapeAttr(task) + '"' +
      ' data-min="' + escapeAttr(String(min)) + '"' +
      ' data-max="' + escapeAttr(String(max)) + '"' +
      ' data-group="' + escapeAttr(String(groupId || 0)) + '"' +
      silenceAttr + '>' +
      title + dirs + prompt +
      '<div class="bmf-bv-status" data-status>Ready to record</div>' +
      '<div class="bmf-bv-device-wrap" data-device-wrap hidden>' +
      '<label class="bmf-bv-device-label" for="bmf-bv-device-wiz">Microphone</label>' +
      '<select class="bmf-bv-device" data-device id="bmf-bv-device-wiz"></select>' +
      '</div>' +
      '<div class="bmf-bv-meter" data-meter aria-hidden="true"><div class="bmf-bv-meter-bar" data-meter-bar></div></div>' +
      '<div class="bmf-bv-controls">' +
      '<button type="button" class="bmf-bv-btn bmf-bv-btn-record" data-action="start">' +
      '<span class="bmf-bv-dot"></span> Start Recording</button>' +
      '<button type="button" class="bmf-bv-btn bmf-bv-btn-stop" data-action="stop" disabled>Stop</button>' +
      '</div>' +
      '<div class="bmf-bv-timer" data-timer>00:00</div>' +
      limits +
      '<div class="bmf-bv-preview" data-preview hidden>' +
      '<p class="bmf-bv-preview-label">Last take</p>' +
      '<audio controls data-player></audio>' +
      '<div class="bmf-bv-preview-actions">' +
      '<button type="button" class="bmf-bv-btn bmf-bv-btn-save" data-action="save">Save Recording</button>' +
      '<button type="button" class="bmf-bv-btn bmf-bv-btn-discard" data-action="discard">Discard</button>' +
      '</div></div>' +
      '<div class="bmf-bv-message" data-message hidden></div>' +
      '</div>'
    );
  }

  function escapeHtml(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function escapeAttr(s) {
    return escapeHtml(s).replace(/'/g, '&#39;');
  }

  function initWizard(root) {
    // "auto" = derive baseline → comparison → ongoing from GET /status.
    const purposeAttr = root.getAttribute('data-purpose') || cfg.purpose || 'auto';
    const panel = root.querySelector('[data-wizard-panel]');
    let state = null;
    let protocol = null;
    let purpose = purposeAttr === 'auto' ? 'baseline' : purposeAttr;
    let statusSummary = null;

    function setPanel(html) {
      panel.innerHTML = html;
    }

    function resolvePurposeFromStatus(summary) {
      if (purposeAttr && purposeAttr !== 'auto') {
        return purposeAttr;
      }
      if (summary && summary.phase) {
        return summary.phase;
      }
      return 'baseline';
    }

    function purposeLabel(p) {
      if (p === 'comparison') return 'Comparison';
      if (p === 'ongoing') return 'Ongoing';
      return 'Baseline';
    }

    function progressMetaLabel(group) {
      const p = (group && group.purpose) || purpose;
      const prog = (group && group.progress) || {};
      if (p === 'comparison') {
        const done = prog.comparison_done != null ? prog.comparison_done : 0;
        const target = prog.comparison_target != null ? prog.comparison_target : (cfg.comparisonTarget || 6);
        return 'Comparison sessions: ' + done + ' / ' + target;
      }
      if (p === 'ongoing') {
        const done = prog.ongoing_done != null ? prog.ongoing_done : 0;
        return 'Ongoing sessions: ' + done;
      }
      const bp = (group && group.baseline_progress) || {};
      const done = prog.baseline_done != null ? prog.baseline_done : (bp.complete_groups || 0);
      const req = prog.baseline_required != null ? prog.baseline_required : (bp.required || cfg.baselineRequired || 3);
      return 'Baseline sessions: ' + done + ' / ' + req;
    }

    function canRetakeGroup(group) {
      // Admin unlock may reopen a full group (all takes still present).
      // Allow chips whenever the group is open and not finalized.
      return !!(
        group &&
        group.group_id &&
        group.status === 'in_progress' &&
        !group.is_final
      );
    }

    function progressHtml(group) {
      const steps = (group.steps || []).filter(function (s) {
        return s.task_code !== 'mic_check';
      });
      const done = group.completed_tasks || {};
      const doneCount = Object.keys(done).length;
      const total = steps.length || 1;
      const pct = Math.min(100, Math.round((doneCount / total) * 100));
      const allowRetake = canRetakeGroup(group);

      let chips = '';
      steps.forEach(function (s) {
        const ok = !!done[s.task_code];
        const retakeOk = ok && allowRetake && s.allow_retake !== false && s.allow_retake !== 0;
        const title = s.title || s.task_code;
        if (retakeOk) {
          chips +=
            '<button type="button" class="bmf-bv-chip is-done is-retakeable"' +
            ' data-retake-task="' + escapeAttr(s.task_code) + '"' +
            ' data-retake-title="' + escapeAttr(title) + '"' +
            ' title="Retake this step" aria-label="Retake ' + escapeAttr(title) + '">' +
            escapeHtml(title) +
            ' <span class="bmf-bv-chip-retake" aria-hidden="true">↻</span></button>';
        } else {
          chips +=
            '<span class="bmf-bv-chip' + (ok ? ' is-done' : '') + '">' +
            escapeHtml(title) +
            '</span>';
        }
      });

      return (
        '<div class="bmf-bv-progress">' +
        '<div class="bmf-bv-progress-meta">' +
        '<span>Session steps: ' + doneCount + ' / ' + total + '</span>' +
        '<span>' + escapeHtml(progressMetaLabel(group)) + '</span>' +
        '</div>' +
        '<div class="bmf-bv-progress-bar"><div class="bmf-bv-progress-fill" style="width:' + pct + '%"></div></div>' +
        '<div class="bmf-bv-chips">' + chips + '</div>' +
        (allowRetake && doneCount > 0
          ? '<p class="bmf-bv-retake-hint">Tap a completed step to retake it.</p>'
          : '') +
        (group.device_mismatch
          ? '<p class="bmf-bv-warn">Different device or mic detected vs earlier takes in this session.</p>'
          : '') +
        '</div>'
      );
    }

    function retakeDialogHtml(taskCode, title) {
      return (
        '<div class="bmf-bv-retake-overlay" data-retake-dialog role="dialog" aria-modal="true"' +
        ' aria-labelledby="bmf-bv-retake-title">' +
        '<div class="bmf-bv-retake-card">' +
        '<h3 id="bmf-bv-retake-title" class="bmf-bv-heading">Retake “' + escapeHtml(title) + '”?</h3>' +
        '<p class="bmf-bv-lead">The previous recording for this step will be permanently deleted.</p>' +
        '<div class="bmf-bv-retake-options">' +
        '<label class="bmf-bv-retake-opt">' +
        '<input type="radio" name="bmf_retake_mode" value="one" checked>' +
        '<span><strong>This step only</strong><br><small>Keep later recordings</small></span>' +
        '</label>' +
        '<label class="bmf-bv-retake-opt">' +
        '<input type="radio" name="bmf_retake_mode" value="forward">' +
        '<span><strong>This step and everything after</strong><br><small>Clear this and all following steps</small></span>' +
        '</label>' +
        '</div>' +
        '<div class="bmf-bv-retake-actions">' +
        '<button type="button" class="bmf-bv-btn bmf-bv-btn-discard" data-retake-cancel>Cancel</button>' +
        '<button type="button" class="bmf-bv-btn bmf-bv-btn-record" data-retake-confirm' +
        ' data-task="' + escapeAttr(taskCode) + '">Retake</button>' +
        '</div>' +
        '</div></div>'
      );
    }

    function openRetakeDialog(taskCode, title) {
      closeRetakeDialog();
      const wrap = document.createElement('div');
      wrap.innerHTML = retakeDialogHtml(taskCode, title);
      const dialog = wrap.firstChild;
      panel.appendChild(dialog);

      const cancel = dialog.querySelector('[data-retake-cancel]');
      const confirmBtn = dialog.querySelector('[data-retake-confirm]');
      if (cancel) {
        cancel.addEventListener('click', closeRetakeDialog);
      }
      if (confirmBtn) {
        confirmBtn.addEventListener('click', function () {
          const mode = dialog.querySelector('input[name="bmf_retake_mode"]:checked');
          const clearForward = mode && mode.value === 'forward';
          runRetake(taskCode, clearForward, confirmBtn);
        });
      }
      dialog.addEventListener('click', function (ev) {
        if (ev.target === dialog) {
          closeRetakeDialog();
        }
      });
    }

    function closeRetakeDialog() {
      const existing = panel.querySelector('[data-retake-dialog]');
      if (existing) {
        existing.remove();
      }
    }

    function runRetake(taskCode, clearForward, btn) {
      if (!state || !state.group_id) {
        return;
      }
      if (btn) {
        btn.disabled = true;
        btn.textContent = 'Working…';
      }
      api('/groups/' + state.group_id + '/retake', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          task_code: taskCode,
          clear_forward: !!clearForward
        })
      }).then(function (data) {
        closeRetakeDialog();
        showForState(data);
      }).catch(function (err) {
        alert(err.message || 'Could not retake that step.');
        if (btn) {
          btn.disabled = false;
          btn.textContent = 'Retake';
        }
      });
    }

    function wellnessHtml() {
      let items = '';
      WELLNESS_ITEMS.forEach(function (item) {
        let opts = '';
        for (let i = 1; i <= 5; i++) {
          opts += '<label class="bmf-bv-scale-opt">' +
            '<input type="radio" name="w_' + item.key + '" value="' + i + '" required>' +
            '<span>' + i + '</span></label>';
        }
        items +=
          '<fieldset class="bmf-bv-wellness-item">' +
          '<legend>' + escapeHtml(item.label) + '</legend>' +
          '<div class="bmf-bv-scale-ends"><span>' + escapeHtml(item.low) +
          '</span><span>' + escapeHtml(item.high) + '</span></div>' +
          '<div class="bmf-bv-scale">' + opts + '</div>' +
          '</fieldset>';
      });

      return (
        '<div class="bmf-bv-wellness">' +
        '<h3 class="bmf-bv-heading">Before you begin</h3>' +
        '<p class="bmf-bv-lead">Answer these five questions, then we’ll walk through the recording steps.</p>' +
        '<form data-wellness-form>' + items +
        '<button type="submit" class="bmf-bv-btn bmf-bv-btn-record">Continue</button>' +
        '</form></div>'
      );
    }

    function completeHtml(group) {
      const prog = group.progress || {};
      const bp = group.baseline_progress || { complete_groups: 0, required: 3 };
      const baselineDone = prog.baseline_done != null ? prog.baseline_done : (bp.complete_groups || 0);
      const baselineReq = prog.baseline_required != null ? prog.baseline_required : (bp.required || cfg.baselineRequired || 3);
      const comparisonDone = prog.comparison_done != null ? prog.comparison_done : 0;
      const comparisonTarget = prog.comparison_target != null ? prog.comparison_target : (cfg.comparisonTarget || 6);
      const nextPurpose = prog.recommended_purpose || resolvePurposeFromStatus(statusSummary);
      const finishedPurpose = group.purpose || purpose;

      let note = '';
      let btnLabel = 'Start next session';

      if (finishedPurpose === 'baseline' && baselineDone < baselineReq) {
        note =
          '<p class="bmf-bv-lead">You still need ' +
          (baselineReq - baselineDone) +
          ' more full session(s) for baseline.</p>';
        btnLabel = 'Start next baseline session';
      } else if (finishedPurpose === 'baseline' && baselineDone >= baselineReq) {
        note =
          '<p class="bmf-bv-success-note">Baseline complete (' +
          baselineReq +
          ' sessions). You can start the comparison series.</p>';
        btnLabel = 'Start first comparison session';
      } else if (finishedPurpose === 'comparison' && comparisonDone < comparisonTarget) {
        note =
          '<p class="bmf-bv-lead">Comparison progress: ' +
          comparisonDone +
          ' of ' +
          comparisonTarget +
          ' sessions.</p>';
        btnLabel = 'Start next comparison session';
      } else if (finishedPurpose === 'comparison') {
        note =
          '<p class="bmf-bv-success-note">Comparison series target reached (' +
          comparisonTarget +
          '). You can continue with ongoing sessions.</p>';
        btnLabel = 'Start ongoing session';
      } else {
        note = '<p class="bmf-bv-lead">Session saved. You can record another whenever you like.</p>';
        btnLabel = 'Start another session';
      }

      return (
        '<div class="bmf-bv-complete">' +
        '<h3 class="bmf-bv-heading">Session complete</h3>' +
        '<p class="bmf-bv-lead">All steps for this ' +
        escapeHtml(purposeLabel(finishedPurpose).toLowerCase()) +
        ' session are saved.</p>' +
        progressHtml(group) +
        note +
        '<button type="button" class="bmf-bv-btn bmf-bv-btn-record" data-action="start-next-group"' +
        ' data-next-purpose="' +
        escapeAttr(nextPurpose) +
        '">' +
        escapeHtml(btnLabel) +
        '</button>' +
        '</div>'
      );
    }

    function mountRecorder(step, groupId) {
      // Provide cfg expected by recorder.js for this step.
      window.bmfBioVoice = {
        restUrl: cfg.sessionsUrl,
        nonce: cfg.nonce,
        sessionType: purpose,
        userId: cfg.userId,
        taskCode: step.task_code || '',
        sessionGroupId: groupId || 0,
        minSeconds: step.min_seconds || 0,
        maxSeconds: step.max_seconds || 0
      };

      const html =
        progressHtml(state) +
        '<div class="bmf-bv-step-panel">' +
        recorderMarkup(step, groupId, purpose) +
        '</div>';
      setPanel(html);

      // Boot recorder on the new node.
      if (typeof window.bmfBioVoiceBootRecorders === 'function') {
        window.bmfBioVoiceBootRecorders();
      } else {
        // Fallback: re-run same selector boot if available via custom event.
        document.dispatchEvent(new Event('bmf-biovoice-boot-recorders'));
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
        if (form) {
          form.addEventListener('submit', onWellnessSubmit);
        }
        return;
      }

      if (group.is_group_complete || group.status === 'complete') {
        setPanel(completeHtml(group));
        const btn = panel.querySelector('[data-action="start-next-group"]');
        if (btn) {
          btn.addEventListener('click', function () {
            const next = btn.getAttribute('data-next-purpose') || purpose;
            startFreshGroup(next);
          });
        }
        return;
      }

      // Mic check once per group (client-only).
      if (!hasMicPass(group.group_id)) {
        const micStep = (group.steps || []).find(function (s) {
          return s.task_code === 'mic_check';
        }) || {
          task_code: 'mic_check',
          title: 'Microphone check',
          directions: 'Speak naturally for a couple of seconds so we can confirm audio is coming through.',
          prompt_text: 'Testing one two three.',
          min_seconds: 2,
          max_seconds: 5
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
        if (!el) {
          valid = false;
          return;
        }
        answers[item.key] = parseInt(el.value, 10);
      });
      if (!valid) {
        alert('Please answer all five questions.');
        return;
      }
      answers.captured_at = new Date().toISOString();

      const btn = form.querySelector('button[type="submit"]');
      if (btn) btn.disabled = true;

      api('/groups/' + state.group_id + '/wellness', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ wellness_anchor: answers })
      }).then(function (data) {
        showForState(data);
      }).catch(function (err) {
        alert(err.message || 'Could not save wellness answers.');
        if (btn) btn.disabled = false;
      });
    }

    function startFreshGroup(nextPurpose) {
      if (nextPurpose) {
        purpose = nextPurpose;
      }
      setPanel('<p class="bmf-bv-empty">Starting next ' + escapeHtml(purposeLabel(purpose).toLowerCase()) + ' session…</p>');
      api('/groups', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ purpose: purpose })
      }).then(function (data) {
        showForState(data);
      }).catch(function (err) {
        setPanel('<p class="bmf-bv-empty--error">' + escapeHtml(err.message) + '</p>');
      });
    }

    function load() {
      setPanel('<p class="bmf-bv-empty">Loading session…</p>');

      // Resolve phase first so purpose=auto does not keep creating extra baseline groups.
      api('/status')
        .catch(function () {
          return null;
        })
        .then(function (summary) {
          statusSummary = summary;
          purpose = resolvePurposeFromStatus(summary);

          return Promise.all([
            api('/protocol?purpose=' + encodeURIComponent(purpose)),
            api('/groups?purpose=' + encodeURIComponent(purpose)).catch(function () {
              return { group: null };
            })
          ]);
        })
        .then(function (results) {
          if (!results) {
            return;
          }
          protocol = results[0];
          const current = results[1];

          // Resume in-progress group for the active phase.
          if (current && current.group_id) {
            showForState(current);
            return;
          }

          // No open group for this phase — create one.
          return api('/groups', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ purpose: purpose })
          }).then(function (data) {
            showForState(data);
          });
        })
        .catch(function (err) {
          setPanel('<p class="bmf-bv-empty--error">' + escapeHtml(err.message || 'Failed to load session.') + '</p>');
        });
    }

    root.addEventListener('bmf-biovoice-mic-check-passed', function () {
      if (state && state.group_id) {
        setMicPass(state.group_id);
        showForState(state);
      }
    });

    root.addEventListener('bmf-biovoice-saved', function (ev) {
      const detail = ev.detail || {};
      if (detail.group) {
        showForState(detail.group);
      } else if (state && state.group_id) {
        api('/groups?purpose=' + encodeURIComponent(purpose)).then(function (data) {
          if (data && data.group_id) {
            showForState(data);
          }
        }).catch(function () { /* keep current */ });
      }
    });

    // Retake chips (event delegation — chips are re-rendered each step).
    root.addEventListener('click', function (ev) {
      const chip = ev.target.closest('[data-retake-task]');
      if (!chip || !panel.contains(chip)) {
        return;
      }
      ev.preventDefault();
      if (!canRetakeGroup(state)) {
        return;
      }
      const task = chip.getAttribute('data-retake-task');
      const title = chip.getAttribute('data-retake-title') || task;
      if (task) {
        openRetakeDialog(task, title);
      }
    });

    load();
  }

  function boot() {
    document.querySelectorAll('[data-bmf-biovoice-session]').forEach(initWizard);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
