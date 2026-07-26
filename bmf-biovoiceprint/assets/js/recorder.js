/**
 * BioVoicePrint – MediaRecorder client (POC).
 * Records → local preview → optional save via REST.
 * Supports microphone device selection when multiple inputs exist.
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

  function initRecorder(root) {
    const statusEl   = root.querySelector('[data-status]');
    const timerEl    = root.querySelector('[data-timer]');
    const previewEl  = root.querySelector('[data-preview]');
    const playerEl   = root.querySelector('[data-player]');
    const messageEl  = root.querySelector('[data-message]');
    const deviceWrap = root.querySelector('[data-device-wrap]');
    const deviceSel  = root.querySelector('[data-device]');
    const btnStart   = root.querySelector('[data-action="start"]');
    const btnStop    = root.querySelector('[data-action="stop"]');
    const btnSave    = root.querySelector('[data-action="save"]');
    const btnDiscard = root.querySelector('[data-action="discard"]');

    let mediaRecorder = null;
    let chunks = [];
    let blob = null;
    let startTs = 0;
    let timerId = null;
    let stream = null;
    let selectedDeviceId = null;
    let devicesReady = false;

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
      if (playerEl) {
        playerEl.removeAttribute('src');
        playerEl.load();
      }
      if (previewEl) previewEl.hidden = true;
    }

    function getSavedDeviceId() {
      try {
        return localStorage.getItem(STORAGE_KEY) || '';
      } catch (e) {
        return '';
      }
    }

    function saveDeviceId(id) {
      try {
        if (id) {
          localStorage.setItem(STORAGE_KEY, id);
        } else {
          localStorage.removeItem(STORAGE_KEY);
        }
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

    async function refreshDevices() {
      if (!navigator.mediaDevices || !navigator.mediaDevices.enumerateDevices) {
        return;
      }

      let list = [];
      try {
        list = await navigator.mediaDevices.enumerateDevices();
      } catch (e) {
        return;
      }

      const inputs = list.filter(function (d) {
        return d.kind === 'audioinput';
      });

      if (!deviceSel || !deviceWrap) return;

      const labeled = inputs.filter(function (d) { return d.deviceId && d.label; });

      if (labeled.length < 2) {
        deviceWrap.hidden = true;
        if (selectedDeviceId) {
          const stillThere = inputs.some(function (d) { return d.deviceId === selectedDeviceId; });
          if (!stillThere) selectedDeviceId = null;
        }
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
      devicesReady = true;
    }

    async function ensurePermissionAndDevices() {
      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        return false;
      }
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
              audio: {
                echoCancellation: true,
                noiseSuppression: true,
                autoGainControl: true
              }
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

      chunks = [];
      const options = {};
      if (MediaRecorder.isTypeSupported('audio/mp4')) {
        options.mimeType = 'audio/mp4';
      } else if (MediaRecorder.isTypeSupported('audio/webm;codecs=opus')) {
        options.mimeType = 'audio/webm;codecs=opus';
      } else if (MediaRecorder.isTypeSupported('audio/webm')) {
        options.mimeType = 'audio/webm';
      }

      try {
        mediaRecorder = new MediaRecorder(stream, options);
      } catch (err) {
        mediaRecorder = new MediaRecorder(stream);
      }

      mediaRecorder.ondataavailable = function (e) {
        if (e.data && e.data.size > 0) {
          chunks.push(e.data);
        }
      };

      mediaRecorder.onstop = function () {
        blob = new Blob(chunks, { type: mediaRecorder.mimeType || 'audio/webm' });
        const url = URL.createObjectURL(blob);
        if (playerEl) {
          playerEl.src = url;
        }
        if (previewEl) previewEl.hidden = false;
        setStatus('Recording complete — review and save, or discard.');
        btnStart.disabled = false;
        btnStop.disabled = true;
        if (deviceSel) deviceSel.disabled = false;

        if (stream) {
          stream.getTracks().forEach(function (t) { t.stop(); });
          stream = null;
        }
      };

      mediaRecorder.start(250);
      startTs = Date.now();
      timerEl.textContent = '00:00';
      timerId = setInterval(function () {
        const elapsed = (Date.now() - startTs) / 1000;
        timerEl.textContent = formatTime(elapsed);
      }, 250);

      setStatus('Recording…');
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
      if (!blob) {
        showMessage('Nothing to save.', 'error');
        return;
      }

      btnSave.disabled = true;
      setStatus('Uploading…');
      hideMessage();

      const form = new FormData();
      const type = blob.type || '';
      let ext = 'webm';
      if (type.indexOf('mp4') !== -1 || type.indexOf('m4a') !== -1 || type.indexOf('aac') !== -1) {
        ext = 'm4a';
      } else if (type.indexOf('ogg') !== -1) {
        ext = 'ogg';
      } else if (type.indexOf('wav') !== -1) {
        ext = 'wav';
      } else if (type.indexOf('mpeg') !== -1 || type.indexOf('mp3') !== -1) {
        ext = 'mp3';
      }

      form.append('audio', blob, 'recording.' + ext);
      form.append('session_type', cfg.sessionType || 'comparison');

      const parts = (timerEl.textContent || '0:0').split(':');
      const dur = (parseInt(parts[0], 10) || 0) * 60 + (parseInt(parts[1], 10) || 0);
      form.append('duration_sec', String(dur));

      let deviceHint = navigator.userAgent || '';
      if (deviceSel && deviceSel.selectedOptions && deviceSel.selectedOptions[0]) {
        deviceHint = deviceSel.selectedOptions[0].textContent + ' | ' + deviceHint;
      }
      form.append('device_info', deviceHint);

      try {
        const res = await fetch(cfg.restUrl, {
          method: 'POST',
          headers: {
            'X-WP-Nonce': cfg.nonce
          },
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
    btnSave.addEventListener('click', save);
    btnDiscard.addEventListener('click', discard);
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
