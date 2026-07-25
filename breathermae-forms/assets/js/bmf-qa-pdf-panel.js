(function () {
  var LOGO = (window.bmfQaCfg && window.bmfQaCfg.logo) ||
    'https://breathermae.com/wp-content/uploads/2025/11/Article-1-Image-1-e1764384968248.jpeg';

  function esc(s) {
    var d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }

  function ensurePdfButton(root) {
    if (!root || root.querySelector('.bmf-qa-export-pdf')) return;
    var csv = root.querySelector('.bmf-qa-export, .bmf-qa-export-csv');
    if (!csv) return;

    if (!csv.classList.contains('bmf-qa-export-csv')) {
      csv.classList.add('bmf-qa-export-csv');
    }

    var group = csv.closest('.bmf-qa-export-group');
    if (!group) {
      group = document.createElement('span');
      group.className = 'bmf-qa-export-group';
      group.style.cssText = 'margin-left:auto;display:inline-flex;gap:6px;flex-wrap:wrap';
      csv.parentNode.insertBefore(group, csv);
      group.appendChild(csv);
      csv.style.marginLeft = '0';
    }

    var pdf = document.createElement('button');
    pdf.type = 'button';
    pdf.className = 'bmf-qa-export bmf-qa-export-pdf';
    pdf.disabled = true;
    pdf.title = 'Export current Q&A to PDF';
    pdf.textContent = 'Export PDF';
    group.appendChild(pdf);
  }

  function tableHasData(root) {
    return !!(root && root.querySelector('.bmf-qa-table tbody tr'));
  }

  function syncPdfState(root) {
    ensurePdfButton(root);
    var pdf = root.querySelector('.bmf-qa-export-pdf');
    if (!pdf) return;
    pdf.disabled = !tableHasData(root);
  }

  function exportPdfFromPanel(root) {
    var table = root.querySelector('.bmf-qa-table');
    if (!table) {
      alert('Nothing to export yet.');
      return;
    }

    var titleEl = root.querySelector('.bmf-qa-title');
    var memberEl = root.querySelector('.bmf-qa-member-label');
    var selectEl = root.querySelector('.bmf-qa-response-select');
    var title = titleEl ? titleEl.textContent.trim() : 'Q&A';
    var member = memberEl ? memberEl.textContent.trim() : '';
    var submitted = '';
    if (selectEl && selectEl.selectedOptions && selectEl.selectedOptions[0]) {
      submitted = selectEl.selectedOptions[0].textContent.trim();
    }

    var headers = [];
    table.querySelectorAll('thead th').forEach(function (th) {
      headers.push(th.textContent.trim());
    });
    if (!headers.length) headers = ['#', 'Question', 'Answer'];

    var bodyRows = [];
    table.querySelectorAll('tbody tr').forEach(function (tr) {
      if (tr.classList.contains('bmf-qa-section-row')) {
        var label = (tr.textContent || '').trim();
        bodyRows.push({ section: true, label: label, colSpan: headers.length });
        return;
      }
      var cells = [];
      tr.querySelectorAll('td').forEach(function (td) {
        cells.push((td.textContent || '').trim());
      });
      bodyRows.push({
        extreme: tr.classList.contains('bmf-qa-extreme'),
        cells: cells
      });
    });

    if (!bodyRows.length) {
      alert('Nothing to export yet.');
      return;
    }

    var html = '<!DOCTYPE html><html><head><meta charset="utf-8">';
    html += '<title>' + esc(title) + '</title>';
    html += '<style>';
    html += 'body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:#1e293b;margin:24px;font-size:11px;}';
    html += '.brand{display:flex;align-items:center;gap:14px;margin-bottom:16px;border-bottom:2px solid #001d50;padding-bottom:12px;}';
    html += '.brand img{max-height:48px;max-width:160px;object-fit:contain;}';
    html += '.brand h1{margin:0;font-size:16px;color:#001d50;}';
    html += '.brand .sub{margin:2px 0 0;color:#64748b;font-size:11px;}';
    html += '.meta{margin:0 0 14px;color:#475569;}';
    html += 'table{width:100%;border-collapse:collapse;margin-top:6px;}';
    html += 'th,td{border:1px solid #cbd5e1;padding:5px 7px;text-align:left;vertical-align:top;}';
    html += 'th{background:#6ec1e4;color:#001d50;font-weight:600;}';
    html += 'tr.section td{background:#f1f5f9;font-weight:600;color:#001d50;}';
    html += 'tr.extreme td{background:#fef2f2;}';
    html += 'tr.extreme td.answer{color:#991b1b;font-weight:600;}';
    html += '.note{margin-top:14px;font-size:10px;color:#64748b;}';
    html += '.pdf-footer{margin-top:28px;padding-top:12px;border-top:1px solid #cbd5e1;font-size:10px;color:#64748b;text-align:center;line-height:1.5;}';
    html += '@media print{body{margin:12px;} .no-print{display:none!important;}}';
    html += '</style></head><body>';
    html += '<div class="brand">';
    if (LOGO) html += '<img src="' + esc(LOGO) + '" alt="BreatherMae">';
    html += '<div><h1>' + esc(title) + '</h1>';
    if (member) html += '<div class="sub">Member: ' + esc(member) + '</div>';
    html += '</div></div>';
    if (submitted) html += '<div class="meta">Submitted: ' + esc(submitted) + '</div>';
    html += '<table><thead><tr>';
    headers.forEach(function (h) {
      html += '<th>' + esc(h) + '</th>';
    });
    html += '</tr></thead><tbody>';

    bodyRows.forEach(function (r) {
      if (r.section) {
        html += '<tr class="section"><td colspan="' + r.colSpan + '">' + esc(r.label) + '</td></tr>';
        return;
      }
      html += '<tr' + (r.extreme ? ' class="extreme"' : '') + '>';
      (r.cells || []).forEach(function (c, i) {
        var cls = '';
        if (r.extreme && headers[i] && /answer/i.test(headers[i])) cls = ' class="answer"';
        html += '<td' + cls + '>' + esc(c) + '</td>';
      });
      html += '</tr>';
    });

    html += '</tbody></table>';
    var genDate = new Date();
    var genStr = genDate.toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' });
    var year = String(genDate.getFullYear());
    html += '<div class="pdf-footer">Breathermae, Inc &copy; Copyright ' + year + '. All Rights Reserved.<br>Generated ' + esc(genStr) + '</div>';
    html += '<p class="note no-print">Use your browser print dialog and choose Save as PDF.</p>';
    html += '</body></html>';

    var w = window.open('', '_blank');
    if (!w) {
      alert('Pop-up blocked. Allow pop-ups for this site to export PDF.');
      return;
    }
    w.document.open();
    w.document.write(html);
    w.document.close();
    setTimeout(function () {
      try {
        w.focus();
        w.print();
      } catch (err) {}
    }, 450);
  }

  function wirePanel(root) {
    if (!root || root.classList.contains('bmf-qa-extremes-wrap')) return;
    if (root.getAttribute('data-bmf-pdf-wired') === '1') {
      syncPdfState(root);
      return;
    }
    root.setAttribute('data-bmf-pdf-wired', '1');
    ensurePdfButton(root);
    syncPdfState(root);

    var body = root.querySelector('.bmf-qa-body') || root;
    var mo = new MutationObserver(function () {
      syncPdfState(root);
    });
    mo.observe(body, { childList: true, subtree: true });
  }

  function scan() {
    document.querySelectorAll('.bmf-qa-wrap').forEach(wirePanel);
  }

  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest('.bmf-qa-export-pdf');
    if (!btn || btn.disabled) return;
    var root = btn.closest('.bmf-qa-wrap');
    if (!root || root.classList.contains('bmf-qa-extremes-wrap')) return;
    ev.preventDefault();
    exportPdfFromPanel(root);
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', scan);
  } else {
    scan();
  }

  document.addEventListener('uls:selected-member', function () {
    setTimeout(scan, 50);
  });
  document.addEventListener('bmf:selected-form', function () {
    setTimeout(scan, 50);
  });
})();
