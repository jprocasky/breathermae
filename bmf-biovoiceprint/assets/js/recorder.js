/**
 * BioVoicePrint – basic MediaRecorder client (POC).
 * Records → local preview → optional save via REST.
 */
(function () {
  'use strict';

  if (typeof window.bmfBioVoice === 'undefined') {
    return;
  }

  const cfg = window.bmfBioVoice;

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

    async function start() {
      hideMessage();
      resetPreview();

      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        showMessage('Microphone access is not supported in this browser.', 'error');
        return;
      }

      try {
        stream = await navigator.mediaDevices.getUserMedia({
          audio: {
            echoCancellation: true,
            noiseSuppression: true,
            autoGainControl: true
          }
        });
      } catch (err) {
        showMessage('Could not access microphone. Please allow permission and try again.', 'error');
        return;
      }

      chunks = [];
      const options = {};
      // Prefer webm/opus when available.
      if (MediaRecorder.isTypeSupported('audio/webm;codecs=opus')) {
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

        // Stop all tracks.
        if (stream) {
          stream.getTracks().forEach(function (t) { t.stop(); });
          stream = null;
        }
      };

      mediaRecorder.start(250); // timeslice for more reliable chunks
      startTs = Date.now();
      timerEl.textContent = '00:00';
      timerId = setInterval(function () {
        const elapsed = (Date.now() - startTs) / 1000;
        timerEl.textContent = formatTime(elapsed);
      }, 250);

      setStatus('Recording…');
      btnStart.disabled = true;
      btnStop.disabled = false;
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
      const ext = (blob.type || '').indexOf('webm') !== -1 ? 'webm' : 'wav';
      form.append('audio', blob, 'recording.' + ext);
      form.append('session_type', cfg.sessionType || 'comparison');

      // Approximate duration from timer.
      const parts = (timerEl.textContent || '0:0').split(':');
      const dur = (parseInt(parts[0], 10) || 0) * 60 + (parseInt(parts[1], 10) || 0);
      form.append('duration_sec', String(dur));

      // Simple device hint.
      form.append('device_info', navigator.userAgent || '');

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

        // Optional: soft-reload the sessions list if present on the page.
        const list = document.querySelector('.bmf-biovoice-sessions');
        if (list) {
          // Simple approach for POC — full page reload keeps it reliable.
          // window.location.reload();
        }
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
