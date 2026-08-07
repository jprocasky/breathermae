(function () {
  'use strict';

  // Register the service worker as early as possible (required for installability).
  if ('serviceWorker' in navigator && typeof bmfPwa !== 'undefined' && bmfPwa.swUrl) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register(bmfPwa.swUrl, { scope: '/' })
        .catch(function (err) {
          console.warn('BMF PWA: Service worker registration failed', err);
        });
    });
  }

  let deferredPrompt = null;
  const buttons = document.querySelectorAll('.bmf-pwa-install-btn');

  if (!buttons.length) {
    return;
  }

  // Already running as installed PWA?
  const isStandalone = window.matchMedia('(display-mode: standalone)').matches
    || window.navigator.standalone === true;

  if (isStandalone) {
    // Hide all install buttons permanently.
    buttons.forEach(function (btn) {
      btn.style.display = 'none';
    });
    return;
  }

  // Capture the browser's install prompt.
  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    deferredPrompt = e;

    // Show the buttons.
    buttons.forEach(function (btn) {
      btn.style.display = '';
      btn.classList.add('bmf-pwa-install-btn--ready');
    });
  });

  // Handle click on any of the buttons.
  buttons.forEach(function (btn) {
    btn.addEventListener('click', async function () {
      if (!deferredPrompt) {
        // Fallback message for browsers that don't fire beforeinstallprompt
        // (mainly iOS Safari – they use Share → Add to Home Screen).
        alert('To install this app:\n\n• On Chrome / Edge: look for the install icon in the address bar\n• On iPhone / iPad: tap Share → Add to Home Screen');
        return;
      }

      deferredPrompt.prompt();
      const { outcome } = await deferredPrompt.userChoice;

      // Hide buttons after the user makes a choice.
      if (outcome === 'accepted' || outcome === 'dismissed') {
        buttons.forEach(function (b) {
          b.style.display = 'none';
        });
      }

      deferredPrompt = null;
    });
  });

  // Optional: listen for successful install.
  window.addEventListener('appinstalled', function () {
    buttons.forEach(function (btn) {
      btn.style.display = 'none';
    });
    deferredPrompt = null;
  });
})();
