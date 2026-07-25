/**
 * Keep Q&A / Extremes date|response selects visible & enabled when they have options.
 * setEmpty() was hiding the wrap even after dates/responses had already been loaded.
 */
(function () {
  function fixSelects(root) {
    if (!root) return;
    var wraps = root.querySelectorAll
      ? root.querySelectorAll('.bmf-qa-select-wrap')
      : [];
    if (!wraps.length && root.classList && root.classList.contains('bmf-qa-select-wrap')) {
      wraps = [root];
    }
    wraps.forEach(function (wrap) {
      var sel = wrap.querySelector('select.bmf-qa-date-select, select.bmf-qa-response-select, select.bmf-qa-select');
      if (!sel) return;
      if (sel.options && sel.options.length > 0) {
        wrap.style.display = 'inline-flex';
        sel.disabled = false;
        sel.removeAttribute('disabled');
      }
    });
  }

  function scan() {
    document.querySelectorAll('.bmf-qa-wrap').forEach(function (root) {
      fixSelects(root);
      // Clear stuck loading state that blocks pointer events on the select
      if (root.classList.contains('bmf-qa-loading')) {
        var body = root.querySelector('.bmf-qa-table-wrap');
        if (body && (body.querySelector('table') || body.querySelector('.bmf-qa-empty'))) {
          var empty = body.querySelector('.bmf-qa-empty');
          var txt = empty ? (empty.textContent || '') : '';
          if (!/loading/i.test(txt)) {
            root.classList.remove('bmf-qa-loading');
          }
        }
      }
    });
  }

  var scheduled = null;
  function schedule() {
    if (scheduled) return;
    scheduled = requestAnimationFrame(function () {
      scheduled = null;
      scan();
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', scan);
  } else {
    scan();
  }

  if (document.body) {
    var mo = new MutationObserver(schedule);
    mo.observe(document.body, {
      childList: true,
      subtree: true,
      attributes: true,
      attributeFilter: ['style', 'class', 'disabled']
    });
  }

  document.addEventListener('uls:selected-member', function () {
    setTimeout(scan, 100);
    setTimeout(scan, 600);
  });
  document.addEventListener('bmf:selected-form', function () {
    setTimeout(scan, 100);
    setTimeout(scan, 600);
  });
  document.addEventListener('change', function (ev) {
    if (ev.target && ev.target.matches && ev.target.matches('.bmf-qa-date-select, .bmf-qa-response-select')) {
      setTimeout(scan, 100);
      setTimeout(scan, 600);
    }
  });
})();
