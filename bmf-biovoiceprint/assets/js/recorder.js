/**
 * BioVoicePrint – MediaRecorder client.
 * Records → VU meter → min-duration hard fail → optional save via REST.
 * Supports mic selection, task_code, session_group_id, mic_check (client-only).
 */
(function () {
  'use strict';

  if (typeof window.bmfBioVoice === 'undefined') {
    return;
  }

  const cfg = window.bmfBioVoice;
  const STORAGE_KEY = 'bmf_biovoice_device_id';

  function formatTime(sec) {
    const m = Math.floor(sec / 60);
    const s = Math.floor(sec % 60);
    return String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
  }

  function detectDeviceClass() {
    const ua = navigator.userAgent || '';
    if (/iPad|Tablet|Android(?!.*Mobile)/i.test(ua)) return 'tablet';
    if (/iPhone|iPod|Android.*Mobile|Mobile/i.test(ua)) return 'phone';
    return 'desktop';
  }

  function detectOS() {
    const ua = navigator.userAgent || '';
    if (/iPhone|iPad|iPod/.test(ua)) return 'iOS';
    if (/Android/.test(ua)) return 'Android';
    if (/Mac OS X/.test(ua)) return 'macOS';
    if (/Windows/.test(ua)) return 'Windows';
    if (/Linux/.test(ua)) return 'Linux';
    return 'unknown';
  }

  function initRecorder(root) {
    const statusEl   = root.querySelector('[data-status]');
    const timerEl    = root.querySelector('[data-timer]');
    const previewEl  = root.querySelector('[data-preview]');
    const playerEl   = root.querySelector('[data-player]');
    const messageEl  = root.querySelector('[data-message]');
    const deviceWrap = root.querySelector('[data-device-wrap]');
    const deviceSel  = root.querySelector('[data-device]');
    const meterEl    = root.querySelector('[data-meter]');
    const meterBar   = root.querySelector('[data-meter-bar]');
    const btnStart   = root.querySelector('[data-action="start"]');
    const btnStop    = root.querySelector('[data-action="stop"]');
    const btnSave    = root.querySelector('[data-action="save"]');
    const btnDiscard = root.querySelector('[data-action="discard"]');

    let mediaRecorder = null;
    let chunks = [];
    let blob = null;
    let recordedMime = '';
    let startTs = 0;
    let timerId = null;
    let stream = null;
    let selectedDeviceId = null;
    let audioCtx = null;
    let analyser = null;
    let meterRaf = null;
    let peakRms = 0;

    const taskCode = (root.getAttribute('data-task') || cfg.taskCode || '').toLowerCase();
    const minSec = parseFloat(root.getAttribute('data-min') || cfg.minSeconds || 0) || 0;
    const maxSec = parseFloat(root.getAttribute('data-max') || cfg.maxSeconds || 0) || 0;
    const groupId = parseInt(root.getAttribute('data-group') || cfg.sessionGroupId || 0, 10) || 0;
    const isMicCheck = taskCode === 'mic_check';

    function isIOS() {
      return /iPad|iPhone|iPod/.test(navigator.userAgent) ||
        (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    }

    function pickRecorderOptions() {
      const options = {};
      const candidates = isIOS()
        ? ['audio/mp4', 'audio/aac', 'audio/webm;codecs=opus', 'audio/webm']
        : ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4'];
      for (let i = 0; i < candidates.length; i++) {
        if (window.MediaRecorder && MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported(candidates[i])) {
          options.mimeType = candidates[i];
          break;
        }
      }
      return options;
    }

    function extensionForMime(type) {
      const t = (type || '').toLowerCase();
      if (t.indexOf('mp4') !== -1 || t.indexOf('m4a') !== -1 || t.indexOf('aac') !== -1) return 'm4a';
      if (t.indexOf('ogg') !== -1) return 'ogg';
      if (t.indexOf('wav') !== -1) return 'wav';
      if (t.indexOf('mpeg') !== -1 || t.indexOf('mp3') !== -1) return 'mp3';
      if (t.indexOf('webm') !== -1) return 'webm';
      if (isIOS()) return 'm4a';
      return 'webm';
    }

    function setStatus(text) {
      if (statusEl) statusEl.textContent = text;
    }

    function showMessage(text, type) {
      if (!messageEl) return;
      messageEl.hidden = false;
      messageEl.textContent = text;
      messageEl.className = 'bmf-bv-message bmf-bv-message--' + (type || 'info');
    }

    function hideMessage() {
      if (messageEl) messageEl.hidden = true;
    }

    function resetPreview() {
      blob = null;
      chunks = [];
      recordedMime = '';
      peakRms = 0;
      if (playerEl) {
        playerEl.removeAttribute('src');
        playerEl.load();
      }
      if (previewEl) previewEl.hidden = true;
      if (btnSave) btnSave.disabled = false;
    }

    function stopMeter() {
      if (meterRaf) {
        cancelAnimationFrame(meterRaf);
        meterRaf = null;
      }
      if (meterBar) meterBar.style.width = '0%';
      if (audioCtx) {
        try { audioCtx.close(); } catch (e) { /* ignore */ }
        audioCtx = null;
        analyser = null;
      }
    }

    function startMeter(mediaStream) {
      stopMeter();
      peakRms = 0;
      try {
        const AC = window.AudioContext || window.webkitAudioContext;
        if (!AC) return;
        audioCtx = new AC();
        if (audioCtx.state === 'suspended') {
          audioCtx.resume();
        }
        const source = audioCtx.createMediaStreamSource(mediaStream);
        analyser = audioCtx.createAnalyser();
        analyser.fftSize = 2048;
        source.connect(analyser);

        const data = new Uint8Array(analyser.fftSize);
        const tick = function () {
          analyser.getByteTimeDomainData(data);
          let sum = 0;
          for (let i = 0; i < data.length; i++) {
            const v = (data[i] - 128) / 128;
            sum += v * v;
          }
          const rms = Math.sqrt(sum / data.length);
          if (rms > peakRms) peakRms = rms;
          const pct = Math.min(100, Math.round(rms * 280));
          if (meterBar) {
            meterBar.style.width = pct + '%';
            meterBar.classList.toggle('is-hot', pct > 85);
          }
          meterRaf = requestAnimationFrame(tick);
        };
        meterRaf = requestAnimationFrame(tick);
      } catch (e) {
        /* VU optional */
      }
    }

    function getSavedDeviceId() {
      try { return localStorage.getItem(STORAGE_KEY) || ''; } catch (e) { return ''; }
    }

    function saveDeviceId(id) {
      try {
        if (id) localStorage.setItem(STORAGE_KEY, id);
        else localStorage.removeItem(STORAGE_KEY);
      } catch (e) { /* ignore */ }
    }

    function audioConstraints() {
      const base = {
        echoCancellation: true,
        noiseSuppression: true,
        autoGainControl: true
      };
      if (selectedDeviceId) {
        base.deviceId = { exact: selectedDeviceId };
      }
      return { audio: base };
    }

    function micLabel() {
      if (deviceSel && deviceSel.selectedOptions && deviceSel.selectedOptions[0]) {
        return deviceSel.selectedOptions[0].textContent || '';
      }
      return '';
    }

    function buildDevicePayload() {
      return {
        device_class: detectDeviceClass(),
        os: detectOS(),
        browser: navigator.userAgent || '',
        mic_label: micLabel(),
        device_id: selectedDeviceId || ''
      };
    }

    async function refreshDevices() {
      if (!navigator.mediaDevices || !navigator.mediaDevices.enumerateDevices) return;
      let list = [];
      try { list = await navigator.mediaDevices.enumerateDevices(); } catch (e) { return; }
      const inputs = list.filter(function (d) { return d.kind === 'audioinput'; });
      if (!deviceSel || !deviceWrap) return;
      const labeled = inputs.filter(function (d) { return d.deviceId && d.label; });
      if (labeled.length < 2) {
        deviceWrap.hidden = true;
        return;
      }
      const saved = getSavedDeviceId();
      deviceSel.innerHTML = '';
      labeled.forEach(function (d, i) {
        const opt = document.createElement('option');
        opt.value = d.deviceId;
        opt.textContent = d.label || ('Microphone ' + (i + 1));
        deviceSel.appendChild(opt);
      });
      if (saved && labeled.some(function (d) { return d.deviceId === saved; })) {
        deviceSel.value = saved;
        selectedDeviceId = saved;
      } else {
        selectedDeviceId = deviceSel.value || labeled[0].deviceId;
        deviceSel.value = selectedDeviceId;
      }
      deviceWrap.hidden = false;
    }

    async function ensurePermissionAndDevices() {
      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) return false;
      try {
        const tmp = await navigator.mediaDevices.getUserMedia({ audio: true });
        tmp.getTracks().forEach(function (t) { t.stop(); });
      } catch (err) {
        return false;
      }
      await refreshDevices();
      return true;
    }

    async function start() {
      hideMessage();
      resetPreview();
      root.classList.remove('is-recording');

      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        showMessage('Microphone access is not supported in this browser.', 'error');
        return;
      }

      if (deviceSel && !deviceWrap.hidden && deviceSel.value) {
        selectedDeviceId = deviceSel.value;
        saveDeviceId(selectedDeviceId);
      }

      try {
        stream = await navigator.mediaDevices.getUserMedia(audioConstraints());
      } catch (err) {
        if (selectedDeviceId) {
          try {
            selectedDeviceId = null;
            stream = await navigator.mediaDevices.getUserMedia({
              audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true }
            });
          } catch (err2) {
            showMessage('Could not access microphone. Please allow permission and try again.', 'error');
            return;
          }
        } else {
          showMessage('Could not access microphone. Please allow permission and try again.', 'error');
          return;
        }
      }

      refreshDevices();
      startMeter(stream);

      chunks = [];
      recordedMime = '';
      const options = pickRecorderOptions();

      try {
        mediaRecorder = new MediaRecorder(stream, options);
      } catch (err) {
        mediaRecorder = new MediaRecorder(stream);
      }

      recordedMime = mediaRecorder.mimeType || options.mimeType || '';

      mediaRecorder.ondataavailable = function (e) {
        if (e.data && e.data.size > 0) {
          chunks.push(e.data);
          if (!recordedMime && e.data.type) recordedMime = e.data.type;
        }
      };

      mediaRecorder.onstop = function () {
        stopMeter();
        root.classList.remove('is-recording');
        const mime = recordedMime || mediaRecorder.mimeType || (isIOS() ? 'audio/mp4' : 'audio/webm');
        recordedMime = mime;
        blob = new Blob(chunks, { type: mime });

        if (stream) {
          stream.getTracks().forEach(function (t) { t.stop(); });
          stream = null;
        }

        if (!blob || blob.size < 1) {
          showMessage('Recording produced no audio data. Please try again.', 'error');
          setStatus('Ready to record');
          btnStart.disabled = false;
          btnStop.disabled = true;
          if (deviceSel) deviceSel.disabled = false;
          return;
        }

        const elapsed = startTs ? (Date.now() - startTs) / 1000 : 0;

        if (isMicCheck) {
          if (peakRms < 0.01) {
            showMessage('Little or no audio detected. Check the microphone and try again.', 'error');
            setStatus('Mic check failed');
            btnStart.disabled = false;
            btnStop.disabled = true;
            if (deviceSel) deviceSel.disabled = false;
            blob = null;
            return;
          }
          showMessage('Microphone looks good.', 'success');
          setStatus('Mic check passed');
          btnStart.disabled = false;
          btnStop.disabled = true;
          if (deviceSel) deviceSel.disabled = false;
          blob = null;
          return;
        }

        if (minSec > 0 && elapsed + 0.05 < minSec) {
          showMessage('Recording too short (min ' + minSec + 's). Please retake.', 'error');
          setStatus('Too short — retake required');
          if (previewEl) {
            const url = URL.createObjectURL(blob);
            if (playerEl) playerEl.src = url;
            previewEl.hidden = false;
          }
          if (btnSave) btnSave.disabled = true;
          btnStart.disabled = false;
          btnStop.disabled = true;
          if (deviceSel) deviceSel.disabled = false;
          return;
        }

        const url = URL.createObjectURL(blob);
        if (playerEl) playerEl.src = url;
        if (previewEl) previewEl.hidden = false;
        if (btnSave) btnSave.disabled = false;
        setStatus('Recording complete — review and save, or discard.');
        btnStart.disabled = false;
        btnStop.disabled = true;
        if (deviceSel) deviceSel.disabled = false;
      };

      mediaRecorder.start(isIOS() ? 1000 : 250);
      startTs = Date.now();
      timerEl.textContent = '00:00';
      root.classList.add('is-recording');

      timerId = setInterval(function () {
        const elapsed = (Date.now() - startTs) / 1000;
        timerEl.textContent = formatTime(elapsed);
        if (maxSec > 0 && elapsed >= maxSec) {
          stop();
        }
      }, 200);

      setStatus(isMicCheck ? 'Mic check — speak briefly…' : 'Recording…');
      btnStart.disabled = true;
      btnStop.disabled = false;
      if (deviceSel) deviceSel.disabled = true;
    }

    function stop() {
      if (mediaRecorder && mediaRecorder.state !== 'inactive') {
        mediaRecorder.stop();
      }
      if (timerId) {
        clearInterval(timerId);
        timerId = null;
      }
    }

    async function save() {
      if (isMicCheck) {
        showMessage('Mic check does not need to be saved.', 'info');
        return;
      }
      if (!blob) {
        showMessage('Nothing to save.', 'error');
        return;
      }

      const parts = (timerEl.textContent || '0:0').split(':');
      const dur = (parseInt(parts[0], 10) || 0) * 60 + (parseInt(parts[1], 10) || 0);
      const elapsed = startTs ? (Date.now() - startTs) / 1000 : 0;
      const duration = dur || elapsed;

      if (minSec > 0 && duration + 0.05 < minSec) {
        showMessage('Recording too short (min ' + minSec + 's). Please retake.', 'error');
        return;
      }

      btnSave.disabled = true;
      setStatus('Uploading…');
      hideMessage();

      const form = new FormData();
      const type = blob.type || recordedMime || '';
      const ext = extensionForMime(type);

      form.append('audio', blob, 'recording.' + ext);
      form.append('session_type', cfg.sessionType || 'comparison');
      form.append('duration_sec', String(Math.round(duration * 10) / 10));

      if (taskCode) form.append('task_code', taskCode);
      if (groupId) form.append('session_group_id', String(groupId));

      const device = buildDevicePayload();
      form.append('device_info_json', JSON.stringify(device));
      form.append(
        'device_info',
        [device.device_class, device.os, device.mic_label, device.browser].filter(Boolean).join(' | ')
      );

      try {
        const res = await fetch(cfg.restUrl, {
          method: 'POST',
          headers: { 'X-WP-Nonce': cfg.nonce },
          body: form,
          credentials: 'same-origin'
        });

        const data = await res.json().catch(function () { return {}; });

        if (!res.ok) {
          const msg = (data && data.message) ? data.message : 'Upload failed (' + res.status + ').';
          showMessage(msg, 'error');
          setStatus('Upload failed');
          btnSave.disabled = false;
          return;
        }

        showMessage('Recording saved (session #' + (data.session_id || '?') + ').', 'success');
        setStatus('Saved');
        resetPreview();
        btnSave.disabled = false;
      } catch (err) {
        showMessage('Network error while saving. Please try again.', 'error');
        setStatus('Upload failed');
        btnSave.disabled = false;
      }
    }

    function discard() {
      resetPreview();
      setStatus('Ready to record');
      hideMessage();
    }

    if (deviceSel) {
      deviceSel.addEventListener('change', function () {
        selectedDeviceId = deviceSel.value || null;
        saveDeviceId(selectedDeviceId);
      });
    }

    selectedDeviceId = getSavedDeviceId() || null;
    ensurePermissionAndDevices();

    if (navigator.mediaDevices && navigator.mediaDevices.addEventListener) {
      navigator.mediaDevices.addEventListener('devicechange', function () {
        refreshDevices();
      });
    }

    btnStart.addEventListener('click', start);
    btnStop.addEventListener('click', stop);
    if (btnSave) btnSave.addEventListener('click', save);
    if (btnDiscard) btnDiscard.addEventListener('click', discard);
  }

  function boot() {
    document.querySelectorAll('[data-bmf-biovoice-recorder]').forEach(initRecorder);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
